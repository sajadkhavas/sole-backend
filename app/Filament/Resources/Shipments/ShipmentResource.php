<?php

namespace App\Filament\Resources\Shipments;

use App\Models\Shipment;
use App\Services\Operations\AdminOperationsService;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ShipmentResource extends Resource
{
    protected static ?string $model = Shipment::class;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('public_id')->label('Shipment')->copyable(), TextColumn::make('order.public_id')->label('Order')->searchable(),
            TextColumn::make('provider'), TextColumn::make('service_code'), TextColumn::make('status')->badge()->sortable(),
            TextColumn::make('tracking_number')->searchable(), TextColumn::make('updated_at')->dateTime()->sortable(),
        ])->defaultSort('updated_at', 'desc')->recordActions([
            Action::make('transition')->visible(fn (Shipment $record): bool => auth()->user()?->can('update', $record) ?? false)
                ->schema([
                    Select::make('status')->options(['ready' => 'Ready', 'shipped' => 'Shipped', 'delivered' => 'Delivered', 'exception' => 'Exception', 'cancelled' => 'Cancelled'])->required(),
                    TextInput::make('tracking_number')->maxLength(160),
                    Textarea::make('reason')->required()->maxLength(120),
                ])->action(fn (array $data, Shipment $record, AdminOperationsService $service) => $service->transitionShipment($record, $data['status'], $data['reason'], $data['tracking_number'] ?? null)),
        ]);
    }
    public static function getPages(): array
    {
        return ['index' => Pages\ManageShipments::route('/')];
    }
}
