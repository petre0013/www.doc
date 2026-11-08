<?php

use App\Models\AuditEvent;
use App\Models\Backup;
use App\Models\Document;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Backup\BackupService;
use App\Support\Audit;
use App\Support\ClamAv;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

test('admins are warned until backups are scheduled and encrypted', function () {
    login();
    $this->get('/workspaces')->assertInertia(fn (Assert $p) => $p->where('backupWarning', fn ($w) => str_contains($w, 'off')));

    \App\Models\Setting::put('backup', ['enabled' => true, 'encryption' => false]);
    $this->get('/workspaces')->assertInertia(fn (Assert $p) => $p->where('backupWarning', fn ($w) => str_contains($w, 'encrypted')));

    \App\Models\Setting::put('backup', ['enabled' => true, 'encryption' => true]);
    $this->get('/workspaces')->assertInertia(fn (Assert $p) => $p->where('backupWarning', null));
});

test('non-admins do not see the backup warning', function () {
    login(role: 'viewer');

    $this->get('/workspaces')->assertInertia(fn (Assert $p) => $p->where('backupWarning', null));
});

test('refused access to a restricted workspace is audited once per minute', function () {
    login(role: 'viewer');
    $workspace = Workspace::factory()->create(['restricted' => true]);

    $this->get("/workspaces/{$workspace->id}")->assertNotFound();
    $this->get("/workspaces/{$workspace->id}")->assertNotFound();

    $events = AuditEvent::where('event', 'access.denied')->get();
    expect($events)->toHaveCount(1)->and($events[0]->workspace_id)->toBe($workspace->id);
});

test('a short password is flagged at sign-in and must be replaced', function () {
    $user = User::factory()->create(); // factory password is "password"
    $user->assignRole(\Spatie\Permission\Models\Role::findOrCreate('viewer', 'web'));

    $this->post('/login', ['email' => $user->email, 'password' => 'password']);

    expect($user->fresh()->must_change_password)->toBeTrue();
    $this->get('/workspaces')->assertRedirect('/settings/profile');
    $this->get('/settings/profile')->assertOk();

    $this->patch('/settings/password', [
        'current_password' => 'password',
        'password' => 'a-long-enough-password',
        'password_confirmation' => 'a-long-enough-password',
    ])->assertSessionHasNoErrors();

    expect($user->fresh()->must_change_password)->toBeFalse();
    $this->get('/workspaces')->assertOk();
});

test('backup verification loads rows into a throwaway schema and leaves no trace', function () {
    Storage::fake('local');
    login();
    Document::factory()->create();
    $backup = Backup::create(['trigger' => 'manual', 'disk' => 'local', 'status' => 'pending']);
    app(BackupService::class)->run($backup->fresh(), true);

    $count = Document::count();
    $this->artisan('backup:verify')->assertExitCode(0);

    expect(DB::selectOne("SELECT count(*) AS c FROM information_schema.schemata WHERE schema_name = 'restore_check'")->c)->toBe(0)
        ->and(DB::selectOne('SELECT current_schema() AS s')->s)->toBe('public')
        ->and(Document::count())->toBe($count);
});

test('audit events are streamed off-host when configured, and an outage is harmless', function () {
    config(['logging.channels.audit_syslog.handler_with.host' => 'collector.example']);
    login();

    $channel = Mockery::mock();
    $channel->shouldReceive('info')->once()->with('audit', Mockery::on(fn ($row) => $row['event'] === 'document.updated' && $row['row_hash'] !== null));
    Log::shouldReceive('channel')->once()->with('audit_syslog')->andReturn($channel);
    Audit::record('document.updated');

    Log::shouldReceive('channel')->once()->andThrow(new RuntimeException('down'));
    expect(Audit::record('document.created')->exists)->toBeTrue();
});

function fakeScanner(string|Throwable $result): void
{
    config(['services.clamav.host' => 'clamd']);
    app()->instance(ClamAv::class, new class($result) extends ClamAv {
        public function __construct(private string|Throwable $result) {}

        protected function scan(string $path): string
        {
            return $this->result instanceof Throwable ? throw $this->result : $this->result;
        }
    });
}

test('uploads are scanned when a scanner is configured', function (string|Throwable $result, bool $accepted) {
    Storage::fake('local');
    login();
    fakeScanner($result);
    $document = Document::factory()->create();

    $response = $this->post("/documents/{$document->id}/attachments", ['file' => UploadedFile::fake()->create('a.pdf', 4)]);

    $accepted ? $response->assertNoContent() : $response->assertSessionHasErrors('file');
    expect($document->attachments()->count())->toBe($accepted ? 1 : 0);
})->with([
    'clean'       => ["stream: OK\0", true],
    'infected'    => ["stream: Eicar FOUND\0", false],
    'unreachable' => [new RuntimeException('no route'), false],
]);

test('only deactivated accounts can be anonymised, and identity leaves the account', function () {
    login();
    $user = User::factory()->create(['name' => 'Ana Real']);
    $event = Audit::record('document.updated', null, [], $user->id);

    $this->post("/admin/users/{$user->id}/anonymise")->assertSessionHas('error');

    $user->forceFill(['deactivated_at' => now()])->save();
    $this->post("/admin/users/{$user->id}/anonymise")->assertSessionHas('success');

    expect($user->fresh()->name)->toBe("Former user #{$user->id}")
        ->and($user->fresh()->email)->not->toContain('@example.com')
        ->and($event->fresh()->actor_name)->toBe('Ana Real')
        ->and(\App\Support\AuditChain::verify()['broken_at'])->toBeNull();

    $this->post("/admin/users/{$user->id}/reactivate")->assertSessionHas('error');
});
