<?php

use App\Models\Attachment;
use App\Models\AuditEvent;
use App\Models\Backup;
use App\Models\ConversionJob;
use App\Models\Document;
use App\Models\Template;
use App\Models\User;
use App\Services\Backup\BackupService;
use App\Support\Audit;
use App\Support\AuditChain;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/** Change a row the way someone with direct database access could. */
function tamperWithAuditEvents(callable $change): void
{
    DB::statement('ALTER TABLE audit_events DISABLE TRIGGER audit_events_no_change');
    $change();
    DB::statement('ALTER TABLE audit_events ENABLE TRIGGER audit_events_no_change');
}

// ── Audit integrity ──────────────────────────────────────────────────────────

test('the database refuses to change or remove audit events', function (string $sql) {
    login();
    Audit::record('document.updated');

    expect(fn () => DB::transaction(fn () => DB::statement($sql)))->toThrow(Illuminate\Database\QueryException::class);
})->with([
    'update'   => ["UPDATE audit_events SET event = 'x'"],
    'delete'   => ['DELETE FROM audit_events'],
    'truncate' => ['TRUNCATE audit_events'],
]);

test('an untouched chain verifies, including context with nested keys', function () {
    login();
    Audit::record('document.updated', null, ['z' => 1, 'a' => ['y' => 'é', 'b' => [1, 2]]]);
    Audit::record('document.created');

    expect(AuditChain::verify())->toMatchArray(['broken_at' => null, 'checked' => AuditEvent::count()]);
    $this->artisan('audit:verify')->assertExitCode(0);
});

test('editing a row breaks the chain and is reported', function () {
    login();
    $event = Audit::record('document.updated', null, ['title' => 'Policy']);
    Audit::record('document.created');

    tamperWithAuditEvents(fn () => DB::table('audit_events')->where('id', $event->id)
        ->update(['context' => json_encode(['title' => 'Forged'])]));

    expect(AuditChain::verify()['broken_at'])->toBe($event->id);
    $this->artisan('audit:verify')->assertExitCode(1);
    expect(AuditEvent::where('event', 'audit.chain_broken')->exists())->toBeTrue();
});

test('removing a row from the middle breaks the chain', function () {
    login();
    Audit::record('document.updated');
    $middle = Audit::record('document.updated');
    $last = Audit::record('document.updated');

    tamperWithAuditEvents(fn () => DB::table('audit_events')->where('id', $middle->id)->delete());

    expect(AuditChain::verify()['broken_at'])->toBe($last->id);
});

test('retention pruning leaves a valid chain and records itself', function () {
    login();
    AuditChain::withMaintenance(fn () => AuditEvent::query()->delete());
    $old = Audit::record('document.updated');
    Audit::record('document.created');
    AuditChain::withMaintenance(fn () => DB::table('audit_events')->where('id', $old->id)->delete());

    expect(AuditChain::verify()['broken_at'])->toBeNull();
});

test('the actor stays named on an event after the account row is gone', function () {
    $user = login();
    $event = Audit::record('document.updated');

    DB::table('users')->where('id', $user->id)->delete();

    expect($event->fresh())
        ->actor_name->toBe($user->name)
        ->actor_email->toBe($user->email)
        ->user_id->toBe($user->id);
});

test('the CSV export neutralises spreadsheet formulas and is audited', function () {
    login();
    Audit::record('auth.login_failed', null, ['email' => 'x']);
    tamperWithAuditEvents(fn () => DB::table('audit_events')->update(['actor_name' => '=HYPERLINK("evil")']));

    $csv = $this->get('/admin/audit/export')->assertOk()->streamedContent();

    expect($csv)->toContain("'=HYPERLINK")
        ->and(AuditEvent::where('event', 'audit.exported')->exists())->toBeTrue();
});

test('audit retention is validated, saved and audited', function () {
    login();

    $this->patch('/admin/audit/settings', ['retention_days' => 5])->assertSessionHasErrors('retention_days');
    $this->patch('/admin/audit/settings', ['retention_days' => 730])->assertSessionHasNoErrors();

    expect(\App\Models\Setting::get('audit')['retention_days'])->toBe(730)
        ->and(AuditEvent::where('event', 'settings.audit_updated')->exists())->toBeTrue();
});

