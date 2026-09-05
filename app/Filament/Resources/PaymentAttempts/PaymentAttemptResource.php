<?php

namespace App\Filament\Resources\PaymentAttempts;

use App\Filament\Resources\PaymentAttempts\Pages\ManagePaymentAttempts;
use App\Models\PaymentAttempt;
use App\Services\Operations\AdminOperationsService;
use Filament\Actions\Action;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class PaymentAttemptResource extends Resource
{
    protected static ?string $model = PaymentAttempt::class;
    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }
    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('public_id')->label('Attempt')->copyable(),
            TextColumn::make('order.public_id')->label('Order')->searchable(),
            TextColumn::make('provider'), TextColumn::make('status')->badge()->sortable(),
            TextColumn::make('amount_minor')->numeric(), TextColumn::make('currency'),
            TextColumn::make('reference_id')->toggleable(), TextColumn::make('created_at')->dateTime()->sortable(),
        ])->defaultSort('created_at', 'desc')->recordActions([
            Action::make('reconcile')->requiresConfirmation()
                ->visible(fn (PaymentAttempt $record): bool => in_array($record->status, ['initiating', 'pending'], true) && (auth()->user()?->can('reconcile', $record) ?? false))
                ->action(fn (PaymentAttempt $record, AdminOperationsService $service) => $service->reconcilePayment($record)),
        ]);
    }
    public static function getPages(): array
    {
        return ['index' => ManagePaymentAttempts::route('/')];
    }
}
