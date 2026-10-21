<?php

namespace App\Filament\Resources\Users\Pages;

use App\Filament\Resources\Users\Support\SectionAssignments;
use App\Filament\Resources\Users\UserResource;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;

class CreateUser extends CreateRecord
{
    protected static string $resource = UserResource::class;

    protected static bool $canCreateAnother = false;

    public function getTitle(): string|Htmlable
    {
        return __('New team member');
    }

    /** The sections are not a column: they are stored in the pivot once the account exists. */
    protected function handleRecordCreation(array $data): Model
    {
        $sections = Arr::pull($data, SectionAssignments::STATE);

        $record = parent::handleRecordCreation($data);

        if (is_array($sections)) {
            SectionAssignments::sync($record, $sections, auth()->user());
        }

        return $record;
    }
}
