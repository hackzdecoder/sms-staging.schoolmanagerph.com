<?php

namespace App\Http\Controllers\api\notifications;

use App\Http\Controllers\Controller;
use App\Helpers\DatabaseManager;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class PushDeviceController extends Controller
{
    /**
     * Register a new device for push notifications.
     * Stores the device record in the school's own dynamic database.
     * Enforces one-to-one: only the latest device per user receives notifications.
     */
    public function registerDevice(Request $request)
    {
        $request->validate([
            'player_id' => 'required|string',
            'platform'  => 'nullable|string',
        ]);

        try {
            $authUser = auth()->user();

            if (!$authUser) {
                return response()->json([
                    'success' => false,
                    'message' => 'User not authenticated'
                ], 401);
            }

            $userId     = $authUser->user_id;
            $schoolCode = $authUser->school_code;

            if (!$schoolCode) {
                return response()->json([
                    'success' => false,
                    'message' => 'School code not found on user account'
                ], 400);
            }

            // Connect to this user's school database
            $schoolDb = DatabaseManager::connectBySchoolCode($schoolCode);

            // ─── STEP 1: Delete previous device records for this user ───
            // This ensures only the latest device receives push notifications (1:1 relationship).
            // NOTE: We keep notification_logs intact so already-notified messages are NOT re-sent.
            $schoolDb->table('push_devices')
                ->where('user_id', $userId)
                ->delete();

            Log::info("PushDevice: Deleted previous devices for user {$userId} in school DB ({$schoolCode})");

            // ─── STEP 2: Also remove any existing record tied to this player_id ───
            $schoolDb->table('push_devices')
                ->where('player_id', $request->player_id)
                ->delete();

            // ─── STEP 3: Insert the current device as the active push device ───
            $schoolDb->table('push_devices')->insert([
                'user_id'    => $userId,
                'school_code'=> $schoolCode,
                'player_id'  => $request->player_id,
                'platform'   => $request->platform ?? 'web',
                'is_active'  => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            Log::info("PushDevice: Registered device for user {$userId} in school DB ({$schoolCode}) (player_id: {$request->player_id})");

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
     * Unregister a device (e.g. on logout).
     * Removes the device record from the school's own dynamic database.
     */
    public function unregisterDevice(Request $request)
    {
        $request->validate([
            'player_id' => 'required|string',
        ]);

        try {
            $authUser = auth()->user();

            if (!$authUser) {
                return response()->json([
                    'success' => false,
                    'message' => 'User not authenticated'
                ], 401);
            }

            $schoolCode = $authUser->school_code;

            if (!$schoolCode) {
                return response()->json([
                    'success' => false,
                    'message' => 'School code not found on user account'
                ], 400);
            }

            // Connect to this user's school database and remove the device
            $schoolDb = DatabaseManager::connectBySchoolCode($schoolCode);

            $schoolDb->table('push_devices')
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
     * Lightweight endpoint for polling unread counts (Attendance + Messages).
     * Reads data from the school's own dynamic database.
     */
    public function checkNew(Request $request)
    {
        try {
            $authUser = auth()->user();

            if (!$authUser) {
                return response()->json(['success' => false, 'message' => 'User not authenticated'], 401);
            }

            $userId     = $authUser->user_id;
            $schoolCode = $authUser->school_code;

            if (!$schoolCode) {
                return response()->json(['success' => false, 'message' => 'School code not found'], 400);
            }

            // Connect to correct school database
            $databaseName = DatabaseManager::generateDatabaseName($schoolCode);
            $schoolDb     = DatabaseManager::connect($databaseName);

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

            DatabaseManager::disconnect($databaseName);

            return response()->json([
                'success' => true,
                'data'    => [
                    'unread_attendance' => $unreadAttendance,
                    'unread_messages'   => $unreadMessages,
                    'total_unread'      => $unreadAttendance + $unreadMessages,
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
