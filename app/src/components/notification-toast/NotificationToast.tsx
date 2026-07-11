import { useEffect, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import Snackbar from '@mui/material/Snackbar';
import Alert from '@mui/material/Alert';
import AlertTitle from '@mui/material/AlertTitle';
import Slide, { SlideProps } from '@mui/material/Slide';
import Button from '@mui/material/Button';
import Box from '@mui/material/Box';

function SlideTransition(props: SlideProps) {
  return <Slide {...props} direction="left" />;
}

interface NotificationToastProps {
  events: { type: 'attendance' | 'message'; count: number }[];
  onClose: () => void;
}

export function NotificationToast({ events, onClose }: NotificationToastProps) {
  const [open, setOpen] = useState(false);
  const navigate = useNavigate();

  useEffect(() => {
    if (events.length > 0) {
      setOpen(true);
    }
  }, [events]);

  const handleClose = (event?: React.SyntheticEvent | Event, reason?: string) => {
    if (reason === 'clickaway') {
      return;
    }
    setOpen(false);
    // Call parent onClose after animation completes
    setTimeout(onClose, 300);
  };

  const handleView = () => {
    handleClose();
    // Navigate based on what the notification is for
    if (events.length === 1) {
      if (events[0].type === 'message') {
        navigate('/messaging');
      } else {
        navigate('/attendance');
      }
    } else {
      // If multiple types, go to dashboard
      navigate('/');
    }
  };

  if (events.length === 0) return null;

  // Determine what to show
  let title = 'New Notification';
  let message = '';
  let severity: 'info' | 'success' | 'warning' | 'error' = 'info';

  if (events.length === 1) {
    const event = events[0];
    if (event.type === 'message') {
      title = 'New Message';
      message = `You have ${event.count} new unread message${event.count > 1 ? 's' : ''}.`;
      severity = 'info';
    } else if (event.type === 'attendance') {
      title = 'New Attendance Record';
      message = `You have ${event.count} new attendance record${event.count > 1 ? 's' : ''}.`;
      severity = 'success';
    }
  } else {
    title = 'New Notifications';
    message = 'You have new messages and attendance records.';
    severity = 'info';
  }

  return (
    <Snackbar
      open={open}
      autoHideDuration={8000}
      onClose={handleClose}
      TransitionComponent={SlideTransition}
      anchorOrigin={{ vertical: 'top', horizontal: 'right' }}
      sx={{ mt: 8 }}
    >
      <Alert
        onClose={handleClose}
        severity={severity}
        variant="filled"
        sx={{ width: '100%', boxShadow: 3, borderRadius: 2 }}
      >
        <AlertTitle sx={{ fontWeight: 'bold' }}>{title}</AlertTitle>
        <Box sx={{ mb: 1 }}>{message}</Box>
        <Box sx={{ display: 'flex', gap: 1, justifyContent: 'flex-end', mt: 1 }}>
          <Button 
            color="inherit" 
            size="small" 
            onClick={handleView}
            sx={{ fontWeight: 'bold', bgcolor: 'rgba(255,255,255,0.2)', '&:hover': { bgcolor: 'rgba(255,255,255,0.3)' } }}
          >
            View
          </Button>
        </Box>
      </Alert>
    </Snackbar>
  );
}
