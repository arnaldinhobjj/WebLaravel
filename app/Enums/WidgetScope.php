<?php

namespace App\Enums;

/**
 * Whose tickets a widget counts. "Mine" is worked out for whoever is looking,
 * so the same shared dashboard shows each person their own numbers.
 */
enum WidgetScope: string
{
    case Everyone = 'everyone';
    case Mine = 'mine';

    public function label(): string
    {
        return match ($this) {
            self::Everyone => 'Everyone’s tickets',
            self::Mine => 'Just mine',
        };
    }
}
