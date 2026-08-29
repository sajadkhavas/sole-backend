<?php

namespace App\Filament\Resources\ProductVariants;

use App\Filament\Resources\ProductVariants\Pages\ManageProductVariants;
use App\Models\InventoryLocation;
use App\Models\ProductVariant;
use App\Services\InventoryLedger;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Gate;

class ProductVariantResource extends Resource
{
    protected static ?string $model = ProductVariant::class;

    protected static ?string $recordTitleAttribute = 'sku';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('product_id')->relationship('product', 'name')->required()->searchable()->preload(),
            TextInput::make('sku')->required()->maxLength(255)->unique(ignoreRecord: true),
            TextInput::make('title')->required()->maxLength(255),
            TextInput::make('size')->maxLength(255),
            TextInput::make('color')->maxLength(255),
            TextInput::make('price_minor')->required()->integer()->minValue(0),
            TextInput::make('compare_at_price_minor')->integer()->minValue(0),
            TextInput::make('currency')->required()->length(3)->default('IRR'),
            Select::make('is_active')->options([1 => 'Active', 0 => 'Inactive'])->required(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('sku')->searchable()->sortable(),
                TextColumn::make('product.name')->searchable()->sortable(),
                TextColumn::make('title')->searchable(),
                TextColumn::make('price_minor')->numeric()->sortable(),
                TextColumn::make('currency'),
                IconColumn::make('is_active')->boolean(),
            ])
            ->recordActions([
                EditAction::make(),
                Action::make('adjustInventory')
                    ->label('Adjust inventory')
                    ->visible(fn (ProductVariant $record): bool => auth()->user()?->can('adjustInventory', $record) ?? false)
                    ->schema([
                        Select::make('inventory_location_id')
                            ->options(fn () => InventoryLocation::query()->where('is_active', true)->orderBy('name')->pluck('name', 'id'))
                            ->required()
                            ->searchable(),
                        TextInput::make('delta')->integer()->required()->notIn([0]),
                        TextInput::make('reason')->required()->maxLength(120),
                    ])
                    ->action(function (array $data, ProductVariant $record, InventoryLedger $ledger): void {
                        Gate::authorize('adjustInventory', $record);
                        $location = InventoryLocation::query()->where('is_active', true)->findOrFail($data['inventory_location_id']);
                        $ledger->adjust($record, $location, (int) $data['delta'], $data['reason']);
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageProductVariants::route('/')];
    }
}
