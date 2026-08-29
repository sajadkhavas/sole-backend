<?php

namespace App\Filament\Resources\BusinessSettings;

use App\Filament\Resources\BusinessSettings\Pages\ManageBusinessSettings;
use App\Models\BusinessSetting;
use Filament\Actions\EditAction;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class BusinessSettingResource extends Resource
{
    protected static ?string $model = BusinessSetting::class;

    protected static ?string $recordTitleAttribute = 'key';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('key')->required()->maxLength(255)->unique(ignoreRecord: true),
            KeyValue::make('value')->required()->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('key')->searchable()->sortable(),
                TextColumn::make('version')->numeric()->sortable(),
                TextColumn::make('updated_at')->dateTime()->sortable(),
            ])
            ->recordActions([EditAction::make()]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageBusinessSettings::route('/')];
    }
}
