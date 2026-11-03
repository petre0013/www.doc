<?php

use App\Models\Attachment;
use App\Models\Backup;
use App\Models\Document;
use App\Models\DocumentFolder;
use App\Models\DocumentVersion;
use App\Models\Setting;
use App\Models\Tag;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceGroup;
use App\Services\Backup\BackupService;
use App\Services\Backup\RestoreService;
use Database\Factories\DocumentFactory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

/** A full, valid settings payload with optional overrides (recursive merge). */
function settingsPayload(array $overrides = []): array
{
    $base = [
        'enabled'    => true,
        'interval'   => 'daily',
        'retention'  => 7,
        'driver'     => 'local',
        'encryption' => false,
        'smb'  => ['host' => '', 'share' => '', 'path' => '', 'username' => '', 'password' => '', 'domain' => ''],
        'mail' => ['enabled' => false, 'to' => '', 'host' => '', 'port' => 587, 'username' => '', 'password' => '', 'encryption' => 'tls', 'from_address' => '', 'from_name' => ''],
    ];

    return array_replace_recursive($base, $overrides);
}

/** Open a backup archive and list its entry names. */
function archiveEntries(Backup $backup): array
{
    $zip = new ZipArchive();
    $zip->open(Storage::disk($backup->disk)->path($backup->path));
    $names = [];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $names[] = $zip->getNameIndex($i);
    }
    $zip->close();

    return $names;
}

/** Read a single entry's contents out of a backup archive. */
function archiveEntry(Backup $backup, string $name): string|false
{
    $zip = new ZipArchive();
    $zip->open(Storage::disk($backup->disk)->path($backup->path));
    $contents = $zip->getFromName($name);
    $zip->close();

    return $contents;
}

test('non-admins cannot reach the backups area', function () {
    login(role: 'editor');

    $this->get('/admin/backups')->assertForbidden();
    $this->post('/admin/backups')->assertForbidden();
    $this->patch('/admin/backups/settings', settingsPayload())->assertForbidden();
});

test('an admin can save the backup schedule settings', function () {
    login();

    $this->patch('/admin/backups/settings', settingsPayload([
        'interval'  => 'weekly',
        'retention' => 5,
    ]))->assertRedirect();

    expect(Setting::get('backup'))->toMatchArray([
        'enabled'   => true,
        'interval'  => 'weekly',
        'driver'    => 'local',
        'retention' => 5,
    ]);
});

test('saving settings rejects an unknown interval', function () {
    login();

    $this->patch('/admin/backups/settings', settingsPayload([
        'interval' => 'hourly',
    ]))->assertSessionHasErrors('interval');
});

test('a custom interval is accepted and stored as an integer number of hours', function () {
    login();

    $this->patch('/admin/backups/settings', settingsPayload([
        'interval' => '72',
    ]))->assertRedirect()->assertSessionHasNoErrors();

    expect(Setting::get('backup')['interval'])->toBe(72);
});

test('a custom interval outside 1..8760 hours is rejected', function () {
    login();

    $this->patch('/admin/backups/settings', settingsPayload([
        'interval' => '0',
    ]))->assertSessionHasErrors('interval');

    $this->patch('/admin/backups/settings', settingsPayload([
        'interval' => '9000',
    ]))->assertSessionHasErrors('interval');
});

test('retention 0 (never delete) is accepted and skips pruning', function () {
    Storage::fake('local');
    login();

    $this->patch('/admin/backups/settings', settingsPayload([
        'retention' => 0,
    ]))->assertRedirect()->assertSessionHasNoErrors();

    expect(Setting::get('backup')['retention'])->toBe(0);

    // Older backups present, then a fresh run — with retention 0 none are pruned.
    foreach (range(1, 3) as $i) {
        Backup::create(['trigger' => 'manual', 'disk' => 'local', 'status' => 'done']);
    }
    $newest = Backup::create(['trigger' => 'manual', 'disk' => 'local', 'status' => 'pending']);

    app(BackupService::class)->run($newest->fresh(), true);

    expect(Backup::where('status', 'done')->count())->toBe(4);
});

test('retention prunes the oldest backups past the keep count, archives and all', function () {
    Storage::fake('local');
    login();

    Setting::put('backup', settingsPayload(['retention' => 2]));

    // A failed run is never a restore source: pruning filters to status=done, so
    // it must be left untouched and must not count toward the keep window.
    $failed = Backup::create(['trigger' => 'manual', 'disk' => 'local', 'status' => 'failed']);

    // Three successful backups, oldest → newest, each writing a real archive.
    // Pruning runs at the tail of every run, so the third run is what trims the
    // first once three done backups exist with retention 2.
    $runs = [];
    foreach (range(1, 3) as $i) {
        $backup = Backup::create(['trigger' => 'manual', 'disk' => 'local', 'status' => 'pending']);
        app(BackupService::class)->run($backup->fresh(), true); // canonical-only: no Node/PDF
        $runs[] = $backup->fresh();
    }
    [$oldest, $middle, $newest] = $runs;

    // The two newest survive (row + archive on disk); the oldest is gone, and so
    // is its archive file (deleted through the destination, not just the row).
    expect(Backup::find($newest->id))->not->toBeNull()
        ->and(Storage::disk('local')->exists($newest->path))->toBeTrue();
    expect(Backup::find($middle->id))->not->toBeNull();

    expect(Backup::find($oldest->id))->toBeNull()
        ->and(Storage::disk('local')->exists($oldest->path))->toBeFalse();

    // Exactly the keep count remains, and the unrelated failed run is intact.
    expect(Backup::where('status', 'done')->count())->toBe(2);
    expect(Backup::find($failed->id))->not->toBeNull();
});

test('pruning is scoped to the destination it ran on', function () {
    Storage::fake('local');
    login();

    Setting::put('backup', settingsPayload(['retention' => 1]));

    // A done backup recorded against a different driver must not be pruned (or
    // counted) when a local run trims its own history.
    $other = Backup::create(['trigger' => 'manual', 'disk' => 'smb', 'status' => 'done', 'path' => 'other.zip']);

    foreach (range(1, 2) as $i) {
        $backup = Backup::create(['trigger' => 'manual', 'disk' => 'local', 'status' => 'pending']);
        app(BackupService::class)->run($backup->fresh(), true);
    }

    expect(Backup::where('disk', 'local')->where('status', 'done')->count())->toBe(1);
    expect(Backup::find($other->id))->not->toBeNull();
});

