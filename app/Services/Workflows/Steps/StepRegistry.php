<?php

namespace App\Services\Workflows\Steps;

/**
 * The step types actions can `use`.
 */
class StepRegistry
{
    /** @var array<string, class-string<Step>> */
    public const STEPS = [
        'ticket/create' => TicketCreateStep::class,
        'ticket/update' => TicketUpdateStep::class,
        'ticket/reply' => TicketReplyStep::class,
        'http/request' => HttpRequestStep::class,
        'email/send' => EmailSendStep::class,
        'file/save' => FileSaveStep::class,
        'message/show' => MessageShowStep::class,
        'page/open' => PageOpenStep::class,
        'ticket/open-form' => TicketOpenFormStep::class,
    ];

    public function has(string $uses): bool
    {
        return isset(self::STEPS[$uses]);
    }

    public function get(string $uses): Step
    {
        return app(self::STEPS[$uses]);
    }
}
