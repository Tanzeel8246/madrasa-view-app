-- Enable required extensions for cron jobs
CREATE EXTENSION IF NOT EXISTS pg_cron WITH SCHEMA extensions;
CREATE EXTENSION IF NOT EXISTS pg_net WITH SCHEMA extensions;

-- Create backups table to store backup metadata
CREATE TABLE IF NOT EXISTS public.backups (
  id UUID NOT NULL DEFAULT gen_random_uuid() PRIMARY KEY,
  madrasah_id UUID NOT NULL,
  backup_date TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT now(),
  backup_type TEXT NOT NULL CHECK (backup_type IN ('manual', 'auto', 'pre_restore')),
  backup_data JSONB NOT NULL,
  created_at TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT now(),
  notes TEXT
);

-- Enable RLS
ALTER TABLE public.backups ENABLE ROW LEVEL SECURITY;

-- RLS Policies
CREATE POLICY "Users can view their madrasah backups"
  ON public.backups
  FOR SELECT
  USING (madrasah_id = get_user_madrasah_id());

CREATE POLICY "Users can insert their madrasah backups"
  ON public.backups
  FOR INSERT
  WITH CHECK (madrasah_id = get_user_madrasah_id());

CREATE POLICY "Users can delete their madrasah backups"
  ON public.backups
  FOR DELETE
  USING (madrasah_id = get_user_madrasah_id());

-- Indexes
CREATE INDEX idx_backups_madrasah_id ON public.backups(madrasah_id);
CREATE INDEX idx_backups_backup_date ON public.backups(backup_date DESC);