<?php

namespace App\Filament\Resources\Users\Pages;

use App\Filament\Resources\Users\UserResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;

class ListUsers extends ListRecords
{
    protected static string $resource = UserResource::class;

    protected ?string $subheading = 'Deactivate instead of deleting: stories keep their author.';

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('New team member')->icon(Heroicon::OutlinedUserPlus),
        ];
    }
}
