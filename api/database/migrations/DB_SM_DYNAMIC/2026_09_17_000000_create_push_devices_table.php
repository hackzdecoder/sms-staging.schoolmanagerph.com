<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migration reference for the push_devices table in dynamic school databases.
 *
 * This table tracks OneSignal player/subscription IDs per user within each school's
 * own database (e.g. svastg, atheneumstg) instead of users_main.
 *
 * NOTE: Run this manually against each school DB that does NOT already have the table:
 *   php artisan migrate --path=database/migrations/DB_SM_DYNAMIC --database=<schoolDbConnection>
 *
 * Tables are already present in svastg and atheneumstg — verify with:
 *   DESCRIBE push_devices;
 */
return new class extends Migration
{
    public function up(): void
    {
        // The connection name here is a placeholder.
        // In practice this table is created directly on each school's DB.
        Schema::create('push_devices', function (Blueprint $table) {
            $table->id();
            $table->string('user_id')->index();
            $table->string('school_code')->index()->nullable();
            $table->string('player_id')->index();
            $table->string('platform')->default('web');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('push_devices');
    }
};
