<?php

namespace App\Filament\Resources\Invoices\Schemas;

use App\Filament\Support\CurrentCompany;
use App\Filament\Support\InvoiceLineItemCalculator;
use App\Filament\Support\NextInvoiceNumber;
use Carbon\Carbon;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;

class InvoiceForm
{
    /** Due dates default this many days after the invoice date. */
    private const DEFAULT_DUE_DAYS = 10;

    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Document details')
                    ->columns(2)
                    ->components([
                        Select::make('customer_id')
                            ->relationship('customer', 'name')
                            ->searchable()
                            ->preload()
                            ->required(),
                        Select::make('currency_id')
                            ->relationship('currency', 'code')
                            ->searchable()
                            ->preload(),
                        TextInput::make('invoice_number')
                            ->default(fn () => NextInvoiceNumber::preview())
                            ->disabled()
                            ->dehydrated(false)
                            ->helperText('Generated automatically from the company\'s numbering format.'),
                        TextInput::make('reference_number'),
                        DateTimePicker::make('invoice_date')
                            ->required()
                            ->default(now())
                            ->live()
                            ->afterStateUpdated(function (?string $state, Set $set): void {
                                if (filled($state)) {
                                    $set('due_date', Carbon::parse($state)->addDays(self::DEFAULT_DUE_DAYS)->toDateString());
                                }
                            }),
                        DatePicker::make('due_date')
                            ->default(now()->addDays(self::DEFAULT_DUE_DAYS)),
                    ]),

                Section::make('Line items')
                    ->description('Entered here at create time; each row becomes an invoice item. Totals recalculate as you type.')
                    ->components([
                        Repeater::make('items')
                            ->relationship()
                            ->mutateRelationshipDataBeforeCreateUsing(fn (array $data): array => self::mutateLineItemData($data))
                            ->mutateRelationshipDataBeforeSaveUsing(fn (array $data): array => self::mutateLineItemData($data))
                            ->hiddenLabel()
                            ->columns(12)
                            ->schema([
                                Select::make('item_id')
                                    ->label('Catalog item')
                                    ->relationship('item', 'name')
                                    ->searchable()
                                    ->preload()
                                    ->columnSpan(2),
                                TextInput::make('name')
                                    ->required()
                                    ->columnSpan(2),
                                TextInput::make('quantity')
                                    ->label('Qty')
                                    ->numeric()
                                    ->default(1)
                                    ->required()
                                    ->live(onBlur: true)
                                    ->columnSpan(1),
                                TextInput::make('price')
                                    ->numeric()
                                    ->default(0)
                                    ->required()
                                    ->live(onBlur: true)
                                    ->columnSpan(2),
                                Select::make('discount_type')
                                    ->label('Disc. type')
                                    ->options([
                                        'fixed' => 'Fixed',
                                        'percentage' => '%',
                                    ])
                                    ->default('fixed')
                                    ->required()
                                    ->live()
                                    ->columnSpan(1),
                                TextInput::make('discount')
                                    ->label('Disc.')
                                    ->numeric()
                                    ->default(0)
                                    ->live(onBlur: true)
                                    ->columnSpan(1),
                                TextInput::make('tax')
                                    ->numeric()
                                    ->default(0)
                                    ->required()
                                    ->live(onBlur: true)
                                    ->columnSpan(1),
                                TextEntry::make('lineTotal')
                                    ->label('Total')
                                    ->state(fn (Get $get) => self::formatMinorUnits(InvoiceLineItemCalculator::lineTotal([
                                        'price' => $get('price'),
                                        'quantity' => $get('quantity'),
                                        'discount_type' => $get('discount_type'),
                                        'discount' => $get('discount'),
                                        'tax' => $get('tax'),
                                    ])))
                                    ->columnSpan(2),
                            ])
                            ->itemLabel(fn (array $state): ?string => $state['name'] ?? null)
                            ->addActionLabel('Add line item')
                            ->reorderable(false)
                            ->collapsible()
                            ->defaultItems(1)
                            ->columnSpanFull(),
                    ]),

                Section::make('Totals')
                    ->columns(4)
                    ->components([
                        TextEntry::make('sub_total_display')
                            ->label('Sub total')
                            ->state(fn (Get $get) => self::formatMinorUnits(InvoiceLineItemCalculator::invoiceTotals($get('items') ?? [])['sub_total'])),
                        TextEntry::make('tax_display')
                            ->label('Tax')
                            ->state(fn (Get $get) => self::formatMinorUnits(InvoiceLineItemCalculator::invoiceTotals($get('items') ?? [])['tax'])),
                        TextEntry::make('total_display')
                            ->label('Total')
                            ->state(fn (Get $get) => self::formatMinorUnits(InvoiceLineItemCalculator::invoiceTotals($get('items') ?? [])['total'])),
                        TextEntry::make('due_amount_display')
                            ->label('Due')
                            ->state(fn (Get $get) => self::formatMinorUnits(InvoiceLineItemCalculator::invoiceTotals($get('items') ?? [])['due_amount'])),
                    ]),

                Section::make('Notes')
                    ->components([
                        Textarea::make('notes')
                            ->hiddenLabel()
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private static function mutateLineItemData(array $data): array
    {
        $data['company_id'] = CurrentCompany::id();
        $data['discount_val'] = InvoiceLineItemCalculator::discountValue($data);
        $data['total'] = InvoiceLineItemCalculator::lineTotal($data);

        return $data;
    }

    /** Minor units (cents) rendered as a plain decimal, e.g. 5000 -> "50.00". */
    private static function formatMinorUnits(int $amount): string
    {
        return number_format($amount / 100, 2);
    }
}
