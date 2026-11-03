<?php

use App\Models\Backup;
use App\Services\Backup\BackupService;
use Illuminate\Support\Facades\Storage;

it('shares the app version with every Inertia page', function () {
    config(['app.version' => '1.2.0']);
    login();

    $this->get('/settings/profile')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('appVersion', '1.2.0'));
});

it('defaults the version to dev when no APP_VERSION is baked in', function () {
    expect(config('app.version'))->toBe('dev');
});

it('records the app version in the backup manifest', function () {
    Storage::fake('local');
    config(['app.version' => '1.2.0']);
    login();

    $backup = Backup::create(['trigger' => 'manual', 'disk' => 'local', 'status' => 'pending']);
    app(BackupService::class)->run($backup->fresh(), canonicalOnly: true);

    expect($backup->fresh()->manifest['app_version'])->toBe('1.2.0');
});
