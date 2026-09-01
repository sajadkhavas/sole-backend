<?php

namespace App\Filament\Resources\Products;

use App\Filament\Resources\Products\Pages\ManageProducts;
use App\Models\Product;
use App\Services\Catalog\ProductPublicationService;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Gate;

class ProductResource extends Resource
{
    protected static ?string $model = Product::class;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->required()->maxLength(255),
            TextInput::make('slug')->required()->maxLength(255)->unique(ignoreRecord: true),
            TextInput::make('brand')->required()->maxLength(120),
            TextInput::make('colorway')->maxLength(160),
            TextInput::make('merchandising_priority')
                ->label('Merchandising priority')
                ->numeric()
                ->default(0)
                ->helperText('Explicit ranking control only. Never present this value as popularity or scarcity.'),
            TagsInput::make('tags')->columnSpanFull(),
            Select::make('category_id')->relationship('category', 'name')->searchable()->preload(),
            Select::make('collections')->relationship('collections', 'name')->multiple()->searchable()->preload(),
            Textarea::make('description')->rows(6)->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('brand')->searchable()->sortable(),
                TextColumn::make('slug')->searchable(),
                TextColumn::make('category.name')->label('Category')->sortable(),
                TextColumn::make('merchandising_priority')->label('Priority')->numeric()->sortable(),
                TextColumn::make('status')->badge()->sortable(),
                TextColumn::make('published_at')->dateTime()->sortable(),
            ])
            ->recordActions([
                EditAction::make(),
                Action::make('review')
                    ->label('Submit for review')
                    ->requiresConfirmation()
                    ->visible(fn (Product $record): bool => $record->status === 'draft' && (auth()->user()?->can('review', $record) ?? false))
                    ->action(function (Product $record): void {
                        Gate::authorize('review', $record);
                        app(ProductPublicationService::class)->requestReview($record, auth()->user());
                    }),
                Action::make('publish')
                    ->requiresConfirmation()
                    ->visible(fn (Product $record): bool => $record->status === 'review' && (auth()->user()?->can('publish', $record) ?? false))
                    ->action(function (Product $record): void {
                        Gate::authorize('publish', $record);
                        app(ProductPublicationService::class)->publish($record, auth()->user());
                    }),
                Action::make('rollbackPublication')
                    ->label('Rollback publication')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->visible(fn (Product $record): bool => $record->status === 'published' && (auth()->user()?->can('rollbackPublication', $record) ?? false))
                    ->action(function (Product $record): void {
                        Gate::authorize('rollbackPublication', $record);
                        app(ProductPublicationService::class)->rollbackLatestPublication($record, auth()->user());
                    }),
                Action::make('archive')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->visible(fn (Product $record): bool => $record->status !== 'archived' && (auth()->user()?->can('archive', $record) ?? false))
                    ->action(function (Product $record): void {
                        Gate::authorize('archive', $record);
                        $record->forceFill(['status' => 'archived'])->save();
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageProducts::route('/')];
    }
}
