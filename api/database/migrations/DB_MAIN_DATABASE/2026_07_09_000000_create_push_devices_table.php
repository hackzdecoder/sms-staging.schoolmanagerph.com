<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::connection('users_main')->create('push_devices', function (Blueprint $table) {
            $table->id();
            $table->string('user_id')->index();
            $table->string('school_code')->index()->nullable();
            $table->string('player_id')->index();
            $table->string('platform')->default('web');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('users_main')->dropIfExists('push_devices');
    }
};
