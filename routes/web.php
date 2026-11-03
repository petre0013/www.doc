<?php

use App\Http\Controllers\Admin\AuditController as AdminAuditController;
use App\Http\Controllers\Admin\BackupController as AdminBackupController;
use App\Http\Controllers\Admin\MailSettingsController as AdminMailSettingsController;
use App\Http\Controllers\Admin\UpdateSettingsController as AdminUpdateSettingsController;
use App\Http\Controllers\Admin\UserController as AdminUserController;
use App\Http\Controllers\AssetController;
use App\Http\Controllers\AttachmentController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\CompareController;
use App\Http\Controllers\DocumentController;
use App\Http\Controllers\DocumentFolderController;
use App\Http\Controllers\ExportController;
use App\Http\Controllers\ImportController;
use App\Http\Controllers\PasswordResetController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\SearchController;
use App\Http\Controllers\SetupController;
use App\Http\Controllers\TagController;
use App\Http\Controllers\TemplateController;
use App\Http\Controllers\TrashController;
use App\Http\Controllers\VersionController;
use App\Http\Controllers\WorkspaceController;
use App\Http\Controllers\WorkspaceGroupController;
use Illuminate\Support\Facades\Route;

// Model-bound route params are bigint ids — constrain them to digits so a
// non-numeric URL segment 404s cleanly instead of hitting the DB with an
// invalid bigint (Postgres would otherwise throw a QueryException → 500).
Route::pattern('document', '[0-9]+');
Route::pattern('version', '[0-9]+');
Route::pattern('workspace', '[0-9]+');
Route::pattern('folder', '[0-9]+');
Route::pattern('group', '[0-9]+');
Route::pattern('tag', '[0-9]+');
Route::pattern('template', '[0-9]+');
Route::pattern('job', '[0-9]+');
Route::pattern('user', '[0-9]+');
Route::pattern('backup', '[0-9]+');
Route::pattern('attachment', '[0-9]+');

// First-run installation wizard. No auth — the operator hasn't created an
// account yet. EnsureSetupComplete funnels everything here until it's finished,
// and exempts these `setup.*` routes to avoid a redirect loop.
Route::prefix('setup')->name('setup.')->group(function () {
    Route::get('/', [SetupController::class, 'show'])->name('show');
    Route::post('admin', [SetupController::class, 'storeAdmin'])->name('admin');
    Route::post('instance', [SetupController::class, 'storeInstance'])->name('instance');
    Route::post('mail', [SetupController::class, 'storeMail'])->name('mail');
    Route::post('mail/test', [SetupController::class, 'testMail'])->name('mail.test');
    Route::post('complete', [SetupController::class, 'complete'])->name('complete');
});

Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
    Route::post('/login', [AuthController::class, 'login']);

    // Password reset (forgot → emailed link → reset). Delivered via the SMTP
    // settings from the setup wizard / admin Email tab. The POST endpoints are
    // throttled so the forgot form can't be abused to mail-bomb an address or
    // brute-force reset tokens (the broker's per-user email throttle aside).
    Route::get('/forgot-password', [PasswordResetController::class, 'showForgot'])->name('password.request');
    Route::post('/forgot-password', [PasswordResetController::class, 'sendLink'])
        ->middleware('throttle:6,1')->name('password.email');
    Route::get('/reset-password/{token}', [PasswordResetController::class, 'showReset'])->name('password.reset');
    Route::post('/reset-password', [PasswordResetController::class, 'reset'])
        ->middleware('throttle:6,1')->name('password.update');
});

