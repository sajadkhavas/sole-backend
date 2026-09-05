<?php

namespace App\Filament\Resources\NotificationDeliveryAttempts;

use App\Models\NotificationDeliveryAttempt;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class NotificationDeliveryAttemptResource extends Resource
{
    protected static ?string $model = NotificationDeliveryAttempt::class;
    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }
    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('attempt_key')->searchable()->copyable(), TextColumn::make('signal.public_id')->label('Signal')->searchable(),
            TextColumn::make('channel')->badge(), TextColumn::make('provider'), TextColumn::make('status')->badge()->sortable(),
            TextColumn::make('reason')->searchable(), TextColumn::make('response_hash')->toggleable(), TextColumn::make('attempted_at')->dateTime()->sortable(),
        ])->defaultSort('attempted_at', 'desc');
    }
    public static function getPages(): array
    {
        return ['index' => Pages\ManageNotificationDeliveryAttempts::route('/')];
    }
}
