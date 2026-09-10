<?php

namespace App\Filament\Resources\Invoices\Tables;

use App\Domains\Sales\Models\Invoice;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class InvoicesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('invoice_number')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('customer.name')
                    ->label('Customer')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('invoice_date')
                    ->date()
                    ->sortable(),
                TextColumn::make('due_date')
                    ->date()
                    ->sortable(),
                TextColumn::make('status')
                    ->badge()
                    ->sortable(),
                TextColumn::make('paid_status')
                    ->badge()
                    ->sortable(),
                TextColumn::make('total')
                    ->money(fn ($record) => $record->currency?->code ?? 'usd', divideBy: 100)
                    ->sortable(),
                TextColumn::make('due_amount')
                    ->money(fn ($record) => $record->currency?->code ?? 'usd', divideBy: 100)
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('customer_id')
                    ->label('Customer')
                    ->relationship('customer', 'name')
                    ->searchable(),
                SelectFilter::make('status')
                    ->options([
                        Invoice::STATUS_DRAFT => 'Draft',
                        Invoice::STATUS_SENT => 'Sent',
                        Invoice::STATUS_VIEWED => 'Viewed',
                        Invoice::STATUS_COMPLETED => 'Completed',
                    ]),
                SelectFilter::make('paid_status')
                    ->label('Paid status')
                    ->options([
                        Invoice::STATUS_UNPAID => 'Unpaid',
                        Invoice::STATUS_PARTIALLY_PAID => 'Partially paid',
                        Invoice::STATUS_PAID => 'Paid',
                    ]),
            ])
            ->defaultSort('invoice_date', 'desc')
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
