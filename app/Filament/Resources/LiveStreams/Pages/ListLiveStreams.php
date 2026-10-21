<?php

namespace App\Filament\Resources\LiveStreams\Pages;

use App\Filament\Resources\LiveStreams\LiveStreamResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Contracts\Support\Htmlable;

class ListLiveStreams extends ListRecords
{
    protected static string $resource = LiveStreamResource::class;

    /** Sentence case like the rest of the panel (Filament would title-case the plural label: "Live Broadcasts"). */
    public function getTitle(): string|Htmlable
    {
        return __('Live broadcasts');
    }

    public function getSubheading(): string|Htmlable|null
    {
        return __('Video is streamed by YouTube or Vimeo; the site only shows it. Register a broadcast, then press Go live when it starts and End when it is over.');
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label(__('New broadcast')),
        ];
    }
}
