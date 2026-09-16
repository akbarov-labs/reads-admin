<?php

namespace App\Filament\Resources;

use App\Filament\Resources\PublisherResource\Pages;
use App\Models\Publisher;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class PublisherResource extends Resource
{
    protected static ?string $model = Publisher::class;
    protected static ?string $navigationIcon = 'heroicon-o-building-office-2';
    protected static ?string $navigationLabel = 'Publishers';

    public static function shouldRegisterNavigation(): bool
    {
        return auth()->user()?->isAdmin() ?? false;
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Publisher identity')->schema([
                Forms\Components\TextInput::make('name')->required()->maxLength(255),
                Forms\Components\TextInput::make('slug')->required()->alphaDash()->unique(ignoreRecord: true)->maxLength(255),
                Forms\Components\TextInput::make('website_url')->url()->maxLength(255),
                Forms\Components\FileUpload::make('logo_path')->label('Logo')->image()->acceptedFileTypes(TranslatorResource::SAFE_IMAGE_TYPES)->maxSize(4096)->disk('public')->directory('publishers'),
            ])->columns(2),
            Forms\Components\Section::make('Localized information')->schema([
                Forms\Components\Tabs::make('Locales')->tabs([
                    Forms\Components\Tabs\Tab::make('Uzbek')->schema(self::localizedFields('uz')),
                    Forms\Components\Tabs\Tab::make('Russian')->schema(self::localizedFields('ru')),
                    Forms\Components\Tabs\Tab::make('English')->schema(self::localizedFields('en')),
                ])->columnSpanFull(),
            ]),
            Forms\Components\TextInput::make('established_year')->numeric()->minValue(1)->maxValue(9999),
        ]);
    }

    /** @return array<int, Forms\Components\Component> */
    private static function localizedFields(string $locale): array
    {
        return [
            Forms\Components\Textarea::make("bio.{$locale}")->label('About')->rows(3),
            Forms\Components\TextInput::make("country.{$locale}")->label('Country'),
        ];
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            Tables\Columns\ImageColumn::make('logo_path')->label('')->disk('public'),
            Tables\Columns\TextColumn::make('name')->searchable()->sortable(),
            Tables\Columns\TextColumn::make('slug')->searchable(),
            Tables\Columns\TextColumn::make('books_count')->counts('books')->label('Books')->sortable(),
        ])->actions([
            Tables\Actions\EditAction::make(),
        ])->bulkActions([
            Tables\Actions\BulkActionGroup::make([Tables\Actions\DeleteBulkAction::make()]),
        ]);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->withCount('books');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPublishers::route('/'),
            'create' => Pages\CreatePublisher::route('/create'),
            'edit' => Pages\EditPublisher::route('/{record}/edit'),
        ];
    }
}