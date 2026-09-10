<?php

namespace App\Filament\Resources\Invoices\Resources\InvoiceItems\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class InvoiceItemsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->searchable(),
                TextColumn::make('description')
                    ->limit(50),
                TextColumn::make('price')
                    ->money(fn ($record) => $record->invoice?->currency?->code ?? 'usd', divideBy: 100),
                TextColumn::make('quantity')
                    ->numeric(),
                TextColumn::make('tax')
                    ->money(fn ($record) => $record->invoice?->currency?->code ?? 'usd', divideBy: 100),
                TextColumn::make('total')
                    ->money(fn ($record) => $record->invoice?->currency?->code ?? 'usd', divideBy: 100),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                //
            ])
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
