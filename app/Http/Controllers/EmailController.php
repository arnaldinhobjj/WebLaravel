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
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
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
    /** How many recent files the picker shows before you search. */
    public const RECENT_FILES = 30;

    public function create(Request $request): View
    {
        $me = CurrentUser::get();
        $canUseTemplates = $me->hasPermission('email_templates.view');
        $template = $canUseTemplates && $request->integer('template') ? EmailTemplate::find($request->integer('template')) : null;
        $chosenIds = array_map('intval', (array) $request->old('stored_files', []));

        return view('emails.compose', [
            'pickable' => $this->pickableFiles($me)->whereKeyNot($chosenIds)->limit(self::RECENT_FILES)->get(),
            'chosen' => $this->pickableFiles($me)->whereKey($chosenIds)->get(),
            'templateFiles' => $template ? Attachables::filesFor('email_template', $template->id) : collect(),
            'people' => User::orderBy('name')->get(),
            'templates' => $canUseTemplates ? EmailTemplate::orderBy('name')->get() : collect(),
            'template' => $template,
            'sent' => SentEmail::where('user_id', $me->id)->where('source', 'compose')->latest('id')->limit(20)->get(),
        ]);
    }

    /**
     * Files you may attach whose name matches `q`, for the picker's search box.
     */
    public function files(Request $request): JsonResponse
    {
        $term = mb_strtolower(trim((string) $request->string('q')));
        $query = $this->pickableFiles(CurrentUser::get());
        if ($term !== '') {
            $query->whereRaw('lower(name) like ?', ['%'.$term.'%']);
        }

        return response()->json($query->limit(50)->get()->map(fn (StoredFile $file): array => [
            'id' => $file->id,
            'name' => $file->name,
            'path' => $file->folder->path(),
        ]));
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
            // A template's files are only for people who may see templates.
            abort_unless($me->hasPermission('email_templates.view'), 403);
            array_push($files, ...Mailer::templateAttachments(EmailTemplate::find($data['template_id'])));
        }

        Mailer::assertAttachmentSize($files);

        return $files;
    }

    /**
     * Files in folders this person may open (library and their own), newest first.
     *
     * @return Builder<StoredFile>
     */
    private function pickableFiles(User $me): Builder
    {
        $folderIds = app(FileAccess::class)->visibleLibraryFolderIds($me);
        if ($me->hasPermission('my_files.view')) {
            array_push($folderIds, ...app(FileStore::class)->subtreeIds(Folder::personalRootFor($me)));
        }

        return StoredFile::whereIn('folder_id', $folderIds)->whereNotNull('current_version_id')->with('folder')->latest('updated_at');
    }
}
