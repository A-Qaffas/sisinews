<?php

namespace App\Filament\Resources\Pages\Pages;

use App\Filament\Resources\Pages\PageResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListPages extends ListRecords
{
    protected static string $resource = PageResource::class;

    protected ?string $subheading = 'About, standards, privacy and the like. To take a page off the site, turn off its language instead of deleting it.';

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('New page'),
        ];
    }
}
