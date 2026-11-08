<?php

use App\Mail\ReminderDigest;
use App\Models\AuditEvent;
use App\Models\Backup;
use App\Models\Document;
use App\Models\DocumentAcknowledgement;
use App\Models\RegisterItem;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Backup\BackupService;
use App\Services\Backup\RestoreService;
use App\Support\Registers;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

function registerItem(string $type, array $attributes = []): RegisterItem
{
    return RegisterItem::create($attributes + ['type' => $type, 'title' => 'Item', 'status' => Registers::all()[$type]['statuses'][0], 'data' => []]);
}

test('viewers cannot open registers', function (string $uri) {
    login(role: 'viewer');
    $this->get($uri)->assertForbidden();
})->with(['/registers', '/registers/incident', '/registers/risk']);

test('an unknown register type is a 404', function () {
    login();
    $this->get('/registers/nonsense')->assertNotFound();
});

test('a record is created with a reference and audited', function () {
    login(role: 'editor');

    $this->post('/registers/incident', ['title' => 'Phishing', 'status' => 'open', 'data' => ['significant' => 'undetermined']])->assertRedirect();

    $item = RegisterItem::firstWhere('title', 'Phishing');
    expect($item->reference)->toBe('INC-' . str_pad((string) $item->id, 4, '0', STR_PAD_LEFT))
        ->and(AuditEvent::firstWhere('event', 'register.created')->context['reference'])->toBe($item->reference);
});

test('fields are validated against the register definition', function () {
    login(role: 'editor');

    $this->post('/registers/risk', ['title' => 'x', 'status' => 'nonsense', 'data' => ['likelihood' => '9']])
        ->assertSessionHasErrors(['status', 'data.likelihood']);
});

test('updates audit which fields changed', function () {
    login(role: 'editor');
    $item = registerItem('task', ['data' => ['details' => 'a']]);

    $this->patch("/registers/task/{$item->id}", ['title' => 'Item', 'status' => 'done', 'data' => ['details' => 'b']])->assertSessionHasNoErrors();

    expect(AuditEvent::firstWhere('event', 'register.updated')->context['changed'])->toBe(['status', 'details']);
});

test('a record cannot be reached through the wrong register', function () {
    login(role: 'editor');
    $item = registerItem('task');

    $this->get("/registers/risk/{$item->id}")->assertNotFound();
});

test('only admins can delete a record, and it is kept as a soft delete', function () {
    login(role: 'editor');
    $item = registerItem('task');
    $this->delete("/registers/task/{$item->id}")->assertForbidden();

    login();
    $this->delete("/registers/task/{$item->id}")->assertRedirect();
    expect(RegisterItem::find($item->id))->toBeNull()
        ->and(RegisterItem::withTrashed()->find($item->id))->not->toBeNull();
});

test('significant incidents get the Art. 23 deadlines', function () {
    $aware = now()->subHours(30)->startOfMinute();
    $item = registerItem('incident', ['data' => ['significant' => 'yes', 'aware_at' => $aware->toIso8601String(), 'early_warning_at' => $aware->copy()->addHours(2)->toIso8601String()]]);

    [$early, $notification, $final] = Registers::incidentDeadlines($item);

    expect($early)->late->toBeFalse()->sent->not->toBeNull()
        ->and($notification['due'])->toBe($aware->copy()->addHours(72)->toIso8601String())
        ->and($notification['late'])->toBeFalse()
        ->and($final['due'])->toBeNull();
});

test('a missed deadline is late, and the final report runs from the notification', function () {
    $aware = now()->subDays(5);
    $sent = now()->subDays(3)->startOfMinute();
    $item = registerItem('incident', ['data' => ['significant' => 'yes', 'aware_at' => $aware->toIso8601String(), 'notification_at' => $sent->toIso8601String()]]);

    [$early, , $final] = Registers::incidentDeadlines($item);

    expect($early['late'])->toBeTrue()
        ->and($final['due'])->toBe($sent->copy()->addMonthNoOverflow()->toIso8601String());
});

