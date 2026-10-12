<?php

namespace App\Filament\Resources;

use App\Enums\ModerationStatus;
use App\Filament\Resources\ReaderReviewResource\Pages;
use App\Models\ReaderReview;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;

/**
 * Reviews Kitoblarim bot readers chose to show on the site. Nothing is
 * written here: an admin only publishes or rejects. The text is the
 * reader's, and an edit in the bot sends it back to this queue.
 */
class ReaderReviewResource extends Resource
{
    protected static ?string $model = ReaderReview::class;

    protected static ?string $navigationIcon = 'heroicon-o-chat-bubble-left-ellipsis';

    protected static ?string $navigationLabel = 'Reader reviews';

    protected static ?string $modelLabel = 'reader review';

    public static function shouldRegisterNavigation(): bool
    {
        return auth()->user()?->isAdmin() ?? false;
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Infolists\Components\TextEntry::make('book.uzbek_title')->label('Book'),
            Infolists\Components\TextEntry::make('edition.isbn')->label('ISBN')->placeholder('—'),
            Infolists\Components\TextEntry::make('author_name')->label('Reader')->placeholder('No name'),
            Infolists\Components\TextEntry::make('rating')->label('Stars')->placeholder('—'),
            Infolists\Components\TextEntry::make('status')->badge(),
            Infolists\Components\TextEntry::make('written_at')->label('Written')->dateTime('M j, Y H:i'),
            Infolists\Components\TextEntry::make('body')->label('Review')->columnSpanFull(),
        ])->columns(3);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('written_at')
                    ->label('Written')
                    ->dateTime('M j, H:i')
                    ->sortable(),
                Tables\Columns\TextColumn::make('book.uzbek_title')
                    ->label('Book')
                    ->searchable(),
                Tables\Columns\TextColumn::make('author_name')
                    ->label('Reader')
                    ->placeholder('No name'),
                Tables\Columns\TextColumn::make('rating')
                    ->label('Stars')
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('body')
                    ->label('Review')
                    ->limit(120)
                    ->wrap()
                    ->searchable(),
                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->sortable(),
            ])
            ->defaultSort('written_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options(ModerationStatus::class)
                    ->default(ModerationStatus::Pending->value),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\Action::make('publish')
                    ->label('Publish')
                    ->icon('heroicon-m-check-circle')
                    ->color('success')
                    ->visible(fn (ReaderReview $record) => $record->status !== ModerationStatus::Approved)
                    ->action(fn (ReaderReview $record) => $record->update(['status' => ModerationStatus::Approved])),
                Tables\Actions\Action::make('reject')
                    ->label('Reject')
                    ->icon('heroicon-m-no-symbol')
                    ->color('danger')
                    ->visible(fn (ReaderReview $record) => $record->status !== ModerationStatus::Rejected)
                    ->requiresConfirmation()
                    ->action(fn (ReaderReview $record) => $record->update(['status' => ModerationStatus::Rejected])),
            ])
            ->bulkActions([
                Tables\Actions\BulkAction::make('publishSelected')
                    ->label('Publish selected')
                    ->icon('heroicon-m-check-circle')
                    ->color('success')
                    ->action(fn (Collection $records) => $records->each->update(['status' => ModerationStatus::Approved])),
                Tables\Actions\BulkAction::make('rejectSelected')
                    ->label('Reject selected')
                    ->icon('heroicon-m-no-symbol')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->action(fn (Collection $records) => $records->each->update(['status' => ModerationStatus::Rejected])),
            ]);
    }

    public static function getNavigationBadge(): ?string
    {
        $count = ReaderReview::query()->where('status', ModerationStatus::Pending)->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListReaderReviews::route('/'),
        ];
    }
}
