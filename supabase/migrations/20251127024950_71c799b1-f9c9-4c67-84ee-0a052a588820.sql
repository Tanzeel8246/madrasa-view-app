-- Add app_url column to madrasah table for storing production URL
ALTER TABLE public.madrasah 
ADD COLUMN app_url TEXT NULL;