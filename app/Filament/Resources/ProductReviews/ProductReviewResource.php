<?php

namespace App\Filament\Resources\ProductReviews;

use App\Models\ProductReview;
use App\Services\Operations\AdminOperationsService;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ProductReviewResource extends Resource
{
    protected static ?string $model = ProductReview::class;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('public_id')->label('Review')->copyable(), TextColumn::make('product_variant_id')->label('Variant ID')->numeric(),
            TextColumn::make('rating')->numeric()->sortable(), TextColumn::make('title')->searchable(), TextColumn::make('status')->badge()->sortable(),
            TextColumn::make('created_at')->dateTime()->sortable(), TextColumn::make('moderated_at')->dateTime()->sortable(),
        ])->defaultSort('created_at', 'desc')->recordActions([
            Action::make('moderate')->visible(fn (ProductReview $record): bool => $record->status === 'pending' && (auth()->user()?->can('update', $record) ?? false))
                ->schema([Select::make('decision')->options(['published' => 'Publish', 'rejected' => 'Reject'])->required(), Textarea::make('reason')->required()->maxLength(120)])
                ->action(fn (array $data, ProductReview $record, AdminOperationsService $service) => $service->moderateReview($record, $data['decision'], $data['reason'])),
        ]);
    }
    public static function getPages(): array
    {
        return ['index' => Pages\ManageProductReviews::route('/')];
    }
}
