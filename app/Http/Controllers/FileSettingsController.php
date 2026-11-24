<?php

namespace App\Http\Controllers;

use App\Models\AppSetting;
use App\Services\Files\FileStore;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/**
 * Upload limits: maximum size, blocked types and storage quotas.
 */
class FileSettingsController extends Controller
{
    public function edit(FileStore $store): View
    {
        return view('files.settings', [
            'maxMb' => FileStore::setting('files.max_mb'),
            'blocked' => implode(', ', (array) FileStore::setting('files.blocked_extensions')),
            'personalQuotaMb' => FileStore::setting('files.personal_quota_mb'),
            'totalQuotaMb' => FileStore::setting('files.total_quota_mb'),
            'usedBytes' => $store->usage(),
            'serverMaxMb' => (int) ini_get('upload_max_filesize'),
            'canEdit' => Gate::allows('file_settings.edit'),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'max_mb' => ['required', 'integer', 'min:1', 'max:2048'],
            'blocked' => ['nullable', 'string', 'max:500'],
            'personal_quota_mb' => ['required', 'integer', 'min:0', 'max:10000000'],
            'total_quota_mb' => ['required', 'integer', 'min:0', 'max:100000000'],
        ]);

        $blocked = array_values(array_unique(array_filter(array_map(
            fn (string $extension): string => strtolower(ltrim(trim($extension), '.')),
            preg_split('/[\s,;]+/', (string) ($data['blocked'] ?? '')),
        ))));

        AppSetting::put('files.max_mb', (int) $data['max_mb']);
        AppSetting::put('files.blocked_extensions', $blocked);
        AppSetting::put('files.personal_quota_mb', (int) $data['personal_quota_mb']);
        AppSetting::put('files.total_quota_mb', (int) $data['total_quota_mb']);

        return redirect()->route('file-settings.edit')->with('status', 'File settings saved.');
    }
}
