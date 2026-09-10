<?php

namespace App\Filament\Support;

use App\Domains\Sales\Application\SerialNumberService;
use App\Domains\Sales\Models\Invoice;

/**
 * Renders the number the next invoice raised through this panel would carry,
 * using the same per-company format the rest of the app numbers invoices with.
 */
class NextInvoiceNumber
{
    /**
     * A preview for the create form, rendered before a customer has been
     * chosen so it can only use the company-wide sequence.
     */
    public static function preview(): ?string
    {
        $companyId = CurrentCompany::id();

        if ($companyId === null) {
            return null;
        }

        return self::serial($companyId)->getNextNumber();
    }

    /**
     * The authoritative number and sequences, resolved again at submit time so
     * they reflect whatever has been created since the form was opened, with
     * the customer-scoped sequence taken into account when the format uses one.
     *
     * @return array{invoice_number: string, sequence_number: int, customer_sequence_number: ?int}
     */
    public static function resolve(int $companyId, ?int $customerId): array
    {
        $serial = self::serial($companyId)->setCustomer($customerId);

        return [
            'invoice_number' => $serial->getNextNumber(),
            'sequence_number' => $serial->nextSequenceNumber,
            'customer_sequence_number' => $serial->nextCustomerSequenceNumber,
        ];
    }

    private static function serial(int $companyId): SerialNumberService
    {
        return (new SerialNumberService)
            ->setModel(new Invoice)
            ->setCompany($companyId)
            ->setSequenceScope(['type' => Invoice::TYPE_INVOICE]);
    }
}
