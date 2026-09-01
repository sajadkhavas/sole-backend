<?php

namespace App\Filament\Resources\SizeGuides\Pages;

use App\Filament\Resources\SizeGuides\SizeGuideResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageSizeGuides extends ManageRecords
{
    protected static string $resource = SizeGuideResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
