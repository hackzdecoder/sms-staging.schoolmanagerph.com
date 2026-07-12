import { useEffect } from 'react';
import { api } from 'src/routes/api/config';

// Define the WTN interface to avoid TypeScript errors
declare global {
  interface Window {
    WTN?: {
      OneSignal?: {
        getPlayerId: () => Promise<string>;
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
    let retries = 0;
    
    // WebToNative SDK takes time to inject into the app. We check every 1 second.
    const checkWtn = setInterval(async () => {
      if (typeof window !== 'undefined' && window.WTN?.OneSignal) {
        clearInterval(checkWtn);
        
        try {
          const playerId = await window.WTN.OneSignal.getPlayerId();

          if (playerId) {
            await api.post('/notifications/register-device', {
              player_id: playerId,
              platform: navigator.userAgent.toLowerCase().includes('android') ? 'android' : 
                        navigator.userAgent.toLowerCase().includes('iphone') ? 'ios' : 'web'
            });
          }
        } catch (error) {
          console.error('Error registering device:', error);
        }
      } else {
        retries++;
        if (retries >= 10) {
          clearInterval(checkWtn);
          console.warn("WebToNative SDK never loaded after 10 seconds.");
        }
      }
    }, 1000);
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
      const playerId = await window.WTN.OneSignal.getPlayerId();
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