test('the scheduled command resolves a custom interval in hours', function () {
    Setting::put('backup', settingsPayload(['enabled' => true, 'interval' => 6]));

    // A successful scheduled run 3 hours ago is still inside a 6-hour cadence,
    // so the command must NOT dispatch another.
    Backup::create([
        'trigger'     => 'scheduled',
        'disk'        => 'local',
        'status'      => 'done',
        'finished_at' => now()->subHours(3),
    ]);

    $this->artisan('backup:run')->assertSuccessful();

    expect(Backup::where('trigger', 'scheduled')->where('status', 'pending')->count())->toBe(0);
});

test('choosing the SMB driver requires a host and share', function () {
    login();

    $this->patch('/admin/backups/settings', settingsPayload([
        'driver' => 'smb',
    ]))->assertSessionHasErrors(['smb.host', 'smb.share']);
});

test('SMB and SMTP passwords are stored encrypted and preserved when left blank', function () {
    login();

    // First save: provide both passwords.
    $this->patch('/admin/backups/settings', settingsPayload([
        'driver' => 'smb',
        'smb'    => ['host' => 'host', 'share' => 'share', 'path' => 'docs', 'username' => 'u', 'password' => 's3cret', 'domain' => ''],
        'mail'   => ['enabled' => true, 'to' => 'a@b.com', 'host' => 'smtp', 'port' => 587, 'username' => 'mu', 'password' => 'mailpw', 'encryption' => 'tls', 'from_address' => 'f@b.com', 'from_name' => 'x'],
    ]))->assertRedirect();

    $stored = Setting::get('backup');
    expect($stored['smb']['password'])->not->toBe('s3cret')->not->toBe('');
    expect(\App\Support\BackupSettings::smbPassword())->toBe('s3cret');
    expect(\App\Support\BackupSettings::mailPassword())->toBe('mailpw');

    // Second save with blank passwords keeps the stored ones.
    $this->patch('/admin/backups/settings', settingsPayload([
        'driver' => 'smb',
        'smb'    => ['host' => 'host2', 'share' => 'share', 'path' => 'docs', 'username' => 'u', 'password' => '', 'domain' => ''],
        'mail'   => ['enabled' => true, 'to' => 'a@b.com', 'host' => 'smtp', 'port' => 587, 'username' => 'mu', 'password' => '', 'encryption' => 'tls', 'from_address' => 'f@b.com', 'from_name' => 'x'],
    ]))->assertRedirect();

    expect(\App\Support\BackupSettings::smbPassword())->toBe('s3cret');
    expect(\App\Support\BackupSettings::mailPassword())->toBe('mailpw');
    expect(Setting::get('backup')['smb']['host'])->toBe('host2');
});

test('the local destination can be tested', function () {
    Storage::fake('local');
    login();

    $this->post('/admin/backups/test-destination', ['driver' => 'local'])
        ->assertRedirect()
        ->assertSessionHas('success');
});

test('a test email is sent through the configured mailer', function () {
    Mail::fake();
    fakeSmtpProbe();
    login();

    $this->post('/admin/backups/test-email', [
        'mail' => [
            'to' => 'admin@company.com', 'host' => 'smtp.test', 'port' => 587,
            'encryption' => 'tls', 'username' => 'u', 'password' => 'p',
            'from_address' => 'backups@company.com', 'from_name' => 'Backups',
        ],
    ])->assertRedirect()->assertSessionHas('success')
        // The staged connection report rides along for the inline panel.
        ->assertSessionHas('smtpTest', fn ($r) => count($r['stages']) === 4 && $r['endpoint'] === 'smtp.test:587' && $r['transport'] === 'own');

    Mail::assertSent(\App\Mail\BackupReport::class, fn ($m) => $m->hasTo('admin@company.com') && $m->isTest);
});

test('backup reports fall back to the global SMTP when no backup host is set', function () {
    login();

    \App\Support\MailSettings::save([
        'host' => 'smtp.global.test', 'port' => 2525, 'encryption' => 'ssl',
        'username' => 'guser', 'password' => 'gpass',
        'from_address' => 'docs@acme.test', 'from_name' => 'Acme',
    ]);

    // Backup notifications on, with a recipient but NO SMTP host of their own.
    $settings = \App\Support\BackupSettings::get();
    $settings['mail'] = array_replace($settings['mail'], ['enabled' => true, 'to' => 'ops@acme.test', 'host' => '']);
    Setting::put('backup', $settings);

    $cfg = \App\Support\BackupSettings::mailConfig();
    expect($cfg['host'])->toBe('smtp.global.test')       // borrowed from the global mailer
        ->and($cfg['port'])->toBe(2525)
        ->and($cfg['encryption'])->toBe('ssl')
        ->and($cfg['password'])->toBe('gpass')
        ->and($cfg['from_address'])->toBe('docs@acme.test')
        ->and($cfg['to'])->toBe('ops@acme.test');        // recipient stays with the backup block
});

test('a backup-specific SMTP host overrides the global one', function () {
    login();

    \App\Support\MailSettings::save([
        'host' => 'smtp.global.test', 'port' => 2525, 'encryption' => 'ssl',
        'username' => '', 'password' => '', 'from_address' => 'docs@acme.test', 'from_name' => 'Acme',
    ]);

    $settings = \App\Support\BackupSettings::get();
    $settings['mail'] = array_replace($settings['mail'], [
        'enabled' => true, 'to' => 'ops@acme.test',
        'host' => 'smtp.backup.test', 'port' => 587, 'from_address' => 'backups@acme.test',
    ]);
    Setting::put('backup', $settings);

    $cfg = \App\Support\BackupSettings::mailConfig();
    expect($cfg['host'])->toBe('smtp.backup.test')
        ->and($cfg['from_address'])->toBe('backups@acme.test');
});

