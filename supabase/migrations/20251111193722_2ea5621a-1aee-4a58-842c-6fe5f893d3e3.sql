-- Update attendance table to support time slots and better status tracking
-- Add time_slot column
ALTER TABLE public.attendance 
ADD COLUMN IF NOT EXISTS time_slot TEXT CHECK (time_slot IN ('morning', 'afternoon', 'evening', 'night'));

-- Update the unique constraint to prevent duplicate attendance for same student, date, and time slot
ALTER TABLE public.attendance 
DROP CONSTRAINT IF EXISTS attendance_student_date_unique;

ALTER TABLE public.attendance 
ADD CONSTRAINT attendance_student_date_time_unique 
UNIQUE (student_id, date, time_slot);

-- Update status check constraint to include new statuses
ALTER TABLE public.attendance 
DROP CONSTRAINT IF EXISTS attendance_status_check;

ALTER TABLE public.attendance 
ADD CONSTRAINT attendance_status_check 
CHECK (status IN ('present', 'absent', 'leave', 'sick'));