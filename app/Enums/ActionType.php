<?php

namespace App\Enums;

enum ActionType: string
{
    case Link = 'link';
    case Ticket = 'ticket';

    public function label(): string
    {
        return match ($this) {
            self::Link => 'Open a link',
            self::Ticket => 'Start a ticket',
        };
    }

    public function hint(): string
    {
        return match ($this) {
            self::Link => 'Go to a page in Fort Vale (like /ticket?priority=urgent) or a website.',
            self::Ticket => 'Open the new ticket form already filled in from a template.',
        };
    }
}
