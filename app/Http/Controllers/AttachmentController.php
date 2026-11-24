<?php

namespace App\Http\Controllers;

use App\Services\Files\FileStore;
use App\Support\Attachables;
use App\Support\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Adding files to a page's attachments box. Downloading, previewing and
 * removing them use the file routes, which check the page's rules.
 */
class AttachmentController extends Controller
{
    public function store(Request $request, string $type, string $id, FileStore $store): RedirectResponse
    {
        $me = CurrentUser::get();
        abort_unless(Attachables::canView($me, $type, $id), 404);
        abort_unless(Attachables::canAttach($me, $type, $id), 403);

        $request->validate(['files' => ['required', 'array', 'max:20'], 'files.*' => ['required', 'file']], [
            'files.*.uploaded' => 'A file couldn’t be uploaded; it may be larger than the server allows.',
        ]);

        $uploads = $request->file('files');
        foreach ($uploads as $upload) {
            $store->assertAcceptable($upload);
        }
        foreach ($uploads as $upload) {
            $store->attach($me, $type, $id, $upload);
        }

        return back()->with('status', count($uploads).' '.str('file')->plural(count($uploads)).' attached.');
    }
}
