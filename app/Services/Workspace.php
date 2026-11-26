<?php

namespace App\Services;

use Illuminate\Contracts\Session\Session;

/**
 * Holds the data shown on the Fort Vale home screen and the per-user state
 * (favorites, action runs, handled mentions), which is kept in the session.
 */
class Workspace
{
    public const MODULES = [
        'quality' => ['name' => 'Quality', 'icon' => 'folder', 'description' => 'Audits, inspections and non-conformance reports'],
        'nuclear' => ['name' => 'Nuclear', 'icon' => 'reactor', 'description' => 'Reactor status, dosimetry and safety checks'],
        'dispatch' => ['name' => 'Dispatch', 'icon' => 'plane', 'description' => 'Flights, crews and outbound logistics'],
        'cctv' => ['name' => 'CCTV', 'icon' => 'cctv', 'description' => 'Live camera feeds and recordings'],
        'health' => ['name' => 'Health', 'icon' => 'health', 'description' => 'Clinic visits, medical records and first aid'],
        'fleet' => ['name' => 'Fleet', 'icon' => 'truck', 'description' => 'Vehicles, fuel and maintenance'],
        'energy' => ['name' => 'Energy', 'icon' => 'zap', 'description' => 'Grid load and power consumption'],
        'weather' => ['name' => 'Weather', 'icon' => 'cloud', 'description' => 'Forecasts and site weather alerts'],
    ];

    public const DEFAULT_FAVORITES = ['quality', 'nuclear', 'dispatch', 'cctv', 'health'];

    public const MENTIONS = [
        37941 => ['author' => 'Derek Lamar', 'message' => 'Could you please confirm you have reviewed the updated inspection checklist for building C before Friday’s audit? We need sign-off from every team lead.', 'minutes_ago' => 12],
        37902 => ['author' => 'Priya Natarajan', 'message' => 'The dispatch schedule for next week has two overlapping crews on Tuesday. Can you take a look and pick one?', 'minutes_ago' => 95],
        37855 => ['author' => 'Tom Achebe', 'message' => 'Camera 14 at the north gate has been offline since this morning. Facilities says it is a network issue — can you approve the call-out?', 'minutes_ago' => 260],
        37810 => ['author' => 'Helena Brandt', 'message' => 'Thanks for the quick turnaround on the dosimetry report. One last question about the March readings, see the attached note.', 'minutes_ago' => 1440],
    ];

    public const NAVIGATION = [
        ['label' => 'Overview', 'items' => [['label' => 'Dashboard', 'route' => 'dashboards.index', 'active' => 'dashboards.*', 'can' => 'dashboards.view'], 'Activity', 'Calendar']],
        ['label' => 'Operations', 'items' => ['Shifts', 'Work orders', 'Incidents', ['label' => 'Materials', 'route' => 'materials.index', 'active' => 'materials.*', 'can' => 'materials.view']]],
        ['label' => 'Logistics', 'items' => ['Shipments', 'Warehouses', 'Suppliers']],
        ['label' => 'Security', 'items' => ['Access control', 'Visitors', 'Patrols']],
        ['label' => 'Facilities', 'items' => ['Buildings', 'Maintenance', 'Utilities']],
        ['label' => 'People', 'items' => ['Directory', 'Teams', 'Training']],
        ['label' => 'Finance', 'items' => ['Budgets', 'Purchase orders', 'Invoices']],
        ['label' => 'Reports', 'items' => ['Sales', 'Compliance', 'Exports']],
        ['label' => 'Workspace', 'open' => true, 'items' => [
            ['label' => 'Home', 'route' => 'dashboard'],
            ['label' => 'Tickets', 'route' => 'tickets.index', 'badge' => 'active_tickets', 'can' => 'tickets.view'],
            ['label' => 'My tickets', 'route' => 'tickets.index', 'params' => ['assignee' => 'me'], 'badge' => 'my_tickets', 'can' => 'tickets.view'],
            'Approvals',
            ['label' => 'Workspace Files', 'route' => 'workspace.index', 'active' => 'workspace.*', 'can' => 'workspace'],
            ['label' => 'AI', 'route' => 'ai.index', 'can' => 'ai.chat'],
            ['label' => 'Email', 'route' => 'emails.create', 'can' => 'emails.view'],
            ['label' => 'Email templates', 'route' => 'email-templates.index', 'can' => 'email_templates.view'],
            ['label' => 'Email settings', 'route' => 'email-settings.edit', 'can' => 'email_settings.view'],
            ['label' => 'Tags', 'route' => 'tags.index', 'can' => 'tags-page'],
        ]],
    ];

    public const FOOTER_NAVIGATION = ['label' => 'Help', 'open' => true, 'items' => [
        ['label' => 'Support center', 'route' => 'tickets.create', 'params' => ['category' => 'general'], 'can' => 'tickets.create'],
    ]];

    public function __construct(private Session $session) {}

    /** @return list<string> */
    public function favoriteSlugs(): array
    {
        return $this->session->get('favorites', self::DEFAULT_FAVORITES);
    }

    /** @return array<string, array{name: string, icon: string, description: string}> */
    public function favorites(): array
    {
        $favorites = [];
        foreach ($this->favoriteSlugs() as $slug) {
            if (isset(self::MODULES[$slug])) {
                $favorites[$slug] = self::MODULES[$slug];
            }
        }

        return $favorites;
    }

    /** @return array<string, array{name: string, icon: string, description: string}> */
    public function otherModules(): array
    {
        return array_diff_key(self::MODULES, array_flip($this->favoriteSlugs()));
    }

    public function isFavorite(string $slug): bool
    {
        return in_array($slug, $this->favoriteSlugs(), true);
    }

    /** Adds or removes a module from favorites; returns true when it is now a favorite. */
    public function toggleFavorite(string $slug): bool
    {
        $favorites = $this->favoriteSlugs();

        if ($this->isFavorite($slug)) {
            $favorites = array_values(array_diff($favorites, [$slug]));
            $this->session->put('favorites', $favorites);

            return false;
        }

        $favorites[] = $slug;
        $this->session->put('favorites', $favorites);

        return true;
    }

    /** Mentions that are neither resolved nor dismissed, newest first. */
    public function openMentions(): array
    {
        $handled = $this->session->get('handled_mentions', []);

        return collect(self::MENTIONS)
            ->reject(fn ($mention, $ticket) => array_key_exists($ticket, $handled))
            ->map(fn (array $mention, int $ticket) => $mention + [
                'ticket' => $ticket,
                'received_at' => now()->subMinutes($mention['minutes_ago']),
            ])
            ->sortBy('minutes_ago')
            ->all();
    }

    public function handledMentionCount(): int
    {
        return count($this->session->get('handled_mentions', []));
    }

    /** @param 'resolved'|'dismissed' $status */
    public function handleMention(int $ticket, string $status): void
    {
        $handled = $this->session->get('handled_mentions', []);
        $handled[$ticket] = $status;
        $this->session->put('handled_mentions', $handled);
    }

    public function restoreMentions(): void
    {
        $this->session->forget('handled_mentions');
    }
}
