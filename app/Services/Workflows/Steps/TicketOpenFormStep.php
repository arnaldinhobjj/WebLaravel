<?php

namespace App\Services\Workflows\Steps;

use App\Enums\TicketPriority;
use App\Models\Ticket;
use App\Services\Workflows\RunContext;

/**
 * `ticket/open-form`: opens the new ticket form filled in with these values,
 * at the end of a Run-button run.
 */
class TicketOpenFormStep extends BaseStep
{
    protected function keys(): array
    {
        return ['subject' => false, 'description' => false, 'category' => false, 'priority' => false];
    }

    protected function checks(array $with, string $path): array
    {
        return [
            ...$this->plainText($with, 'subject', $path, 160),
            ...$this->plainText($with, 'description', $path, 5000),
            ...$this->oneOf($with, 'category', array_keys(Ticket::CATEGORIES), $path),
            ...$this->oneOf($with, 'priority', array_column(TicketPriority::cases(), 'value'), $path),
        ];
    }

    public function run(array $with, RunContext $context): array
    {
        $context->redirect = route('tickets.create', array_filter(
            array_map(fn (mixed $value): string => (string) $value, $with),
            fn (string $value): bool => $value !== '',
        ));

        return [];
    }

    public function redirects(): bool
    {
        return true;
    }
}