test('only admins can export the audit trail', function () {
    login(role: 'editor');

    $this->get('/admin/audit/export')->assertForbidden();
});

// ── Account lifecycle ────────────────────────────────────────────────────────

test('a deactivated user cannot sign in', function () {
    $user = User::factory()->create(['deactivated_at' => now()]);

    $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertSessionHasErrors('email');

    $this->assertGuest();
});

test('deactivation ends a live session', function () {
    $user = login();
    $user->forceFill(['deactivated_at' => now()])->save();

    $this->get('/workspaces')->assertRedirect('/login');

    $this->assertGuest();
});

test('a user can be deactivated and reactivated without losing history', function () {
    login();
    $other = User::factory()->create();
    $other->assignRole('viewer');

    $this->delete("/admin/users/{$other->id}")->assertSessionHas('success');
    expect($other->fresh()->isActive())->toBeFalse();

    $this->post("/admin/users/{$other->id}/reactivate")->assertSessionHas('success');
    expect($other->fresh()->isActive())->toBeTrue()
        ->and(AuditEvent::where('event', 'user.reactivated')->exists())->toBeTrue();
});

test('deactivated admins do not count towards keeping one admin', function () {
    $admin = login();
    $inactive = User::factory()->create(['deactivated_at' => now()]);
    $inactive->assignRole('admin');
    $other = User::factory()->create();
    $other->assignRole('admin');

    $this->delete("/admin/users/{$other->id}")->assertSessionHas('success');
    $this->patch("/admin/users/{$admin->id}", ['role' => 'editor'])->assertSessionHas('error');
});

// ── Credentials and sessions ─────────────────────────────────────────────────

test('passwords shorter than twelve characters are rejected', function () {
    login();

    $this->patch('/settings/password', [
        'current_password' => 'password',
        'password' => 'elevenchars',
        'password_confirmation' => 'elevenchars',
    ])->assertSessionHasErrors('password');
});

test('changing a password invalidates sessions holding the old hash', function () {
    $user = login();
    $oldHash = $user->getAuthPassword();

    $this->patch('/settings/password', [
        'current_password' => 'password',
        'password' => 'a-much-longer-password',
        'password_confirmation' => 'a-much-longer-password',
    ])->assertSessionHasNoErrors();

    // Another device's session still carries the hash from before the change.
    $this->withSession(['password_hash_web' => $oldHash])->get('/workspaces')->assertRedirect('/login');
});

test('changing email requires the current password and is audited', function () {
    $user = login();
    $payload = ['name' => $user->name, 'email' => 'new@example.com'];

    $this->patch('/settings/profile', $payload)->assertSessionHasErrors('current_password');
    expect($user->fresh()->email)->not->toBe('new@example.com');

    $this->patch('/settings/profile', [...$payload, 'current_password' => 'password'])->assertSessionHasNoErrors();
    expect($user->fresh()->email)->toBe('new@example.com')
        ->and(AuditEvent::firstWhere('event', 'user.email_changed')->context['to'])->toBe('new@example.com');
});

test('changing only the name needs no password', function () {
    $user = login();

    $this->patch('/settings/profile', ['name' => 'New Name', 'email' => $user->email])->assertSessionHasNoErrors();

    expect($user->fresh()->name)->toBe('New Name');
});

// ── Attachments and images ───────────────────────────────────────────────────

test('a removed attachment stays downloadable and in backups, until the page is purged', function () {
    Storage::fake('local');
    login();
    $document = Document::factory()->create();
    Storage::disk('local')->put('attachments/kept.pdf', 'bytes');
    $attachment = Attachment::factory()->for($document)->create(['path' => 'attachments/kept.pdf']);

    $this->delete("/documents/{$document->id}/attachments/{$attachment->id}")->assertNoContent();
    $this->get("/documents/{$document->id}/attachments/{$attachment->id}")->assertOk();

    $backup = Backup::create(['trigger' => 'manual', 'disk' => 'local', 'status' => 'pending']);
    app(BackupService::class)->run($backup->fresh(), true);
    expect($backup->fresh()->manifest['counts']['attachments'])->toBe(1);

    $document->delete();
    $this->delete("/trash/documents/{$document->id}");
    Storage::disk('local')->assertMissing('attachments/kept.pdf');
});

