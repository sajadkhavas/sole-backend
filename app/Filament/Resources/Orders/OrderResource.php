<?php

namespace App\Filament\Resources\Orders;

use App\Filament\Resources\Orders\Pages\ManageOrders;
use App\Models\Order;
use App\Services\Operations\AdminOperationsService;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class OrderResource extends Resource
{
    protected static ?string $model = Order::class;
    protected static ?string $recordTitleAttribute = 'public_id';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('public_id')->label('Order')->searchable()->copyable(),
            TextColumn::make('user.email')->label('Customer')->searchable(),
            TextColumn::make('status')->badge()->sortable(),
            TextColumn::make('total_minor')->numeric()->sortable(),
            TextColumn::make('currency'),
            TextColumn::make('created_at')->dateTime()->sortable(),
        ])->defaultSort('created_at', 'desc')->recordActions([
            Action::make('cancel')->color('danger')->requiresConfirmation()
                ->visible(fn (Order $record): bool => in_array($record->status, ['awaiting_payment', 'paid', 'processing'], true) && (auth()->user()?->can('update', $record) ?? false))
                ->schema([Textarea::make('reason')->required()->maxLength(120)])
                ->action(fn (array $data, Order $record, AdminOperationsService $service) => $service->cancelOrder($record, $data['reason'])),
        ]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageOrders::route('/')];
    }
}
