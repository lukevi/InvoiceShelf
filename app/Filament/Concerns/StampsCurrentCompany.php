<?php

namespace App\Filament\Concerns;

use App\Filament\Support\CurrentCompany;

/**
 * Stamps `company_id` on create, since it is deliberately absent from these
 * resources' forms — see {@see ScopedToCurrentCompany}.
 */
trait StampsCurrentCompany
{
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['company_id'] = CurrentCompany::id();

        return $data;
    }
}
