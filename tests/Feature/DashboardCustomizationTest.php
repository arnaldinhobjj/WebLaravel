<?php

namespace Tests\Feature;

use App\Enums\DashboardVisibility;
use App\Enums\DashboardWidgetType;
use App\Enums\TicketPriority;
use App\Enums\WidgetScope;
use App\Enums\WidgetSize;
use App\Models\ActionRun;
use App\Models\Dashboard;
use App\Models\DashboardWidget;
use App\Models\Department;
use App\Models\Folder;
use App\Models\StoredFile;
use App\Models\Tag;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The customize screen: drag-and-drop order, adding at a spot, widget
 * settings, and table widgets whose tables and filters follow permissions.
 */
class DashboardCustomizationTest extends TestCase
{
    use RefreshDatabase;

    private const OWN = ['dashboards.view', 'dashboards.create', 'dashboards.edit', 'dashboards.delete'];

    private User $me;

    protected function setUp(): void
    {
        parent::setUp();

        Tag::everyone()?->update(['permissions' => []]);
        $this->me = User::factory()->withPermissions(...self::OWN, ...['tickets.view'])->create(['name' => 'Alex Morgan']);
        $this->actingAs($this->me);
    }

    /**
     * Gives the signed-in person more permissions. Permissions are worked out
     * once per loaded person, so the next requests use a fresh copy.
     */
    private function grant(string ...$permissions): void
    {
        $this->me->tags()->attach(Tag::factory()->granting(...$permissions)->create());
        $this->me = $this->me->fresh();
        $this->actingAs($this->me);
    }

