<?php

namespace App\Filament\Resources\WorkLogs\Pages;

use App\Filament\Resources\WorkLogs\WorkLogResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditWorkLog extends EditRecord
{
    protected static string $resource = WorkLogResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
