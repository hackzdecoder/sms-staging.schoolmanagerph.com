<?php

namespace App\Http\Controllers\api\notifications;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PushDeviceController extends Controller
{
    /**
     * Register a new device for push notifications
     */
    public function registerDevice(Request $request)
    {
        $request->validate([
            'player_id' => 'required|string',
            'platform' => 'nullable|string',
        ]);

        try {
            $authUser = auth()->user();

            if (!$authUser) {
                return response()->json([
                    'success' => false,
                    'message' => 'User not authenticated'
                ], 401);
            }

            $userId = $authUser->user_id;
            $schoolCode = $authUser->school_code;

            // Check if device already exists
            $existing = DB::connection('users_main')->table('push_devices')
                ->where('player_id', $request->player_id)
                ->first();

            if ($existing) {
                // Update existing device
                DB::connection('users_main')->table('push_devices')
                    ->where('id', $existing->id)
                    ->update([
                        'user_id' => $userId,
                        'school_code' => $schoolCode,
                        'platform' => $request->platform ?? 'web',
                        'is_active' => true,
                        'updated_at' => now()
                    ]);
            } else {
                // Insert new device
                DB::connection('users_main')->table('push_devices')->insert([
                    'user_id' => $userId,
                    'school_code' => $schoolCode,
                    'player_id' => $request->player_id,
                    'platform' => $request->platform ?? 'web',
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now()
                ]);
            }

            return response()->json([
                'success' => true,
                'message' => 'Device registered successfully'
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to register push device: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to register device: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Unregister a device (e.g. on logout)
     */
    public function unregisterDevice(Request $request)
    {
        $request->validate([
            'player_id' => 'required|string',
        ]);

        try {
            DB::connection('users_main')->table('push_devices')
                ->where('player_id', $request->player_id)
                ->delete();

            return response()->json([
                'success' => true,
                'message' => 'Device unregistered successfully'
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to unregister push device: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to unregister device: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Lightweight endpoint for polling unread counts (Attendance + Messages)
     */
    public function checkNew(Request $request)
    {
        try {
            $authUser = auth()->user();

            if (!$authUser) {
                return response()->json(['success' => false, 'message' => 'User not authenticated'], 401);
            }

            $userId = $authUser->user_id;
            $schoolCode = $authUser->school_code;

            if (!$schoolCode) {
                return response()->json(['success' => false, 'message' => 'School code not found'], 400);
            }

            // Connect to correct school database
            $databaseName = \App\Helpers\DatabaseManager::generateDatabaseName($schoolCode);
            $schoolDb = \App\Helpers\DatabaseManager::connect($databaseName);

            // Get unread attendance count
            $unreadAttendance = $schoolDb->table('attendance_records')
                ->where('user_id', $userId)
                ->where('school_code', $schoolCode)
                ->where('status', 'unread')
                ->count();

            // Get unread message count
            $unreadMessages = $schoolDb->table('messages')
                ->where('user_id', $userId)
                ->where('school_code', $schoolCode)
                ->where('status', 'unread')
                ->count();

            \App\Helpers\DatabaseManager::disconnect($databaseName);

            return response()->json([
                'success' => true,
                'data' => [
                    'unread_attendance' => $unreadAttendance,
                    'unread_messages' => $unreadMessages,
                    'total_unread' => $unreadAttendance + $unreadMessages
                ]
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to check new notifications: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to check notifications: ' . $e->getMessage()
            ], 500);
        }
    }
}
