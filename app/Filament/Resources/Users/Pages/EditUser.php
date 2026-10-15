<?php

namespace App\Filament\Resources\Users\Pages;

use App\Enums\Role;
use App\Filament\Resources\Users\Support\AccountActions;
use App\Filament\Resources\Users\Support\SectionAssignments;
use App\Filament\Resources\Users\Support\TeamGuard;
use App\Filament\Resources\Users\UserResource;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;

class EditUser extends EditRecord
{
    protected static string $resource = UserResource::class;

    /** No delete here on purpose: an account is deactivated so its articles keep their author. */
    protected function getHeaderActions(): array
    {
        return [
            AccountActions::resetPassword(),
            AccountActions::deactivate(),
            AccountActions::activate(),
        ];
    }

    /** The picker shows what is stored even while it is hidden (a role change back to journalist finds it again). */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data[SectionAssignments::STATE] = SectionAssignments::assignedIds($this->record);

        return $data;
    }

    /**
     * The sections are saved only after the account itself was: a save the last-administrator guard
     * refuses leaves them untouched too. A hidden picker submits nothing, so it never changes them.
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $sections = Arr::pull($data, SectionAssignments::STATE);

        $record = $this->updateAccount($record, $data);

        if (is_array($sections)) {
            SectionAssignments::sync($record, $sections, auth()->user());
        }

        return $record;
    }

    /**
     * The form disables these two fields on your own account and has no password field at all
     * (that is the "Reset password" action); this keeps both true for a crafted request.
     * A save that would demote or switch off the last active administrator is refused.
     *
     * @param  array<string, mixed>  $data
     */
    private function updateAccount(Model $record, array $data): Model
    {
        $data = Arr::except($data, ['password']);

        if ($record->is(auth()->user())) {
            $data = Arr::except($data, ['role', 'is_active']);
        }

        if (! $this->stopsBeingAnAdministrator($data)) {
            return parent::handleRecordUpdate($record, $data);
        }

        if (! TeamGuard::keepingAnAdministrator([$record->getKey()], fn () => parent::handleRecordUpdate($record, $data))) {
            TeamGuard::refuseLastAdministrator();
            // The form still shows the refused values; show what is really stored.
            $this->refreshFormData(['role', 'is_active']);

            throw new Halt;
        }

        return $record;
    }

    /**
     * Whether the submitted values take an account out of the "active administrator" group. Judged on the
     * new values alone: if the account is not an active administrator now, the guard has nothing to protect.
     *
     * @param  array<string, mixed>  $data
     */
    private function stopsBeingAnAdministrator(array $data): bool
    {
        $role = $data['role'] ?? null;
        $role = $role instanceof Role ? $role : Role::tryFrom((string) $role);

        return ($role !== null && $role !== Role::Admin)
            || (array_key_exists('is_active', $data) && ! $data['is_active']);
    }
}