test('attachment activity is audited', function () {
    Storage::fake('local');
    login();
    $document = Document::factory()->create();

    $this->post("/documents/{$document->id}/attachments", [
        'file' => \Illuminate\Http\UploadedFile::fake()->create('evidence.pdf', 4),
    ])->assertNoContent();

    $attachment = $document->attachments()->first();
    $this->get("/documents/{$document->id}/attachments/{$attachment->id}")->assertOk();
    $this->delete("/documents/{$document->id}/attachments/{$attachment->id}")->assertNoContent();

    expect(AuditEvent::where('event', 'like', 'attachment.%')->orderBy('id')->pluck('event')->all())
        ->toBe(['attachment.added', 'attachment.downloaded', 'attachment.removed']);
});

test('uploaded images require a signed-in user', function () {
    Storage::fake('public');
    Storage::disk('public')->put('assets/diagram.png', 'png-bytes');

    $this->get('/storage/assets/diagram.png')->assertRedirect('/login');

    login();
    $response = $this->get('/storage/assets/diagram.png')->assertOk();
    expect($response->headers->get('Cache-Control'))->toContain('private');
});

test('the image route cannot leave the upload directory', function () {
    Storage::fake('public');
    login();

    $this->get('/storage/assets/../../.env')->assertNotFound();
});

test('downloading an export is audited', function () {
    Storage::fake('local');
    login();
    $document = Document::factory()->create();
    Storage::disk('local')->put('exports/x.pdf', 'pdf');
    $job = ConversionJob::factory()->create([
        'document_id' => $document->id, 'direction' => 'export', 'format' => 'pdf',
        'status' => 'done', 'result_path' => 'exports/x.pdf', 'created_by_id' => auth()->id(),
    ]);

    $this->get("/documents/{$document->id}/exports/{$job->id}?download=1")->assertOk();

    expect(AuditEvent::firstWhere('event', 'document.exported')->context['format'])->toBe('pdf');
});

// ── Backups ──────────────────────────────────────────────────────────────────

function completedBackup(): Backup
{
    $backup = Backup::create(['trigger' => 'manual', 'disk' => 'local', 'status' => 'pending']);
    app(BackupService::class)->run($backup->fresh(), true);

    return $backup->fresh();
}

test('downloading a backup is audited', function () {
    Storage::fake('local');
    login();
    $backup = completedBackup();

    $this->get("/admin/backups/{$backup->id}/download")->assertOk();

    expect(AuditEvent::where('event', 'backup.downloaded')->exists())->toBeTrue();
});

test('backup:verify records a pass for a good archive', function () {
    Storage::fake('local');
    login();
    Document::factory()->create();
    $backup = completedBackup();

    $this->artisan('backup:verify')->assertExitCode(0);

    expect($backup->fresh())
        ->verify_status->toBe('passed')
        ->verified_at->not->toBeNull()
        ->and(AuditEvent::where('event', 'backup.verified')->exists())->toBeTrue();
});

test('backup:verify records a failure for a corrupted archive', function () {
    Storage::fake('local');
    login();
    $backup = completedBackup();
    Storage::disk('local')->put($backup->path, 'not a zip');

    $this->artisan('backup:verify')->assertExitCode(1);

    expect($backup->fresh()->verify_status)->toBe('failed')
        ->and(AuditEvent::where('event', 'backup.verify_failed')->exists())->toBeTrue();
});

// ── Templates ────────────────────────────────────────────────────────────────

test('the NIS2 pack installs once and renders as valid content', function () {
    login();

    $this->post('/templates/nis2-pack')->assertSessionHas('success');
    $count = Template::where('name', 'like', 'NIS2:%')->count();
    $this->post('/templates/nis2-pack')->assertSessionHas('success');

    expect($count)->toBe(13)
        ->and(Template::where('name', 'like', 'NIS2:%')->count())->toBe(13)
        ->and(\App\Services\RenderDocument::toHtml(Template::first()->content))->toContain('Document control');
});

test('viewers cannot install the NIS2 pack', function () {
    login(role: 'viewer');

    $this->post('/templates/nis2-pack')->assertForbidden();
});
