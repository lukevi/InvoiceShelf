<?php

namespace App\Filament\Resources\WorkLogs\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class WorkLogForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('customer_id')
                    ->relationship('customer', 'name')
                    ->searchable()
                    ->preload()
                    ->required(),
                Select::make('charge_category_id')
                    ->label('Category')
                    ->relationship('chargeCategory', 'name')
                    ->searchable()
                    ->preload()
                    ->required(),
                Textarea::make('description')
                    ->required()
                    ->columnSpanFull(),
                TextInput::make('duration_hours')
                    ->label('Hours')
                    ->required()
                    ->numeric()
                    ->step(0.25)
                    ->minValue(0),
                Select::make('invoice_item_id')
                    ->label('Billed invoice line')
                    ->relationship('invoiceItem', 'name')
                    ->searchable()
                    ->preload()
                    ->helperText('Leave blank until this time has been carried onto an invoice.'),
            ]);
    }
}