test('incidents not marked significant have no deadlines', function () {
    expect(Registers::incidentDeadlines(registerItem('incident', ['data' => ['significant' => 'no', 'aware_at' => now()->toIso8601String()]])))->toBe([]);
});

test('risk scores are computed and only admins can accept', function () {
    login(role: 'editor');
    $item = registerItem('risk', ['data' => ['likelihood' => '4', 'impact' => '5', 'residual_likelihood' => '2', 'residual_impact' => '3']]);

    expect(Registers::computed($item))->toBe(['score' => 20, 'residual_score' => 6]);
    $this->post("/registers/risk/{$item->id}/accept")->assertForbidden();

    $admin = login();
    $this->post("/registers/risk/{$item->id}/accept")->assertSessionHas('success');
    expect($item->fresh())->status->toBe('accepted')
        ->and($item->fresh()->data['accepted_email'])->toBe($admin->email);
});

test('the requirements list installs once and flags missing evidence', function () {
    login();
    $this->post('/registers/requirement/install');
    $count = RegisterItem::where('type', 'requirement')->count();
    $this->post('/registers/requirement/install');

    expect($count)->toBe(count(Registers::catalogue()))
        ->and(RegisterItem::where('type', 'requirement')->count())->toBe($count);

    $item = RegisterItem::where('type', 'requirement')->first();
    $item->update(['status' => 'implemented']);
    $this->get('/registers')->assertInertia(fn (Assert $p) => $p->has('requirements.no_evidence', 1));

    $item->links()->create(['document_id' => Document::factory()->create()->id]);
    $this->get('/registers')->assertInertia(fn (Assert $p) => $p->has('requirements.no_evidence', 0));
});

test('registers do not expose restricted pages', function () {
    login(role: 'editor');
    $page = Document::factory()->for(Workspace::factory()->create(['restricted' => true]))->create();
    $item = registerItem('requirement');
    $item->links()->create(['document_id' => $page->id]);

    $this->post("/registers/requirement/{$item->id}/links", ['document_id' => $page->id])->assertNotFound();
    $this->get("/registers/requirement/{$item->id}")->assertInertia(fn (Assert $p) => $p
        ->has('links', 0)
        ->where('documentOptions', fn ($docs) => collect($docs)->pluck('id')->doesntContain($page->id)));
});

test('the digest emails owners about due work and pending approvals', function () {
    Mail::fake();
    $owner = User::factory()->create();
    $approver = User::factory()->create();
    registerItem('task', ['title' => 'Patch firewall', 'owner_id' => $owner->id, 'due_at' => today()->addDays(3)]);
    registerItem('task', ['title' => 'Far away', 'owner_id' => $owner->id, 'due_at' => today()->addDays(60)]);
    registerItem('task', ['title' => 'Finished', 'owner_id' => $owner->id, 'due_at' => today(), 'status' => 'done']);
    Document::factory()->create()->control()->create(['doc_number' => 'P-1', 'approver_id' => $approver->id, 'status' => 'in_review', 'classification' => 'internal']);

    $this->artisan('reminders:send')->assertExitCode(0);

    Mail::assertSent(ReminderDigest::class, fn ($m) => $m->hasTo($owner->email) && count($m->lines) === 1 && str_contains($m->lines[0], 'Patch firewall'));
    Mail::assertSent(ReminderDigest::class, fn ($m) => $m->hasTo($approver->email));
});

test('an approaching incident deadline alerts the owner and admins once', function () {
    Mail::fake();
    login();
    $admin = User::role('admin')->first();
    $owner = User::factory()->create();
    registerItem('incident', ['owner_id' => $owner->id, 'data' => ['significant' => 'yes', 'aware_at' => now()->subHours(20)->toIso8601String()]]);

    $this->artisan('reminders:send', ['--incidents' => true]);
    Mail::assertSent(ReminderDigest::class, 2);
    Mail::assertSent(ReminderDigest::class, fn ($m) => $m->hasTo($admin->email));

    $this->artisan('reminders:send', ['--incidents' => true]);
    Mail::assertSent(ReminderDigest::class, 2);
});