test('the backup test email can use the global SMTP when no backup host is set', function () {
    Mail::fake();
    fakeSmtpProbe();
    login();

    \App\Support\MailSettings::save([
        'host' => 'smtp.global.test', 'port' => 587, 'encryption' => 'tls',
        'username' => '', 'password' => '', 'from_address' => 'docs@acme.test', 'from_name' => 'Acme',
    ]);

    // Only a recipient + encryption — host left blank, leaning on the global SMTP.
    // The probe must test the EFFECTIVE transport: the panel's endpoint names
    // the global server the test actually went through.
    $this->post('/admin/backups/test-email', ['mail' => ['to' => 'ops@acme.test', 'encryption' => 'tls']])
        ->assertRedirect()->assertSessionHas('success')
        ->assertSessionHas('smtpTest', fn ($r) => $r['endpoint'] === 'smtp.global.test:587' && $r['transport'] === 'global');

    Mail::assertSent(\App\Mail\BackupReport::class, fn ($m) => $m->hasTo('ops@acme.test') && $m->isTest);
});

test('the backup test email still requires a host when no global SMTP exists', function () {
    login();

    $this->post('/admin/backups/test-email', ['mail' => ['to' => 'ops@acme.test', 'encryption' => 'tls']])
        ->assertSessionHasErrors('mail.host');
});

test('a successful backup emails a report when notifications are on', function () {
    Storage::fake('local');
    Mail::fake();
    login();

    Setting::put('backup', array_replace(\App\Support\BackupSettings::get(), [
        'mail' => [
            'enabled' => true, 'to' => 'admin@company.com', 'host' => 'smtp.test', 'port' => 587,
            'encryption' => 'tls', 'username' => 'u', 'password' => \App\Support\BackupSettings::encrypt('p'),
            'from_address' => 'backups@company.com', 'from_name' => 'Backups',
        ],
    ]));

    Document::factory()->create(['content' => DocumentFactory::tiptap('hi')]);

    $this->post('/admin/backups')->assertRedirect();

    Mail::assertSent(\App\Mail\BackupReport::class, fn ($m) =>
        $m->hasTo('admin@company.com') && $m->backup?->status === 'done');
});

// The completion event is written by the worker, which has no session AND no
// request — so both the actor and the address ride the backups row, captured
// when the admin clicked. Running the job outside the original request (ambient
// IP 127.0.0.1) is what makes the assertion meaningful.
test('a queued backup audits its completion with the admin ip captured at request time', function () {
    Storage::fake('local');
    \Illuminate\Support\Facades\Queue::fake();
    login();

    $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.10'])
        ->post('/admin/backups')
        ->assertRedirect();

    $backup = Backup::latest('id')->first();
    expect($backup->ip)->toBe('192.0.2.10');

    (new \App\Jobs\RunBackupJob($backup->id))->handle(
        app(BackupService::class),
        app(\App\Services\Backup\BackupNotifier::class),
    );

    $event = \App\Models\AuditEvent::where('event', 'backup.completed')->latest('id')->first();
    expect($event->ip)->toBe('192.0.2.10')
        ->and($event->user_id)->toBe(auth()->id());
});

// A scheduled run has no human behind it: no actor, and no address to invent.
test('a scheduled backup audits its completion as System with no ip', function () {
    Storage::fake('local');

    $backup = Backup::create(['trigger' => 'scheduled', 'disk' => 'local', 'status' => 'pending']);

    (new \App\Jobs\RunBackupJob($backup->id))->handle(
        app(BackupService::class),
        app(\App\Services\Backup\BackupNotifier::class),
    );

    $event = \App\Models\AuditEvent::where('event', 'backup.completed')->latest('id')->first();
    expect($event->user_id)->toBeNull()
        ->and($event->ip)->toBeNull();
});

test('a backup produces an archive with the canonical layer and a manifest', function () {
    Storage::fake('local');
    login();

    $ws  = Workspace::factory()->create();
    Document::factory()->for($ws)->create(['content' => DocumentFactory::tiptap('Backup me.')]);

    $backup = Backup::create(['trigger' => 'manual', 'disk' => 'local', 'status' => 'pending']);
    app(BackupService::class)->run($backup->fresh());
    $backup->refresh();

    expect($backup->status)->toBe('done');
    expect(Storage::disk('local')->exists($backup->path))->toBeTrue();

    $entries = archiveEntries($backup);
    expect($entries)
        ->toContain('manifest.json')
        ->toContain('canonical/documents.ndjson')
        ->toContain('canonical/workspaces.json')
        ->toContain('canonical/users.json');

    expect($backup->manifest['counts']['documents'])->toBe(1);
    expect($backup->manifest['files'])->toHaveKey('canonical/documents.ndjson'); // sha256 present

    // The readable layer is PDF-per-page (non-authoritative; not checksummed).
    expect(collect($entries)->contains(
        fn ($e) => str_starts_with($e, 'readable/') && str_ends_with($e, '.pdf')
    ))->toBeTrue();
});

test('the canonical layer streams documents as NDJSON and restores at scale', function () {
    Storage::fake('local');
    login();

    $ws = Workspace::factory()->create();
    Document::factory()->for($ws)->count(300)->create([
        'content' => DocumentFactory::tiptap('Scale me.'),
    ]);

    $backup = Backup::create(['trigger' => 'manual', 'disk' => 'local', 'status' => 'pending']);
    app(BackupService::class)->run($backup->fresh());
    $backup->refresh();

    // NDJSON: one JSON object per line, not a single pretty-printed array.
    $ndjson = archiveEntry($backup, 'canonical/documents.ndjson');
    expect($ndjson)->not->toBeFalse();

    $lines = array_values(array_filter(explode("\n", trim($ndjson))));
    expect($lines)->toHaveCount(300);
    expect(json_decode($lines[0], true))->toHaveKeys(['id', 'title', 'content', 'tag_ids']);

    // The whole set streams back losslessly (crosses the 200-row insert batch).
    Document::query()->delete(); // soft-delete drives the visible count to 0
    expect(Document::count())->toBe(0);

    app(RestoreService::class)->restore($backup->fresh());
    expect(Document::count())->toBe(300);
});

