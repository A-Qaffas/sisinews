<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-person changes to a role's ready-made permission bundle (App\Enums\Role::permissions()): what an administrator
 * ticked on top of it, and what they unticked. Both null for everyone at first, so every account keeps exactly the
 * access its role gave it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->json('permissions_granted')->nullable()->after('role');
            $table->json('permissions_revoked')->nullable()->after('permissions_granted');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['permissions_granted', 'permissions_revoked']);
        });
    }
};
