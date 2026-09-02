<?php

namespace App\Filament\Resources\AnalyticsFunnelSnapshots;

use App\Filament\Resources\AnalyticsFunnelSnapshots\Pages\ManageAnalyticsFunnelSnapshots;
use App\Models\AnalyticsFunnelSnapshot;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class AnalyticsFunnelSnapshotResource extends Resource
{
    protected static ?string $model = AnalyticsFunnelSnapshot::class;
    protected static ?string $navigationLabel = 'Consent-aware funnel';

    public static function form(Schema $schema): Schema { return $schema->components([]); }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('snapshot_date')->date()->sortable(),
            TextColumn::make('taxonomy_version')->label('Taxonomy')->numeric(),
            TextColumn::make('catalog_sessions')->numeric(),
            TextColumn::make('product_sessions')->numeric(),
            TextColumn::make('cart_sessions')->numeric(),
            TextColumn::make('checkout_sessions')->numeric(),
            TextColumn::make('order_sessions')->numeric(),
            TextColumn::make('paid_sessions')->numeric(),
            TextColumn::make('rebuilt_at')->dateTime()->sortable(),
        ])->defaultSort('snapshot_date', 'desc');
    }

    public static function getPages(): array { return ['index' => ManageAnalyticsFunnelSnapshots::route('/')]; }
}
