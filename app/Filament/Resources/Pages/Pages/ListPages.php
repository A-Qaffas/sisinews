<?php

namespace App\Filament\Resources\Pages\Pages;

use App\Filament\Resources\Pages\PageResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Contracts\Support\Htmlable;

class ListPages extends ListRecords
{
    protected static string $resource = PageResource::class;

    public function getSubheading(): string|Htmlable|null
    {
        return __('About, standards, privacy and the like. To take a page off the site, turn off its language instead of deleting it.');
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label(__('New page')),
        ];
    }
}
