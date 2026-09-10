<?php

namespace App\Filament\Resources\Invoices\Resources\InvoiceItems;

use App\Domains\Sales\Models\InvoiceItem;
use App\Filament\Resources\Invoices\InvoiceResource;
use App\Filament\Resources\Invoices\Resources\InvoiceItems\Pages\CreateInvoiceItem;
use App\Filament\Resources\Invoices\Resources\InvoiceItems\Pages\EditInvoiceItem;
use App\Filament\Resources\Invoices\Resources\InvoiceItems\Schemas\InvoiceItemForm;
use App\Filament\Resources\Invoices\Resources\InvoiceItems\Tables\InvoiceItemsTable;
use BackedEnum;
use Filament\Resources\ParentResourceRegistration;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class InvoiceItemResource extends Resource
{
    protected static ?string $model = InvoiceItem::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedListBullet;

    protected static ?string $parentResource = InvoiceResource::class;

    /**
     * The relationship the two models actually carry is `Invoice::items()`, not
     * the `invoiceItems` name the parent/child resource basenames would guess.
     */
    public static function getParentResourceRegistration(): ?ParentResourceRegistration
    {
        return InvoiceResource::asParent(childResource: static::class)->relationship('items');
    }

    public static function form(Schema $schema): Schema
    {
        return InvoiceItemForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return InvoiceItemsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'create' => CreateInvoiceItem::route('/create'),
            'edit' => EditInvoiceItem::route('/{record}/edit'),
        ];
    }
}
