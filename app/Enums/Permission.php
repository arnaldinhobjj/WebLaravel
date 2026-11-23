<?php

namespace App\Enums;

/**
 * What a tag can allow. Tags are given to people directly or through their department.
 */
enum Permission: string
{
    case ManagePeople = 'people.manage';
    case EditSharedActions = 'actions.shared.edit';
    case ViewAllTickets = 'tickets.view_all';

    public function label(): string
    {
        return match ($this) {
            self::ManagePeople => 'Manage tags and people',
            self::EditSharedActions => 'Edit shared actions',
            self::ViewAllTickets => 'See and work on every ticket',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::ManagePeople => 'Open the Tags page; add people and change their department, tags and password.',
            self::EditSharedActions => 'Save the shared action files (actions/*.yml) from the browser.',
            self::ViewAllTickets => 'Without it, people only see tickets they opened or are assigned to.',
        };
    }
}
