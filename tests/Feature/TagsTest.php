<?php

namespace Tests\Feature;

use App\Models\Action;
use App\Models\Department;
use App\Models\Tag;
use App\Models\Ticket;
use App\Models\User;
use App\Support\PagePermissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TagsTest extends TestCase
{
    use RefreshDatabase;

    /** Everything on the Tags page. */
    private const ADMIN = [
        'tags.view', 'tags.create', 'tags.edit', 'tags.delete',
        'departments.view', 'departments.create', 'departments.edit', 'departments.delete',
        'people.view', 'people.create', 'people.edit',
    ];

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->withPermissions(...self::ADMIN)->create(['name' => 'Alex Morgan']);
        $this->actingAs($this->admin);
    }

    public function test_only_people_with_a_tags_page_permission_open_it(): void
    {
        $this->get('/tags')->assertOk()->assertSee('Tags');
        $this->get('/')->assertSee(route('tags.index'));

        $this->actingAs(User::factory()->create());
        $this->get('/tags')->assertForbidden();
        $this->get(route('people.create'))->assertForbidden();
        $this->post(route('tags.store'), ['name' => 'Sneaky', 'permissions' => ['people.edit']])->assertForbidden();
        $this->get('/')->assertDontSee(route('tags.index'));
        $this->assertDatabaseMissing('tags', ['name' => 'Sneaky']);
    }

    public function test_each_section_and_button_follows_its_own_permission(): void
    {
        $viewer = User::factory()->withPermissions('people.view', 'departments.edit')->create();
        $department = Department::factory()->create(['name' => 'IT']);

        $this->actingAs($viewer)->get('/tags')
            ->assertOk()
            ->assertSee('People')
            ->assertDontSee('Add person')
            ->assertDontSee('id="tags-title"', false)
            ->assertSee(route('departments.edit', $department))
            ->assertDontSee('New department');

        $this->get(route('departments.edit', $department))->assertOk();
        $this->delete(route('departments.destroy', $department))->assertForbidden();
        $this->get(route('people.edit', $this->admin))->assertForbidden();
    }

    public function test_edit_on_one_page_and_only_delete_on_another(): void
    {
        $person = User::factory()->withPermissions('tickets.edit', 'actions.delete')->create(['username' => 'mixed.person']);
        $everyone = Tag::everyone();
        $everyone->update(['permissions' => []]);
        $ticket = Ticket::factory()->create(['requester_id' => $person->id]);
        $action = Action::factory()->for($person)->create();
        $this->actingAs($person);

        $this->patch(route('tickets.update', $ticket), ['status' => 'closed'])->assertSessionHasNoErrors();
        $this->assertSame('closed', $ticket->fresh()->status->value);
        $this->post(route('tickets.comment', $ticket), ['body' => 'Hi'])->assertForbidden();
        $this->get(route('tickets.create'))->assertForbidden();

        $this->get(route('actions.edit', [$person, $action]))->assertForbidden();
        $this->get(route('actions.create', $person))->assertForbidden();
        $this->delete(route('actions.destroy', [$person, $action]))->assertRedirect();
        $this->assertModelMissing($action);
    }

    public function test_the_grid_lists_every_page_and_ticking_an_ability_adds_view(): void
    {
        $page = $this->get(route('tags.create'))->assertOk();
        foreach (PagePermissions::all() as $permission => $label) {
            $page->assertSee('value="'.$permission.'"', false);
        }
        $page->assertDontSee('value="tickets.delete"', false);

        $this->post(route('tags.store'), ['name' => 'Repliers', 'permissions' => ['tickets.reply', 'people.edit']])
            ->assertRedirect(route('tags.index'));

        $this->assertEqualsCanonicalizing(
            ['tickets.view', 'tickets.reply', 'people.view', 'people.edit'],
            Tag::where('name', 'Repliers')->sole()->permissions,
        );
        $this->get('/tags')->assertSee('Tickets:</strong> View, Reply', false);
    }

    public function test_unknown_permissions_are_rejected(): void
    {
        $this->post(route('tags.store'), ['name' => 'Odd', 'permissions' => ['everything']])->assertSessionHasErrors('permissions.0');
        $this->post(route('tags.store'), ['name' => 'Odd', 'permissions' => ['tickets.delete']])->assertSessionHasErrors('permissions.0');
    }

    public function test_the_everyone_tag_applies_to_all_and_cannot_be_deleted_or_renamed(): void
    {
        $everyone = Tag::everyone();
        $newcomer = User::factory()->create();
        $this->assertTrue($newcomer->hasPermission('tickets.create'));

        $this->put(route('tags.update', $everyone), ['name' => 'Renamed', 'permissions' => ['tickets.view']])->assertSessionHasNoErrors();
        $this->assertSame('Everyone', $everyone->fresh()->name);
        $this->assertFalse($newcomer->fresh()->hasPermission('tickets.create'));

        $this->delete(route('tags.destroy', $everyone))->assertSessionHasErrors('permissions');
        $this->assertModelExists($everyone);

        $this->get(route('people.create'))->assertDontSee('value="'.$everyone->id.'"', false);
        $this->post(route('departments.store'), ['name' => 'X', 'tags' => [$everyone->id]])->assertSessionHasErrors('tags.0');
    }

    public function test_without_the_everyone_defaults_pages_and_links_are_closed(): void
    {
        Tag::everyone()->update(['permissions' => []]);
        $person = User::factory()->create(['username' => 'no.access']);
        $this->actingAs($person);

        $this->get('/')->assertOk()
            ->assertDontSee('href="'.route('tickets.index').'"', false)
            ->assertDontSee('href="'.route('tickets.create').'"', false)
            ->assertDontSee('data-new-ticket-url', false)
            ->assertDontSee('href="'.route('actions.index', $person).'"', false);
        $this->get('/ticket')->assertForbidden();
        $this->get('/ticket/create')->assertForbidden();
        $this->get('/no.access/actions')->assertForbidden();
        $this->get('/no.access/settings')->assertOk();
    }

    public function test_a_department_grants_its_tags_to_everyone_in_it(): void
    {
        $this->post(route('tags.store'), ['name' => 'Helpdesk', 'permissions' => ['tickets.view_all']])
            ->assertRedirect(route('tags.index'));
        $helpdesk = Tag::where('name', 'Helpdesk')->sole();

        $this->post(route('departments.store'), ['name' => 'IT', 'tags' => [$helpdesk->id]])->assertRedirect(route('tags.index'));
        $it = Department::where('name', 'IT')->sole();

        $sam = User::factory()->create(['name' => 'Sam Okafor']);
        $this->assertFalse($sam->hasPermission('tickets.view_all'));

        $this->put(route('people.update', $sam), ['name' => 'Sam Okafor', 'email' => $sam->email, 'department_id' => $it->id])
            ->assertSessionHasNoErrors();

        $this->assertTrue($sam->fresh()->hasPermission('tickets.view_all'));
        $this->get('/tags')->assertSeeInOrder(['Sam Okafor', 'IT', 'See everyone’s tickets']);

        $this->put(route('departments.update', $it), ['name' => 'IT', 'tags' => []]);
        $this->assertFalse($sam->fresh()->hasPermission('tickets.view_all'));
    }

    public function test_people_can_get_extra_tags_of_their_own(): void
    {
        $editors = Tag::factory()->granting('shared_actions.edit')->create(['name' => 'Action editors']);
        $sam = User::factory()->create();

        $this->put(route('people.update', $sam), ['name' => $sam->name, 'email' => $sam->email, 'tags' => [$editors->id]])
            ->assertSessionHasNoErrors();

        $this->assertTrue($sam->fresh()->hasPermission('shared_actions.edit'));
        $this->assertFalse($sam->fresh()->hasPermission('people.edit'));
    }

    public function test_adding_a_person_who_can_then_sign_in(): void
    {
        $this->post(route('people.store'), ['name' => 'Nina Park', 'email' => 'nina@fortvale.test', 'password' => 'short'])
            ->assertSessionHasErrors('password');

        $this->post(route('people.store'), ['name' => 'Nina Park', 'email' => 'nina@fortvale.test', 'job_title' => 'Planner', 'password' => 'a-good-password'])
            ->assertRedirect(route('tags.index'));

        $nina = User::where('email', 'nina@fortvale.test')->sole();
        $this->assertSame('nina.park', $nina->username);

        $this->post('/logout');
        $this->post('/login', ['email' => 'nina@fortvale.test', 'password' => 'a-good-password'])->assertRedirect('/');
        $this->assertAuthenticatedAs($nina);
    }

    public function test_editing_a_person_keeps_their_password_unless_a_new_one_is_given(): void
    {
        $sam = User::factory()->create();
        $hash = $sam->password;

        $this->put(route('people.update', $sam), ['name' => 'Sam O.', 'email' => $sam->email, 'password' => ''])->assertSessionHasNoErrors();

        $this->assertSame($hash, $sam->fresh()->password);
        $this->assertSame('Sam O.', $sam->fresh()->name);
    }

    public function test_changes_that_would_leave_nobody_able_to_edit_tags_and_people_are_refused(): void
    {
        $adminTag = $this->admin->tags()->sole();

        $this->put(route('tags.update', $adminTag), ['name' => $adminTag->name, 'permissions' => ['tags.edit']])
            ->assertSessionHasErrors('permissions');
        $this->delete(route('tags.destroy', $adminTag))->assertSessionHasErrors('permissions');
        $this->put(route('people.update', $this->admin), ['name' => 'Alex Morgan', 'email' => $this->admin->email, 'tags' => []])
            ->assertSessionHasErrors('tags');

        $this->assertTrue($this->admin->fresh()->hasPermission('people.edit'));
        $this->assertModelExists($adminTag);

        // Once someone else can edit tags and people, the first admin's tag can go.
        User::factory()->create()->tags()->attach($adminTag);
        $this->put(route('people.update', $this->admin), ['name' => 'Alex Morgan', 'email' => $this->admin->email, 'tags' => []])
            ->assertSessionHasNoErrors();
    }

    public function test_every_row_on_the_tags_page_can_be_found_by_search(): void
    {
        $helpdesk = Tag::factory()->granting('tickets.view_all')->create(['name' => 'Helpdesk']);
        $it = Department::factory()->hasAttached($helpdesk)->create(['name' => 'IT']);
        User::factory()->for($it)->create(['name' => 'Sam Okafor', 'job_title' => 'IT support']);

        $page = $this->get('/tags')
            ->assertSee('placeholder="Search tags, departments and people"', false)
            ->assertSee('No people match your search.')
            ->getContent();

        // Each row's search text includes what is shown in its columns.
        $this->assertMatchesRegularExpression('/data-search-item="Helpdesk\s.*Tickets: View, See everyone’s tickets IT"/u', $page);
        $this->assertMatchesRegularExpression('/data-search-item="IT Helpdesk Sam Okafor"/', $page);
        $this->assertMatchesRegularExpression('/data-search-item="Sam Okafor .*IT support IT\s+Tickets: .*See everyone’s tickets/u', $page);
    }
}
