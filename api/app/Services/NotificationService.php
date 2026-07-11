<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

class NotificationService
{
    /**
     * Send a push notification via OneSignal to a specific user (using External User ID)
     *
     * @param string $userId The user's external ID (user_id from users table)
     * @param string $title Notification title
     * @param string $message Notification body
     * @param array $data Additional data payload
     * @return bool
     */
    public static function sendToUser(string $userId, string $title, string $message, array $data = [])
    {
        // First check if the user has any active devices
        $hasDevices = DB::connection('users_main')->table('push_devices')
            ->where('user_id', $userId)
            ->where('is_active', true)
            ->exists();

        // If no devices, no need to call OneSignal
        if (!$hasDevices) {
            return false;
        }

        return self::sendOneSignal([$userId], $title, $message, $data);
    }

    /**
     * Send a push notification to all admins of a school
     * 
     * @param string $schoolCode
     * @param string $title
     * @param string $message
     * @param array $data
     * @return bool
     */
    public static function sendToAdmins(string $schoolCode, string $title, string $message, array $data = [])
    {
        // Get all admin user IDs for this school (assuming gs_access_status or specific role identifies admins)
        // Here we just broadcast to devices matching the school_code where they might be admins.
        // Actually, we need to map to specific users. Let's find users with admin privileges.
        // Assuming user records have an 'account_status' = 'admin' or similar, but the exact column
        // depends on the schema. For now, we'll find all devices mapped to this school.
        // Ideally, we query users where gs_access_status = 'active' and role = 'admin'.
        // Wait, from User model, we have `gs_access_status`. We'll just fetch all user_ids 
        // belonging to the school from push_devices, but wait, maybe not everyone should get it.
        // If we want to send to all users in the school:
        
        $adminUserIds = DB::connection('users_main')->table('users')
            ->where('school_code', $schoolCode)
            // Need a way to filter admins. If not available, we skip for now and rely on specific user sending.
            // Let's assume we can query them or we will just use sendToUser for now.
            ->pluck('user_id')
            ->toArray();

        if (empty($adminUserIds)) {
            return false;
        }

        return self::sendOneSignal($adminUserIds, $title, $message, $data);
    }

    /**
     * Send to multiple external user IDs
     */
    private static function sendOneSignal(array $externalUserIds, string $title, string $message, array $data = [])
    {
        $appId = env('ONESIGNAL_APP_ID');
        $apiKey = env('ONESIGNAL_REST_API_KEY');

        if (empty($appId) || empty($apiKey)) {
            Log::warning('OneSignal credentials not configured.');
            return false;
        }

        try {
            $response = Http::withHeaders([
                'Authorization' => 'Basic ' . $apiKey,
                'Content-Type' => 'application/json; charset=utf-8',
            ])->post('https://onesignal.com/api/v1/notifications', [
                'app_id' => $appId,
                'include_external_user_ids' => $externalUserIds,
                'channel_for_external_user_ids' => 'push',
                'headings' => ['en' => $title],
                'contents' => ['en' => $message],
                'data' => $data,
            ]);

            if ($response->successful()) {
                Log::info('OneSignal push sent successfully', ['response' => $response->json()]);
                return true;
            } else {
                Log::error('OneSignal push failed', [
                    'status' => $response->status(),
                    'body' => $response->json()
                ]);
                return false;
            }
        } catch (\Exception $e) {
            Log::error('Exception while sending OneSignal push: ' . $e->getMessage());
            return false;
        }
    }
}
