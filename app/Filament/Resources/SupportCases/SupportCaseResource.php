<?php

namespace App\Filament\Resources\SupportCases;

use App\Models\SupportCase;
use App\Services\Operations\AdminOperationsService;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class SupportCaseResource extends Resource
{
    protected static ?string $model = SupportCase::class;
    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }
    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('public_id')->label('Case')->copyable(), TextColumn::make('user.email')->label('Customer')->searchable(),
            TextColumn::make('subject')->searchable(), TextColumn::make('category')->badge(), TextColumn::make('priority')->badge()->sortable(),
            TextColumn::make('status')->badge()->sortable(), TextColumn::make('sla_due_at')->dateTime()->sortable(), TextColumn::make('updated_at')->dateTime()->sortable(),
        ])->defaultSort('updated_at', 'desc')->recordActions([
            Action::make('replyAndTriage')->label('Reply / triage')
                ->visible(fn (SupportCase $record): bool => $record->status !== 'closed' && (auth()->user()?->can('update', $record) ?? false))
                ->schema([
                    Select::make('status')->options(['open' => 'Open', 'waiting_customer' => 'Waiting customer', 'resolved' => 'Resolved', 'closed' => 'Closed'])->required(),
                    Select::make('priority')->options(['low' => 'Low', 'normal' => 'Normal', 'high' => 'High', 'urgent' => 'Urgent'])->required(),
                    Textarea::make('message')->required()->maxLength(5000),
                ])->action(fn (array $data, SupportCase $record, AdminOperationsService $service) => $service->updateSupportCase($record, $data['status'], $data['priority'], $data['message'])),
        ]);
    }
    public static function getPages(): array
    {
        return ['index' => Pages\ManageSupportCases::route('/')];
    }
}