function approvedControl(bool $ack = false): array
{
    $approver = User::factory()->create();
    $approver->assignRole(\Spatie\Permission\Models\Role::findOrCreate('viewer', 'web'));
    $editor = login(role: 'editor');
    $document = Document::factory()->create(['content' => ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Policy']]]]]]);
    $document->control()->create(['doc_number' => 'POL-9', 'owner_id' => $editor->id, 'approver_id' => $approver->id, 'classification' => 'internal', 'ack_required' => $ack]);
    test()->post("/documents/{$document->id}/control/submit");
    test()->actingAs($approver)->post("/documents/{$document->id}/control/approve");
    test()->actingAs($editor);

    return [$editor, $approver, $document->fresh()];
}

test('readers can confirm reading the approved version, once', function () {
    [, $approver, $document] = approvedControl(ack: true);

    $this->actingAs($approver)->post("/documents/{$document->id}/control/acknowledge")->assertSessionHas('success');
    $this->post("/documents/{$document->id}/control/acknowledge");

    expect(DocumentAcknowledgement::count())->toBe(1)
        ->and(AuditEvent::where('event', 'control.acknowledged')->count())->toBe(1);
    $this->get("/documents/{$document->id}")->assertInertia(fn (Assert $p) => $p->where('control.ack.mine', true)->where('control.ack.done', 1));
});

test('acknowledging is refused when it is not required', function () {
    [, $approver, $document] = approvedControl();

    $this->actingAs($approver)->post("/documents/{$document->id}/control/acknowledge")->assertSessionHas('error');
    expect(DocumentAcknowledgement::count())->toBe(0);
});

test('a new approved version needs a fresh acknowledgement', function () {
    [$editor, $approver, $document] = approvedControl(ack: true);
    $this->actingAs($approver)->post("/documents/{$document->id}/control/acknowledge");

    $this->actingAs($editor)->patch("/documents/{$document->id}/rename", ['title' => 'V2']);
    $this->post("/documents/{$document->id}/control/submit");
    $this->actingAs($approver)->post("/documents/{$document->id}/control/approve");

    $this->get("/documents/{$document->id}")->assertInertia(fn (Assert $p) => $p->where('control.ack.mine', false));
});

test('the audit pack contains registers, sign-offs, an approved PDF and a correct manifest', function () {
    [, , $document] = approvedControl();
    registerItem('risk', ['title' => '=EvilFormula']);
    login();

    $response = $this->get('/document-control/audit-pack')->assertOk();

    $zip = new ZipArchive();
    $zip->open($response->getFile()->getPathname());
    $manifest = json_decode($zip->getFromName('manifest.json'), true);

    expect($manifest['files'])->toHaveKeys(['document-register.csv', 'sign-offs.csv', 'registers/risk.csv'])
        ->and(collect($manifest['files'])->keys()->filter(fn ($n) => str_starts_with($n, 'approved/') && str_ends_with($n, '.pdf')))->toHaveCount(1)
        ->and($zip->getFromName('registers/risk.csv'))->toContain("'=EvilFormula");

    foreach ($manifest['files'] as $name => $hash) {
        expect(hash('sha256', $zip->getFromName($name)))->toBe($hash);
    }
    expect(AuditEvent::where('event', 'audit.pack_exported')->exists())->toBeTrue();
});

test('only admins can download the audit pack', function () {
    login(role: 'editor');
    $this->get('/document-control/audit-pack')->assertForbidden();
});

test('registers and acknowledgements survive a backup and restore', function () {
    Storage::fake('local');
    [, $approver, $document] = approvedControl(ack: true);
    $this->actingAs($approver)->post("/documents/{$document->id}/control/acknowledge");
    $item = registerItem('risk', ['data' => ['likelihood' => '3']]);
    $item->links()->create(['document_id' => $document->id]);

    $backup = Backup::create(['trigger' => 'manual', 'disk' => 'local', 'status' => 'pending']);
    app(BackupService::class)->run($backup->fresh(), true);
    app(RestoreService::class)->restore($backup->fresh());

    expect(RegisterItem::find($item->id)->data['likelihood'])->toBe('3')
        ->and(RegisterItem::find($item->id)->links()->count())->toBe(1)
        ->and(DocumentAcknowledgement::count())->toBe(1);
});
