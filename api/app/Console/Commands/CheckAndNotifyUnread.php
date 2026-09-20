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
     *
     * Strategy:
     *  1. Fetch all distinct school_codes that exist in users_main.users.
     *  2. For each school_code, connect to its dynamic database.
     *  3. Read push_devices FROM that school DB (not users_main).
     *  4. For each registered user, check unread messages (already school DB).
     *  5. Read/write notification_logs FROM that school DB (not users_main).
     */
    public function handle()
    {
        $this->info('Checking for new unread records...');

        // Get all distinct school codes that have users registered
        $schoolCodes = DB::connection('users_main')
            ->table('users')
            ->whereNotNull('school_code')
            ->where('school_code', '!=', '')
            ->distinct()
            ->pluck('school_code');

        if ($schoolCodes->isEmpty()) {
            $this->info('No school codes found. Skipping.');
            return;
        }

        $totalNotificationsSent = 0;

        foreach ($schoolCodes as $schoolCode) {
            if (!$schoolCode) {
                continue;
            }

            try {
                // Connect to this school's dynamic database
                $databaseName = DatabaseManager::generateDatabaseName($schoolCode);
                $schoolDb     = DatabaseManager::connect($databaseName);

                // Get all users who have active push devices registered IN THE SCHOOL DB
                $usersWithDevices = $schoolDb->table('push_devices')
                    ->where('is_active', true)
                    ->select('user_id', 'school_code')
                    ->distinct()
                    ->get();

                if ($usersWithDevices->isEmpty()) {
                    // No registered devices for this school — skip quietly
                    DatabaseManager::disconnect($databaseName);
                    continue;
                }

                // Fetch school name for notification titles
                $schoolRecord = DB::connection('idrs_school')
                    ->table('school_id')
                    ->where('school_code', $schoolCode)
                    ->first();
                $schoolName = $schoolRecord ? $schoolRecord->school_name : 'New Message';

                foreach ($usersWithDevices as $userDevice) {
                    $userId = $userDevice->user_id;

                    // --- Check for new unread MESSAGES ---
                    $totalNotificationsSent += $this->checkMessages(
                        $schoolDb,
                        $userId,
                        $schoolCode,
                        $schoolName
                    );
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
     * Check for new unread attendance records and send notifications.
     * Both the attendance data AND the notification_log are in the school DB.
     *
     * NOTE: Currently excluded per requirement (only messages trigger notifications).
     * Kept here for future use.
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
                // Check if we already notified about this record (from the school DB)
                $alreadyNotified = $schoolDb->table('notification_logs')
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
                $timeIn      = $record->time_in ?? '';
                $timeOut     = $record->time_out ?? '';
                $date        = $record->date ?? date('Y-m-d');

                $title   = '📋 New Attendance Record';
                $message = "{$studentName} - {$date}";
                if ($timeIn) {
                    $message .= " | In: {$timeIn}";
                }
                if ($timeOut) {
                    $message .= " | Out: {$timeOut}";
                }

                // Send push notification (reads push_devices from school DB internally)
                $success = NotificationService::sendToUser(
                    $userId,
                    $schoolCode,
                    $title,
                    $message,
                    [
                        'type'        => 'attendance',
                        'record_id'   => $record->id,
                        'school_code' => $schoolCode,
                    ]
                );

                // Log it in the school DB so we don't send again
                $schoolDb->table('notification_logs')->insert([
                    'user_id'          => $userId,
                    'school_code'      => $schoolCode,
                    'record_type'      => 'attendance',
                    'record_id'        => $record->id,
                    'sent_successfully'=> $success,
                    'notified_at'      => now(),
                    'created_at'       => now(),
                    'updated_at'       => now(),
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
     * Check for new unread messages and send notifications.
     * Both the messages data AND the notification_log are in the school DB.
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
                // Check if we already notified about this record (from the school DB)
                $alreadyNotified = $schoolDb->table('notification_logs')
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

                $title   = '💬 ' . $schoolName;
                $message = $subject;
                if ($preview) {
                    $message .= ": {$preview}";
                    if (strlen($record->message) > 80) {
                        $message .= '...';
                    }
                }

                // Send push notification (reads push_devices from school DB internally)
                $success = NotificationService::sendToUser(
                    $userId,
                    $schoolCode,
                    $title,
                    $message,
                    [
                        'type'        => 'message',
                        'record_id'   => $record->id,
                        'school_code' => $schoolCode,
                    ]
                );

                // Log it in the school DB so we don't send again
                $schoolDb->table('notification_logs')->insert([
                    'user_id'          => $userId,
                    'school_code'      => $schoolCode,
                    'record_type'      => 'message',
                    'record_id'        => $record->id,
                    'sent_successfully'=> $success,
                    'notified_at'      => now(),
                    'created_at'       => now(),
                    'updated_at'       => now(),
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
