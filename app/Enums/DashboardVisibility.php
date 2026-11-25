<?php

namespace App\Enums;

enum DashboardVisibility: string
{
    case Personal = 'personal';
    case Shared = 'shared';

    public function label(): string
    {
        return match ($this) {
            self::Personal => 'Only me',
            self::Shared => 'Everyone',
        };
    }
}
