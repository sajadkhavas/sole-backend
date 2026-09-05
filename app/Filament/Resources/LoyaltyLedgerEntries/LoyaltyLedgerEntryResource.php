<?php

namespace App\Filament\Resources\LoyaltyLedgerEntries;

use App\Models\LoyaltyLedgerEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class LoyaltyLedgerEntryResource extends Resource
{
    protected static ?string $model = LoyaltyLedgerEntry::class;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('public_id')->label('Entry')->copyable(), TextColumn::make('user.email')->label('Customer')->searchable(),
            TextColumn::make('type')->badge()->sortable(), TextColumn::make('points_delta')->numeric()->sortable(),
            TextColumn::make('reason')->searchable(), TextColumn::make('idempotency_key')->toggleable(), TextColumn::make('created_at')->dateTime()->sortable(),
        ])->defaultSort('created_at', 'desc');
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ManageLoyaltyLedgerEntries::route('/')];
    }
}
