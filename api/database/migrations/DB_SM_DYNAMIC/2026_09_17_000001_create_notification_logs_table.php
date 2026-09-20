<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migration reference for the notification_logs table in dynamic school databases.
 *
 * This table is a dedup log: it records which attendance/message records have already
 * triggered a push notification so the cron never sends duplicates. Each school's DB
 * owns its own log instead of a single shared log in users_main.
 *
 * NOTE: Run this manually against each school DB that does NOT already have the table:
 *   php artisan migrate --path=database/migrations/DB_SM_DYNAMIC --database=<schoolDbConnection>
 *
 * Tables are already present in svastg and atheneumstg — verify with:
 *   DESCRIBE notification_logs;
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_logs', function (Blueprint $table) {
            $table->id();
            $table->string('user_id')->index();
            $table->string('school_code')->index();
            $table->string('record_type')->index(); // 'attendance' or 'message'
            $table->unsignedBigInteger('record_id');
            $table->boolean('sent_successfully')->default(false);
            $table->timestamp('notified_at')->useCurrent();
            $table->timestamps();

            // Composite unique to prevent duplicate notifications
            $table->unique(['user_id', 'school_code', 'record_type', 'record_id'], 'unique_notification');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_logs');
    }
};
