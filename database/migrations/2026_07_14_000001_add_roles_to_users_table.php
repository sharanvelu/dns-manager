<?php

declare(strict_types = 1);

use App\Enums\Role;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

return new class () extends Migration {
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->json('roles')->default('[]');
        });

        // Before RBAC every user had full access — preserve that for
        // existing installs so nobody is locked out by the upgrade.
        DB::table('users')->update(['roles' => json_encode([Role::SuperAdmin->value])]);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('roles');
        });
    }
};
