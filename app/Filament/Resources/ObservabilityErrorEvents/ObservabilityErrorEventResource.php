<?php

namespace App\Filament\Resources\ObservabilityErrorEvents;

use App\Filament\Resources\ObservabilityErrorEvents\Pages\ManageObservabilityErrorEvents;
use App\Models\ObservabilityErrorEvent;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ObservabilityErrorEventResource extends Resource
{
    protected static ?string $model = ObservabilityErrorEvent::class;
    protected static ?string $navigationLabel = 'Error monitoring';

    public static function form(Schema $schema): Schema { return $schema->components([]); }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('occurred_at')->dateTime()->sortable(),
            TextColumn::make('route_name')->searchable()->sortable(),
            TextColumn::make('method')->badge(),
            TextColumn::make('exception_class')->searchable(),
            TextColumn::make('fingerprint')->copyable()->limit(16),
            TextColumn::make('trace_id')->copyable()->toggleable(),
            TextColumn::make('request_id')->copyable()->toggleable(),
        ])->defaultSort('occurred_at', 'desc');
    }

    public static function getPages(): array { return ['index' => ManageObservabilityErrorEvents::route('/')]; }
}
