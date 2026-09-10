<?php

namespace App\Filament\Support;

use App\Support\DocumentTotals;

/**
 * Live-calculates the numbers this panel no longer lets an admin type
 * directly: a repeater row's discount/total, and the invoice's own totals
 * rolled up from its rows.
 *
 * The subtotal-minus-discount half of each calculation is delegated to
 * {@see DocumentTotals::itemTotal()} — the same authoritative, trusted
 * function `DocumentItemService` recomputes server-side from on the real
 * invoicing path (see its GHSA-8c69 note) — so a row's total here can never
 * drift from how the rest of the app would total the same row. Per-item tax
 * is treated as a flat entered amount rather than routed through the
 * per-tax-type `taxes` table the full invoicing engine supports; that fuller
 * system is out of scope for this admin panel.
 */
class InvoiceLineItemCalculator
{
    /**
     * @param  array<string, mixed>  $item
     */
    public static function discountValue(array $item): int
    {
        $price = (float) ($item['price'] ?? 0);
        $quantity = (float) ($item['quantity'] ?? 0);
        $discount = (float) ($item['discount'] ?? 0);

        if (($item['discount_type'] ?? 'fixed') === 'percentage') {
            return (int) round($price * $quantity * $discount / 100);
        }

        return (int) round($discount);
    }

    /**
     * @param  array<string, mixed>  $item
     */
    public static function lineTotal(array $item): int
    {
        $item['discount_val'] = self::discountValue($item);
        $tax = (int) round((float) ($item['tax'] ?? 0));

        return DocumentTotals::itemTotal($item, perItemDiscount: true) + $tax;
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @return array{sub_total: int, tax: int, total: int, due_amount: int}
     */
    public static function invoiceTotals(array $items): array
    {
        $subTotal = 0;
        $tax = 0;

        foreach ($items as $item) {
            $item['discount_val'] = self::discountValue($item);
            $subTotal += DocumentTotals::itemTotal($item, perItemDiscount: true);
            $tax += (int) round((float) ($item['tax'] ?? 0));
        }

        $total = $subTotal + $tax;

        return [
            'sub_total' => $subTotal,
            'tax' => $tax,
            'total' => $total,
            'due_amount' => $total,
        ];
    }
}
