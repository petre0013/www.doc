<?php

use App\Models\Backup;
use App\Models\Document;
use App\Models\Setting;
use App\Models\Workspace;
use App\Services\Backup\ArchiveCipher;
use App\Services\Backup\BackupService;
use App\Services\Backup\RestoreService;
use App\Support\BackupSettings;
use Database\Factories\DocumentFactory;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Build a real archive from the current content, return its bytes, then wipe the
 * source (row + file + content) so it can be re-imported as a "foreign" archive.
 */
function buildArchiveBytes(bool $canonicalOnly = true): string
{
    $src = Backup::create(['trigger' => 'manual', 'disk' => 'local', 'status' => 'pending']);
    app(BackupService::class)->run($src->fresh(), $canonicalOnly);
    $src->refresh();

    $bytes = Storage::disk('local')->get($src->path);

    Storage::disk('local')->delete($src->path);
    $src->delete();

    return $bytes;
}

test('non-admins cannot import a backup', function () {
    login(role: 'editor');

    $this->post('/admin/backups/import')->assertForbidden();
});

test('importing a plaintext archive registers a restorable backup', function () {
    Storage::fake('local');
    login();

    $ws = Workspace::factory()->create(['name' => 'Imported WS']);
    Document::factory()->for($ws)->create(['title' => 'Imported Doc', 'content' => DocumentFactory::tiptap('imported body')]);

    $bytes = buildArchiveBytes();

    // Clean slate, then import the archive as if it came from elsewhere.
    Document::query()->forceDelete();
    Workspace::query()->forceDelete();

    $file = UploadedFile::fake()->createWithContent('backup.zip', $bytes);
    $this->post('/admin/backups/import', ['file' => $file])->assertRedirect()->assertSessionHasNoErrors();

    $imported = Backup::where('trigger', 'import')->latest('id')->first();
    expect($imported)->not->toBeNull();
    expect($imported->status)->toBe('done');
    expect($imported->manifest['counts']['documents'])->toBe(1);
    expect($imported->manifest['encryption']['enabled'])->toBeFalse();

    // And it can be restored through the existing flow.
    app(RestoreService::class)->restore($imported->fresh());
    expect(Document::where('title', 'Imported Doc')->exists())->toBeTrue();
});

test('importing an encrypted archive with the correct key normalises it for restore', function () {
    Storage::fake('local');
    login();

    // Foreign archive encrypted under key A.
    $keyA = ArchiveCipher::generateKey();
    config(['backup.encryption_key' => $keyA]);
    Setting::put('backup', array_replace(BackupSettings::get(), ['encryption' => true]));

    $ws = Workspace::factory()->create();
    Document::factory()->for($ws)->create(['title' => 'Enc Doc', 'content' => DocumentFactory::tiptap('secret body')]);

    $bytes = buildArchiveBytes();
    Document::query()->forceDelete();
    Workspace::query()->forceDelete();

    // This host runs a DIFFERENT key (B) — import must re-encrypt under B so the
    // env-key-only restore path can consume it.
    $keyB = ArchiveCipher::generateKey();
    config(['backup.encryption_key' => $keyB]);

    $file = UploadedFile::fake()->createWithContent('backup.zip.enc', $bytes);
    $this->post('/admin/backups/import', ['file' => $file, 'key' => $keyA])
        ->assertRedirect()->assertSessionHasNoErrors();

    $imported = Backup::where('trigger', 'import')->latest('id')->first();
    expect($imported->status)->toBe('done');
    expect($imported->manifest['counts']['documents'])->toBe(1);
    // Re-encrypted under the host key: encrypted on disk, fingerprint matches env.
    expect($imported->manifest['encryption']['enabled'])->toBeTrue();
    expect($imported->manifest['encryption']['fingerprint'])->toBe(ArchiveCipher::currentFingerprint());
    expect(ArchiveCipher::isEncrypted(Storage::disk('local')->path($imported->path)))->toBeTrue();

    app(RestoreService::class)->restore($imported->fresh());
    expect(Document::where('title', 'Enc Doc')->exists())->toBeTrue();
});

