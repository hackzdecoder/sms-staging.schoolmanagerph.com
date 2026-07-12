<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * This table tracks which attendance/message records have already triggered
     * a push notification, so we don't send duplicates.
     */
    public function up(): void
    {
        Schema::connection('users_main')->create('notification_logs', function (Blueprint $table) {
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

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('users_main')->dropIfExists('notification_logs');
    }
};
