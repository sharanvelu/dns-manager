<?php

declare(strict_types = 1);

use Illuminate\Support\Facades\DB;
use Illuminate\Database\Migrations\Migration;

/**
 * Role-system redesign: the old global roles (dns-manager, providers-manager,
 * viewer) are replaced by SUPER_ADMIN / SUPER_VIEWER / USER_ADMIN plus
 * per-zone grants. Existing super-admins keep full access; every other user
 * becomes a read-only SUPER_VIEWER until an admin grants zone access.
 * String literals only — this migration must stay valid as enums evolve.
 */
return new class () extends Migration {
    public function up(): void
    {
        DB::table('users')->get(['id', 'roles'])->each(function ($row) {
            $roles = json_decode($row->roles ?? '[]', true) ?: [];

            $mapped = in_array('super-admin', $roles, true)
                ? ['super-admin']
                : ['super-viewer'];

            DB::table('users')->where('id', $row->id)->update(['roles' => json_encode($mapped)]);
        });
    }

    public function down(): void
    {
        // Lossy remap by design — the old role set no longer exists.
    }
};
