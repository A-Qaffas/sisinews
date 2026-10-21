<?php

namespace App\Filament\Resources\Users\Pages;

use App\Filament\Resources\Users\Support\AccountActions;
use App\Filament\Resources\Users\Support\PermissionFields;
use App\Filament\Resources\Users\Support\SectionAssignments;
use App\Filament\Resources\Users\Support\TeamGuard;
use App\Filament\Resources\Users\UserResource;
use App\Models\User;
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

        return [...$data, ...PermissionFields::fill($this->record)];
    }

    /**
     * The sections are saved only after the account itself was: a save the last-administrator guard
     * refuses leaves them untouched too. A hidden picker submits nothing, so it never changes them.
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $sections = Arr::pull($data, SectionAssignments::STATE);
        $permissions = PermissionFields::pull($data);

        $record = $this->updateAccount($record, $data, $permissions);

        if (is_array($sections)) {
            SectionAssignments::sync($record, $sections, auth()->user());
        }

        return $record;
    }

    /**
     * The form locks role, status and permissions on your own account and has no password field at all
     * (that is the "Reset password" action); this keeps all of that true for a crafted request.
     * A save that would leave nobody active who can manage the team is refused (TeamGuard checks after the change).
     *
     * @param  array<string, mixed>  $data
     * @param  list<string>|null  $permissions  the ticked permissions, null to leave them as they are
     */
    private function updateAccount(Model $record, array $data, ?array $permissions): Model
    {
        $data = Arr::except($data, ['password']);

        if ($record->is(auth()->user())) {
            $data = Arr::except($data, ['role', 'is_active']);
            $permissions = null;
        }

        $apply = function () use ($record, $data, $permissions): void {
            $record->fill($data);
            if ($permissions !== null && $record instanceof User) {
                // After the role: what is stored is the difference from the (new) role's bundle.
                $record->choosePermissions($permissions);
            }
            $record->save();
        };

        if (! TeamGuard::keepingAnAdministrator([], $apply)) {
            TeamGuard::refuseLastAdministrator();
            // The form still shows the refused values; show what is really stored.
            $record->refresh();
            $this->fillForm();

            throw new Halt;
        }

        return $record;
    }
}
