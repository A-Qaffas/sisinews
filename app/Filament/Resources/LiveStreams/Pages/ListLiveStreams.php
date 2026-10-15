<?php

namespace App\Filament\Resources\LiveStreams\Pages;

use App\Filament\Resources\LiveStreams\LiveStreamResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListLiveStreams extends ListRecords
{
    protected static string $resource = LiveStreamResource::class;

    /** Sentence case like the rest of the panel (Filament would title-case the plural label: "Live Broadcasts"). */
    protected static ?string $title = 'Live broadcasts';

    protected ?string $subheading = 'Video is streamed by YouTube or Vimeo; the site only shows it. Register a broadcast, then press Go live when it starts and End when it is over.';

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('New broadcast'),
        ];
    }
}
