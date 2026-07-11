import { useState, useEffect, useRef } from 'react';
import { api } from 'src/routes/api/config';

interface NotificationCounts {
  unread_attendance: number;
  unread_messages: number;
  total_unread: number;
}

interface NewNotificationEvent {
  type: 'attendance' | 'message';
  count: number;
}

export function useNotificationPolling(intervalMs: number = 30000) {
  const [counts, setCounts] = useState<NotificationCounts>({
    unread_attendance: 0,
    unread_messages: 0,
    total_unread: 0,
  });
  
  // Track previous counts to detect increases
  const prevCountsRef = useRef<NotificationCounts | null>(null);
  
  // Event state to trigger toasts
  const [newNotifications, setNewNotifications] = useState<NewNotificationEvent[]>([]);

  const fetchCounts = async () => {
    try {
      // Don't poll if not logged in
      const token = localStorage.getItem('auth_token');
      if (!token) return;

      const response = await api.get<any>('/notifications/check-new');
      if (response.data?.success && response.data?.data) {
        const newCounts: NotificationCounts = response.data.data;
        const prev = prevCountsRef.current;
        
        // Detect if there are new unread items since last check
        if (prev) {
          const events: NewNotificationEvent[] = [];
          
          if (newCounts.unread_messages > prev.unread_messages) {
            events.push({ 
              type: 'message', 
              count: newCounts.unread_messages - prev.unread_messages 
            });
          }
          
          if (newCounts.unread_attendance > prev.unread_attendance) {
            events.push({ 
              type: 'attendance', 
              count: newCounts.unread_attendance - prev.unread_attendance 
            });
          }
          
          if (events.length > 0) {
            setNewNotifications(events);
          }
        }
        
        // Only update if changed
        if (!prev || prev.total_unread !== newCounts.total_unread) {
          setCounts(newCounts);
        }
        
        prevCountsRef.current = newCounts;
      }
    } catch (error) {
      console.error('Error polling notifications:', error);
    }
  };

  useEffect(() => {
    // Initial fetch
    fetchCounts();
    
    // Set up polling
    const interval = setInterval(fetchCounts, intervalMs);
    
    return () => clearInterval(interval);
  }, [intervalMs]);

  const clearNewNotifications = () => {
    setNewNotifications([]);
  };

  // Allow manual override (e.g. when user marks as read in the UI)
  const setCountsManually = (newCounts: NotificationCounts) => {
    setCounts(newCounts);
    prevCountsRef.current = newCounts;
  };

  return {
    counts,
    newNotifications,
    clearNewNotifications,
    setCounts: setCountsManually,
    refetch: fetchCounts
  };
}
