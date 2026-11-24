<?php

namespace App\Http\Controllers;

use App\Models\EmailTemplate;
use App\Models\Folder;
use App\Models\SentEmail;
use App\Models\StoredFile;
use App\Models\User;
use App\Services\Email\Mailer;
use App\Services\Files\FileAccess;
use App\Services\Files\FilePaths;
use App\Services\Files\FileStore;
use App\Support\Attachables;
use App\Support\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * The compose page: write an email to colleagues or any address, optionally
 * starting from a template.
 */
class EmailController extends Controller
{
    public function create(Request $request, FileAccess $access): View
    {
        $template = $request->integer('template') ? EmailTemplate::find($request->integer('template')) : null;
        $me = CurrentUser::get();

        $folderIds = $access->visibleLibraryFolderIds($me);
        if ($me->hasPermission('my_files.view')) {
            $mine = Folder::personalRootFor($me);
            array_push($folderIds, ...app(FileStore::class)->subtreeIds($mine));
        }

        return view('emails.compose', [
            // shortcut: lists the 300 most recently changed files; add a search box if libraries grow past that.
            'pickable' => StoredFile::whereIn('folder_id', $folderIds)->whereNotNull('current_version_id')->with('folder')->latest('updated_at')->limit(300)->get(),
            'templateFiles' => $template ? Attachables::filesFor('email_template', $template->id) : collect(),
            'people' => User::orderBy('name')->get(),
            'templates' => EmailTemplate::orderBy('name')->get(),
            'template' => $template,
            'sent' => SentEmail::where('user_id', $me->id)->where('source', 'compose')->latest('id')->limit(20)->get(),
        ]);
    }

    public function store(Request $request, Mailer $mailer): RedirectResponse
    {
        $data = $request->validate([
            'people' => ['array'],
            'people.*' => ['integer', Rule::exists(User::class, 'id')],
            'addresses' => ['nullable', 'string', 'max:2000'],
            'subject' => ['required', 'string', 'max:200'],
            'body' => ['required', 'string', 'max:10000'],
            'template_id' => ['nullable', 'integer', Rule::exists(EmailTemplate::class, 'id')],
            'stored_files' => ['array', 'max:20'],
            'stored_files.*' => ['integer'],
            'uploads' => ['array', 'max:10'],
            'uploads.*' => ['file'],
        ]);

        $entries = User::whereKey($data['people'] ?? [])->pluck('username')->all();
        array_push($entries, ...preg_split('/[\s,;]+/', (string) ($data['addresses'] ?? ''), -1, PREG_SPLIT_NO_EMPTY));

        ['recipients' => $recipients, 'invalid' => $invalid] = $mailer->resolve($entries);

        if ($invalid !== []) {
            throw ValidationException::withMessages(['addresses' => 'These aren’t valid email addresses: '.implode(', ', $invalid).'.']);
        }
        if ($recipients === []) {
            throw ValidationException::withMessages(['people' => 'Choose at least one person or type an email address.']);
        }
        if (count($recipients) > Mailer::MAX_RECIPIENTS) {
            throw ValidationException::withMessages(['addresses' => 'An email can go to at most '.Mailer::MAX_RECIPIENTS.' people.']);
        }

        $me = CurrentUser::get();
        $files = $this->attachments($request, $data, $me);

        $sent = $mailer->send($recipients, $data['subject'], $data['body'], ['actor' => ['name' => $me->name, 'username' => $me->username]], 'compose', $me, null, $files);

        if ($sent < count($recipients)) {
            return back()->withInput()->with('status', "Sent to {$sent} of ".count($recipients).'. The rest couldn’t be delivered; check the mail settings in .env.');
        }

        return redirect()->route('emails.create')->with('status', 'Email sent to '.count($recipients).' '.str('recipient')->plural(count($recipients)).'.');
    }

    /**
     * Files chosen from Files (each checked against folder access), new
     * uploads (checked against the file limits, sent but not stored), and
     * the template's own files.
     *
     * @param  array<string, mixed>  $data
     * @return list<array{name: string, mime: string, size: int, path?: string, local?: string}>
     */
    private function attachments(Request $request, array $data, User $me): array
    {
        $files = [];
        $paths = app(FilePaths::class);

        foreach (StoredFile::whereKey($data['stored_files'] ?? [])->get() as $file) {
            if (! $paths->canRead($me, $file)) {
                throw ValidationException::withMessages(['stored_files' => "You can’t send “{$file->name}”."]);
            }
            $files[] = Mailer::attachmentFor($file);
        }

        foreach ($request->file('uploads', []) as $upload) {
            ['name' => $name, 'mime' => $mime, 'size' => $size] = app(FileStore::class)->assertAcceptable($upload);
            $files[] = ['name' => $name, 'mime' => $mime, 'size' => $size, 'local' => $upload->getRealPath()];
        }

        if (! empty($data['template_id'])) {
            array_push($files, ...Mailer::templateAttachments(EmailTemplate::find($data['template_id'])));
        }

        Mailer::assertAttachmentSize($files);

        return $files;
    }
}
