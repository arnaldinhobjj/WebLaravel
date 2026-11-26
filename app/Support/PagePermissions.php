<?php

namespace App\Support;

/**
 * Every page and what a tag can allow on it. A permission is "page.ability",
 * for example "tickets.edit".
 *
 * To add a page or functionality: add it here, then protect its routes with
 * `can:page.ability`. The tag form and the Tags page pick it up by themselves.
 */
class PagePermissions
{
    /** The shared columns of the tag grid. */
    public const STANDARD = ['view' => 'View', 'create' => 'Create', 'edit' => 'Edit', 'delete' => 'Delete'];

    /**
     * @var array<string, array{label: string, abilities: list<string>, extras?: array<string, string>}>
     */
    public const PAGES = [
        'tickets' => [
            'label' => 'Tickets',
            'abilities' => ['view', 'create', 'edit'],
            'extras' => ['reply' => 'Reply', 'assign' => 'Assign people', 'view_all' => 'See everyone’s tickets'],
        ],
        'actions' => [
            'label' => 'My actions',
            'abilities' => ['view', 'create', 'edit', 'delete'],
            'extras' => ['run' => 'Run them'],
        ],
        'shared_actions' => [
            'label' => 'Shared actions',
            'abilities' => ['view', 'edit'],
            'extras' => ['run' => 'Run them'],
        ],
        'dashboards' => [
            'label' => 'Dashboards',
            'abilities' => ['view', 'create', 'edit', 'delete'],
            'extras' => ['share' => 'Share dashboards with everyone'],
        ],
        'materials' => ['label' => 'Materials', 'abilities' => ['view', 'edit']],
        'tags' => ['label' => 'Tags', 'abilities' => ['view', 'create', 'edit', 'delete']],
        'departments' => ['label' => 'Departments', 'abilities' => ['view', 'create', 'edit', 'delete']],
        'people' => ['label' => 'People', 'abilities' => ['view', 'create', 'edit']],
        'emails' => ['label' => 'Email', 'abilities' => ['view'], 'extras' => ['send' => 'Send emails']],
        'email_templates' => ['label' => 'Email templates', 'abilities' => ['view', 'create', 'edit', 'delete']],
        'email_settings' => ['label' => 'Email settings', 'abilities' => ['view', 'edit']],
        'files' => [
            'label' => 'Files (library)',
            'abilities' => ['view', 'create', 'edit', 'delete'],
            'extras' => ['manage_access' => 'Manage folder access', 'empty_trash' => 'Empty trash'],
        ],
        'my_files' => ['label' => 'My files', 'abilities' => ['view', 'create', 'edit', 'delete']],
        'file_settings' => ['label' => 'File settings', 'abilities' => ['view', 'edit']],
        'ai' => ['label' => 'AI', 'abilities' => [], 'extras' => ['chat' => 'Chat with my AI', 'connect' => 'Connect my AI to Fort Vale', 'manage_skills' => 'Switch skills off for everyone', 'web' => 'Search and read the web']],
    ];

    /**
     * What the built-in Everyone tag allows on a new install.
     *
     * @var list<string>
     */
    public const EVERYONE_DEFAULTS = [
        'tickets.view', 'tickets.create', 'tickets.reply',
        'actions.view', 'actions.create', 'actions.edit', 'actions.delete', 'actions.run',
        'shared_actions.view', 'shared_actions.run',
        'dashboards.view', 'dashboards.create', 'dashboards.edit', 'dashboards.delete',
        'files.view', 'files.create', 'files.edit',
        'materials.view',
        'my_files.view', 'my_files.create', 'my_files.edit', 'my_files.delete',
        'ai.chat', 'ai.web',
    ];

    /**
     * Every permission, as "page.ability" => "Page: Ability".
     *
     * @return array<string, string>
     */
    public static function all(): array
    {
        $all = [];
        foreach (self::PAGES as $page => $definition) {
            foreach ($definition['abilities'] as $ability) {
                $all["{$page}.{$ability}"] = "{$definition['label']}: ".self::STANDARD[$ability];
            }
            foreach ($definition['extras'] ?? [] as $ability => $label) {
                $all["{$page}.{$ability}"] = "{$definition['label']}: {$label}";
            }
        }

        return $all;
    }

    public static function exists(string $permission): bool
    {
        return array_key_exists($permission, self::all());
    }

    /**
     * Cleans a submitted list: drops unknown entries and adds View for any page
     * that has another ability, since every other ability needs the page open.
     *
     * @param  list<string>  $permissions
     * @return list<string>
     */
    public static function normalize(array $permissions): array
    {
        $known = array_values(array_filter(array_unique($permissions), self::exists(...)));

        foreach ($known as $permission) {
            $view = strtok($permission, '.').'.view';
            if (self::exists($view) && ! in_array($view, $known, true)) {
                $known[] = $view;
            }
        }

        return array_values(array_intersect(array_keys(self::all()), $known));
    }

    /**
     * A tag's permissions grouped by page, e.g. ["Tickets" => ["View", "Reply"]].
     *
     * @param  list<string>  $permissions
     * @return array<string, list<string>>
     */
    public static function summary(array $permissions): array
    {
        $summary = [];
        foreach (self::PAGES as $page => $definition) {
            $labels = [];
            foreach ($definition['abilities'] as $ability) {
                if (in_array("{$page}.{$ability}", $permissions, true)) {
                    $labels[] = self::STANDARD[$ability];
                }
            }
            foreach ($definition['extras'] ?? [] as $ability => $label) {
                if (in_array("{$page}.{$ability}", $permissions, true)) {
                    $labels[] = $label;
                }
            }
            if ($labels !== []) {
                $summary[$definition['label']] = $labels;
            }
        }

        return $summary;
    }
}
