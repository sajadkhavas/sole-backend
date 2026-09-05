<?php

namespace App\Filament\Resources\PaymentReconciliations;

use App\Models\PaymentReconciliation;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class PaymentReconciliationResource extends Resource
{
    protected static ?string $model = PaymentReconciliation::class;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('order_id')->label('Order ID')->numeric(),
            TextColumn::make('payment_attempt_id')->label('Attempt ID')->numeric(),
            TextColumn::make('expected_status')->badge(), TextColumn::make('observed_status')->badge(),
            TextColumn::make('outcome')->badge()->sortable(), TextColumn::make('payload_hash')->toggleable(),
            TextColumn::make('reconciled_at')->dateTime()->sortable(),
        ])->defaultSort('reconciled_at', 'desc');
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ManagePaymentReconciliations::route('/')];
    }
}
