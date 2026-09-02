<?php

namespace App\Filament\Resources\SeoRoutePolicies\Pages;

use App\Filament\Resources\SeoRoutePolicies\SeoRoutePolicyResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageSeoRoutePolicies extends ManageRecords
{
    protected static string $resource = SeoRoutePolicyResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
