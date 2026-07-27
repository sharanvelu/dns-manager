<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Field-level drift diff recorded by the drift check: a list of
     * {field, tracked, actual} objects (null while not drifted, or when
     * the record is missing at the provider entirely).
     */
    public function up(): void
    {
        Schema::table('dns_entry_zone_provider', function (Blueprint $table) {
            $table->json('drift_details')->nullable()->after('last_error');
        });
    }

    public function down(): void
    {
        Schema::table('dns_entry_zone_provider', function (Blueprint $table) {
            $table->dropColumn('drift_details');
        });
    }
};
