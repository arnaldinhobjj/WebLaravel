<?php

namespace Tests\Feature\Console;

use App\Models\Ticket;
use Illuminate\Database\Console\Migrations\FreshCommand;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Prompts\Prompt;
use Tests\TestCase;

class FreshCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_declining_in_production_keeps_the_tickets_database(): void
    {
        $ticket = Ticket::factory()->create();
        $this->app['env'] = 'production';
        // Outside the testing environment Laravel stops routing prompts through
        // the testable fallback; keep it on so the confirmation can be answered.
        Prompt::fallbackWhen(true);

        $this->artisan('migrate:fresh')
            ->expectsConfirmation('Are you sure you want to run this command?', 'no')
            ->assertFailed();

        $this->assertModelExists($ticket);
    }

    public function test_is_prohibited_like_laravels_command(): void
    {
        $ticket = Ticket::factory()->create();
        FreshCommand::prohibit();

        try {
            $this->artisan('migrate:fresh', ['--force' => true])->assertFailed();
        } finally {
            FreshCommand::prohibit(false);
        }

        $this->assertModelExists($ticket);
    }
}
