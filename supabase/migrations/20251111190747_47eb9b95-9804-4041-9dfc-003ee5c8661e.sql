-- Create notifications table
CREATE TABLE public.notifications (
  id UUID NOT NULL DEFAULT gen_random_uuid() PRIMARY KEY,
  madrasah_id UUID NOT NULL,
  title TEXT NOT NULL,
  message TEXT NOT NULL,
  type TEXT NOT NULL CHECK (type IN ('fee', 'attendance', 'salary', 'general')),
  is_read BOOLEAN NOT NULL DEFAULT false,
  created_at TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT now(),
  updated_at TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT now()
);

-- Enable Row Level Security
ALTER TABLE public.notifications ENABLE ROW LEVEL SECURITY;

-- Create policies for notifications
CREATE POLICY "Users can view their madrasah notifications"
ON public.notifications
FOR SELECT
USING (madrasah_id = get_user_madrasah_id());

CREATE POLICY "Users can update their madrasah notifications"
ON public.notifications
FOR UPDATE
USING (madrasah_id = get_user_madrasah_id());

CREATE POLICY "Users can insert their madrasah notifications"
ON public.notifications
FOR INSERT
WITH CHECK (madrasah_id = get_user_madrasah_id());

CREATE POLICY "Users can delete their madrasah notifications"
ON public.notifications
FOR DELETE
USING (madrasah_id = get_user_madrasah_id());

-- Create trigger for automatic timestamp updates
CREATE TRIGGER update_notifications_updated_at
BEFORE UPDATE ON public.notifications
FOR EACH ROW
EXECUTE FUNCTION public.update_updated_at_column();

-- Create index for better query performance
CREATE INDEX idx_notifications_madrasah_id ON public.notifications(madrasah_id);
CREATE INDEX idx_notifications_is_read ON public.notifications(is_read);
CREATE INDEX idx_notifications_created_at ON public.notifications(created_at DESC);