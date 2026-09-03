<?php

namespace App\Filament\Resources\ObservabilityRequestMetrics;

use App\Filament\Resources\ObservabilityRequestMetrics\Pages\ManageObservabilityRequestMetrics;
use App\Models\ObservabilityRequestMetric;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ObservabilityRequestMetricResource extends Resource
{
    protected static ?string $model = ObservabilityRequestMetric::class;

    protected static ?string $navigationLabel = 'Request RED metrics';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('bucket_started_at')->dateTime()->sortable(),
            TextColumn::make('route_name')->searchable()->sortable(),
            TextColumn::make('method')->badge(),
            TextColumn::make('status_class')->badge(),
            TextColumn::make('request_count')->numeric()->sortable(),
            TextColumn::make('error_count')->numeric()->sortable(),
            TextColumn::make('duration_max_ms')->label('Max ms')->numeric(decimalPlaces: 1)->sortable(),
        ])->defaultSort('bucket_started_at', 'desc');
    }

    public static function getPages(): array
    {
        return ['index' => ManageObservabilityRequestMetrics::route('/')];
    }
}
