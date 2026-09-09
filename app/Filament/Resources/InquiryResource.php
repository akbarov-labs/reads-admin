<?php

namespace App\Filament\Resources;

use App\Enums\BudgetRange;
use App\Enums\InquiryStatus;
use App\Enums\ProjectType;
use App\Filament\Resources\InquiryResource\Pages;
use App\Models\Inquiry;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class InquiryResource extends Resource
{
    protected static ?string $model = Inquiry::class;

    protected static ?string $navigationIcon = 'heroicon-o-envelope';

    protected static ?string $navigationLabel = 'Inquiries';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('From the client')
                ->schema([
                    Forms\Components\TextInput::make('name')->disabled(),
                    Forms\Components\TextInput::make('email')->disabled(),
                    Forms\Components\TextInput::make('from_language')->label('From')->disabled(),
                    Forms\Components\TextInput::make('to_language')->label('To')->disabled(),
                    Forms\Components\TextInput::make('project_type')
                        ->formatStateUsing(fn ($state) => ($state instanceof ProjectType
                            ? $state
                            : ProjectType::tryFrom((string) $state))?->getLabel())
                        ->disabled(),
                    Forms\Components\TextInput::make('word_count')->disabled(),
                    Forms\Components\TextInput::make('budget_range')
                        ->formatStateUsing(fn ($state) => ($state instanceof BudgetRange
                            ? $state
                            : BudgetRange::tryFrom((string) $state))?->getLabel())
                        ->disabled(),
                    Forms\Components\DatePicker::make('deadline')->disabled(),
                    Forms\Components\Textarea::make('message')
                        ->disabled()
                        ->rows(5)
                        ->columnSpanFull(),
                ])
                ->columns(2),

            Forms\Components\Section::make('Your triage')
                ->description('The only part of this form you actually edit.')
                ->schema([
                    Forms\Components\Select::make('status')
                        ->options(InquiryStatus::class)
                        ->required(),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Received')
                    ->dateTime('M j, H:i')
                    ->sortable(),
                Tables\Columns\TextColumn::make('translator.name')
                    ->label('Translator')
                    ->toggleable()
                    ->searchable(),
                Tables\Columns\TextColumn::make('name')
                    ->label('From')
                    ->description(fn (Inquiry $record) => $record->email)
                    ->searchable(['name', 'email']),
                Tables\Columns\TextColumn::make('pair')
                    ->label('Language pair')
                    ->state(fn (Inquiry $record) => "{$record->from_language} → {$record->to_language}"),
                Tables\Columns\TextColumn::make('project_type')
                    ->badge()
                    ->sortable(),
                Tables\Columns\TextColumn::make('budget_range')
                    ->label('Budget')
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('deadline')
                    ->date()
                    ->placeholder('—')
                    ->sortable(),
                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options(InquiryStatus::class),
            ])
            ->actions([
                Tables\Actions\Action::make('markReplied')
                    ->label('Mark replied')
                    ->icon('heroicon-m-check-circle')
                    ->color('success')
                    ->visible(fn (Inquiry $record) => $record->status !== InquiryStatus::Replied)
                    ->action(fn (Inquiry $record) => $record->update(['status' => InquiryStatus::Replied])),
                Tables\Actions\Action::make('markSpam')
                    ->label('Mark spam')
                    ->icon('heroicon-m-no-symbol')
                    ->color('danger')
                    ->visible(fn (Inquiry $record) => $record->status !== InquiryStatus::Spam)
                    ->requiresConfirmation()
                    ->action(fn (Inquiry $record) => $record->update(['status' => InquiryStatus::Spam])),
                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()->with('translator');

        if (auth()->user()?->isAdmin()) {
            return $query;
        }

        return $query->whereHas(
            'translator',
            fn (Builder $translators) => $translators->where('user_id', auth()->id()),
        );
    }

    public static function getNavigationBadge(): ?string
    {
        $count = static::getEloquentQuery()->where('status', InquiryStatus::New)->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListInquiries::route('/'),
            'edit' => Pages\EditInquiry::route('/{record}/edit'),
        ];
    }
}