test('an encrypted archive with the wrong key is imported but flagged undecryptable', function () {
    Storage::fake('local');
    login();

    // Foreign archive encrypted under key A.
    config(['backup.encryption_key' => ArchiveCipher::generateKey()]);
    Setting::put('backup', array_replace(BackupSettings::get(), ['encryption' => true]));
    Document::factory()->create(['content' => DocumentFactory::tiptap('secret')]);

    $bytes = buildArchiveBytes();
    Document::query()->forceDelete();

    // Host has no key configured, and the operator supplies a WRONG one.
    config(['backup.encryption_key' => null]);

    $file = UploadedFile::fake()->createWithContent('backup.zip.enc', $bytes);
    $this->post('/admin/backups/import', ['file' => $file, 'key' => ArchiveCipher::generateKey()])
        ->assertRedirect()->assertSessionHasNoErrors();

    $imported = Backup::where('trigger', 'import')->latest('id')->first();
    expect($imported->status)->toBe('done');
    expect($imported->manifest['encryption']['undecryptable'] ?? false)->toBeTrue();

    // The UI is told it can't be restored…
    $this->get('/admin/backups')->assertInertia(fn (Assert $page) => $page
        ->where('backups.0.undecryptable', true));

    // …and the controller refuses a restore attempt.
    $this->post("/admin/backups/{$imported->id}/restore")
        ->assertRedirect()->assertSessionHas('error');
    expect($imported->fresh()->restore_status)->toBeNull();
});

test('importing a non-wwwdoc zip fails cleanly without touching live data', function () {
    Storage::fake('local');
    login();

    $live = Document::factory()->create(['title' => 'Live Page']);

    // A zip with no manifest.json is not a www.doc backup.
    $zipPath = tempnam(sys_get_temp_dir(), 'z') . '.zip';
    $zip = new ZipArchive();
    $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addFromString('random.txt', 'not a backup');
    $zip->close();

    $file = UploadedFile::fake()->createWithContent('random.zip', file_get_contents($zipPath));
    @unlink($zipPath);

    $this->post('/admin/backups/import', ['file' => $file])->assertRedirect();

    $imported = Backup::where('trigger', 'import')->latest('id')->first();
    expect($imported->status)->toBe('failed');
    expect($imported->error)->toContain('manifest.json');

    // Nothing was wiped — a failed import never reaches the destructive restore.
    expect(Document::find($live->id))->not->toBeNull();
});

test('imported archives are exempt from retention pruning', function () {
    Storage::fake('local');
    login();

    Setting::put('backup', array_replace(BackupSettings::get(), ['retention' => 1]));

    // An imported archive we want to keep, plus content to back up.
    $imported = Backup::create(['trigger' => 'import', 'disk' => 'local', 'status' => 'done', 'path' => 'backups/imported.zip']);
    Storage::disk('local')->put('backups/imported.zip', 'IMPORTED');
    Document::factory()->create(['content' => DocumentFactory::tiptap('hi')]);

    // Two scheduled runs with retention 1 — normally the older would be pruned…
    foreach (range(1, 2) as $i) {
        $b = Backup::create(['trigger' => 'manual', 'disk' => 'local', 'status' => 'pending']);
        app(BackupService::class)->run($b->fresh(), true);
    }

    // …but the import survives, row and archive intact.
    expect(Backup::find($imported->id))->not->toBeNull();
    expect(Storage::disk('local')->exists('backups/imported.zip'))->toBeTrue();
});

test('ArchiveCipher::fromKey accepts a valid key and rejects malformed ones', function () {
    $key = ArchiveCipher::generateKey();
    $cipher = ArchiveCipher::fromKey($key);

    $plain = tempnam(sys_get_temp_dir(), 'p');
    file_put_contents($plain, 'round trip me');
    $enc = "{$plain}.enc";
    $out = "{$plain}.out";
    $cipher->encryptFile($plain, $enc);
    ArchiveCipher::fromKey($key)->decryptFile($enc, $out);
    expect(file_get_contents($out))->toBe('round trip me');

    // Not base64.
    expect(fn () => ArchiveCipher::fromKey('!!! not base64 !!!'))->toThrow(RuntimeException::class);
    // Valid base64 but the wrong length (16 bytes, not 32).
    expect(fn () => ArchiveCipher::fromKey(base64_encode(random_bytes(16))))->toThrow(InvalidArgumentException::class);

    @unlink($plain);
    @unlink($enc);
    @unlink($out);
});
