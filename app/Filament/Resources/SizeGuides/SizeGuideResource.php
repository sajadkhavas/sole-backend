<?php

namespace App\Filament\Resources\SizeGuides;

use App\Filament\Resources\SizeGuides\Pages\ManageSizeGuides;
use App\Models\SizeGuide;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class SizeGuideResource extends Resource
{
    protected static ?string $model = SizeGuide::class;

    protected static ?string $recordTitleAttribute = 'source_label';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('product_id')->relationship('product', 'name')->required()->searchable()->preload()->unique(ignoreRecord: true),
            Select::make('status')->options(['draft' => 'Draft', 'published' => 'Published'])->required(),
            TextInput::make('source_label')->required()->maxLength(160),
            TextInput::make('source_url')->url()->maxLength(2048),
            Select::make('width_profile')->options(['narrow' => 'Narrow', 'standard' => 'Standard', 'wide' => 'Wide'])->required(),
            Textarea::make('notes')->columnSpanFull(),
            Repeater::make('entries')->relationship()->schema([
                TextInput::make('eu_size')->numeric()->required()->minValue(20)->maxValue(60),
                TextInput::make('foot_length_min_mm')->integer()->required()->minValue(180)->maxValue(340),
                TextInput::make('foot_length_max_mm')->integer()->required()->minValue(180)->maxValue(340),
                TextInput::make('label')->maxLength(80),
            ])->minItems(1)->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('product.name')->searchable()->sortable(),
            TextColumn::make('source_label')->searchable(),
            TextColumn::make('status')->badge()->sortable(),
            TextColumn::make('verified_at')->dateTime()->sortable(),
        ])->recordActions([EditAction::make()]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageSizeGuides::route('/')];
    }
}
