<?php

namespace App\Filament\Resources\ContentPages;

use App\Filament\Resources\ContentPages\Pages\ManageContentPages;
use App\Models\ContentPage;
use App\Services\Content\ContentPublicationService;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Gate;

class ContentPageResource extends Resource
{
    protected static ?string $model = ContentPage::class;

    protected static ?string $recordTitleAttribute = 'title';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('title')->required()->maxLength(255),
            TextInput::make('slug')->required()->maxLength(255)->unique(ignoreRecord: true),
            Textarea::make('summary')->maxLength(500)->columnSpanFull(),
            Repeater::make('blocks')->schema([
                Select::make('type')->options(['prose' => 'Prose', 'callout' => 'Callout', 'faq' => 'FAQ'])->required(),
                TextInput::make('heading')->maxLength(255),
                Textarea::make('body')->required()->maxLength(10000),
            ])->minItems(1)->columnSpanFull(),
            TextInput::make('seo_title')->required()->maxLength(70),
            TextInput::make('seo_description')->required()->maxLength(180),
            TextInput::make('canonical_path')->required()->startsWith('/')->unique(ignoreRecord: true),
            Select::make('robots')->options([
                'index,follow' => 'Index, follow',
                'noindex,follow' => 'Noindex, follow',
                'noindex,nofollow' => 'Noindex, nofollow',
            ])->required(),
            Select::make('schema_type')->options([
                'WebPage' => 'WebPage',
                'AboutPage' => 'AboutPage',
                'ContactPage' => 'ContactPage',
                'FAQPage' => 'FAQPage',
            ])->required(),
            Select::make('sitemap_segment')->options(['content' => 'Content', 'legal' => 'Legal'])->required(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('title')->searchable()->sortable(),
            TextColumn::make('canonical_path')->searchable(),
            TextColumn::make('status')->badge()->sortable(),
            TextColumn::make('version')->numeric()->sortable(),
            TextColumn::make('published_at')->dateTime()->sortable(),
        ])->recordActions([
            EditAction::make()->visible(fn (ContentPage $record): bool => $record->status !== 'published'),
            Action::make('review')->requiresConfirmation()
                ->visible(fn (ContentPage $record): bool => $record->status === 'draft' && (auth()->user()?->can('review', $record) ?? false))
                ->action(function (ContentPage $record): void {
                    Gate::authorize('review', $record);
                    app(ContentPublicationService::class)->requestReview($record, auth()->user());
                }),
            Action::make('publish')->requiresConfirmation()
                ->visible(fn (ContentPage $record): bool => $record->status === 'review' && (auth()->user()?->can('publish', $record) ?? false))
                ->action(function (ContentPage $record): void {
                    Gate::authorize('publish', $record);
                    app(ContentPublicationService::class)->publish($record, auth()->user());
                }),
            Action::make('rollbackPublication')->label('Rollback publication')->color('warning')->requiresConfirmation()
                ->visible(fn (ContentPage $record): bool => $record->status === 'published' && (auth()->user()?->can('publish', $record) ?? false))
                ->action(function (ContentPage $record): void {
                    Gate::authorize('publish', $record);
                    app(ContentPublicationService::class)->rollbackLatestPublication($record, auth()->user());
                }),
        ]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageContentPages::route('/')];
    }
}
