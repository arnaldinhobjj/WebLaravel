<?php

namespace App\Console\Commands;

use App\Services\Files\FileStore;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('files:empty-trash {--days='.FileStore::TRASH_DAYS.' : Delete items that have been in the trash at least this many days}')]
#[Description('Permanently deletes files and folders that have been in the trash too long, including their stored content')]
class EmptyFileTrash extends Command
{
    public function handle(FileStore $store): int
    {
        $removed = $store->purge((int) $this->option('days'));
        $this->info("Deleted {$removed} ".str('file')->plural($removed).' for good.');

        return self::SUCCESS;
    }
}
