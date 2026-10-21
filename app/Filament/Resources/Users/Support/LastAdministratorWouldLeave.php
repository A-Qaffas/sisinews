<?php

namespace App\Filament\Resources\Users\Support;

use RuntimeException;

/** Thrown inside TeamGuard's transaction to roll back a change that would leave nobody to manage the team. */
final class LastAdministratorWouldLeave extends RuntimeException {}
