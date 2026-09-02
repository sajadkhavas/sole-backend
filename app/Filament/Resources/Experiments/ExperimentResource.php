<?php

namespace App\Filament\Resources\Experiments;

use App\Filament\Resources\Experiments\Pages\ManageExperiments;
use App\Models\Experiment;
use App\Services\Observability\AnalyticsTaxonomy;
use App\Services\Observability\ExperimentService;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Gate;

class ExperimentResource extends Resource
{
    protected static ?string $model = Experiment::class;
    protected static ?string $recordTitleAttribute = 'key';

    public static function form(Schema $schema): Schema
    {
        $metrics = array_combine(AnalyticsTaxonomy::EXPERIMENT_METRICS, AnalyticsTaxonomy::EXPERIMENT_METRICS);

        return $schema->components([
            TextInput::make('key')->required()->maxLength(80)->regex('/^[a-z0-9][a-z0-9_-]{2,79}$/'),
            TextInput::make('version')->required()->numeric()->minValue(1),
            Select::make('surface')->options(['home' => 'Home', 'catalog' => 'Catalog', 'product' => 'Product', 'cart' => 'Cart', 'checkout' => 'Checkout'])->required(),
            Textarea::make('hypothesis')->required()->maxLength(500)->columnSpanFull(),
            Select::make('primary_metric')->options($metrics)->required(),
            Select::make('guardrail_metrics')->options($metrics)->multiple()->required(),
            TagsInput::make('variants')->required()->helperText('2–5 stable labels, for example control and treatment.'),
            KeyValue::make('allocation_basis_points')->required()->helperText('Variant => basis points. Total must equal 10000.'),
            TextInput::make('minimum_sample_size')->required()->numeric()->minValue(100),
            Textarea::make('rollback_plan')->required()->maxLength(500)->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('key')->searchable()->sortable(),
            TextColumn::make('version')->numeric()->sortable(),
            TextColumn::make('surface')->badge(),
            TextColumn::make('status')->badge()->sortable(),
            TextColumn::make('primary_metric'),
            TextColumn::make('minimum_sample_size')->numeric(),
            TextColumn::make('starts_at')->dateTime()->sortable(),
        ])->recordActions([
            EditAction::make()->visible(fn (Experiment $record): bool => $record->status !== 'running'),
            Action::make('activate')->requiresConfirmation()
                ->visible(fn (Experiment $record): bool => in_array($record->status, ['draft', 'paused'], true) && (auth()->user()?->hasPermission('experiments.manage') ?? false))
                ->action(function (Experiment $record): void {
                    Gate::authorize('update', $record);
                    app(ExperimentService::class)->activate($record, auth()->user());
                }),
            Action::make('pause')->color('warning')->requiresConfirmation()
                ->visible(fn (Experiment $record): bool => $record->status === 'running' && (auth()->user()?->hasPermission('experiments.manage') ?? false))
                ->action(fn (Experiment $record) => app(ExperimentService::class)->pause($record)),
        ]);
    }

    public static function getPages(): array { return ['index' => ManageExperiments::route('/')]; }
}
