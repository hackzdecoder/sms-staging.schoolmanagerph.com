import { useEffect } from 'react';
import { api } from 'src/routes/api/config';

// Define the WTN interface to avoid TypeScript errors
declare global {
  interface Window {
    WTN?: {
      OneSignal?: {
        getPlayerId: () => string;
        setExternalUserId: (userId: string) => void;
        removeExternalUserId: () => void;
      };
    };
  }
}

export function useWebToNative() {
  const isWebToNative = typeof window !== 'undefined' && !!window.WTN;

  // Register device for push notifications
  const registerForPush = async () => {
    if (!isWebToNative || !window.WTN?.OneSignal) return;

    try {
      // Wait 3 seconds to ensure OneSignal SDK is fully loaded before checking
      setTimeout(async () => {
        // Get the device's OneSignal player ID from WebToNative
        const playerId = window.WTN?.OneSignal?.getPlayerId();
        
        // DEBUG: Pop up an alert on the phone so we can see if it's actually working
        alert("Debug: OneSignal Player ID is: " + (playerId ? playerId : "EMPTY/NULL"));

        if (playerId) {
          // Send it to our backend to register the device
          await api.post('/notifications/register-device', {
            player_id: playerId,
            platform: navigator.userAgent.toLowerCase().includes('android') ? 'android' : 
                      navigator.userAgent.toLowerCase().includes('iphone') ? 'ios' : 'web'
          });
        }
      }, 3000);
    } catch (error) {
      console.error('Error registering device for push notifications:', error);
    }
  };

  // Associate device with specific user for targeted push
  const setUserForPush = (userId: string) => {
    if (!isWebToNative || !window.WTN?.OneSignal) return;
    
    try {
      window.WTN.OneSignal.setExternalUserId(userId);
      // Re-register device so backend knows the current user mapping
      registerForPush();
    } catch (error) {
      console.error('Error setting external user ID for push:', error);
    }
  };

  // Unregister user on logout
  const unregisterPush = async () => {
    if (!isWebToNative || !window.WTN?.OneSignal) return;

    try {
      const playerId = window.WTN.OneSignal.getPlayerId();
      if (playerId) {
        await api.request({
          method: 'DELETE',
          url: '/notifications/unregister-device',
          data: { player_id: playerId }
        });
      }
      window.WTN.OneSignal.removeExternalUserId();
    } catch (error) {
      console.error('Error unregistering push notifications:', error);
    }
  };

  return {
    isWebToNative,
    registerForPush,
    setUserForPush,
    unregisterPush
  };
}