    private function dashboardOf(User $owner, DashboardVisibility $visibility = DashboardVisibility::Personal): Dashboard
    {
        return Dashboard::factory()->for($owner, 'owner')->create(['visibility' => $visibility]);
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    private function table(Dashboard $dashboard, array $settings): DashboardWidget
    {
        return $dashboard->addWidget(DashboardWidgetType::Table, WidgetScope::Everyone, null, $settings);
    }

    public function test_the_customize_screen_offers_only_what_the_person_may_add(): void
    {
        $mine = $this->dashboardOf($this->me);

        $this->get(route('dashboards.edit', $mine))
            ->assertOk()
            ->assertSee('Tickets table')
            ->assertSee('Ticket summary')
            ->assertDontSee('People table')
            ->assertDontSee('Files table')
            ->assertSee('data-dashboard-canvas', false);

        $this->grant('people.view', 'files.view');

        $this->get(route('dashboards.edit', $mine))->assertSee('People table')->assertSee('Files table');
    }

    public function test_dragging_widgets_saves_the_new_order(): void
    {
        $mine = $this->dashboardOf($this->me);
        $a = $mine->addWidget(DashboardWidgetType::TicketSummary);
        $b = $mine->addWidget(DashboardWidgetType::TicketQueue);
        $c = $mine->addWidget(DashboardWidgetType::RecentActivity);

        $this->putJson(route('dashboards.widgets.reorder', $mine), ['order' => [$c->id, $a->id, $b->id]])
            ->assertOk()
            ->assertJson(['saved' => true]);

        $this->assertSame([$c->id, $a->id, $b->id], $mine->widgets()->pluck('id')->all());
    }

    public function test_a_new_order_must_list_exactly_this_dashboards_widgets(): void
    {
        $mine = $this->dashboardOf($this->me);
        $a = $mine->addWidget(DashboardWidgetType::TicketSummary);
        $b = $mine->addWidget(DashboardWidgetType::TicketQueue);
        $elsewhere = $this->dashboardOf($this->me)->addWidget(DashboardWidgetType::TicketQueue);

        $this->putJson(route('dashboards.widgets.reorder', $mine), ['order' => [$b->id, $elsewhere->id]])->assertUnprocessable();
        $this->putJson(route('dashboards.widgets.reorder', $mine), ['order' => [$b->id]])->assertUnprocessable();
        $this->putJson(route('dashboards.widgets.reorder', $mine), ['order' => [$b->id, $b->id]])->assertUnprocessable();

        $this->assertSame([$a->id, $b->id], $mine->widgets()->pluck('id')->all());
    }

    public function test_only_people_who_may_change_a_dashboard_can_reorder_it(): void
    {
        $colleague = User::factory()->withPermissions(...self::OWN)->create();
        $theirs = $this->dashboardOf($colleague);
        $shared = $this->dashboardOf($colleague, DashboardVisibility::Shared);
        $theirWidget = $theirs->addWidget(DashboardWidgetType::TicketSummary);
        $sharedWidget = $shared->addWidget(DashboardWidgetType::TicketSummary);

        $this->putJson(route('dashboards.widgets.reorder', $theirs), ['order' => [$theirWidget->id]])->assertNotFound();
        $this->putJson(route('dashboards.widgets.reorder', $shared), ['order' => [$sharedWidget->id]])->assertForbidden();
        $this->patch(route('dashboards.widgets.update', [$shared, $sharedWidget]), ['title' => 'Mine now'])->assertForbidden();
        $this->assertNull($sharedWidget->fresh()->title);
    }

    public function test_a_widget_dropped_from_the_library_lands_where_it_was_dropped(): void
    {
        $mine = $this->dashboardOf($this->me);
        $a = $mine->addWidget(DashboardWidgetType::TicketSummary);
        $b = $mine->addWidget(DashboardWidgetType::TicketQueue);

        $this->post(route('dashboards.widgets.store', $mine), ['type' => 'recent_activity', 'position' => 2])->assertRedirect();

        $types = $mine->widgets()->get()->map(fn (DashboardWidget $widget): string => $widget->type->value)->all();
        $this->assertSame(['ticket_summary', 'recent_activity', 'ticket_queue'], $types);
        $this->assertSame([1, 2, 3], $mine->widgets()->pluck('position')->all());
        // Without a scope, a personal dashboard's new widget shows the person's own tickets.
        $this->assertSame(WidgetScope::Mine, $mine->widgets()->where('type', 'recent_activity')->first()->scope);
    }

    public function test_a_new_table_widget_starts_with_the_tables_usual_columns_and_opens_its_settings(): void
    {
        $mine = $this->dashboardOf($this->me);

        $response = $this->post(route('dashboards.widgets.store', $mine), ['type' => 'table', 'source' => 'tickets']);

        $widget = $mine->widgets()->first();
        $response->assertRedirect(route('dashboards.edit', [$mine, 'widget' => $widget->id]));
        $this->assertSame('tickets', $widget->settings['source']);
        $this->assertSame(['number', 'subject', 'status', 'priority', 'assignee'], $widget->settings['columns']);
        $this->assertSame('active', $widget->settings['filters']['status']);

        $this->get(route('dashboards.edit', [$mine, 'widget' => $widget->id]))
            ->assertOk()
            ->assertSee('data-open-on-load', false);
    }

    public function test_widgets_need_access_to_what_they_show(): void
    {
        $mine = $this->dashboardOf($this->me);

        $this->post(route('dashboards.widgets.store', $mine), ['type' => 'table', 'source' => 'people'])->assertSessionHasErrors('source');

        $noTickets = User::factory()->withPermissions(...self::OWN)->create();
        $theirs = $this->dashboardOf($noTickets);
        $this->actingAs($noTickets);
        $this->post(route('dashboards.widgets.store', $theirs), ['type' => 'ticket_queue'])->assertSessionHasErrors('type');
        $this->post(route('dashboards.widgets.store', $theirs), ['type' => 'table'])->assertSessionHasErrors('type');

        $this->assertSame(0, DashboardWidget::count());
    }

    public function test_saves_a_widgets_title_width_and_scope(): void
    {
        $mine = $this->dashboardOf($this->me);
        $widget = $mine->addWidget(DashboardWidgetType::TicketQueue, WidgetScope::Everyone);

        $this->patch(route('dashboards.widgets.update', [$mine, $widget]), ['title' => 'Fires', 'size' => 'wide', 'scope' => 'mine'])
            ->assertRedirect(route('dashboards.edit', $mine));

        $widget->refresh();
        $this->assertSame('Fires', $widget->displayTitle());
        $this->assertSame(WidgetSize::Wide, $widget->size);
        $this->assertTrue($widget->isWide());
        $this->assertSame(WidgetScope::Mine, $widget->scope);

        // The width button sends only the width.
        $this->patch(route('dashboards.widgets.update', [$mine, $widget]), ['size' => 'normal']);
        $this->assertSame('Fires', $widget->fresh()->title);
        $this->assertFalse($widget->fresh()->isWide());

        $this->get(route('dashboards.show', $mine))->assertSee('Fires');
    }

    public function test_saves_a_tables_columns_in_the_dragged_order_and_its_filters(): void
    {
        $mine = $this->dashboardOf($this->me);
        $widget = $this->table($mine, ['source' => 'tickets', 'columns' => ['subject']]);

        $this->patch(route('dashboards.widgets.update', [$mine, $widget]), [
            'title' => '',
            'source' => 'tickets',
            'columns' => ['priority', 'number', 'subject'],
            'filters' => ['status' => 'active', 'priority' => 'urgent', 'assignee' => 'me', 'sort' => 'oldest'],
            'limit' => 5,
        ])->assertSessionHasNoErrors();

        $settings = $widget->fresh()->settings;
        $this->assertSame(['priority', 'number', 'subject'], $settings['columns']);
        $this->assertSame('urgent', $settings['filters']['priority']);
        $this->assertSame(5, $settings['limit']);
        $this->assertNull($widget->fresh()->title);
    }

    public function test_tables_columns_and_filter_values_are_limited_to_the_editors_permissions(): void
    {
        $mine = $this->dashboardOf($this->me);
        $widget = $this->table($mine, ['source' => 'tickets', 'columns' => ['subject']]);
        $someone = User::factory()->create();
        $update = fn (array $changes) => $this->patch(route('dashboards.widgets.update', [$mine, $widget]), [
            'source' => 'tickets', 'columns' => ['subject'], 'limit' => 10, ...$changes,
        ]);

        // Picking someone else as assignee needs "See everyone's tickets".
        $update(['filters' => ['assignee' => (string) $someone->id]])->assertSessionHasErrors('filters.assignee');
        $update(['columns' => ['salary']])->assertSessionHasErrors('columns.0');
        $update(['columns' => []])->assertSessionHasErrors('columns');
        $update(['filters' => ['made_up' => 'x']])->assertSessionHasErrors('filters');
        $update(['source' => 'people'])->assertSessionHasErrors('source');
        $update(['limit' => 500])->assertSessionHasErrors('limit');

        $this->grant('tickets.view_all');
        $update(['filters' => ['assignee' => (string) $someone->id]])->assertSessionHasNoErrors();
        $this->assertSame((string) $someone->id, $widget->fresh()->settings['filters']['assignee']);
    }

    public function test_a_failed_save_reopens_that_widgets_settings(): void
    {
        $mine = $this->dashboardOf($this->me);
        $widget = $this->table($mine, ['source' => 'tickets', 'columns' => ['subject']]);

        $this->from(route('dashboards.edit', $mine))
            ->patch(route('dashboards.widgets.update', [$mine, $widget]), ['source' => 'tickets', 'columns' => []])
            ->assertRedirect(route('dashboards.edit', [$mine, 'widget' => $widget->id]));
    }

    public function test_a_ticket_table_shows_each_viewer_only_tickets_they_may_open(): void
    {
        $lead = User::factory()->withPermissions(...self::OWN, ...['tickets.view', 'tickets.view_all', 'dashboards.share'])->create();
        $shared = $this->dashboardOf($lead, DashboardVisibility::Shared);
        $this->table($shared, ['source' => 'tickets', 'columns' => ['subject', 'priority'], 'filters' => ['priority' => 'urgent']]);

        Ticket::factory()->create(['subject' => 'Boiler room flooding', 'priority' => TicketPriority::Urgent, 'assignee_id' => $this->me->id]);
        Ticket::factory()->create(['subject' => 'Payroll export broken', 'priority' => TicketPriority::Urgent]);
        Ticket::factory()->create(['subject' => 'Replace mouse', 'priority' => TicketPriority::Low, 'assignee_id' => $this->me->id]);

        $this->get(route('dashboards.show', $shared))
            ->assertOk()
            ->assertSee('Boiler room flooding')
            ->assertDontSee('Payroll export broken')
            ->assertDontSee('Replace mouse')
            ->assertSee('Urgent');

        $this->actingAs($lead)->get(route('dashboards.show', $shared))
            ->assertSee('Boiler room flooding')
            ->assertSee('Payroll export broken')
            ->assertSee(route('tickets.index', ['priority' => 'urgent']), false);
    }

    public function test_viewers_without_access_to_a_table_or_one_of_its_filters_see_why(): void
    {
        $lead = User::factory()->withPermissions(...self::OWN, ...['tickets.view', 'tickets.view_all', 'people.view', 'dashboards.share'])->create();
        $shared = $this->dashboardOf($lead, DashboardVisibility::Shared);
        $this->table($shared, ['source' => 'people', 'columns' => ['name']]);
        $this->table($shared, ['source' => 'tickets', 'columns' => ['subject'], 'filters' => ['assignee' => (string) $lead->id]]);
        Ticket::factory()->create(['subject' => 'Assigned to the lead', 'assignee_id' => $lead->id, 'requester_id' => $this->me->id]);

        $this->get(route('dashboards.show', $shared))
            ->assertOk()
            ->assertSee('You don’t have access to People.')
            ->assertSee('This table uses a filter that isn’t available to you.')
            ->assertDontSee($lead->name)
            ->assertDontSee('Assigned to the lead');
    }

    public function test_the_people_table_shows_tags_only_with_the_tags_permission(): void
    {
        $this->grant('people.view');
        $quality = Department::factory()->create(['name' => 'Quality']);
        $lab = Tag::factory()->create(['name' => 'Lab access']);
        $inQuality = User::factory()->create(['name' => 'Priya Shah', 'department_id' => $quality->id]);
        $inQuality->tags()->attach($lab);
        User::factory()->create(['name' => 'Tom Reyes']);

        $mine = $this->dashboardOf($this->me);
        $widget = $this->table($mine, ['source' => 'people', 'columns' => ['name', 'tags'], 'filters' => ['department' => (string) $quality->id]]);

        $this->get(route('dashboards.show', $mine))
            ->assertSee('Priya Shah')
            ->assertDontSee('Tom Reyes')
            ->assertDontSee('Lab access');

        $this->patch(route('dashboards.widgets.update', [$mine, $widget]), ['source' => 'people', 'columns' => ['name'], 'filters' => ['tag' => (string) $lab->id]])
            ->assertSessionHasErrors('filters');

        $this->grant('tags.view');

        $this->get(route('dashboards.show', $mine))->assertSee('Lab access');
        $this->patch(route('dashboards.widgets.update', [$mine, $widget]), ['source' => 'people', 'columns' => ['name'], 'filters' => ['tag' => (string) $lab->id]])
            ->assertSessionHasNoErrors();
        $this->get(route('dashboards.show', $mine))->assertSee('Priya Shah')->assertDontSee('Tom Reyes');
    }

    public function test_the_files_table_never_shows_files_the_viewer_cannot_open(): void
    {
        $this->grant('files.view', 'my_files.view');
        $open = Folder::factory()->create(['name' => 'Handbooks']);
        $restricted = Folder::factory()->create(['name' => 'Board']);
        $restricted->access()->create(['tag_id' => Tag::factory()->create()->id, 'abilities' => ['view']]);
        $someoneElse = User::factory()->create();

        StoredFile::factory()->create(['folder_id' => $open->id, 'name' => 'safety-handbook.pdf']);
        StoredFile::factory()->create(['folder_id' => $restricted->id, 'name' => 'board-minutes.pdf']);
        StoredFile::factory()->create(['folder_id' => Folder::personalRootFor($this->me)->id, 'name' => 'my-notes.txt']);
        StoredFile::factory()->create(['folder_id' => Folder::personalRootFor($someoneElse)->id, 'name' => 'their-notes.txt']);

        $mine = $this->dashboardOf($this->me);
        $widget = $this->table($mine, ['source' => 'files', 'columns' => ['name', 'folder']]);

        $this->get(route('dashboards.show', $mine))
            ->assertSee('safety-handbook.pdf')
            ->assertSee('my-notes.txt')
            ->assertDontSee('board-minutes.pdf')
            ->assertDontSee('their-notes.txt');

        $widget->update(['settings' => [...$widget->settings, 'filters' => ['place' => 'mine']]]);
        $this->get(route('dashboards.show', $mine))->assertSee('my-notes.txt')->assertDontSee('safety-handbook.pdf');
    }

    public function test_the_action_runs_table_shows_only_runs_the_viewer_may_see(): void
    {
        $this->grant('actions.view');
        ActionRun::factory()->for($this->me)->create(['action_name' => 'Weekly report', 'status' => ActionRun::FAILED]);
        ActionRun::factory()->create(['action_name' => 'Someone else’s sync']);

        $mine = $this->dashboardOf($this->me);
        $this->table($mine, ['source' => 'action_runs', 'columns' => ['action', 'result'], 'filters' => ['result' => 'failed']]);

        $this->get(route('dashboards.show', $mine))
            ->assertSee('Weekly report')
            ->assertSee('Failed')
            ->assertDontSee('Someone else’s sync');
    }

    public function test_a_copy_keeps_each_widgets_settings(): void
    {
        $lead = User::factory()->withPermissions(...self::OWN, ...['tickets.view', 'dashboards.share'])->create();
        $shared = $this->dashboardOf($lead, DashboardVisibility::Shared);
        $widget = $this->table($shared, ['source' => 'tickets', 'columns' => ['subject'], 'filters' => ['priority' => 'high'], 'limit' => 5]);
        $widget->update(['title' => 'Hot list', 'size' => WidgetSize::Normal]);

        $copy = $shared->copyFor($this->me);

        $copied = $copy->widgets()->first();
        $this->assertSame('Hot list', $copied->title);
        $this->assertSame(WidgetSize::Normal, $copied->size);
        $this->assertSame($widget->settings, $copied->settings);
    }
}
