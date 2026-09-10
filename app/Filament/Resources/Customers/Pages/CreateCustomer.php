<?php

namespace App\Filament\Resources\Customers\Pages;

use App\Filament\Concerns\StampsCurrentCompany;
use App\Filament\Resources\Customers\CustomerResource;
use Filament\Resources\Pages\CreateRecord;

class CreateCustomer extends CreateRecord
{
    use StampsCurrentCompany;

    protected static string $resource = CustomerResource::class;
}
