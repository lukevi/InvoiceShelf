<?php

namespace App\Domains\Sales\Application;

use App\Domains\Accounts\Models\Company;
use App\Domains\Accounts\Models\CompanySetting;
use App\Domains\Contacts\Models\Customer;
use App\Domains\Metadata\Contracts\CustomFieldValueWriter;
use App\Domains\Sales\Contracts\DocumentExchangeRateRecorder;
use App\Domains\Sales\Models\Invoice;
use App\Domains\Sales\Models\RecurringInvoice;
use App\Facades\Hashids;
use App\Support\Hashids\HashidConnection;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class RecurringInvoiceService
{
    /**
     * Ceiling on how many invoices a single scheduler tick will mint, across
     * every schedule together. Bounds the damage a stuck or misfiring
     * scheduler can do in one run.
     */
    private const MAX_INVOICES_PER_RUN = 100;

    /**
     * Ceiling on how many invoices one schedule can catch up on in a single
     * scheduler tick, so one long-overdue schedule cannot starve every other
     * schedule out of MAX_INVOICES_PER_RUN.
     */
    private const MAX_INVOICES_PER_SCHEDULE = 10;

    public function __construct(
        private readonly DocumentItemService $documentItemService,
        private readonly InvoiceService $invoiceService,
        private readonly CustomFieldValueWriter $customFieldValueWriter,
        private readonly DocumentExchangeRateRecorder $exchangeRateRecorder,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     * @param  array<int, array<string, mixed>>  $items
     * @param  array<int, array<string, mixed>>|null  $taxes
     */
    public function create(
        array $attributes,
        array $items,
        ?array $taxes = null,
        ?iterable $customFields = null,
    ): RecurringInvoice {
        $recurringInvoice = RecurringInvoice::create($attributes);

        $companyCurrency = CompanySetting::getSetting('currency', $recurringInvoice->company_id);

        if ((string) $recurringInvoice['currency_id'] !== $companyCurrency) {
            $this->exchangeRateRecorder->record($recurringInvoice);
        }

        $this->createItems($recurringInvoice, $items);

        if ($taxes) {
            $this->createTaxes($recurringInvoice, $taxes);
        }

        if ($customFields) {
            $this->customFieldValueWriter->attach($recurringInvoice, $customFields);
        }

        return $recurringInvoice;
    }

    public function update(
        RecurringInvoice $recurringInvoice,
        array $attributes,
        array $items,
        ?array $taxes = null,
        ?iterable $customFields = null,
    ): RecurringInvoice {
        $recurringInvoice->update($attributes);

        $companyCurrency = CompanySetting::getSetting('currency', $recurringInvoice->company_id);

        if ((string) $attributes['currency_id'] !== $companyCurrency) {
            $this->exchangeRateRecorder->record($recurringInvoice);
        }

        $recurringInvoice->items()->delete();
        $this->createItems($recurringInvoice, $items);

        $recurringInvoice->taxes()->delete();
        if ($taxes) {
            $this->createTaxes($recurringInvoice, $taxes);
        }

        if ($customFields) {
            $this->customFieldValueWriter->update($recurringInvoice, $customFields);
        }

        return $recurringInvoice;
    }

    public function delete(Collection $ids): bool
    {
        foreach ($ids as $id) {
            $recurringInvoice = RecurringInvoice::find($id);

            // Invoices already generated outlive their template; all they lose
            // is the link back to it.
            $generated = $recurringInvoice->invoices();

            if ($generated->exists()) {
                $generated->update(['recurring_invoice_id' => null]);
            }

            $lineItems = $recurringInvoice->items();

            if ($lineItems->exists()) {
                $lineItems->delete();
            }

            if ($recurringInvoice->taxes()->exists()) {
                $recurringInvoice->taxes()->delete();
            }

            $recurringInvoice->delete();
        }

        return true;
    }

    /**
     * Mint every invoice currently due across every active schedule.
     *
     * Callers (the scheduler command, in practice) may fire this more than
     * once for the same due window — an overlapping run, a retried job, more
     * than one app instance each running its own scheduler. Each schedule is
     * re-read and row-locked immediately before it is judged due, so a
     * concurrent caller sees the advanced `next_invoice_at` and skips it
     * rather than minting a second invoice for the same occurrence.
     */
    public function generateDueInvoices(): int
    {
        $now = Carbon::now()->format('Y-m-d H:i:s');

        $dueIds = RecurringInvoice::where('status', RecurringInvoice::ACTIVE)
            ->whereNotNull('next_invoice_at')
            ->where('next_invoice_at', '<=', $now)
            ->orderBy('next_invoice_at')
            ->orderBy('id')
            ->pluck('id');

        $generated = 0;

        // Sweep the due set in rounds instead of draining one schedule at a
        // time, so a schedule with many overdue occurrences to catch up on
        // cannot starve the others out of this run's budget.
        for ($round = 0; $round < self::MAX_INVOICES_PER_SCHEDULE && $generated < self::MAX_INVOICES_PER_RUN; $round++) {
            $progressed = false;

            foreach ($dueIds as $id) {
                if ($generated >= self::MAX_INVOICES_PER_RUN) {
                    break 2;
                }

                try {
                    if ($this->generateDueInvoice((int) $id)) {
                        $generated++;
                        $progressed = true;
                    }
                } catch (Throwable $exception) {
                    Log::error('Unable to generate recurring invoice.', [
                        'recurring_invoice_id' => $id,
                        'exception' => $exception,
                    ]);
                }
            }

            if (! $progressed) {
                break;
            }
        }

        return $generated;
    }

    /**
     * Row-lock, re-check, and generate a single schedule's due invoice.
     *
     * The whole check-then-act sequence runs inside the row lock so two
     * concurrent callers can never both see the schedule as due.
     */
    private function generateDueInvoice(int $recurringInvoiceId): bool
    {
        return DB::transaction(function () use ($recurringInvoiceId) {
            $recurringInvoice = RecurringInvoice::query()
                ->whereKey($recurringInvoiceId)
                ->lockForUpdate()
                ->first();

            if (! $recurringInvoice || ! $this->isDue($recurringInvoice)) {
                return false;
            }

            $this->generateInvoice($recurringInvoice);

            return true;
        });
    }

    private function isDue(RecurringInvoice $recurringInvoice): bool
    {
        return $recurringInvoice->status === RecurringInvoice::ACTIVE
            && $recurringInvoice->next_invoice_at
            && Carbon::now()->greaterThanOrEqualTo($recurringInvoice->next_invoice_at);
    }

    public function generateInvoice(RecurringInvoice $recurringInvoice): void
    {
        if (Carbon::now()->lessThan($recurringInvoice->starts_at)) {
            return;
        }

        // A schedule with no next-due date yet, or whose next-due date has not
        // arrived, mints nothing. Without this guard any repeated call —
        // an overlapping scheduler tick, a retry, more than one app instance —
        // would mint another invoice unconditionally.
        if (! $recurringInvoice->next_invoice_at
            || Carbon::now()->lessThan($recurringInvoice->next_invoice_at)) {
            return;
        }

        if ($recurringInvoice->limit_by == 'DATE') {
            $startDate = Carbon::today()->format('Y-m-d');
            $endDate = $recurringInvoice->limit_date;

            if ($endDate >= $startDate) {
                $this->createInvoiceFromRecurring($recurringInvoice);
                $recurringInvoice->updateNextInvoiceDate();
            } else {
                $recurringInvoice->markStatusAsCompleted();
            }
        } elseif ($recurringInvoice->limit_by == 'COUNT') {
            $invoiceCount = Invoice::where('recurring_invoice_id', $recurringInvoice->id)->count();

            if ($invoiceCount < $recurringInvoice->limit_count) {
                $this->createInvoiceFromRecurring($recurringInvoice);
                $recurringInvoice->updateNextInvoiceDate();
            } else {
                $recurringInvoice->markStatusAsCompleted();
            }
        } else {
            $this->createInvoiceFromRecurring($recurringInvoice);
            $recurringInvoice->updateNextInvoiceDate();
        }
    }

    private function createInvoiceFromRecurring(RecurringInvoice $recurringInvoice): void
    {
        $serial = (new SerialNumberService)
            ->setModel(new Invoice)
            ->setCompany($recurringInvoice->company_id)
            ->setCustomer($recurringInvoice->customer_id)
            ->setSequenceScope(['type' => Invoice::TYPE_INVOICE])
            ->setNextNumbers();

        $days = intval(CompanySetting::getSetting('invoice_due_date_days', $recurringInvoice->company_id));

        if (! $days || $days == 'null') {
            $days = 7;
        }

        $newInvoice['creator_id'] = $recurringInvoice->creator_id;
        $newInvoice['invoice_date'] = Carbon::today()->toDateString();
        $newInvoice['due_date'] = Carbon::today()->addDays($days)->toDateString();
        $newInvoice['status'] = Invoice::STATUS_DRAFT;
        $newInvoice['company_id'] = $recurringInvoice->company_id;
        $newInvoice['paid_status'] = Invoice::STATUS_UNPAID;
        $newInvoice['sub_total'] = $recurringInvoice->sub_total;
        $newInvoice['tax_per_item'] = $recurringInvoice->tax_per_item;
        $newInvoice['tax_included'] = $recurringInvoice->tax_included;
        $newInvoice['discount_per_item'] = $recurringInvoice->discount_per_item;
        $newInvoice['tax'] = $recurringInvoice->tax;
        $newInvoice['total'] = $recurringInvoice->total;
        $newInvoice['customer_id'] = $recurringInvoice->customer_id;
        $newInvoice['currency_id'] = Customer::find($recurringInvoice->customer_id)->currency_id;
        $newInvoice['template_name'] = $recurringInvoice->template_name;
        $newInvoice['due_amount'] = $recurringInvoice->total;
        $newInvoice['recurring_invoice_id'] = $recurringInvoice->id;
        $newInvoice['discount_val'] = $recurringInvoice->discount_val;
        $newInvoice['discount'] = $recurringInvoice->discount;
        $newInvoice['discount_type'] = $recurringInvoice->discount_type;
        $newInvoice['notes'] = $recurringInvoice->notes;
        $newInvoice['exchange_rate'] = $recurringInvoice->exchange_rate;
        $newInvoice['sales_tax_type'] = $recurringInvoice->sales_tax_type;
        $newInvoice['sales_tax_address_type'] = $recurringInvoice->sales_tax_address_type;
        $newInvoice['base_due_amount'] = $recurringInvoice->exchange_rate * $recurringInvoice->due_amount;
        $newInvoice['base_discount_val'] = $recurringInvoice->exchange_rate * $recurringInvoice->discount_val;
        $newInvoice['base_sub_total'] = $recurringInvoice->exchange_rate * $recurringInvoice->sub_total;
        $newInvoice['base_tax'] = $recurringInvoice->exchange_rate * $recurringInvoice->tax;
        $newInvoice['base_total'] = $recurringInvoice->exchange_rate * $recurringInvoice->total;

        // Stamped last: the visible number is rendered from a format that may
        // embed either of the two sequences.
        $newInvoice += [
            'invoice_number' => $serial->getNextNumber(),
            'sequence_number' => $serial->nextSequenceNumber,
            'customer_sequence_number' => $serial->nextCustomerSequenceNumber,
        ];

        $invoice = Invoice::create($newInvoice);
        $invoice->unique_hash = Hashids::connection(HashidConnection::Invoice->value)->encode($invoice->id);
        $invoice->save();

        $recurringInvoice->load('items.taxes');
        $this->documentItemService->createItems($invoice, $recurringInvoice->items->toArray());

        if ($recurringInvoice->taxes()->exists()) {
            $this->documentItemService->createTaxes($invoice, $recurringInvoice->taxes->toArray());
        }

        if ($recurringInvoice->fields()->exists()) {
            $customField = [];

            foreach ($recurringInvoice->fields as $answer) {
                $customField[] = ['id' => $answer->custom_field_id, 'value' => $answer->defaultAnswer];
            }

            $this->customFieldValueWriter->attach($invoice, $customField);
        }

        if ($recurringInvoice->send_automatically == true) {
            $customer = $invoice->customer;

            $data = [
                'body' => CompanySetting::getSetting('invoice_mail_body', $recurringInvoice->company_id),
                'from' => config('mail.from.address'),
                'to' => $recurringInvoice->customer->email,
                'subject' => trans('invoices')['new_invoice'],
                'invoice' => $invoice->toArray(),
                'customer' => $customer->toArray(),
                'company' => Company::find($invoice->company_id),
            ];

            $this->invoiceService->send($invoice, $data);
        }
    }

    private function createItems(RecurringInvoice $recurringInvoice, array $items): void
    {
        foreach ($items as $item) {
            $item['company_id'] = $recurringInvoice->company_id;
            $createdItem = $recurringInvoice->items()->create($item);
            if (array_key_exists('taxes', $item) && $item['taxes']) {
                foreach ($item['taxes'] as $tax) {
                    if (empty($tax['tax_type_id'])) {
                        continue;
                    }

                    $tax['company_id'] = $recurringInvoice->company_id;
                    if (gettype($tax['amount']) !== 'NULL') {
                        $createdItem->taxes()->create($tax);
                    }
                }
            }
        }
    }

    /**
     * Write the template's own tax rows, skipping the ones carrying no amount.
     */
    private function createTaxes(RecurringInvoice $recurringInvoice, array $taxes): void
    {
        foreach ($taxes as $tax) {
            if (gettype($tax['amount']) !== 'NULL') {
                $tax['company_id'] = $recurringInvoice->company_id;
                $recurringInvoice->taxes()->create($tax);
            }
        }
    }
}
