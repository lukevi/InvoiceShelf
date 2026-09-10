<?php

namespace App\Domains\Sales\Console;

use App\Domains\Sales\Models\Invoice;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * One-off remediation for the misfiring-schedule bug (see
 * RecurringInvoiceService::generateDueInvoices()): a schedule whose frequency
 * was wrong, or whose scheduler ran without the due-date guard, could mint an
 * invoice on every tick instead of on its real cadence.
 *
 * This only ever removes invoices that could not possibly be real business
 * records — still a draft, never marked paid, and with no payment allocation
 * pointed at them — so a schedule that had already produced legitimate,
 * finalized invoices before the misfire is left untouched beyond the excess.
 * Dry-run by default; pass --force to actually delete.
 */
class CleanupRunawayRecurringInvoices extends Command
{
    protected $signature = 'recurring-invoices:cleanup-runaway
        {recurring_invoice_id : ID of the recurring_invoices row that over-generated}
        {--force : Actually delete. Without this, the command only reports what it would remove.}';

    protected $description = 'Delete the untouched draft invoices a misfiring recurring invoice schedule generated.';

    public function handle(): int
    {
        $recurringInvoiceId = (int) $this->argument('recurring_invoice_id');

        $candidates = Invoice::query()
            ->where('recurring_invoice_id', $recurringInvoiceId)
            ->where('status', Invoice::STATUS_DRAFT)
            ->where('paid_status', Invoice::STATUS_UNPAID)
            ->whereDoesntHave('allocations');

        $count = $candidates->count();

        if ($count === 0) {
            $this->info("No removable duplicate invoices found for recurring invoice #{$recurringInvoiceId}.");

            return self::SUCCESS;
        }

        $this->info("{$count} draft, unpaid, unallocated invoice(s) found for recurring invoice #{$recurringInvoiceId}.");

        if (! $this->option('force')) {
            $this->warn('Dry run only — re-run with --force to delete them.');

            return self::SUCCESS;
        }

        $deleted = 0;

        // Delete in bounded batches rather than one statement, so a very large
        // backlog does not hold a single long-running transaction/lock.
        DB::transaction(function () use ($candidates, &$deleted) {
            $candidates->clone()->orderBy('id')->chunkById(500, function ($invoices) use (&$deleted) {
                foreach ($invoices as $invoice) {
                    $invoice->delete();
                    $deleted++;
                }
            });
        });

        $this->info("Deleted {$deleted} duplicate invoice(s) for recurring invoice #{$recurringInvoiceId}.");

        return self::SUCCESS;
    }
}
