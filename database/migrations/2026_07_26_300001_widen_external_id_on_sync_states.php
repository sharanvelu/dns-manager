<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Technitium external ids encode the whole record tuple including its
     * rData — a TXT value alone may be ~2 KB — so varchar(255) truncates.
     * sqlite (tests) never enforced the limit; Postgres (production) does.
     */
    public function up(): void
    {
        Schema::table('dns_entry_zone_provider', function (Blueprint $table) {
            $table->text('external_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('dns_entry_zone_provider', function (Blueprint $table) {
            $table->string('external_id')->nullable()->change();
        });
    }
};
