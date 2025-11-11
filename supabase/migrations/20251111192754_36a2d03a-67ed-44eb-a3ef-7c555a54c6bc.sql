-- Drop the existing unique constraint on roll_number
ALTER TABLE public.students DROP CONSTRAINT IF EXISTS students_roll_number_key;

-- Create a composite unique constraint on madrasah_id and roll_number
-- This allows each madrasah to have its own roll number sequence
ALTER TABLE public.students 
ADD CONSTRAINT students_madrasah_roll_number_unique 
UNIQUE (madrasah_id, roll_number);