test('the manual backup endpoint queues a run and records it', function () {
    Storage::fake('local');
    login();

    Document::factory()->create(['content' => DocumentFactory::tiptap('hi')]);

    $this->post('/admin/backups')->assertRedirect();

    // QUEUE_CONNECTION=sync in tests, so the job has already run.
    $backup = Backup::latest('id')->first();
    expect($backup)->not->toBeNull();
    expect($backup->status)->toBe('done');
    expect($backup->trigger)->toBe('manual');
});

test('a backup restores losslessly, reverting later edits', function () {
    Storage::fake('local');
    login();

    $ws  = Workspace::factory()->create(['name' => 'Original Name']);
    $doc = Document::factory()->for($ws)->create([
        'title'   => 'Original Title',
        'content' => DocumentFactory::tiptap('Original body.'),
    ]);

    $backup = Backup::create(['trigger' => 'manual', 'disk' => 'local', 'status' => 'pending']);
    app(BackupService::class)->run($backup->fresh());

    // Drift after the backup…
    $ws->update(['name' => 'Edited Name']);
    $doc->update(['title' => 'Edited Title']);

    app(RestoreService::class)->restore($backup->fresh());

    expect(Workspace::find($ws->id)->name)->toBe('Original Name');
    expect(Document::find($doc->id)->title)->toBe('Original Title');
});

test('restore round-trips workspace groups and each workspace group_id', function () {
    Storage::fake('local');
    login();

    $group     = WorkspaceGroup::factory()->create(['name' => 'Security']);
    $grouped   = Workspace::factory()->create(['name' => 'BitLocker', 'group_id' => $group->id]);
    $ungrouped = Workspace::factory()->create(['name' => 'Scratch', 'group_id' => null]);

    $backup = Backup::create(['trigger' => 'manual', 'disk' => 'local', 'status' => 'pending']);
    app(BackupService::class)->run($backup->fresh());

    // Drift after the backup: delete the group (members revert to ungrouped).
    $group->workspaces()->update(['group_id' => null]);
    $group->delete();

    app(RestoreService::class)->restore($backup->fresh());

    // Group table + each workspace's group_id come back (proves the group is
    // inserted before workspaces, so the group_id FK resolves).
    expect(WorkspaceGroup::find($group->id)?->name)->toBe('Security');
    expect(Workspace::find($grouped->id)->group_id)->toBe($group->id);
    expect(Workspace::find($ungrouped->id)->group_id)->toBeNull();
});

test('restore round-trips page folders and each page\'s folder_id', function () {
    Storage::fake('local');
    login();

    $ws     = Workspace::factory()->create(['name' => 'Engineering']);
    $folder = DocumentFolder::factory()->create(['workspace_id' => $ws->id, 'name' => 'Runbooks']);
    $filed  = Document::factory()->for($ws)->create(['title' => 'Restore procedure', 'folder_id' => $folder->id]);
    $loose  = Document::factory()->for($ws)->create(['title' => 'Scratch']);

    $backup = Backup::create(['trigger' => 'manual', 'disk' => 'local', 'status' => 'pending']);
    app(BackupService::class)->run($backup->fresh());

    // Drift after the backup: delete the folder (its page reverts to loose).
    $folder->documents()->update(['folder_id' => null]);
    $folder->delete();

    app(RestoreService::class)->restore($backup->fresh());

    // Folder table + each page's folder_id come back — proves document_folders is
    // inserted after workspaces (its FK) and before documents (their folder_id FK).
    expect(DocumentFolder::find($folder->id)?->name)->toBe('Runbooks');
    expect(Document::find($filed->id)->folder_id)->toBe($folder->id);
    expect(Document::find($loose->id)->folder_id)->toBeNull();
});

test('restore round-trips the page tree, tags, versions and verbatim content', function () {
    Storage::fake('local');
    login();

    $ws     = Workspace::factory()->create(['name' => 'KB']);
    $parent = Document::factory()->for($ws)->create(['title' => 'Parent']);
    $child  = Document::factory()->for($ws)->create(['title' => 'Child', 'parent_id' => $parent->id]);

    // A diagram graph + a coloured mark live in `content`. Written straight to the
    // column (no observer/render) so it's stored exactly as given — restore must
    // bring it back byte-for-byte.
    $richContent = [
        'type' => 'doc',
        'content' => [
            ['type' => 'paragraph', 'content' => [
                ['type' => 'text', 'text' => 'danger', 'marks' => [['type' => 'textStyle', 'attrs' => ['color' => '#B5573E']]]],
            ]],
            ['type' => 'networkDiagram', 'attrs' => [
                'name'  => 'Office LAN',
                'graph' => [
                    'nodes' => [['id' => 'a', 'data' => ['label' => 'Router']]],
                    'edges' => [],
                    'viewport' => ['x' => 0, 'y' => 0, 'zoom' => 1],
                ],
            ]],
        ],
    ];
    DB::table('documents')->where('id', $child->id)->update(['content' => json_encode($richContent)]);

    $tag = Tag::factory()->create(['name' => 'networking']);
    $child->tags()->attach($tag);

    $version = $child->versions()->create([
        'title'        => 'Child v1',
        'content'      => DocumentFactory::tiptap('older body'),
        'content_html' => '<p>older body</p>',
        'tags'         => ['networking'],
    ]);

    $backup = Backup::create(['trigger' => 'manual', 'disk' => 'local', 'status' => 'pending']);
    app(BackupService::class)->run($backup->fresh());

    // Drift away from the backed-up state (via raw writes, so no observer churn).
    DB::table('documents')->where('id', $child->id)
        ->update(['parent_id' => null, 'content' => json_encode(['type' => 'doc', 'content' => []])]);
    $child->tags()->detach();
    DocumentVersion::whereKey($version->id)->delete();

    app(RestoreService::class)->restore($backup->fresh());

    $restored = Document::find($child->id);
    expect($restored->parent_id)->toBe($parent->id);                  // self-ref tree (two-pass wiring)
    expect($restored->content)->toEqual($richContent);                // diagram + colour mark, verbatim
    expect($restored->tags->pluck('name')->all())->toBe(['networking']); // tags via taggables

    $restoredVersion = DocumentVersion::find($version->id);           // versions round-trip
    expect($restoredVersion?->document_id)->toBe($child->id);
    expect($restoredVersion->content)->toEqual(DocumentFactory::tiptap('older body'));
});

