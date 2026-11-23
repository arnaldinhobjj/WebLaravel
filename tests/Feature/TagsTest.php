<?php

namespace Tests\Feature;

use App\Enums\Permission;
use App\Models\Department;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TagsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->withPermissions(Permission::ManagePeople)->create(['name' => 'Alex Morgan']);
        $this->actingAs($this->admin);
    }

    public function test_only_people_who_can_manage_people_open_the_tags_page(): void
    {
        $this->get('/tags')->assertOk()->assertSee('Tags');
        $this->get('/')->assertSee(route('tags.index'));

        $this->actingAs(User::factory()->create());
        $this->get('/tags')->assertForbidden();
        $this->get(route('people.create'))->assertForbidden();
        $this->post(route('tags.store'), ['name' => 'Sneaky', 'permissions' => [Permission::ManagePeople->value]])->assertForbidden();
        $this->get('/')->assertDontSee(route('tags.index'));
        $this->assertDatabaseMissing('tags', ['name' => 'Sneaky']);
    }

    public function test_a_department_grants_its_tags_to_everyone_in_it(): void
    {
        $this->post(route('tags.store'), ['name' => 'Helpdesk', 'permissions' => [Permission::ViewAllTickets->value]])
            ->assertRedirect(route('tags.index'));
        $helpdesk = Tag::where('name', 'Helpdesk')->sole();
        $this->assertSame([Permission::ViewAllTickets->value], $helpdesk->permissions);

        $this->post(route('departments.store'), ['name' => 'IT', 'tags' => [$helpdesk->id]])->assertRedirect(route('tags.index'));
        $it = Department::where('name', 'IT')->sole();

        $sam = User::factory()->create(['name' => 'Sam Okafor']);
        $this->assertFalse($sam->hasPermission(Permission::ViewAllTickets));

        $this->put(route('people.update', $sam), ['name' => 'Sam Okafor', 'email' => $sam->email, 'department_id' => $it->id])
            ->assertSessionHasNoErrors();

        $this->assertTrue($sam->fresh()->hasPermission(Permission::ViewAllTickets));
        $this->get('/tags')->assertSeeInOrder(['Sam Okafor', 'IT', 'See and work on every ticket']);

        $this->put(route('departments.update', $it), ['name' => 'IT', 'tags' => []]);
        $this->assertFalse($sam->fresh()->hasPermission(Permission::ViewAllTickets));
    }

    public function test_people_can_get_extra_tags_of_their_own(): void
    {
        $editors = Tag::factory()->granting(Permission::EditSharedActions)->create(['name' => 'Action editors']);
        $sam = User::factory()->create();

        $this->put(route('people.update', $sam), ['name' => $sam->name, 'email' => $sam->email, 'tags' => [$editors->id]])
            ->assertSessionHasNoErrors();

        $this->assertTrue($sam->fresh()->hasPermission(Permission::EditSharedActions));
        $this->assertFalse($sam->fresh()->hasPermission(Permission::ManagePeople));
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

    public function test_changes_that_would_leave_nobody_able_to_manage_people_are_refused(): void
    {
        $adminTag = $this->admin->tags()->sole();

        $this->put(route('tags.update', $adminTag), ['name' => $adminTag->name, 'permissions' => []])
            ->assertSessionHasErrors('permissions');
        $this->delete(route('tags.destroy', $adminTag))->assertSessionHasErrors('permissions');
        $this->put(route('people.update', $this->admin), ['name' => 'Alex Morgan', 'email' => $this->admin->email, 'tags' => []])
            ->assertSessionHasErrors('tags');

        $this->assertTrue($this->admin->fresh()->hasPermission(Permission::ManagePeople));
        $this->assertModelExists($adminTag);

        // Once someone else can manage people, the first admin's tag can go.
        User::factory()->create()->tags()->attach($adminTag);
        $this->put(route('people.update', $this->admin), ['name' => 'Alex Morgan', 'email' => $this->admin->email, 'tags' => []])
            ->assertSessionHasNoErrors();
    }

    public function test_every_row_on_the_tags_page_can_be_found_by_search(): void
    {
        $helpdesk = Tag::factory()->granting(Permission::ViewAllTickets)->create(['name' => 'Helpdesk']);
        $it = Department::factory()->hasAttached($helpdesk)->create(['name' => 'IT']);
        User::factory()->for($it)->create(['name' => 'Sam Okafor', 'job_title' => 'IT support']);

        $page = $this->get('/tags')
            ->assertSee('placeholder="Search tags, departments and people"', false)
            ->assertSee('No people match your search.')
            ->getContent();

        // Each row's search text includes what is shown in its columns.
        $this->assertMatchesRegularExpression('/data-search-item="Helpdesk\s.*See and work on every ticket IT"/', $page);
        $this->assertMatchesRegularExpression('/data-search-item="IT Helpdesk Sam Okafor"/', $page);
        $this->assertMatchesRegularExpression('/data-search-item="Sam Okafor .*IT support IT\s+See and work on every ticket"/', $page);
    }

    public function test_tag_permissions_must_be_known_ones(): void
    {
        $this->post(route('tags.store'), ['name' => 'Odd', 'permissions' => ['everything']])->assertSessionHasErrors('permissions.0');
    }
}
