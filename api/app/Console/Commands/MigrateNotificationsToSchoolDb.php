<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use App\Helpers\DatabaseManager;

class MigrateNotificationsToSchoolDb extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'notifications:migrate-to-school-db
                            {--dry-run : Show what would be migrated without actually inserting}';

    /**
     * The console command description.
     */
    protected $description = 'One-time migration: create push_devices/notification_logs tables in each school DB (if missing) and copy data from users_main';

    public function handle()
    {
        $isDryRun = $this->option('dry-run');

        if ($isDryRun) {
            $this->warn('--- DRY RUN MODE: No data will be inserted ---');
        }

        $this->info('Starting migration of push_devices and notification_logs to school databases...');
        $this->newLine();

        // Get all distinct school codes from users_main.users
        $schoolCodes = DB::connection('users_main')
            ->table('users')
            ->whereNotNull('school_code')
            ->where('school_code', '!=', '')
            ->distinct()
            ->pluck('school_code');

        if ($schoolCodes->isEmpty()) {
            $this->info('No school codes found in users_main.users. Nothing to migrate.');
            return 0;
        }

        $this->info("Found " . $schoolCodes->count() . " school code(s): " . $schoolCodes->implode(', '));
        $this->newLine();

        foreach ($schoolCodes as $schoolCode) {
            $this->info("── Processing school: {$schoolCode} ──");

            try {
                // Connect to the school's dynamic database
                $databaseName = DatabaseManager::generateDatabaseName($schoolCode);
                $schoolDb     = DatabaseManager::connect($databaseName);

                // ── STEP 1: Create push_devices table if it doesn't exist ──
                if (!$schoolDb->getSchemaBuilder()->hasTable('push_devices')) {
                    $this->line("  push_devices table missing — creating...");
                    if (!$isDryRun) {
                        $schoolDb->getSchemaBuilder()->create('push_devices', function (Blueprint $table) {
                            $table->id();
                            $table->string('user_id')->index();
                            $table->string('school_code')->index()->nullable();
                            $table->string('player_id')->index();
                            $table->string('platform')->default('web');
                            $table->boolean('is_active')->default(true);
                            $table->timestamps();
                        });
                        $this->line("  push_devices table created ✅");
                    } else {
                        $this->line("  [dry-run] push_devices table would be created");
                    }
                } else {
                    $this->line("  push_devices table already exists ✅");
                }

                // ── STEP 2: Create notification_logs table if it doesn't exist ──
                if (!$schoolDb->getSchemaBuilder()->hasTable('notification_logs')) {
                    $this->line("  notification_logs table missing — creating...");
                    if (!$isDryRun) {
                        $schoolDb->getSchemaBuilder()->create('notification_logs', function (Blueprint $table) {
                            $table->id();
                            $table->string('user_id')->index();
                            $table->string('school_code')->index();
                            $table->string('record_type')->index(); // 'attendance' or 'message'
                            $table->unsignedBigInteger('record_id');
                            $table->boolean('sent_successfully')->default(false);
                            $table->timestamp('notified_at')->useCurrent();
                            $table->timestamps();

                            $table->unique(['user_id', 'school_code', 'record_type', 'record_id'], 'unique_notification');
                        });
                        $this->line("  notification_logs table created ✅");
                    } else {
                        $this->line("  [dry-run] notification_logs table would be created");
                    }
                } else {
                    $this->line("  notification_logs table already exists ✅");
                }

                // ── STEP 3: Migrate push_devices data from users_main ──
                $devices = DB::connection('users_main')
                    ->table('push_devices')
                    ->where('school_code', $schoolCode)
                    ->get();

                $deviceInserted = 0;
                $deviceSkipped  = 0;

                foreach ($devices as $device) {
                    $exists = $schoolDb->table('push_devices')
                        ->where('player_id', $device->player_id)
                        ->exists();

                    if ($exists) {
                        $deviceSkipped++;
                        continue;
                    }

                    if (!$isDryRun) {
                        $schoolDb->table('push_devices')->insert([
                            'user_id'    => $device->user_id,
                            'school_code'=> $device->school_code,
                            'player_id'  => $device->player_id,
                            'platform'   => $device->platform,
                            'is_active'  => $device->is_active,
                            'created_at' => $device->created_at,
                            'updated_at' => $device->updated_at,
                        ]);
                    }
                    $deviceInserted++;
                }

                $this->line("  push_devices   → inserted: {$deviceInserted}, skipped: {$deviceSkipped}");

                // ── STEP 4: Migrate notification_logs data from users_main ──
                $logs = DB::connection('users_main')
                    ->table('notification_logs')
                    ->where('school_code', $schoolCode)
                    ->get();

                $logInserted = 0;
                $logSkipped  = 0;

                foreach ($logs as $log) {
                    $exists = $schoolDb->table('notification_logs')
                        ->where('user_id',     $log->user_id)
                        ->where('school_code', $log->school_code)
                        ->where('record_type', $log->record_type)
                        ->where('record_id',   $log->record_id)
                        ->exists();

                    if ($exists) {
                        $logSkipped++;
                        continue;
                    }

                    if (!$isDryRun) {
                        $schoolDb->table('notification_logs')->insert([
                            'user_id'          => $log->user_id,
                            'school_code'      => $log->school_code,
                            'record_type'      => $log->record_type,
                            'record_id'        => $log->record_id,
                            'sent_successfully'=> $log->sent_successfully,
                            'notified_at'      => $log->notified_at,
                            'created_at'       => $log->created_at,
                            'updated_at'       => $log->updated_at,
                        ]);
                    }
                    $logInserted++;
                }

                $this->line("  notification_logs → inserted: {$logInserted}, skipped: {$logSkipped}");

            } catch (\Exception $e) {
                $this->error("  ERROR for school {$schoolCode}: " . $e->getMessage());
                Log::error("MigrateNotificationsToSchoolDb: Error for school {$schoolCode}: " . $e->getMessage());
            }

            $this->newLine();
        }

        if ($isDryRun) {
            $this->warn('--- DRY RUN complete. Run without --dry-run to apply. ---');
        } else {
            $this->info('Migration complete. ✅');
            $this->info('You can now verify by checking push_devices and notification_logs in each school database.');
        }

        return 0;
    }
}
