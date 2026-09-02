<?php

namespace App\Filament\Resources\Experiments\Pages;

use App\Filament\Resources\Experiments\ExperimentResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageExperiments extends ManageRecords
{
    protected static string $resource = ExperimentResource::class;

    protected function getHeaderActions(): array { return [CreateAction::make()]; }
}