test('restore round-trips a deep tree, ordered version history, multiple tags and a diagram with edges', function () {
    Storage::fake('local');
    login();

    $ws = Workspace::factory()->create(['name' => 'KB']);

    // Three levels deep: grandparent → parent → leaf. The two-pass parent_id
    // wiring must reconstruct the whole chain, not just one hop.
    $grand = Document::factory()->for($ws)->create(['title' => 'Grand']);
    $parent = Document::factory()->for($ws)->create(['title' => 'Parent', 'parent_id' => $grand->id]);
    $leaf = Document::factory()->for($ws)->create(['title' => 'Leaf', 'parent_id' => $parent->id]);

    // A diagram with two nodes AND an edge between them — edges are part of the
    // graph JSON and must survive verbatim.
    $richContent = [
        'type' => 'doc',
        'content' => [
            ['type' => 'networkDiagram', 'attrs' => [
                'name'  => 'Office LAN',
                'graph' => [
                    'nodes' => [
                        ['id' => 'a', 'data' => ['label' => 'Router']],
                        ['id' => 'b', 'data' => ['label' => 'Switch']],
                    ],
                    'edges' => [['id' => 'a-b', 'source' => 'a', 'target' => 'b']],
                    'viewport' => ['x' => 0, 'y' => 0, 'zoom' => 1],
                ],
            ]],
        ],
    ];
    DB::table('documents')->where('id', $leaf->id)->update(['content' => json_encode($richContent)]);

    // Two tags spanning the leaf.
    $leaf->tags()->attach([
        Tag::factory()->create(['name' => 'networking'])->id,
        Tag::factory()->create(['name' => 'infra'])->id,
    ]);

    // An ordered version history: three snapshots, oldest → newest.
    foreach (['v1', 'v2', 'v3'] as $i => $label) {
        $leaf->versions()->create([
            'title'        => "Leaf {$label}",
            'content'      => DocumentFactory::tiptap("body {$label}"),
            'content_html' => "<p>body {$label}</p>",
            'tags'         => ['networking'],
        ]);
    }

    $backup = Backup::create(['trigger' => 'manual', 'disk' => 'local', 'status' => 'pending']);
    app(BackupService::class)->run($backup->fresh());

    // Drift hard: flatten the tree, blank the content, drop tags and versions.
    DB::table('documents')->whereIn('id', [$parent->id, $leaf->id])
        ->update(['parent_id' => null, 'content' => json_encode(['type' => 'doc', 'content' => []])]);
    $leaf->tags()->detach();
    DocumentVersion::where('document_id', $leaf->id)->delete();

    app(RestoreService::class)->restore($backup->fresh());

    // The full chain comes back.
    expect(Document::find($parent->id)->parent_id)->toBe($grand->id);
    expect(Document::find($leaf->id)->parent_id)->toBe($parent->id);

    $restored = Document::find($leaf->id);
    expect($restored->content)->toEqual($richContent);                 // nodes + edge verbatim
    expect($restored->tags->pluck('name')->sort()->values()->all())->toBe(['infra', 'networking']);

    // The three explicit snapshots come back in creation order (the page's
    // auto-created initial version is incidental, so scope to ours).
    $titles = DocumentVersion::where('document_id', $leaf->id)
        ->where('title', 'like', 'Leaf v%')
        ->orderBy('id')->pluck('title')->all();
    expect($titles)->toBe(['Leaf v1', 'Leaf v2', 'Leaf v3']);
});

test('restore round-trips page attachments and their binaries', function () {
    Storage::fake('local');
    login();

    $document = Document::factory()->create();
    Storage::disk('local')->put('attachments/keep.pdf', 'ORIGINAL BYTES');
    $attachment = Attachment::factory()->for($document)->create([
        'path'          => 'attachments/keep.pdf',
        'original_name' => 'Policy.pdf',
    ]);

    $backup = Backup::create(['trigger' => 'manual', 'disk' => 'local', 'status' => 'pending']);
    app(BackupService::class)->run($backup->fresh());

    // Drift away from the backed-up state: remove the row and the binary.
    DB::table('attachments')->delete();
    Storage::disk('local')->delete('attachments/keep.pdf');

    app(RestoreService::class)->restore($backup->fresh());

    $restored = Attachment::find($attachment->id);
    expect($restored?->original_name)->toBe('Policy.pdf')
        ->and($restored->document_id)->toBe($document->id);
    expect(Storage::disk('local')->get('attachments/keep.pdf'))->toBe('ORIGINAL BYTES');
});

test('the archive carries the attachments layer with checksummed binaries', function () {
    Storage::fake('local');
    login();

    $document = Document::factory()->create();
    Storage::disk('local')->put('attachments/known.pdf', 'PDF BYTES');
    Attachment::factory()->for($document)->create(['path' => 'attachments/known.pdf', 'original_name' => 'Known.pdf']);

    $backup = Backup::create(['trigger' => 'manual', 'disk' => 'local', 'status' => 'pending']);
    app(BackupService::class)->run($backup->fresh());
    $backup->refresh();

    expect(archiveEntries($backup))
        ->toContain('canonical/attachments.json')
        ->toContain('attachment-files/known.pdf');

    expect($backup->manifest['counts']['attachments'])->toBe(1);
    // The binary is in the integrity set, so restore would reject a tampered copy.
    expect($backup->manifest['files'])->toHaveKey('attachment-files/known.pdf');
});

