<?php

namespace App\Ai\Skills;

use App\Models\StoredFile;
use App\Models\Ticket;

/**
 * How skills describe Fort Vale records to an AI: the same shape everywhere.
 */
final class Present
{
    /**
     * @return array<string, mixed>
     */
    public static function ticket(Ticket $ticket): array
    {
        return [
            'id' => $ticket->id,
            'subject' => $ticket->subject,
            'status' => $ticket->status->value,
            'priority' => $ticket->priority->value,
            'category' => $ticket->category,
            'requester' => $ticket->requester?->name,
            'assignee' => $ticket->assignee?->name,
            'updated' => $ticket->updated_at?->toIso8601String(),
            'url' => route('tickets.show', $ticket),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function file(StoredFile $file): array
    {
        return [
            'id' => $file->id,
            'name' => $file->name,
            'folder' => $file->folder?->path(),
            'size' => $file->size,
            'type' => $file->mime,
            'updated' => $file->updated_at?->toIso8601String(),
        ];
    }
}
