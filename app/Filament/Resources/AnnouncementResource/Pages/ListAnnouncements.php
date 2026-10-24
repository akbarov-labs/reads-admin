<?php

namespace App\Filament\Resources\AnnouncementResource\Pages;

use App\Enums\ModerationStatus;
use App\Filament\Resources\AnnouncementResource;
use App\Models\Publisher;
use App\Services\Telegram\AnnouncementImporter;
use App\Services\Telegram\TelegramChannelException;
use Filament\Actions;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Components\Tab;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;

class ListAnnouncements extends ListRecords
{
    protected static string $resource = AnnouncementResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('paste')
                ->label('Paste post link')
                ->icon('heroicon-o-link')
                ->modalDescription('In Telegram, open the post on the publisher\'s channel and choose Copy Post Link. The post is read from the channel\'s public page and saved as a draft for you to check.')
                ->form([
                    Forms\Components\TextInput::make('link')
                        ->label('Post link')
                        ->placeholder('https://t.me/channel/123')
                        ->required(),
                    Forms\Components\Select::make('publisher_id')
                        ->label('Publisher')
                        ->helperText('Only if the channel is not on a publisher yet.')
                        ->options(fn () => Publisher::query()->orderBy('name')->pluck('name', 'id')->all())
                        ->searchable(),
                ])
                ->action(function (array $data, Actions\Action $action, AnnouncementImporter $importer) {
                    try {
                        [$announcement, $created] = $importer->import(
                            $data['link'],
                            filled($data['publisher_id'] ?? null) ? Publisher::find($data['publisher_id']) : null,
                        );
                    } catch (TelegramChannelException $e) {
                        Notification::make()->title('Could not read that post')->body($e->getMessage())->danger()->send();
                        $action->halt();

                        return;
                    }

                    Notification::make()
                        ->title($created ? 'Draft made from the post' : 'That post was added before')
                        ->success()
                        ->send();

                    $this->redirect(AnnouncementResource::getUrl('edit', ['record' => $announcement]));
                }),
        ];
    }

    public function getTabs(): array
    {
        $status = fn (ModerationStatus $status) => fn (Builder $query) => $query->where('status', $status);

        return [
            'pending' => Tab::make('Awaiting review')->modifyQueryUsing($status(ModerationStatus::Pending)),
            'live' => Tab::make('On the site')->modifyQueryUsing(fn (Builder $query) => $query->live()),
            'approved' => Tab::make('Published')->modifyQueryUsing($status(ModerationStatus::Approved)),
            'rejected' => Tab::make('Rejected')->modifyQueryUsing($status(ModerationStatus::Rejected)),
            'all' => Tab::make('All'),
        ];
    }
}
