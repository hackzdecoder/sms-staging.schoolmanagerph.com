<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

class NotificationService
{
    /**
     * Send a push notification via OneSignal to a specific user (targeting player IDs directly)
     *
     * @param string $userId The user's ID
     * @param string $title Notification title
     * @param string $message Notification body
     * @param array $data Additional data payload
     * @return bool
     */
    public static function sendToUser(string $userId, string $title, string $message, array $data = [])
    {
        // Get all active player IDs for this user directly from push_devices
        $playerIds = DB::connection('users_main')->table('push_devices')
            ->where('user_id', $userId)
            ->where('is_active', true)
            ->pluck('player_id')
            ->toArray();

        // If no devices registered, skip
        if (empty($playerIds)) {
            return false;
        }

        return self::sendOneSignal($playerIds, $title, $message, $data);
    }

    /**
     * Send push notification directly to device player IDs
     */
    private static function sendOneSignal(array $playerIds, string $title, string $message, array $data = [])
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
                'include_player_ids' => $playerIds,
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
