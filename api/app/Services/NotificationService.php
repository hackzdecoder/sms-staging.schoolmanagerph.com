<?php

namespace App\Services;

use App\Helpers\DatabaseManager;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class NotificationService
{
    /**
     * Send a push notification via OneSignal to a specific user.
     * Looks up push devices from the school's own dynamic database.
     *
     * @param string $userId     The user's ID
     * @param string $schoolCode The school code (used to resolve the dynamic DB)
     * @param string $title      Notification title
     * @param string $message    Notification body
     * @param array  $data       Additional data payload
     * @return bool
     */
    public static function sendToUser(string $userId, string $schoolCode, string $title, string $message, array $data = [])
    {
        try {
            // Resolve and connect to the school's own database
            $schoolDb = DatabaseManager::connectBySchoolCode($schoolCode);

            // Get all active subscription/player IDs for this user from the school DB
            $subscriptionIds = $schoolDb->table('push_devices')
                ->where('user_id', $userId)
                ->where('is_active', true)
                ->pluck('player_id')
                ->toArray();

            // If no devices registered, log and skip
            if (empty($subscriptionIds)) {
                Log::warning("NotificationService: No active devices found for user {$userId} in school {$schoolCode}. Skipping push notification.", [
                    'user_id'     => $userId,
                    'school_code' => $schoolCode,
                    'title'       => $title,
                ]);
                return false;
            }

            Log::info("NotificationService: Found " . count($subscriptionIds) . " active device(s) for user {$userId} (school: {$schoolCode}). Sending push.", [
                'user_id'          => $userId,
                'school_code'      => $schoolCode,
                'subscription_ids' => $subscriptionIds,
            ]);

            return self::sendOneSignal($subscriptionIds, $title, $message, $data);

        } catch (\Exception $e) {
            Log::error("NotificationService: Failed to resolve school DB for school {$schoolCode}: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Send push notification directly to device subscription IDs via OneSignal
     */
    private static function sendOneSignal(array $subscriptionIds, string $title, string $message, array $data = [])
    {
        $appId  = env('ONESIGNAL_APP_ID');
        $apiKey = env('ONESIGNAL_REST_API_KEY');

        if (empty($appId) || empty($apiKey)) {
            Log::warning('OneSignal credentials not configured.');
            return false;
        }

        try {
            $payload = [
                'app_id'             => $appId,
                'include_player_ids' => $subscriptionIds,
                'headings'           => ['en' => $title],
                'contents'           => ['en' => $message],
                'data'               => $data,
            ];

            Log::info('NotificationService: Sending OneSignal request', ['payload' => $payload]);

            $response = Http::withHeaders([
                'Authorization' => 'Basic ' . $apiKey,
                'Content-Type'  => 'application/json; charset=utf-8',
            ])->post('https://onesignal.com/api/v1/notifications', $payload);

            if ($response->successful()) {
                Log::info('OneSignal push sent successfully', ['response' => $response->json()]);
                return true;
            } else {
                Log::error('OneSignal push failed', [
                    'status' => $response->status(),
                    'body'   => $response->json(),
                ]);
                return false;
            }
        } catch (\Exception $e) {
            Log::error('Exception while sending OneSignal push: ' . $e->getMessage());
            return false;
        }
    }
}
