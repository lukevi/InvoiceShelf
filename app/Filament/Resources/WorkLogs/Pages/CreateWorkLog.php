<?php

namespace App\Filament\Resources\WorkLogs\Pages;

use App\Filament\Concerns\StampsCurrentCompany;
use App\Filament\Resources\WorkLogs\WorkLogResource;
use Filament\Resources\Pages\CreateRecord;

class CreateWorkLog extends CreateRecord
{
    use StampsCurrentCompany;

    protected static string $resource = WorkLogResource::class;
}
