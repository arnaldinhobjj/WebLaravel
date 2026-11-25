<?php

namespace App\Enums;

/**
 * How much of a dashboard row a widget takes.
 */
enum WidgetSize: string
{
    case Normal = 'normal';
    case Wide = 'wide';

    public function label(): string
    {
        return match ($this) {
            self::Normal => 'Half width',
            self::Wide => 'Full width',
        };
    }
}