Route::middleware('auth')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout'])->name('logout');

    // Settings
    Route::get('settings/profile', [ProfileController::class, 'edit'])->name('settings.profile');
    Route::patch('settings/profile', [ProfileController::class, 'update'])->name('settings.profile.update');
    Route::patch('settings/password', [ProfileController::class, 'updatePassword'])->name('settings.password.update');

    // Admin — instance administration (admins only).
    Route::middleware('admin')->prefix('admin')->name('admin.')->group(function () {
        Route::get('users', [AdminUserController::class, 'index'])->name('users.index');
        Route::post('users', [AdminUserController::class, 'store'])->name('users.store');
        Route::patch('users/{user}', [AdminUserController::class, 'update'])->name('users.update');
        Route::delete('users/{user}', [AdminUserController::class, 'destroy'])->name('users.destroy');

        // Email (SMTP) settings — the global mailer config (password resets etc.).
        Route::get('settings/mail', [AdminMailSettingsController::class, 'index'])->name('settings.mail');
        Route::patch('settings/mail', [AdminMailSettingsController::class, 'update'])->name('settings.mail.update');
        Route::post('settings/mail/test', [AdminMailSettingsController::class, 'test'])->name('settings.mail.test');

        // Outdated-version notification: the "Updates" settings tab (version,
        // opt-in check, cached release notes) plus its toggle and manual refresh.
        Route::get('settings/updates', [AdminUpdateSettingsController::class, 'index'])->name('settings.updates');
        Route::patch('settings/updates', [AdminUpdateSettingsController::class, 'update'])->name('settings.updates.update');
        Route::post('settings/updates/check', [AdminUpdateSettingsController::class, 'check'])->name('settings.updates.check');

        // Backups & restore (NIS2). 'settings'/'run' declared before {backup} so
        // they aren't read as ids (the [0-9]+ pattern already prevents that).
        Route::get('backups', [AdminBackupController::class, 'index'])->name('backups.index');
        Route::post('backups', [AdminBackupController::class, 'store'])->name('backups.store');
        Route::post('backups/import', [AdminBackupController::class, 'import'])->name('backups.import');
        Route::patch('backups/settings', [AdminBackupController::class, 'updateSettings'])->name('backups.settings');
        Route::post('backups/test-destination', [AdminBackupController::class, 'testDestination'])->name('backups.test-destination');
        Route::post('backups/test-email', [AdminBackupController::class, 'testEmail'])->name('backups.test-email');
        Route::get('backups/{backup}', [AdminBackupController::class, 'show'])->name('backups.show');
        Route::get('backups/{backup}/download', [AdminBackupController::class, 'download'])->name('backups.download');
        Route::post('backups/{backup}/restore', [AdminBackupController::class, 'restore'])->name('backups.restore');
        Route::post('backups/{backup}/acknowledge', [AdminBackupController::class, 'acknowledge'])->name('backups.acknowledge');
        Route::delete('backups/{backup}', [AdminBackupController::class, 'destroy'])->name('backups.destroy');

        // Audit trail — read-only (events are created via App\Support\Audit only).
        Route::get('audit', [AdminAuditController::class, 'index'])->name('audit.index');
    });

    // Page-to-page comparison. Declared before the resource so 'compare' isn't
    // read as a {document} id (the [0-9]+ pattern already prevents that, but
    // the ordering keeps intent obvious).
    Route::get('documents/compare', [CompareController::class, 'documents'])->name('documents.compare');

    // Document tree operations — declared before the resource so 'reorder'
    // isn't swallowed by the documents/{document} update route.
    Route::patch('documents/reorder', [DocumentController::class, 'reorder'])->name('documents.reorder');
    Route::get('documents/{document}/children', [DocumentController::class, 'children'])->name('documents.children');
    Route::patch('documents/{document}/move', [DocumentController::class, 'move'])->name('documents.move');
    // Inline title rename from the workspace tree's Edit mode (stays on the page).
    Route::patch('documents/{document}/rename', [DocumentController::class, 'rename'])->name('documents.rename');
    Route::get('documents/{document}/preview', [DocumentController::class, 'preview'])->name('documents.preview');
    // Personal star toggle — any role that can view the page (not audited).
    Route::post('documents/{document}/star', [DocumentController::class, 'star'])->name('documents.star');
    Route::post('documents/diagram-export', [DocumentController::class, 'exportDiagram'])->name('documents.diagram.export');

    Route::patch('workspaces/reorder', [WorkspaceController::class, 'reorder'])->name('workspaces.reorder');
    // Reorder the interleaved top level (groups + ungrouped workspaces share one
    // order). Before the resource so /top-level-order isn't read as a show.
    Route::patch('workspaces/top-level-order', [WorkspaceController::class, 'reorderTopLevel'])->name('workspaces.topLevel.reorder');
    // Workspace groups (BookStack-style shelves). Declared before the resource so
    // the /groups segment isn't read as a workspace show ({workspace} is numeric).
    Route::post('workspaces/groups', [WorkspaceGroupController::class, 'store'])->name('workspaces.groups.store');
    Route::patch('workspaces/groups/{group}', [WorkspaceGroupController::class, 'update'])->name('workspaces.groups.update');
    Route::delete('workspaces/groups/{group}', [WorkspaceGroupController::class, 'destroy'])->name('workspaces.groups.destroy');
    // File a workspace into/out of a group (structural — no updated_at bump).
    Route::patch('workspaces/{workspace}/group', [WorkspaceController::class, 'regroup'])->name('workspaces.regroup');
    // Page folders — containers inside a workspace that are NOT pages. Create is
    // scoped to the workspace (the id never comes from the payload); a folder's
    // own id is globally unique, so rename/delete need no workspace segment.
    Route::post('workspaces/{workspace}/folders', [DocumentFolderController::class, 'store'])->name('folders.store');
    Route::patch('folders/{folder}', [DocumentFolderController::class, 'update'])->name('folders.update');
    Route::delete('folders/{folder}', [DocumentFolderController::class, 'destroy'])->name('folders.destroy');
    // File a page into/out of a folder (structural — no updated_at/version bump).
    Route::patch('documents/{document}/folder', [DocumentController::class, 'refile'])->name('documents.refile');
    // Reorder a workspace's interleaved top level (folders + loose pages share one
    // order) plus each folder's members. Before the resource so it isn't read as a
    // workspace show. Structural — no updated_at bump, unaudited.
    Route::patch('workspaces/{workspace}/folder-order', [DocumentController::class, 'reorderTopLevel'])->name('workspaces.folderOrder');
    // Bulk save of a workspace's whole page tree — the "Reorder" mode's single
    // write on "Done". Declared before the resource so it isn't read as a show.
    Route::patch('workspaces/{workspace}/tree', [DocumentController::class, 'restructure'])->name('workspaces.tree.update');
    Route::resource('workspaces', WorkspaceController::class)->except(['create', 'edit']);
    Route::resource('documents', DocumentController::class)->only(['store', 'show', 'update', 'destroy']);
    Route::resource('tags', TagController::class)->only(['index', 'show', 'store', 'update', 'destroy']);

    // Page templates — reusable starting points. Managed via Inertia pages;
    // "save as template" snapshots an existing page's content.
    Route::resource('templates', TemplateController::class)->only(['index', 'store', 'edit', 'update', 'destroy']);
    Route::post('documents/{document}/template', [TemplateController::class, 'storeFromDocument'])->name('documents.save-as-template');

    // Asset upload + rehost (must be before any {asset} resource route)
    Route::post('assets/rehost', [AssetController::class, 'rehost'])->name('assets.rehost');
    Route::post('assets', [AssetController::class, 'store'])->name('assets.store');

    // Page attachments — files attached to a document, served as forced downloads.
    Route::post('documents/{document}/attachments', [AttachmentController::class, 'store'])->name('attachments.store');
    Route::get('documents/{document}/attachments/{attachment}', [AttachmentController::class, 'download'])->name('attachments.download');
    Route::patch('documents/{document}/attachments/{attachment}', [AttachmentController::class, 'update'])->name('attachments.update');
    Route::delete('documents/{document}/attachments/{attachment}', [AttachmentController::class, 'destroy'])->name('attachments.destroy');

    // Export pipeline
    Route::post('documents/{document}/exports', [ExportController::class, 'store'])->name('exports.store');
    Route::get('documents/{document}/exports/{job}', [ExportController::class, 'show'])->name('exports.show');

    // Version history
    Route::get('documents/{document}/versions', [VersionController::class, 'index'])->name('versions.index');
    // 'compare' before {version} so it isn't read as a version id (the digit
    // pattern already prevents that; ordering keeps intent obvious).
    Route::get('documents/{document}/versions/compare', [CompareController::class, 'versions'])->name('versions.compare');
    Route::get('documents/{document}/versions/{version}', [VersionController::class, 'show'])->name('versions.show');
    Route::post('documents/{document}/versions/{version}/restore', [VersionController::class, 'restore'])->name('versions.restore');

    // Full-text search
    Route::get('search', [SearchController::class, 'index'])->name('search');

    // Trash — soft-deleted workspaces + documents
    Route::get('trash', [TrashController::class, 'index'])->name('trash.index');
    Route::post('trash/documents/{document}/restore', [TrashController::class, 'restoreDocument'])->name('trash.documents.restore');
    Route::delete('trash/documents/{document}', [TrashController::class, 'forceDeleteDocument'])->name('trash.documents.force-delete');
    Route::post('trash/workspaces/{workspace}/restore', [TrashController::class, 'restoreWorkspace'])->name('trash.workspaces.restore');
    Route::delete('trash/workspaces/{workspace}', [TrashController::class, 'forceDeleteWorkspace'])->name('trash.workspaces.force-delete');
    Route::delete('trash', [TrashController::class, 'empty'])->name('trash.empty');

    // Import pipeline (the UI is the ImportDialog on the workspace page)
    Route::post('workspaces/{workspace}/imports', [ImportController::class, 'store'])->name('imports.store');
    Route::get('imports/{job}', [ImportController::class, 'show'])->name('imports.show');
});

// Redirect (not a closure) so the route table stays serialisable for
// `route:cache` in production.
Route::redirect('/', '/workspaces');
