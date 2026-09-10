<?php

namespace App\Domains\Sales\Console;

use App\Domains\Sales\Application\RecurringInvoiceService;
use Illuminate\Console\Command;

/**
 * The scheduler's single entry point into recurring-invoice generation.
 *
 * Every active schedule is judged and, if due, minted from here — nothing
 * else registers a per-schedule cron entry of its own. That used to be how
 * this worked (one `Schedule::call()` per row, matched against the row's own
 * cron expression) and it made every generated invoice as fragile as the
 * schedule check it read: fire the scheduler more than once for the same due
 * minute — an overlapping run, more than one app instance each cron-ing on
 * its own — and each firing minted another invoice, unbounded. Routing
 * everything through one command run under `withoutOverlapping()` closes
 * that door; the per-row due check itself lives in
 * RecurringInvoiceService::generateDueInvoices().
 */
class GenerateRecurringInvoices extends Command
{
    protected $signature = 'recurring-invoices:generate';

    protected $description = 'Generate invoices for every recurring invoice schedule that is currently due.';

    public function handle(RecurringInvoiceService $recurringInvoiceService): int
    {
        $generated = $recurringInvoiceService->generateDueInvoices();

        $this->info("Generated {$generated} recurring invoice(s).");

        return self::SUCCESS;
    }
}
