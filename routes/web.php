<?php

use App\Http\Controllers\ActionController;
use App\Http\Controllers\ActionRunController;
use App\Http\Controllers\AiController;
use App\Http\Controllers\AiSkillController;
use App\Http\Controllers\AttachmentController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DepartmentController;
use App\Http\Controllers\EmailController;
use App\Http\Controllers\EmailSettingsController;
use App\Http\Controllers\EmailTemplateController;
use App\Http\Controllers\FileController;
use App\Http\Controllers\FileSettingsController;
use App\Http\Controllers\LoginController;
use App\Http\Controllers\PersonController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\SharedActionController;
use App\Http\Controllers\TagController;
use App\Http\Controllers\TicketController;
use App\Http\Middleware\EnsureCurrentUser;
use App\Support\Attachables;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::get('/login', [LoginController::class, 'create'])->name('login');
    Route::post('/login', [LoginController::class, 'store']);
});

Route::middleware('auth')->group(function () {
    Route::post('/logout', [LoginController::class, 'destroy'])->name('logout');

    Route::get('/', [DashboardController::class, 'index'])->name('dashboard');

    Route::post('/favorites/{module}', [DashboardController::class, 'toggleFavorite'])->name('favorites.toggle');
    // Shared actions (actions/*.yml in the project): view and edit the YAML, run.
    Route::get('/actions/shared/{slug}', [SharedActionController::class, 'edit'])->middleware('can:shared_actions.view')->name('actions.shared.show');
    Route::put('/actions/shared/{slug}', [SharedActionController::class, 'update'])->middleware('can:shared_actions.edit')->name('actions.shared.update');
    Route::prefix('actions/shared/{slug}')->name('actions.shared.')->middleware('can:shared_actions.run')->controller(ActionRunController::class)->group(function () {
        Route::get('/run', 'createShared')->name('run.form');
        Route::post('/run', 'storeShared')->name('run');
    });
    Route::post('/mentions/{ticket}/resolve', [DashboardController::class, 'resolveMention'])->whereNumber('ticket')->name('mentions.resolve');
    Route::post('/mentions/{ticket}/dismiss', [DashboardController::class, 'dismissMention'])->whereNumber('ticket')->name('mentions.dismiss');
    Route::post('/mentions/restore', [DashboardController::class, 'restoreMentions'])->name('mentions.restore');

    Route::post('/settings/reset-home', [DashboardController::class, 'resetHome'])->name('settings.reset-home');

    Route::prefix('ticket')->name('tickets.')->controller(TicketController::class)->group(function () {
        Route::get('/', 'index')->middleware('can:tickets.view')->name('index');
        Route::get('/create', 'create')->middleware('can:tickets.create')->name('create');
        Route::post('/', 'store')->middleware('can:tickets.create')->name('store');
        Route::get('/{ticket}', 'show')->whereNumber('ticket')->middleware('can:tickets.view')->name('show');
        Route::patch('/{ticket}', 'update')->whereNumber('ticket')->middleware('can:tickets.view')->name('update');
        Route::post('/{ticket}/comments', 'comment')->whereNumber('ticket')->middleware('can:tickets.reply')->name('comment');
    });

    // Email: compose, templates and default addresses.
    Route::prefix('email')->group(function () {
        Route::get('/', [EmailController::class, 'create'])->middleware('can:emails.view')->name('emails.create');
        Route::post('/', [EmailController::class, 'store'])->middleware('can:emails.send')->name('emails.store');
        Route::get('/files', [EmailController::class, 'files'])->middleware('can:emails.send')->name('emails.files');

        Route::get('/templates', [EmailTemplateController::class, 'index'])->middleware('can:email_templates.view')->name('email-templates.index');
        Route::get('/templates/create', [EmailTemplateController::class, 'create'])->middleware('can:email_templates.create')->name('email-templates.create');
        Route::post('/templates', [EmailTemplateController::class, 'store'])->middleware('can:email_templates.create')->name('email-templates.store');
        Route::get('/templates/{emailTemplate}/edit', [EmailTemplateController::class, 'edit'])->middleware('can:email_templates.edit')->name('email-templates.edit');
        Route::put('/templates/{emailTemplate}', [EmailTemplateController::class, 'update'])->middleware('can:email_templates.edit')->name('email-templates.update');
        Route::delete('/templates/{emailTemplate}', [EmailTemplateController::class, 'destroy'])->middleware('can:email_templates.delete')->name('email-templates.destroy');
        Route::post('/templates/{emailTemplate}/test', [EmailTemplateController::class, 'test'])->middleware('can:email_templates.view')->name('email-templates.test');

        Route::get('/settings', [EmailSettingsController::class, 'edit'])->middleware('can:email_settings.view')->name('email-settings.edit');
        Route::put('/settings', [EmailSettingsController::class, 'update'])->middleware('can:email_settings.edit')->name('email-settings.update');
    });

    // AI: chat with your own local AI, saved per person; tokens for your AI to use Fort Vale (MCP).
    Route::prefix('ai')->name('ai.')->controller(AiController::class)->group(function () {
        Route::middleware('can:ai.chat')->group(function () {
            Route::get('/', 'index')->name('index');
            Route::put('/settings', 'saveSettings')->name('settings');
            Route::post('/conversations', 'storeConversation')->name('conversations.store');
            Route::post('/conversations/{conversation}/messages', 'storeMessages')->name('messages.store');
            Route::patch('/conversations/{conversation}', 'renameConversation')->name('conversations.rename');
            Route::delete('/conversations/{conversation}', 'destroyConversation')->name('conversations.destroy');
        });
        Route::middleware('can:ai.chat')->controller(AiSkillController::class)->group(function () {
            Route::get('/skills', 'index')->name('skills');
            Route::put('/skills/{name}/switch', 'toggle')->where('name', '[A-Za-z0-9_.-]+')->name('skills.toggle');
            Route::post('/skills/{name}/run', 'run')->where('name', '[A-Za-z0-9_.-]+')->middleware('throttle:ai-chat-skills')->name('skills.run');
            Route::post('/recipes/{name}', 'recipe')->where('name', '[A-Za-z0-9_.-]+')->name('recipes.show');
        });
        Route::middleware('can:ai.connect')->group(function () {
            Route::post('/tokens', 'storeToken')->name('tokens.store');
            Route::delete('/tokens/{token}', 'destroyToken')->name('tokens.destroy');
        });
    });

    // Files: the library, each person's own files, versions, trash. Folder
    // rules are checked in FileController (they differ per folder).
    Route::get('/workspace-files', [FileController::class, 'workspace'])->middleware('can:workspace')->name('workspace.index');
    Route::get('/workspace-files/trash', [FileController::class, 'workspaceTrash'])->middleware('can:workspace')->name('workspace.trash');
    Route::get('/workspace-files/{folder}', [FileController::class, 'workspaceFolder'])->whereNumber('folder')->name('workspace.folder');
    // The page used to live at /workspace; keep old links and bookmarks working.
    Route::permanentRedirect('/workspace', '/workspace-files');
    Route::get('/workspace/{rest}', fn (Request $request, string $rest) => redirect('/workspace-files/'.$rest.($request->getQueryString() ? '?'.$request->getQueryString() : ''), 301))
        ->where('rest', 'trash|[0-9]+');
    Route::get('/my-files', [FileController::class, 'mine'])->middleware('can:my_files.view')->name('files.mine');
    Route::prefix('files')->controller(FileController::class)->group(function () {
        Route::get('/', 'library')->middleware('can:files.view')->name('files.library');
        Route::get('/search', 'search')->name('files.search');
        Route::get('/trash', 'trash')->name('files.trash');
        Route::post('/trash/empty', 'emptyTrash')->name('files.trash.empty');
        Route::post('/trash/{type}/{id}/restore', 'restoreFromTrash')->whereIn('type', ['file', 'folder'])->whereNumber('id')->name('files.trash.restore');

        Route::get('/folders/{folder}', 'show')->name('files.folder');
        Route::post('/folders/{folder}/upload', 'upload')->name('files.upload');
        Route::post('/folders/{folder}/folders', 'createFolder')->name('files.folders.store');
        Route::patch('/folders/{folder}', 'renameFolder')->name('files.folders.rename');
        Route::delete('/folders/{folder}', 'destroyFolder')->name('files.folders.destroy');
        Route::get('/folders/{folder}/access', 'editAccess')->name('files.access.edit');
        Route::put('/folders/{folder}/access', 'updateAccess')->name('files.access.update');

        Route::get('/{file}/download', 'download')->whereNumber('file')->name('files.download');
        Route::get('/{file}/view', 'raw')->whereNumber('file')->name('files.raw');
        Route::get('/{file}/preview', 'preview')->whereNumber('file')->name('files.preview');
        Route::get('/{file}/history', 'history')->whereNumber('file')->name('files.history');
        Route::get('/{file}/versions/{version}/download', 'downloadVersion')->whereNumber(['file', 'version'])->name('files.versions.download');
        Route::post('/{file}/versions/{version}/restore', 'restoreVersion')->whereNumber(['file', 'version'])->name('files.versions.restore');
        Route::patch('/{file}', 'rename')->whereNumber('file')->name('files.rename');
        Route::patch('/{file}/move', 'move')->whereNumber('file')->name('files.move');
        Route::delete('/{file}', 'destroy')->whereNumber('file')->name('files.destroy');
    });
    Route::post('/attachments/{type}/{id}', [AttachmentController::class, 'store'])
        ->whereIn('type', array_keys(Attachables::TYPES))->where('id', '[a-z0-9_-]{1,100}')->name('attachments.store');
    Route::get('/files/settings', [FileSettingsController::class, 'edit'])->middleware('can:file_settings.view')->name('file-settings.edit');
    Route::put('/files/settings', [FileSettingsController::class, 'update'])->middleware('can:file_settings.edit')->name('file-settings.update');

    // Tags page: tags, departments and people, for those allowed to manage them.
    Route::prefix('tags')->group(function () {
        Route::get('/departments/create', [DepartmentController::class, 'create'])->middleware('can:departments.create')->name('departments.create');
        Route::post('/departments', [DepartmentController::class, 'store'])->middleware('can:departments.create')->name('departments.store');
        Route::get('/departments/{department}/edit', [DepartmentController::class, 'edit'])->middleware('can:departments.edit')->name('departments.edit');
        Route::put('/departments/{department}', [DepartmentController::class, 'update'])->middleware('can:departments.edit')->name('departments.update');
        Route::delete('/departments/{department}', [DepartmentController::class, 'destroy'])->middleware('can:departments.delete')->name('departments.destroy');

        Route::get('/people/create', [PersonController::class, 'create'])->middleware('can:people.create')->name('people.create');
        Route::post('/people', [PersonController::class, 'store'])->middleware('can:people.create')->name('people.store');
        Route::get('/people/{person}/edit', [PersonController::class, 'edit'])->middleware('can:people.edit')->name('people.edit');
        Route::put('/people/{person}', [PersonController::class, 'update'])->middleware('can:people.edit')->name('people.update');

        Route::get('/', [TagController::class, 'index'])->middleware('can:tags-page')->name('tags.index');
        Route::get('/create', [TagController::class, 'create'])->middleware('can:tags.create')->name('tags.create');
        Route::post('/', [TagController::class, 'store'])->middleware('can:tags.create')->name('tags.store');
        Route::get('/{tag}/edit', [TagController::class, 'edit'])->whereNumber('tag')->middleware('can:tags.edit')->name('tags.edit');
        Route::put('/{tag}', [TagController::class, 'update'])->whereNumber('tag')->middleware('can:tags.edit')->name('tags.update');
        Route::delete('/{tag}', [TagController::class, 'destroy'])->whereNumber('tag')->middleware('can:tags.delete')->name('tags.destroy');
    });

    // Personal pages: /{username}/settings and /{username}/actions.
    Route::prefix('{user:username}')
        ->middleware(EnsureCurrentUser::class)
        ->scopeBindings()
        ->group(function () {
            Route::get('/settings', [SettingsController::class, 'edit'])->name('settings.edit');
            Route::put('/settings', [SettingsController::class, 'update'])->name('settings.update');
            Route::put('/settings/password', [SettingsController::class, 'updatePassword'])->name('settings.password');

            Route::get('/actions', [ActionController::class, 'index'])->middleware('can:actions-page')->name('actions.index');
            Route::get('/actions/create', [ActionController::class, 'create'])->middleware('can:actions.create')->name('actions.create');
            Route::post('/actions', [ActionController::class, 'store'])->middleware('can:actions.create')->name('actions.store');
            Route::get('/actions/{action}/edit', [ActionController::class, 'edit'])->middleware('can:actions.edit')->name('actions.edit');
            Route::put('/actions/{action}', [ActionController::class, 'update'])->middleware('can:actions.edit')->name('actions.update');
            Route::delete('/actions/{action}', [ActionController::class, 'destroy'])->middleware('can:actions.delete')->name('actions.destroy');
            Route::get('/actions/{action}/run', [ActionRunController::class, 'create'])->middleware('can:actions.run')->name('actions.custom.run.form');
            Route::post('/actions/{action}/run', [ActionRunController::class, 'store'])->middleware('can:actions.run')->name('actions.custom.run');
        });
});
