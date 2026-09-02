<?php

use App\Domains\Accounts\Models\CompanySetting;
use App\Domains\Sales\Application\RecurringInvoiceService;
use App\Domains\Sales\Models\RecurringInvoice;
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

    $recurringInvoices = RecurringInvoice::where('status', 'ACTIVE')->get();
    foreach ($recurringInvoices as $recurringInvoice) {
        $timeZone = CompanySetting::getSetting('time_zone', $recurringInvoice->company_id);

        Schedule::call(function () use ($recurringInvoice) {
            app(RecurringInvoiceService::class)->generateInvoice($recurringInvoice);
        })->cron($recurringInvoice->frequency)->timezone($timeZone);
    }
}
