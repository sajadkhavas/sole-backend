<?php

namespace App\Filament\Resources\SeoRoutePolicies;

use App\Filament\Resources\SeoRoutePolicies\Pages\ManageSeoRoutePolicies;
use App\Models\SeoRoutePolicy;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class SeoRoutePolicyResource extends Resource
{
    protected static ?string $model = SeoRoutePolicy::class;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('route_key')->required()->unique(ignoreRecord: true),
            TextInput::make('path_pattern')->required()->startsWith('/'),
            Select::make('canonical_policy')->options(['self' => 'Self', 'clean_path' => 'Clean path', 'backend_product' => 'Backend product'])->required(),
            Select::make('robots_policy')->options(['index,follow' => 'Index, follow', 'noindex,follow' => 'Noindex, follow', 'noindex,nofollow' => 'Noindex, nofollow'])->required(),
            TextInput::make('schema_type'),
            TextInput::make('sitemap_segment'),
            Toggle::make('facets_indexable')->default(false),
            Toggle::make('is_active')->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('route_key')->searchable(),
            TextColumn::make('path_pattern'),
            TextColumn::make('robots_policy'),
            IconColumn::make('facets_indexable')->boolean(),
            IconColumn::make('is_active')->boolean(),
        ])->recordActions([EditAction::make()]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageSeoRoutePolicies::route('/')];
    }
}
