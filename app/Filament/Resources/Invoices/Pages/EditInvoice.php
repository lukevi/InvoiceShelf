<?php

namespace App\Filament\Resources\Invoices\Pages;

use App\Domains\Sales\Models\Invoice;
use App\Filament\Resources\Invoices\InvoiceResource;
use App\Filament\Support\InvoiceLineItemCalculator;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Enums\Width;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;

class EditInvoice extends EditRecord
{
    protected static string $resource = InvoiceResource::class;

    protected Width|string|null $maxContentWidth = Width::Full;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }

    /**
     * Status and paid status aren't form fields here (see InvoiceForm), so
     * they're surfaced as read-only badges on the title instead.
     */
    public function getTitle(): string|Htmlable
    {
        $record = $this->getRecord();

        return new HtmlString(
            e($this->getRecordTitle()).' '.Blade::render(<<<'BLADE'
                <x-filament::badge :color="$statusColor">{{ $status }}</x-filament::badge>
                <x-filament::badge :color="$paidStatusColor">{{ $paidStatus }}</x-filament::badge>
                BLADE, [
                'status' => self::humanize($record->status),
                'statusColor' => match ($record->status) {
                    Invoice::STATUS_DRAFT => 'gray',
                    Invoice::STATUS_SENT => 'info',
                    Invoice::STATUS_VIEWED => 'warning',
                    Invoice::STATUS_COMPLETED => 'success',
                    default => 'gray',
                },
                'paidStatus' => self::humanize($record->paid_status),
                'paidStatusColor' => match ($record->paid_status) {
                    Invoice::STATUS_UNPAID => 'danger',
                    Invoice::STATUS_PARTIALLY_PAID => 'warning',
                    Invoice::STATUS_PAID => 'success',
                    default => 'gray',
                },
            ])
        );
    }

    /**
     * The repeater's items never dehydrate into $data — see the matching note
     * in CreateInvoice — so the totals are recomputed from the form's raw
     * state the same way, in case a row's price/quantity/discount changed.
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $items = $this->form->getRawState()['items'] ?? [];

        return array_merge($data, InvoiceLineItemCalculator::invoiceTotals($items));
    }

    /**
     * "PARTIALLY_PAID" -> "Partially Paid". {@see Str::headline()} isn't used
     * here because it splits a fully upper-cased single word like "SENT" into
     * one letter per word ("S E N T"), treating the run as an acronym.
     */
    private static function humanize(string $value): string
    {
        return Str::title(str_replace('_', ' ', strtolower($value)));
    }
}
