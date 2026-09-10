<?php

use App\Platform\Operations\Installation\Application\InstallationState;
use Illuminate\Support\Facades\Schedule;

// Only run in demo environment
if (config('app.env') === 'demo') {
    Schedule::command('reset:app --force')
        ->daily()
        ->runInBackground()
        ->withoutOverlapping();
}

if (InstallationState::isDbCreated()) {
    Schedule::command('check:invoices:status')
        ->daily();

    Schedule::command('check:estimates:status')
        ->daily();

    Schedule::command('backup:clean')
        ->daily()
        ->at('01:00')
        ->withoutOverlapping();

    Schedule::command('backup:run')
        ->daily()
        ->at('01:30')
        ->withoutOverlapping();

    Schedule::command('recurring-invoices:generate')
        ->everyMinute()
        ->withoutOverlapping();
}
