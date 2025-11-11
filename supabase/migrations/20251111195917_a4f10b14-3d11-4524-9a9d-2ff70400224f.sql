-- Add listener name fields to learning_reports table
ALTER TABLE learning_reports 
ADD COLUMN IF NOT EXISTS sabqi_listener_name text,
ADD COLUMN IF NOT EXISTS manzil_listener_name text,
ADD COLUMN IF NOT EXISTS manzil_selected_paras text[];