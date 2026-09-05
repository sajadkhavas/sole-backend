<?php

namespace App\Filament\Resources\LoyaltyLedgerEntries\Pages;

use App\Filament\Resources\LoyaltyLedgerEntries\LoyaltyLedgerEntryResource;
use App\Models\LoyaltyLedgerEntry;
use App\Models\User;
use App\Services\Operations\AdminOperationsService;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Pages\ManageRecords;

class ManageLoyaltyLedgerEntries extends ManageRecords
{
    protected static string $resource = LoyaltyLedgerEntryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('adjust')->label('Append adjustment')
                ->visible(fn (): bool => auth()->user()?->can('create', LoyaltyLedgerEntry::class) ?? false)
                ->schema([
                    Select::make('user_id')->label('Customer')->options(fn () => User::query()->where('is_active', true)->orderBy('email')->pluck('email', 'id'))->searchable()->required(),
                    Select::make('direction')->options(['credit' => 'Credit', 'debit' => 'Debit'])->required(),
                    TextInput::make('points')->integer()->minValue(1)->maxValue(1000000000)->required(),
                    TextInput::make('idempotency_key')->required()->maxLength(120),
                    Textarea::make('reason')->required()->maxLength(100),
                ])->action(function (array $data, AdminOperationsService $service): void {
                    $service->adjustLoyalty(User::query()->findOrFail($data['user_id']), $data['direction'], (int) $data['points'], $data['idempotency_key'], $data['reason']);
                }),
        ];
    }
}
