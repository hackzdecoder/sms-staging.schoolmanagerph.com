<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Helpers\DatabaseManager;
use App\Services\NotificationService;

class CheckAndNotifyUnread extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'notifications:check-unread';

    /**
     * The console command description.
     */
    protected $description = 'Check for new unread attendance/messages and send push notifications automatically';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Checking for new unread records...');

        // Get all users who have active push devices registered
        $usersWithDevices = DB::connection('users_main')
            ->table('push_devices')
            ->where('is_active', true)
            ->select('user_id', 'school_code')
            ->distinct()
            ->get();

        if ($usersWithDevices->isEmpty()) {
            $this->info('No users with active devices found. Skipping.');
            return;
        }

        $totalNotificationsSent = 0;

        // Group by school_code so we connect to each school DB only once
        $grouped = $usersWithDevices->groupBy('school_code');

        foreach ($grouped as $schoolCode => $users) {
            if (!$schoolCode) {
                continue;
            }

            try {
                // Fetch school name
                $schoolRecord = DB::connection('idrs_school')
                    ->table('school_id')
                    ->where('school_code', $schoolCode)
                    ->first();
                $schoolName = $schoolRecord ? $schoolRecord->school_name : 'New Message';

                // Connect to this school's database
                $databaseName = DatabaseManager::generateDatabaseName($schoolCode);
                $schoolDb = DatabaseManager::connect($databaseName);

                foreach ($users as $userDevice) {
                    $userId = $userDevice->user_id;

                    // --- Check for new unread ATTENDANCE records ---
                    $totalNotificationsSent += $this->checkAttendance($schoolDb, $userId, $schoolCode);

                    // --- Check for new unread MESSAGES ---
                    $totalNotificationsSent += $this->checkMessages($schoolDb, $userId, $schoolCode, $schoolName);
                }

                // Disconnect from school DB to free resources
                DatabaseManager::disconnect($databaseName);

            } catch (\Exception $e) {
                Log::error("CheckAndNotifyUnread: Error processing school {$schoolCode}: " . $e->getMessage());
                $this->error("Error processing school {$schoolCode}: " . $e->getMessage());
            }
        }

        $this->info("Done. Sent {$totalNotificationsSent} notification(s).");
    }

    /**
     * Check for new unread attendance records and send notifications
     */
    private function checkAttendance($schoolDb, string $userId, string $schoolCode): int
    {
        $sent = 0;

        try {
            // Find unread attendance records for this user
            $unreadRecords = $schoolDb->table('attendance_records')
                ->where('user_id', $userId)
                ->where('school_code', $schoolCode)
                ->where('status', 'unread')
                ->select('id', 'full_name', 'time_in', 'time_out', 'date', 'created_at')
                ->get();

            foreach ($unreadRecords as $record) {
                // Check if we already notified about this record
                $alreadyNotified = DB::connection('users_main')
                    ->table('notification_logs')
                    ->where('user_id', $userId)
                    ->where('school_code', $schoolCode)
                    ->where('record_type', 'attendance')
                    ->where('record_id', $record->id)
                    ->exists();

                if ($alreadyNotified) {
                    continue;
                }

                // Build notification message
                $studentName = $record->full_name ?? 'Your student';
                $timeIn = $record->time_in ?? '';
                $timeOut = $record->time_out ?? '';
                $date = $record->date ?? date('Y-m-d');

                $title = '📋 New Attendance Record';
                $message = "{$studentName} - {$date}";
                if ($timeIn) {
                    $message .= " | In: {$timeIn}";
                }
                if ($timeOut) {
                    $message .= " | Out: {$timeOut}";
                }

                // Send push notification
                $success = NotificationService::sendToUser(
                    $userId,
                    $title,
                    $message,
                    [
                        'type' => 'attendance',
                        'record_id' => $record->id,
                        'school_code' => $schoolCode,
                    ]
                );

                // Log it so we don't send again
                DB::connection('users_main')->table('notification_logs')->insert([
                    'user_id' => $userId,
                    'school_code' => $schoolCode,
                    'record_type' => 'attendance',
                    'record_id' => $record->id,
                    'sent_successfully' => $success,
                    'notified_at' => now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                if ($success) {
                    $sent++;
                }
            }
        } catch (\Exception $e) {
            Log::error("CheckAndNotifyUnread: Attendance error for user {$userId}: " . $e->getMessage());
        }

        return $sent;
    }

    /**
     * Check for new unread messages and send notifications
     */
    private function checkMessages($schoolDb, string $userId, string $schoolCode, string $schoolName = 'New Message'): int
    {
        $sent = 0;

        try {
            // Find unread messages for this user
            $unreadRecords = $schoolDb->table('messages')
                ->where('user_id', $userId)
                ->where('school_code', $schoolCode)
                ->where('status', 'unread')
                ->select('id', 'subject', 'message', 'date', 'created_at')
                ->get();

            foreach ($unreadRecords as $record) {
                // Check if we already notified about this record
                $alreadyNotified = DB::connection('users_main')
                    ->table('notification_logs')
                    ->where('user_id', $userId)
                    ->where('school_code', $schoolCode)
                    ->where('record_type', 'message')
                    ->where('record_id', $record->id)
                    ->exists();

                if ($alreadyNotified) {
                    continue;
                }

                // Build notification message
                $subject = $record->subject ?? 'New Message';
                $preview = $record->message ? substr($record->message, 0, 80) : '';

                $title = '💬 ' . $schoolName;
                $message = $subject;
                if ($preview) {
                    $message .= ": {$preview}";
                    if (strlen($record->message) > 80) {
                        $message .= '...';
                    }
                }

                // Send push notification
                $success = NotificationService::sendToUser(
                    $userId,
                    $title,
                    $message,
                    [
                        'type' => 'message',
                        'record_id' => $record->id,
                        'school_code' => $schoolCode,
                    ]
                );

                // Log it so we don't send again
                DB::connection('users_main')->table('notification_logs')->insert([
                    'user_id' => $userId,
                    'school_code' => $schoolCode,
                    'record_type' => 'message',
                    'record_id' => $record->id,
                    'sent_successfully' => $success,
                    'notified_at' => now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                if ($success) {
                    $sent++;
                }
            }
        } catch (\Exception $e) {
            Log::error("CheckAndNotifyUnread: Messages error for user {$userId}: " . $e->getMessage());
        }

        return $sent;
    }
}
