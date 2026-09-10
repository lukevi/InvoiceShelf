<?php

namespace App\Filament\Resources\Invoices\Resources\InvoiceItems\Pages;

use App\Filament\Resources\Invoices\Resources\InvoiceItems\InvoiceItemResource;
use Filament\Resources\Pages\CreateRecord;

class CreateInvoiceItem extends CreateRecord
{
    protected static string $resource = InvoiceItemResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['company_id'] = $this->getParentRecord()->company_id;

        return $data;
    }
}
