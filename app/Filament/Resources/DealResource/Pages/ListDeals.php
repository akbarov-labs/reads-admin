<?php

namespace App\Filament\Resources\DealResource\Pages;

use App\Filament\Resources\DealResource;
use App\Models\Publisher;
use App\Services\Telegram\DealImporter;
use App\Services\Telegram\TelegramChannelException;
use Filament\Actions;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;

class ListDeals extends ListRecords
{
    protected static string $resource = DealResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('paste')
                ->label('Paste post link')
                ->icon('heroicon-o-link')
                ->modalDescription('In Telegram, open the post about the sale or contest and choose Copy Post Link. It is read from the channel\'s public page and saved as a draft for you to check.')
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
                    Forms\Components\TextInput::make('shop_name')
                        ->label('Or the shop')
                        ->placeholder('Asaxiy, Qamar, Zabarjad…')
                        ->maxLength(64),
                ])
                ->action(function (array $data, Actions\Action $action, DealImporter $importer) {
                    try {
                        [$deal, $created] = $importer->import(
                            $data['link'],
                            filled($data['publisher_id'] ?? null) ? Publisher::find($data['publisher_id']) : null,
                            $data['shop_name'] ?? null,
                        );
                    } catch (TelegramChannelException $e) {
                        Notification::make()->title('Could not read that post')->body($e->getMessage())->danger()->send();
                        $action->halt();

                        return;
                    }

                    Notification::make()->title($created ? 'Draft made from the post' : 'That post was added before')->success()->send();
                    $this->redirect(DealResource::getUrl('edit', ['record' => $deal]));
                }),
            Actions\CreateAction::make()->label('Type in a deal'),
        ];
    }
}
