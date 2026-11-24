<?php

use App\Services\Workflows\WorkflowScheduler;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Actions with `on: schedule` (cron). Needs `php artisan schedule:work` running.
Schedule::call(fn () => app(WorkflowScheduler::class)->runDue(now()))
    ->everyMinute()
    ->name('fortvale-scheduled-actions')
    ->withoutOverlapping();

// Files that have been in the trash for 30 days are deleted for good.
Schedule::command('files:empty-trash')->daily()->name('fortvale-empty-file-trash');
