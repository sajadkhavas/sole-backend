<?php

namespace App\Filament\Resources\ReturnRequests;

use App\Models\ReturnRequest;
use App\Services\Operations\AdminOperationsService;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ReturnRequestResource extends Resource
{
    protected static ?string $model = ReturnRequest::class;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('public_id')->label('Return')->copyable(), TextColumn::make('order.public_id')->label('Order')->searchable(),
            TextColumn::make('status')->badge()->sortable(), TextColumn::make('reason'), TextColumn::make('requested_at')->dateTime()->sortable(),
        ])->defaultSort('requested_at', 'desc')->recordActions([
            Action::make('transition')->visible(fn (ReturnRequest $record): bool => auth()->user()?->can('update', $record) ?? false)
                ->schema([Select::make('status')->options(['approved' => 'Approved', 'received' => 'Received', 'rejected' => 'Rejected', 'closed' => 'Closed'])->required(), Textarea::make('reason')->required()->maxLength(120)])
                ->action(fn (array $data, ReturnRequest $record, AdminOperationsService $service) => $service->transitionReturn($record, $data['status'], $data['reason'])),
        ]);
    }
    public static function getPages(): array
    {
        return ['index' => Pages\ManageReturnRequests::route('/')];
    }
}
