<?php

namespace App\Filament\Resources\LiveStreams\Pages;

use App\Filament\Resources\LiveStreams\LiveStreamResource;
use App\Filament\Resources\LiveStreams\Support\LiveStreamWriter;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateLiveStream extends CreateRecord
{
    protected static string $resource = LiveStreamResource::class;

    protected static bool $canCreateAnother = false;

    protected static ?string $title = 'New broadcast';

    protected function handleRecordCreation(array $data): Model
    {
        return LiveStreamWriter::create($data, auth()->user());
    }
}