test('backup and restore preserve attachments on a trashed page', function () {
    Storage::fake('local');
    login();

    // An attachment on a soft-deleted (trashed) page must survive a point-in-time
    // backup — the backup captures Trash, so the restore is a faithful copy.
    $document = Document::factory()->create();
    Storage::disk('local')->put('attachments/trashed.pdf', 'BYTES');
    $attachment = Attachment::factory()->for($document)->create([
        'path' => 'attachments/trashed.pdf', 'original_name' => 'Trashed.pdf',
    ]);
    $document->delete();

    $backup = Backup::create(['trigger' => 'manual', 'disk' => 'local', 'status' => 'pending']);
    app(BackupService::class)->run($backup->fresh());

    DB::table('attachments')->delete();
    Storage::disk('local')->delete('attachments/trashed.pdf');

    app(RestoreService::class)->restore($backup->fresh());

    expect(Attachment::find($attachment->id)?->original_name)->toBe('Trashed.pdf');
    expect(Document::withTrashed()->find($document->id)->trashed())->toBeTrue();
    expect(Storage::disk('local')->get('attachments/trashed.pdf'))->toBe('BYTES');
});

test('the archive cipher round-trips and rejects wrong keys, tampering and truncation', function () {
    $cipher = new \App\Services\Backup\ArchiveCipher(
        sodium_crypto_secretstream_xchacha20poly1305_keygen(),
    );

    $plain = tempnam(sys_get_temp_dir(), 'plain');
    file_put_contents($plain, random_bytes(200_000)); // spans multiple 64 KiB chunks
    $enc = "{$plain}.enc";
    $out = "{$plain}.out";

    $cipher->encryptFile($plain, $enc);
    expect(\App\Services\Backup\ArchiveCipher::isEncrypted($enc))->toBeTrue();
    expect(\App\Services\Backup\ArchiveCipher::isEncrypted($plain))->toBeFalse();

    $cipher->decryptFile($enc, $out);
    expect(hash_file('sha256', $out))->toBe(hash_file('sha256', $plain));

    // Wrong key.
    $wrong = new \App\Services\Backup\ArchiveCipher(sodium_crypto_secretstream_xchacha20poly1305_keygen());
    expect(fn () => $wrong->decryptFile($enc, $out))->toThrow(RuntimeException::class);

    // Truncation (drop the last 40 bytes → no FINAL tag / broken chunk).
    $cut = substr(file_get_contents($enc), 0, -40);
    file_put_contents($enc, $cut);
    expect(fn () => $cipher->decryptFile($enc, $out))->toThrow(RuntimeException::class);

    @unlink($plain);
    @unlink($enc);
    @unlink($out);
});

test('backup:decrypt recovers an encrypted archive with only the key', function () {
    $key    = \App\Services\Backup\ArchiveCipher::generateKey();
    $cipher = new \App\Services\Backup\ArchiveCipher(base64_decode($key));

    $src = tempnam(sys_get_temp_dir(), 'zip');
    file_put_contents($src, 'PK pretend-zip payload');
    $enc = "{$src}.enc";
    $out = "{$src}.recovered";
    $cipher->encryptFile($src, $enc);

    // No DB, no app boot beyond the command — just the key.
    $this->artisan('backup:decrypt', ['source' => $enc, '--key' => $key, '--out' => $out])
        ->assertSuccessful();

    expect(file_get_contents($out))->toBe('PK pretend-zip payload');

    @unlink($src);
    @unlink($enc);
    @unlink($out);
});

test('encryption cannot be enabled without a configured key', function () {
    config(['backup.encryption_key' => null]);
    login();

    $this->patch('/admin/backups/settings', settingsPayload(['encryption' => true]))
        ->assertSessionHasErrors('encryption');
});

test('an encrypted backup is unreadable as a zip yet restores losslessly', function () {
    Storage::fake('local');
    config(['backup.encryption_key' => \App\Services\Backup\ArchiveCipher::generateKey()]);
    login();

    Setting::put('backup', array_replace(\App\Support\BackupSettings::get(), ['encryption' => true]));

    $ws  = Workspace::factory()->create(['name' => 'Secret']);
    $doc = Document::factory()->for($ws)->create([
        'title'   => 'Classified',
        'content' => DocumentFactory::tiptap('top secret body'),
    ]);

    $backup = Backup::create(['trigger' => 'manual', 'disk' => 'local', 'status' => 'pending']);
    app(BackupService::class)->run($backup->fresh());
    $backup->refresh();

    expect($backup->status)->toBe('done');
    expect($backup->path)->toEndWith('.zip.enc');
    expect($backup->manifest['encryption']['enabled'])->toBeTrue();

    // The stored archive is ciphertext: not openable as a zip, carries our magic.
    $stored = Storage::disk('local')->path($backup->path);
    expect(\App\Services\Backup\ArchiveCipher::isEncrypted($stored))->toBeTrue();
    expect((new ZipArchive())->open($stored))->not->toBe(true);

    // Restore decrypts transparently and reverts the drift.
    $doc->update(['title' => 'Edited']);
    app(RestoreService::class)->restore($backup->fresh());
    expect(Document::find($doc->id)->title)->toBe('Classified');
});

test('the scheduled command does nothing while backups are disabled', function () {
    Setting::put('backup', ['enabled' => false, 'interval' => 'daily', 'disk' => 'local', 'retention' => 7]);

    $this->artisan('backup:run')->expectsOutputToContain('disabled')->assertSuccessful();

    expect(Backup::count())->toBe(0);
});

test('the scheduled command dispatches a backup when enabled and due', function () {
    Storage::fake('local');
    Setting::put('backup', ['enabled' => true, 'interval' => 'daily', 'disk' => 'local', 'retention' => 7]);

    $this->artisan('backup:run')->assertSuccessful();

    $backup = Backup::latest('id')->first();
    expect($backup?->trigger)->toBe('scheduled');
    expect($backup?->status)->toBe('done'); // ran synchronously
});

test('restore takes a canonical-only safety snapshot of the current state first', function () {
    Storage::fake('local');
    login();

    $ws = Workspace::factory()->create();
    Document::factory()->for($ws)->create(['content' => DocumentFactory::tiptap('the live state')]);

    $backup = Backup::create(['trigger' => 'manual', 'disk' => 'local', 'status' => 'pending']);
    app(BackupService::class)->run($backup->fresh());

    Document::query()->delete(); // drift away from the backed-up state
    app(RestoreService::class)->restore($backup->fresh());

    $snapshot = Backup::where('trigger', 'pre-restore')->latest('id')->first();
    expect($snapshot)->not->toBeNull();
    expect($snapshot->status)->toBe('done');
    expect(Storage::disk('local')->exists($snapshot->path))->toBeTrue();

    // Canonical-only: the archive captures the content but skips the readable PDFs.
    $entries = archiveEntries($snapshot);
    expect(collect($entries)->contains(fn ($e) => str_starts_with($e, 'canonical/')))->toBeTrue();
    expect(collect($entries)->contains(fn ($e) => str_starts_with($e, 'readable/')))->toBeFalse();
});

