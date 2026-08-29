<?php

namespace App\Filament\Resources\InventoryLocations\Pages;

use App\Filament\Resources\InventoryLocations\InventoryLocationResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageInventoryLocations extends ManageRecords
{
    protected static string $resource = InventoryLocationResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
