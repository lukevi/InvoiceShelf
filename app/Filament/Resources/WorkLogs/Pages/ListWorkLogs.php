<?php

namespace App\Filament\Resources\WorkLogs\Pages;

use App\Filament\Resources\WorkLogs\WorkLogResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListWorkLogs extends ListRecords
{
    protected static string $resource = WorkLogResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