test('restore nulls authorship for a user deleted since the backup', function () {
    Storage::fake('local');
    login();

    // A page authored by someone who'll be gone by restore time. Set the FK
    // directly so the observer doesn't reattribute it to the logged-in admin.
    $author = User::factory()->create();
    $ws  = Workspace::factory()->create();
    $doc = Document::factory()->for($ws)->create([
        'content' => DocumentFactory::tiptap('written by a doomed author'),
    ]);
    DB::table('documents')->where('id', $doc->id)
        ->update(['created_by_id' => $author->id, 'updated_by_id' => $author->id]);

    $backup = Backup::create(['trigger' => 'manual', 'disk' => 'local', 'status' => 'pending']);
    app(BackupService::class)->run($backup->fresh());

    // The author is hard-deleted — the archive still carries their id.
    $author->forceDelete();

    // Without the FK scrub this insert would violate created_by_id → users.
    app(RestoreService::class)->restore($backup->fresh());

    $restored = Document::find($doc->id);
    expect($restored)->not->toBeNull();
    expect($restored->created_by_id)->toBeNull();
    expect($restored->updated_by_id)->toBeNull();
    expect($restored->title)->toBe($doc->title); // the rest still round-trips
});

test('restore refuses a tampered archive and leaves the live data intact', function () {
    Storage::fake('local');
    login();

    $ws  = Workspace::factory()->create(['name' => 'Live Name']);
    $doc = Document::factory()->for($ws)->create(['content' => DocumentFactory::tiptap('live')]);

    $backup = Backup::create(['trigger' => 'manual', 'disk' => 'local', 'status' => 'pending']);
    app(BackupService::class)->run($backup->fresh());
    $backup->refresh();

    // Corrupt a canonical file so its bytes no longer match the manifest sha256.
    $path = Storage::disk($backup->disk)->path($backup->path);
    $zip = new ZipArchive();
    $zip->open($path);
    $orig = $zip->getFromName('canonical/workspaces.json');
    $zip->deleteName('canonical/workspaces.json');
    $zip->addFromString('canonical/workspaces.json', $orig . ' '); // one extra byte
    $zip->close();

    expect(fn () => app(RestoreService::class)->restore($backup->fresh()))
        ->toThrow(RuntimeException::class, 'Integrity check failed');

    // verify() runs before the wipe AND the safety snapshot, so nothing changed.
    expect(Workspace::find($ws->id)?->name)->toBe('Live Name');
    expect(Document::find($doc->id))->not->toBeNull();
    expect(Backup::where('trigger', 'pre-restore')->count())->toBe(0);
});

test('restoring a backup tracks its status through to restored', function () {
    Storage::fake('local');
    login();

    Document::factory()->create(['content' => DocumentFactory::tiptap('hi')]);
    $backup = Backup::create(['trigger' => 'manual', 'disk' => 'local', 'status' => 'pending']);
    app(BackupService::class)->run($backup->fresh());

    // QUEUE_CONNECTION=sync, so the restore job runs during the request.
    $this->post("/admin/backups/{$backup->id}/restore")->assertRedirect();

    $backup->refresh();
    expect($backup->restore_status)->toBe('restored');
    expect($backup->restored_at)->not->toBeNull();
    expect($backup->restore_error)->toBeNull();
});

// ── In-app backup notices ───────────────────────────────────────────────────

/** Mail config block with notifications enabled (password stored encrypted). */
function mailOnSettings(): array
{
    return array_replace(\App\Support\BackupSettings::get(), [
        'mail' => [
            'enabled' => true, 'to' => 'admin@company.com', 'host' => 'smtp.test', 'port' => 587,
            'encryption' => 'tls', 'username' => 'u', 'password' => \App\Support\BackupSettings::encrypt('p'),
            'from_address' => 'backups@company.com', 'from_name' => 'Backups',
        ],
    ]);
}

test('a scheduled backup with email off leaves an unacknowledged notice for the admin', function () {
    login();

    // Unattended run, mail off → no email could inform the admin, so a banner does.
    $backup = Backup::create([
        'trigger' => 'scheduled', 'disk' => 'local', 'status' => 'done',
        'finished_at' => now(), 'report_emailed' => false,
    ]);

    $this->get('/admin/backups')->assertInertia(fn (Assert $page) => $page
        ->has('backupNotices', 1)
        ->where('backupNotices.0.id', $backup->id)
        ->where('backupNotices.0.status', 'done'));
});

test('a manual backup leaves no banner notice (the admin is watching)', function () {
    Storage::fake('local');
    login();

    Document::factory()->create(['content' => DocumentFactory::tiptap('hi')]);
    $this->post('/admin/backups')->assertRedirect();

    $backup = Backup::latest('id')->first();
    expect($backup->trigger)->toBe('manual');
    expect($backup->status)->toBe('done');
    expect($backup->report_emailed)->toBeFalse();

    // Even though the result wasn't emailed, a manual run gets a toast, not a
    // persistent banner — so it's absent from the admin's notices.
    $this->get('/admin/backups')->assertInertia(fn (Assert $page) => $page->has('backupNotices', 0));
});

test('a backup whose report email is sent leaves no notice', function () {
    Storage::fake('local');
    Mail::fake();
    login();

    Setting::put('backup', mailOnSettings());
    Document::factory()->create(['content' => DocumentFactory::tiptap('hi')]);
    $this->post('/admin/backups')->assertRedirect();

    expect(Backup::latest('id')->first()->report_emailed)->toBeTrue();

    $this->get('/admin/backups')->assertInertia(fn (Assert $page) => $page->has('backupNotices', 0));
});

