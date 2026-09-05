<?php

namespace App\Filament\Resources\RefundRequests;

use App\Models\RefundRequest;
use App\Services\Operations\AdminOperationsService;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class RefundRequestResource extends Resource
{
    protected static ?string $model = RefundRequest::class;
    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }
    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('public_id')->label('Refund')->copyable(), TextColumn::make('order.public_id')->label('Order')->searchable(),
            TextColumn::make('status')->badge()->sortable(), TextColumn::make('amount_minor')->numeric(), TextColumn::make('reason'),
            TextColumn::make('provider_reference')->toggleable(), TextColumn::make('requested_at')->dateTime()->sortable(),
        ])->defaultSort('requested_at', 'desc')->recordActions([
            Action::make('transition')->visible(fn (RefundRequest $record): bool => auth()->user()?->can('update', $record) ?? false)
                ->schema([
                    Select::make('status')->options(['processing' => 'Processing', 'manual_review' => 'Manual review', 'completed' => 'Completed', 'failed' => 'Failed'])->required(),
                    TextInput::make('provider_reference')->maxLength(160), Textarea::make('reason')->required()->maxLength(120),
                ])->action(fn (array $data, RefundRequest $record, AdminOperationsService $service) => $service->transitionRefund($record, $data['status'], $data['reason'], $data['provider_reference'] ?? null)),
        ]);
    }
    public static function getPages(): array
    {
        return ['index' => Pages\ManageRefundRequests::route('/')];
    }
}
