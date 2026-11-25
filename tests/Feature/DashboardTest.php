<?php

namespace Tests\Feature;

use App\Enums\DashboardVisibility;
use App\Enums\DashboardWidgetType;
use App\Enums\WidgetScope;
use App\Models\Dashboard;
use App\Models\DashboardWidget;
use App\Models\Tag;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    private const OWN = ['dashboards.view', 'dashboards.create', 'dashboards.edit', 'dashboards.delete', 'tickets.view'];

    private User $me;

    private User $colleague;

    /** @var list<string> */
    private array $everyoneDefaults = [];

    protected function setUp(): void
    {
        parent::setUp();

        // Start from nothing: each test grants exactly what it needs. Keep what
        // the migrations gave the Everyone tag for the defaults test.
        $this->everyoneDefaults = Tag::everyone()?->permissions ?? [];
        Tag::everyone()?->update(['permissions' => []]);

        $this->me = User::factory()->withPermissions(...self::OWN)->create(['name' => 'Alex Morgan']);
        $this->colleague = User::factory()->withPermissions(...self::OWN)->create(['name' => 'Sam Okafor']);
        $this->actingAs($this->me);
    }

    private function dashboardOf(User $owner, DashboardVisibility $visibility = DashboardVisibility::Personal, string $name = 'Board'): Dashboard
    {
        return Dashboard::factory()->for($owner, 'owner')->create(['name' => $name, 'visibility' => $visibility]);
    }

    public function test_requires_the_dashboards_view_permission(): void
    {
        $this->actingAs(User::factory()->create())->get('/dashboard')->assertForbidden();
    }

    public function test_shows_an_empty_state_when_there_are_no_dashboards(): void
    {
        $this->get('/dashboard')->assertOk()->assertSee('There are no dashboards yet.')->assertSee(route('dashboards.create'));
    }

    public function test_opens_the_persons_default_then_their_own_then_a_shared_dashboard(): void
    {
        $shared = $this->dashboardOf($this->colleague, DashboardVisibility::Shared, 'Team overview');
        $this->get('/dashboard')->assertOk()->assertSee('<h2 class="section-title">Team overview</h2>', false);

        $mine = $this->dashboardOf($this->me, name: 'My work');
        $this->get('/dashboard')->assertSee('<h2 class="section-title">My work</h2>', false);

        $this->me->forceFill(['default_dashboard_id' => $shared->id])->save();
        $this->get('/dashboard')->assertSee('<h2 class="section-title">Team overview</h2>', false);

        $shared->delete();
        $this->get('/dashboard')->assertSee('<h2 class="section-title">My work</h2>', false);
        $this->assertModelExists($mine);
    }

    public function test_lists_shared_dashboards_and_only_my_own_personal_ones(): void
    {
        $this->dashboardOf($this->colleague, DashboardVisibility::Shared, 'Team overview');
        $this->dashboardOf($this->colleague, name: 'Sam private board');
        $mine = $this->dashboardOf($this->me, name: 'My work');

        $this->get(route('dashboards.show', $mine))
            ->assertOk()
            ->assertSee('Team overview')
            ->assertSee('My work')
            ->assertDontSee('Sam private board');
    }

    public function test_someone_elses_personal_dashboard_looks_like_it_does_not_exist(): void
    {
        $theirs = $this->dashboardOf($this->colleague, name: 'Sam private board');
        $widget = $theirs->addWidget(DashboardWidgetType::TicketSummary);

        $this->get(route('dashboards.show', $theirs))->assertNotFound();
        $this->get(route('dashboards.edit', $theirs))->assertNotFound();
        $this->put(route('dashboards.update', $theirs), ['name' => 'Taken', 'visibility' => 'personal'])->assertNotFound();
        $this->delete(route('dashboards.destroy', $theirs))->assertNotFound();
        $this->post(route('dashboards.widgets.store', $theirs), ['type' => 'ticket_queue', 'scope' => 'mine'])->assertNotFound();
        $this->delete(route('dashboards.widgets.destroy', [$theirs, $widget]))->assertNotFound();
        $this->post(route('dashboards.copy', $theirs))->assertNotFound();
        $this->post(route('dashboards.default', $theirs))->assertNotFound();

        $this->assertSame('Sam private board', $theirs->fresh()->name);
        $this->assertSame(1, $theirs->widgets()->count());
        $this->assertSame(1, Dashboard::count());
    }

    public function test_a_shared_dashboard_can_be_seen_but_not_changed_without_the_share_permission(): void
    {
        $shared = $this->dashboardOf($this->colleague, DashboardVisibility::Shared, 'Team overview');

        $this->get(route('dashboards.show', $shared))->assertOk()->assertDontSee(route('dashboards.edit', $shared));
        $this->get(route('dashboards.edit', $shared))->assertForbidden();
        $this->put(route('dashboards.update', $shared), ['name' => 'Mine now', 'visibility' => 'shared'])->assertForbidden();
        $this->post(route('dashboards.widgets.store', $shared), ['type' => 'ticket_queue', 'scope' => 'everyone'])->assertForbidden();
        $this->delete(route('dashboards.destroy', $shared))->assertForbidden();

        $this->assertSame('Team overview', $shared->fresh()->name);
    }

    public function test_people_who_may_share_maintain_shared_dashboards(): void
    {
        $shared = $this->dashboardOf($this->colleague, DashboardVisibility::Shared, 'Team overview');
        $sharer = User::factory()->withPermissions(...self::OWN, ...['dashboards.share'])->create();

        $this->actingAs($sharer)
            ->put(route('dashboards.update', $shared), ['name' => 'Helpdesk overview', 'visibility' => 'shared'])
            ->assertRedirect(route('dashboards.edit', $shared));

        $this->assertSame('Helpdesk overview', $shared->fresh()->name);
    }

    public function test_creates_a_personal_dashboard_with_recommended_or_no_widgets(): void
    {
        $this->post(route('dashboards.store'), ['name' => 'My work', 'visibility' => 'personal'])->assertRedirect();

        $dashboard = Dashboard::where('name', 'My work')->sole();
        $this->assertTrue($dashboard->isOwnedBy($this->me));
        $this->assertSame(DashboardVisibility::Personal, $dashboard->visibility);
        $this->assertNotEmpty($dashboard->widgets);
        $this->assertTrue($dashboard->widgets->every(fn ($widget) => $widget->scope === WidgetScope::Mine));

        $this->post(route('dashboards.store'), ['name' => 'Blank', 'visibility' => 'personal', 'start_with' => 'empty']);
        $this->assertSame(0, Dashboard::where('name', 'Blank')->sole()->widgets()->count());
    }

    public function test_rejects_a_dashboard_without_a_name_or_with_an_unknown_visibility(): void
    {
        $this->post(route('dashboards.store'), ['name' => '', 'visibility' => 'public'])
            ->assertSessionHasErrors(['name', 'visibility']);

        $this->assertSame(0, Dashboard::count());
    }

    public function test_sharing_with_everyone_needs_the_share_permission(): void
    {
        $this->post(route('dashboards.store'), ['name' => 'For everyone', 'visibility' => 'shared'])
            ->assertSessionHasErrors(['visibility' => 'You can’t share dashboards with everyone. Ask someone who manages tags for the Share permission.']);

        $mine = $this->dashboardOf($this->me, name: 'My work');
        $this->put(route('dashboards.update', $mine), ['name' => 'My work', 'visibility' => 'shared'])->assertSessionHasErrors('visibility');
        $this->assertSame(DashboardVisibility::Personal, $mine->fresh()->visibility);

        $this->me->tags()->first()->update(['permissions' => [...self::OWN, 'dashboards.share']]);
        $this->actingAs($this->me->fresh())->put(route('dashboards.update', $mine), ['name' => 'My work', 'visibility' => 'shared'])->assertSessionHasNoErrors();
        $this->assertSame(DashboardVisibility::Shared, $mine->fresh()->visibility);
    }

    public function test_creating_dashboards_needs_the_create_permission(): void
    {
        $viewer = User::factory()->withPermissions('dashboards.view')->create();
        $shared = $this->dashboardOf($this->colleague, DashboardVisibility::Shared);

        $this->actingAs($viewer)->post(route('dashboards.store'), ['name' => 'Mine', 'visibility' => 'personal'])->assertForbidden();
        $this->actingAs($viewer)->post(route('dashboards.copy', $shared))->assertForbidden();
        $this->assertSame(1, Dashboard::count());
    }

    public function test_copying_a_shared_dashboard_makes_a_personal_one_with_the_same_widgets(): void
    {
        $shared = $this->dashboardOf($this->colleague, DashboardVisibility::Shared, 'Team overview');
        $shared->addWidget(DashboardWidgetType::TicketsByStatus, WidgetScope::Everyone);
        $shared->addWidget(DashboardWidgetType::TicketQueue, WidgetScope::Mine);

        $this->post(route('dashboards.copy', $shared))->assertRedirect();

        $copy = Dashboard::where('name', 'Team overview (my copy)')->sole();
        $this->assertTrue($copy->isOwnedBy($this->me));
        $this->assertSame(DashboardVisibility::Personal, $copy->visibility);
        $this->assertSame(
            [['tickets_by_status', 'everyone'], ['ticket_queue', 'mine']],
            $copy->widgets->map(fn ($w) => [$w->type->value, $w->scope->value])->all(),
        );
        $this->assertSame(2, $shared->widgets()->count());
    }

    public function test_adds_moves_and_removes_widgets(): void
    {
        $mine = $this->dashboardOf($this->me);

        $this->post(route('dashboards.widgets.store', $mine), ['type' => 'ticket_summary', 'scope' => 'mine'])->assertRedirect(route('dashboards.edit', $mine));
        $this->post(route('dashboards.widgets.store', $mine), ['type' => 'recent_activity', 'scope' => 'everyone']);
        $this->post(route('dashboards.widgets.store', $mine), ['type' => 'nope', 'scope' => 'theirs'])->assertSessionHasErrors(['type', 'scope']);

        $types = fn () => $mine->widgets()->get()->map(fn ($w) => $w->type->value)->all();
        $this->assertSame(['ticket_summary', 'recent_activity'], $types());

        [$first, $second] = $mine->widgets()->get()->all();
        $this->putJson(route('dashboards.widgets.reorder', $mine), ['order' => [$second->id, $first->id]])->assertOk();
        $this->assertSame(['recent_activity', 'ticket_summary'], $types());

        $this->delete(route('dashboards.widgets.destroy', [$mine, $second]));
        $this->assertSame(['ticket_summary'], $types());
    }

    public function test_a_widget_can_only_be_changed_through_its_own_dashboard(): void
    {
        $mine = $this->dashboardOf($this->me);
        $theirs = $this->dashboardOf($this->colleague, DashboardVisibility::Shared);
        $theirWidget = $theirs->addWidget(DashboardWidgetType::TicketSummary);

        $this->delete(route('dashboards.widgets.destroy', [$mine, $theirWidget]))->assertNotFound();
        $this->assertModelExists($theirWidget);
    }

    public function test_just_mine_widgets_show_each_viewer_their_own_tickets(): void
    {
        $everyone = User::factory()->withPermissions(...self::OWN, ...['tickets.view_all'])->create();
        $shared = $this->dashboardOf($everyone, DashboardVisibility::Shared, 'Team overview');
        $shared->addWidget(DashboardWidgetType::TicketQueue, WidgetScope::Mine);

        Ticket::factory()->create(['subject' => 'Alex printer jam', 'assignee_id' => $this->me->id, 'status' => 'open']);
        Ticket::factory()->create(['subject' => 'Sam camera offline', 'assignee_id' => $this->colleague->id, 'status' => 'open']);

        $this->get(route('dashboards.show', $shared))->assertSee('Alex printer jam')->assertDontSee('Sam camera offline');
        $this->actingAs($this->colleague)->get(route('dashboards.show', $shared))->assertSee('Sam camera offline')->assertDontSee('Alex printer jam');
    }

    public function test_everyone_widgets_never_show_tickets_the_viewer_cannot_see(): void
    {
        $shared = $this->dashboardOf($this->colleague, DashboardVisibility::Shared, 'Team overview');
        $shared->addWidget(DashboardWidgetType::TicketQueue, WidgetScope::Everyone);
        $shared->addWidget(DashboardWidgetType::RecentActivity, WidgetScope::Everyone);

        $hidden = Ticket::factory()->create(['subject' => 'Confidential HR matter', 'requester_id' => $this->colleague->id, 'status' => 'open']);
        $hidden->addComment('Sensitive details here.', $this->colleague);
        Ticket::factory()->create(['subject' => 'My own request', 'requester_id' => $this->me->id, 'status' => 'open']);

        $this->get(route('dashboards.show', $shared))
            ->assertSee('My own request')
            ->assertDontSee('Confidential HR matter');

        $seesAll = User::factory()->withPermissions(...self::OWN, ...['tickets.view_all'])->create();
        $this->actingAs($seesAll)->get(route('dashboards.show', $shared))->assertSee('Confidential HR matter');
    }

    public function test_summary_counts_follow_the_widget_scope(): void
    {
        $mine = $this->dashboardOf($this->me);
        $mine->addWidget(DashboardWidgetType::TicketSummary, WidgetScope::Mine);
        Ticket::factory()->count(2)->create(['assignee_id' => $this->me->id, 'requester_id' => $this->me->id, 'status' => 'open', 'priority' => 'urgent']);
        Ticket::factory()->create(['requester_id' => $this->me->id, 'status' => 'open', 'priority' => 'low']);

        $this->get(route('dashboards.show', $mine))
            ->assertSeeInOrder(['dashboard-tile-value">2<', 'Assigned to me', 'dashboard-tile-value">2<', 'Urgent'], false);
    }

    public function test_ticket_widgets_explain_when_the_viewer_cannot_see_tickets(): void
    {
        $noTickets = User::factory()->withPermissions('dashboards.view', 'dashboards.create')->create();
        $shared = $this->dashboardOf($this->colleague, DashboardVisibility::Shared);
        $shared->addWidget(DashboardWidgetType::TicketQueue, WidgetScope::Everyone);
        Ticket::factory()->create(['subject' => 'Anything']);

        $this->actingAs($noTickets)->get(route('dashboards.show', $shared))
            ->assertOk()
            ->assertSee('You don’t have access to tickets.')
            ->assertDontSee('Anything');
    }

    public function test_the_resolved_trend_says_so_when_nothing_was_resolved(): void
    {
        $mine = $this->dashboardOf($this->me);
        $mine->addWidget(DashboardWidgetType::ResolvedTrend, WidgetScope::Everyone);

        $this->get(route('dashboards.show', $mine))
            ->assertOk()
            ->assertSee('Nothing resolved or closed in the last 14 days.')
            ->assertDontSee('Tickets resolved per day');
    }

    public function test_choosing_which_dashboard_opens_at_slash_dashboard(): void
    {
        $shared = $this->dashboardOf($this->colleague, DashboardVisibility::Shared, 'Team overview');
        $this->dashboardOf($this->me, name: 'My work');

        $this->post(route('dashboards.default', $shared))->assertRedirect(route('dashboards.index'));

        $this->assertSame($shared->id, $this->me->fresh()->default_dashboard_id);
        $this->get('/dashboard')->assertSee('<h2 class="section-title">Team overview</h2>', false);
        $this->actingAs($this->colleague)->get('/dashboard')->assertSee('<h2 class="section-title">Team overview</h2>', false);
    }

    public function test_deletes_my_own_dashboard(): void
    {
        $mine = $this->dashboardOf($this->me);
        $mine->addWidget(DashboardWidgetType::TicketSummary);

        $this->delete(route('dashboards.destroy', $mine))->assertRedirect(route('dashboards.index'));

        $this->assertModelMissing($mine);
        $this->assertSame(0, DashboardWidget::count());
    }

    public function test_escapes_dashboard_names(): void
    {
        $mine = $this->dashboardOf($this->me, name: '<script>alert("x")</script>');

        $this->get(route('dashboards.show', $mine))
            ->assertSee('&lt;script&gt;', false)
            ->assertDontSee('<script>alert("x")</script>', false);
    }

    public function test_everyone_may_keep_their_own_dashboards_but_not_share_by_default(): void
    {
        Tag::everyone()->update(['permissions' => $this->everyoneDefaults]);
        $person = User::factory()->create();

        $this->actingAs($person)->post(route('dashboards.store'), ['name' => 'Mine', 'visibility' => 'personal'])->assertRedirect();
        $this->actingAs($person)->post(route('dashboards.store'), ['name' => 'Ours', 'visibility' => 'shared'])->assertSessionHasErrors('visibility');
        $this->assertSame(['Mine'], Dashboard::pluck('name')->all());
    }

    public function test_copying_a_dashboard_with_a_long_name_keeps_the_name_within_60_characters(): void
    {
        $longName = str_repeat('Quarterly operations overview ', 2); // 60 characters
        $shared = $this->dashboardOf($this->colleague, DashboardVisibility::Shared, rtrim($longName).'.');

        $this->post(route('dashboards.copy', $shared))->assertRedirect();

        $copy = Dashboard::where('user_id', $this->me->id)->sole();
        $this->assertLessThanOrEqual(60, mb_strlen($copy->name));
        $this->assertStringEndsWith(' (my copy)', $copy->name);
    }

    public function test_the_resolved_in_7_days_tile_links_to_exactly_the_tickets_it_counts(): void
    {
        $everyone = User::factory()->withPermissions(...self::OWN, ...['tickets.view_all'])->create();
        $mine = $this->dashboardOf($everyone);
        $mine->addWidget(DashboardWidgetType::TicketSummary, WidgetScope::Everyone);

        Ticket::factory()->create(['subject' => 'Fixed yesterday', 'status' => 'resolved', 'resolved_at' => now()->subDay()]);
        Ticket::factory()->create(['subject' => 'Fixed last month', 'status' => 'closed', 'resolved_at' => now()->subDays(30)]);
        Ticket::factory()->create(['subject' => 'Still open', 'status' => 'open']);

        $link = route('tickets.index', ['status' => 'all', 'resolved_within' => '7']);
        $this->actingAs($everyone)->get(route('dashboards.show', $mine))
            ->assertSeeInOrder(['href="'.e($link).'"', 'dashboard-tile-value">1<', 'Resolved in 7 days'], false);

        $this->actingAs($everyone)->get($link)
            ->assertSee('Resolved or closed in the last 7 days')
            ->assertSee('Fixed yesterday')
            ->assertDontSee('Fixed last month')
            ->assertDontSee('Still open');
    }
}
