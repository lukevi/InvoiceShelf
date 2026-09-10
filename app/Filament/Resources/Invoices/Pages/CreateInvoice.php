<?php

namespace App\Filament\Resources\Invoices\Pages;

use App\Domains\Sales\Application\InvoiceService;
use App\Domains\Sales\Models\Invoice;
use App\Filament\Resources\Invoices\InvoiceResource;
use App\Filament\Support\CurrentCompany;
use App\Filament\Support\InvoiceLineItemCalculator;
use App\Filament\Support\NextInvoiceNumber;
use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Enums\Width;

class CreateInvoice extends CreateRecord
{
    protected static string $resource = InvoiceResource::class;

    protected Width|string|null $maxContentWidth = Width::Full;

    /**
     * Columns the curated form leaves out because they are either managed by
     * {@see InvoiceService} on the real creation path, or not meaningful for a
     * document raised by hand here.
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['company_id'] = CurrentCompany::id();
        $data['type'] = Invoice::TYPE_INVOICE;
        $data['tax_per_item'] = 'NO';
        $data['discount_per_item'] = 'NO';
        $data['sent'] = false;
        $data['viewed'] = false;
        $data['status'] = Invoice::STATUS_DRAFT;
        $data['paid_status'] = Invoice::STATUS_UNPAID;

        // The repeater's items never dehydrate into $data (it saves itself via
        // its own relationship hooks after the record exists), so its raw,
        // still-untrusted rows have to be read straight off the form to total
        // the document the same way the disabled Totals fields previewed it.
        $items = $this->form->getRawState()['items'] ?? [];

        return array_merge(
            $data,
            NextInvoiceNumber::resolve($data['company_id'], $data['customer_id'] ?? null),
            InvoiceLineItemCalculator::invoiceTotals($items),
        );
    }
}
