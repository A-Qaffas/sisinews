<?php

namespace App\Console\Commands;

use App\Enums\Role;
use App\Filament\Resources\Users\Support\TeamGuard;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Symfony\Component\Console\Exception\RuntimeException as ConsoleException;

/**
 * The only way to get the first admin onto a server: v1 has no registration and no
 * seeded accounts in production. The password is never printed.
 */
#[Signature('sisi:create-admin
    {email : Address the admin signs in with}
    {--name= : Display name in Latin script (defaults to the part of the email before the @)}
    {--name-ar= : Display name in Arabic}
    {--password= : Password. Leave it out to be asked for it without echo; a value here stays in shell history}
    {--reset-password : Set a new password for an existing admin instead of refusing}')]
#[Description("Create an active admin account for the staff panel, or reset an admin's password")]
class CreateAdminCommand extends Command
{
    private const MIN_PASSWORD_LENGTH = 12;

    public function handle(): int
    {
        $email = Str::lower(trim((string) $this->argument('email')));

        $validator = Validator::make(
            ['email' => $email, 'name' => $this->option('name'), 'name_ar' => $this->option('name-ar')],
            ['email' => ['required', 'email:rfc', 'max:255'], 'name' => ['nullable', 'string', 'max:255'], 'name_ar' => ['nullable', 'string', 'max:255']],
        );
        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                $this->components->error($message);
            }

            return self::FAILURE;
        }

        // The byline address is not a person: an admin on it would be invisible in the Team area and could never be switched off there.
        if (TeamGuard::isReservedEmail($email)) {
            $this->components->error("{$email} is reserved for the newsroom byline and cannot be an admin account. Use another address.");

            return self::FAILURE;
        }

        $existing = User::query()->where('email', $email)->first();
        $reset = (bool) $this->option('reset-password');

        if ($existing === null && $reset) {
            $this->components->error("There is no account for {$email} to reset. Run again without --reset-password to create it.");

            return self::FAILURE;
        }
        if ($existing !== null && ! $reset) {
            $this->components->error("An account for {$email} already exists. Run again with --reset-password to give it a new password.");

            return self::FAILURE;
        }
        if ($existing !== null && ! $existing->isAdmin()) {
            $this->components->error("{$email} is a {$existing->role->value}, not an admin. This command only manages admin accounts; change roles in the admin panel.");

            return self::FAILURE;
        }

        $password = $this->readPassword();
        if ($password === null) {
            return self::FAILURE;
        }

        if ($existing !== null) {
            $existing->forceFill(['password' => $password, 'is_active' => true])->save();
            $this->components->info("New password set for {$email}; the account is active.");

            return self::SUCCESS;
        }

        User::query()->create([
            'name' => $this->option('name') ?: Str::headline(Str::replace('.', ' ', Str::before($email, '@'))),
            'name_ar' => $this->option('name-ar') ?: null,
            'email' => $email,
            'password' => $password,
            'role' => Role::Admin,
            'is_active' => true,
        ]);

        $this->components->info("Admin account created for {$email}.");
        $this->line('Sign in at '.url('/admin'));

        return self::SUCCESS;
    }

    /** The validated password from --password or a hidden prompt; null after an error has been printed. */
    private function readPassword(): ?string
    {
        $password = $this->option('password');
        $prompted = ! filled($password);

        if ($prompted) {
            $password = $this->input->isInteractive() ? $this->askHidden('Password (at least '.self::MIN_PASSWORD_LENGTH.' characters)') : null;

            if (! filled($password)) {
                $this->components->error('No password given. Run interactively to be prompted, or pass --password.');

                return null;
            }
        }

        $problem = $this->passwordProblem((string) $password);
        if ($problem !== null) {
            $this->components->error($problem);

            return null;
        }

        if ($prompted && $this->askHidden('Confirm password') !== $password) {
            $this->components->error('The two passwords do not match.');

            return null;
        }

        return $password;
    }

    /** Typed input is never echoed; a terminal that cannot hide it is refused rather than shown in clear. */
    private function askHidden(string $question): ?string
    {
        try {
            return $this->secret($question, false);
        } catch (ConsoleException) {
            $this->components->error('This terminal cannot hide typed input. Run the command from a normal shell, or pass --password.');

            return null;
        }
    }

    private function passwordProblem(string $password): ?string
    {
        if (mb_strlen($password) < self::MIN_PASSWORD_LENGTH) {
            return 'The password must be at least '.self::MIN_PASSWORD_LENGTH.' characters long.';
        }
        if (Str::contains($password, 'password', ignoreCase: true)) {
            return 'The password must not contain the word "password".';
        }

        return null;
    }
}
