<?php

namespace App\Filament\Resources\ContentPages\Pages;

use App\Filament\Resources\ContentPages\ContentPageResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageContentPages extends ManageRecords
{
    protected static string $resource = ContentPageResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