test('a failed report email is recorded as a notice with a friendly reason', function () {
    login();
    Setting::put('backup', mailOnSettings());

    // The runtime mailer can't be reached — notify must record why, not throw.
    Mail::shouldReceive('mailer')->andThrow(
        new RuntimeException('Connection could not be established: getaddrinfo failed: Name or service not known'),
    );

    $backup = Backup::create(['trigger' => 'manual', 'disk' => 'local', 'status' => 'done', 'finished_at' => now()]);
    app(\App\Services\Backup\BackupNotifier::class)->notify($backup);
    $backup->refresh();

    expect($backup->report_emailed)->toBeFalse();
    // Classified diagnosis: names the failure AND keeps the raw transport text.
    expect($backup->report_error)->toContain('Could not resolve SMTP host')
        ->and($backup->report_error)->toContain('(raw: Connection could not be established');
});

test('acknowledging a backup clears its notice', function () {
    login();

    $backup = Backup::create([
        'trigger' => 'scheduled', 'disk' => 'local', 'status' => 'done',
        'finished_at' => now(), 'report_emailed' => false,
    ]);

    $this->post("/admin/backups/{$backup->id}/acknowledge")->assertRedirect();

    expect($backup->refresh()->acknowledged_at)->not->toBeNull();
    $this->get('/admin/backups')->assertInertia(fn (Assert $page) => $page->has('backupNotices', 0));
});

test('non-admins cannot acknowledge a backup notice', function () {
    $backup = Backup::create(['trigger' => 'manual', 'disk' => 'local', 'status' => 'done', 'report_emailed' => false]);

    login(role: 'editor');
    $this->post("/admin/backups/{$backup->id}/acknowledge")->assertForbidden();

    expect($backup->refresh()->acknowledged_at)->toBeNull();
});

test('backup notices are shared only with admins', function () {
    Backup::create([
        'trigger' => 'scheduled', 'disk' => 'local', 'status' => 'failed',
        'error' => 'boom', 'report_emailed' => false,
    ]);

    // An editor browsing a normal page sees no notices.
    login(role: 'editor');
    $this->get('/workspaces')->assertInertia(fn (Assert $page) => $page->where('backupNotices', []));

    // The admin does — with the failure reason.
    login();
    $this->get('/workspaces')->assertInertia(fn (Assert $page) => $page
        ->has('backupNotices', 1)
        ->where('backupNotices.0.status', 'failed')
        ->where('backupNotices.0.error', 'boom'));
});

test('a successful or emailed backup is excluded from notices', function () {
    Backup::create(['trigger' => 'scheduled', 'disk' => 'local', 'status' => 'done', 'report_emailed' => true]);  // emailed
    Backup::create(['trigger' => 'scheduled', 'disk' => 'local', 'status' => 'pending', 'report_emailed' => false]); // not finished
    $acked = Backup::create(['trigger' => 'scheduled', 'disk' => 'local', 'status' => 'done', 'report_emailed' => false, 'acknowledged_at' => now()]);

    login();
    $this->get('/admin/backups')->assertInertia(fn (Assert $page) => $page->has('backupNotices', 0));
});

test('restoring a missing backup flashes an error and marks it missing', function () {
    Storage::fake('local');
    login();

    $backup = Backup::create(['trigger' => 'manual', 'disk' => 'local', 'status' => 'done', 'path' => 'backups/missing.zip']);

    $this->post("/admin/backups/{$backup->id}/restore")
        ->assertRedirect()
        ->assertSessionHas('error');

    $backup->refresh();
    expect($backup->status)->toBe('missing');
});

test('downloading a missing backup flashes an error and marks it missing', function () {
    Storage::fake('local');
    login();

    $backup = Backup::create(['trigger' => 'manual', 'disk' => 'local', 'status' => 'done', 'path' => 'backups/missing.zip']);

    $this->get("/admin/backups/{$backup->id}/download")
        ->assertRedirect()
        ->assertSessionHas('error');

    $backup->refresh();
    expect($backup->status)->toBe('missing');
});

test('a backup with a changed encryption key cannot be restored via the UI', function () {
    Storage::fake('local');
    login();

    // 1. Generate key A, run encrypted backup.
    config(['backup.encryption_key' => \App\Services\Backup\ArchiveCipher::generateKey()]);
    Setting::put('backup', array_replace(\App\Support\BackupSettings::get(), ['encryption' => true]));

    $backup = Backup::create(['trigger' => 'manual', 'disk' => 'local', 'status' => 'pending']);
    app(BackupService::class)->run($backup->fresh());
    $backup->refresh();

    expect($backup->manifest['encryption']['fingerprint'])->not->toBeNull();

    // 2. Change the key to key B
    config(['backup.encryption_key' => \App\Services\Backup\ArchiveCipher::generateKey()]);

    // 3. The UI should pass `key_mismatch` as true
    $this->get('/admin/backups')
        ->assertInertia(fn (\Inertia\Testing\AssertableInertia $page) => $page
            ->has('backups', 1)
            ->where('backups.0.key_mismatch', true)
        );

    // 4. Attempting to restore it via the controller should fail with a redirect error
    $this->post("/admin/backups/{$backup->id}/restore")
        ->assertSessionHas('error', 'The BACKUP_ENCRYPTION_KEY currently configured does not match the key used to encrypt this archive. You cannot restore it.');
});

test('testing the destination records a passing status the page exposes', function () {
    login();

    $this->post('/admin/backups/test-destination', ['driver' => 'local'])
        ->assertRedirect()->assertSessionHas('success');

    expect(\App\Support\TestStatus::get('backup_destination')['ok'])->toBeTrue();

    // The Backups page surfaces it for the passive status badge.
    $this->get('/admin/backups')->assertInertia(
        fn (Assert $page) => $page->where('destinationTest.ok', true),
    );
});

test('saving backup settings clears prior connection test statuses', function () {
    login();
    \App\Support\TestStatus::record('backup_destination', true);
    \App\Support\TestStatus::record('backup_email', true);

    $this->patch('/admin/backups/settings', settingsPayload())
        ->assertRedirect()->assertSessionHasNoErrors();

    expect(\App\Support\TestStatus::get('backup_destination'))->toBeNull()
        ->and(\App\Support\TestStatus::get('backup_email'))->toBeNull();
});
