-- Create madrasah table for multi-tenancy
CREATE TABLE public.madrasah (
  id UUID NOT NULL DEFAULT gen_random_uuid() PRIMARY KEY,
  name TEXT NOT NULL,
  madrasah_id TEXT UNIQUE NOT NULL,
  logo_url TEXT,
  address TEXT,
  contact TEXT,
  email TEXT,
  created_at TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT now(),
  updated_at TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT now()
);

-- Enable RLS
ALTER TABLE public.madrasah ENABLE ROW LEVEL SECURITY;

-- Create profiles table for user-madrasah relationship
CREATE TABLE public.profiles (
  id UUID NOT NULL DEFAULT gen_random_uuid() PRIMARY KEY,
  user_id UUID UNIQUE NOT NULL,
  madrasah_id UUID NOT NULL REFERENCES public.madrasah(id) ON DELETE CASCADE,
  full_name TEXT,
  role TEXT NOT NULL DEFAULT 'user',
  created_at TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT now(),
  updated_at TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT now()
);

-- Enable RLS
ALTER TABLE public.profiles ENABLE ROW LEVEL SECURITY;

-- Add madrasah_id to all existing tables
ALTER TABLE public.students ADD COLUMN madrasah_id UUID REFERENCES public.madrasah(id) ON DELETE CASCADE;
ALTER TABLE public.teachers ADD COLUMN madrasah_id UUID REFERENCES public.madrasah(id) ON DELETE CASCADE;
ALTER TABLE public.classes ADD COLUMN madrasah_id UUID REFERENCES public.madrasah(id) ON DELETE CASCADE;
ALTER TABLE public.attendance ADD COLUMN madrasah_id UUID REFERENCES public.madrasah(id) ON DELETE CASCADE;
ALTER TABLE public.fees ADD COLUMN madrasah_id UUID REFERENCES public.madrasah(id) ON DELETE CASCADE;
ALTER TABLE public.income ADD COLUMN madrasah_id UUID REFERENCES public.madrasah(id) ON DELETE CASCADE;
ALTER TABLE public.expense ADD COLUMN madrasah_id UUID REFERENCES public.madrasah(id) ON DELETE CASCADE;
ALTER TABLE public.salaries ADD COLUMN madrasah_id UUID REFERENCES public.madrasah(id) ON DELETE CASCADE;
ALTER TABLE public.loans ADD COLUMN madrasah_id UUID REFERENCES public.madrasah(id) ON DELETE CASCADE;

-- Function to get user's madrasah_id
CREATE OR REPLACE FUNCTION public.get_user_madrasah_id()
RETURNS UUID
LANGUAGE sql
STABLE
SECURITY DEFINER
SET search_path = public
AS $$
  SELECT madrasah_id FROM public.profiles WHERE user_id = auth.uid()
$$;

-- RLS Policies for madrasah table
CREATE POLICY "Users can view their own madrasah"
ON public.madrasah
FOR SELECT
USING (id = public.get_user_madrasah_id());

CREATE POLICY "Admins can update their madrasah"
ON public.madrasah
FOR UPDATE
USING (id = public.get_user_madrasah_id());

-- RLS Policies for profiles table
CREATE POLICY "Users can view their own profile"
ON public.profiles
FOR SELECT
USING (user_id = auth.uid());

CREATE POLICY "Users can update their own profile"
ON public.profiles
FOR UPDATE
USING (user_id = auth.uid());

-- Update RLS policies for students table
DROP POLICY IF EXISTS "Allow public read access to students" ON public.students;
DROP POLICY IF EXISTS "Allow public insert to students" ON public.students;
DROP POLICY IF EXISTS "Allow public update to students" ON public.students;
DROP POLICY IF EXISTS "Allow public delete to students" ON public.students;

CREATE POLICY "Users can view their madrasah students"
ON public.students
FOR SELECT
USING (madrasah_id = public.get_user_madrasah_id());

CREATE POLICY "Users can insert their madrasah students"
ON public.students
FOR INSERT
WITH CHECK (madrasah_id = public.get_user_madrasah_id());

CREATE POLICY "Users can update their madrasah students"
ON public.students
FOR UPDATE
USING (madrasah_id = public.get_user_madrasah_id());

CREATE POLICY "Users can delete their madrasah students"
ON public.students
FOR DELETE
USING (madrasah_id = public.get_user_madrasah_id());

-- Update RLS policies for teachers table
DROP POLICY IF EXISTS "Allow public read access to teachers" ON public.teachers;
DROP POLICY IF EXISTS "Allow public insert to teachers" ON public.teachers;
DROP POLICY IF EXISTS "Allow public update to teachers" ON public.teachers;
DROP POLICY IF EXISTS "Allow public delete to teachers" ON public.teachers;

CREATE POLICY "Users can view their madrasah teachers"
ON public.teachers
FOR SELECT
USING (madrasah_id = public.get_user_madrasah_id());

CREATE POLICY "Users can insert their madrasah teachers"
ON public.teachers
FOR INSERT
WITH CHECK (madrasah_id = public.get_user_madrasah_id());

CREATE POLICY "Users can update their madrasah teachers"
ON public.teachers
FOR UPDATE
USING (madrasah_id = public.get_user_madrasah_id());

CREATE POLICY "Users can delete their madrasah teachers"
ON public.teachers
FOR DELETE
USING (madrasah_id = public.get_user_madrasah_id());

-- Update RLS policies for classes table
DROP POLICY IF EXISTS "Allow public read access to classes" ON public.classes;
DROP POLICY IF EXISTS "Allow public insert to classes" ON public.classes;
DROP POLICY IF EXISTS "Allow public update to classes" ON public.classes;
DROP POLICY IF EXISTS "Allow public delete to classes" ON public.classes;

CREATE POLICY "Users can view their madrasah classes"
ON public.classes
FOR SELECT
USING (madrasah_id = public.get_user_madrasah_id());

CREATE POLICY "Users can insert their madrasah classes"
ON public.classes
FOR INSERT
WITH CHECK (madrasah_id = public.get_user_madrasah_id());

CREATE POLICY "Users can update their madrasah classes"
ON public.classes
FOR UPDATE
USING (madrasah_id = public.get_user_madrasah_id());

CREATE POLICY "Users can delete their madrasah classes"
ON public.classes
FOR DELETE
USING (madrasah_id = public.get_user_madrasah_id());

-- Update RLS policies for attendance table
DROP POLICY IF EXISTS "Allow public read access to attendance" ON public.attendance;
DROP POLICY IF EXISTS "Allow public insert to attendance" ON public.attendance;
DROP POLICY IF EXISTS "Allow public update to attendance" ON public.attendance;
DROP POLICY IF EXISTS "Allow public delete to attendance" ON public.attendance;

CREATE POLICY "Users can view their madrasah attendance"
ON public.attendance
FOR SELECT
USING (madrasah_id = public.get_user_madrasah_id());

CREATE POLICY "Users can insert their madrasah attendance"
ON public.attendance
FOR INSERT
WITH CHECK (madrasah_id = public.get_user_madrasah_id());

CREATE POLICY "Users can update their madrasah attendance"
ON public.attendance
FOR UPDATE
USING (madrasah_id = public.get_user_madrasah_id());

CREATE POLICY "Users can delete their madrasah attendance"
ON public.attendance
FOR DELETE
USING (madrasah_id = public.get_user_madrasah_id());

-- Update RLS policies for fees table
DROP POLICY IF EXISTS "Allow public read access to fees" ON public.fees;
DROP POLICY IF EXISTS "Allow public insert to fees" ON public.fees;
DROP POLICY IF EXISTS "Allow public update to fees" ON public.fees;
DROP POLICY IF EXISTS "Allow public delete to fees" ON public.fees;

CREATE POLICY "Users can view their madrasah fees"
ON public.fees
FOR SELECT
USING (madrasah_id = public.get_user_madrasah_id());

CREATE POLICY "Users can insert their madrasah fees"
ON public.fees
FOR INSERT
WITH CHECK (madrasah_id = public.get_user_madrasah_id());

CREATE POLICY "Users can update their madrasah fees"
ON public.fees
FOR UPDATE
USING (madrasah_id = public.get_user_madrasah_id());

CREATE POLICY "Users can delete their madrasah fees"
ON public.fees
FOR DELETE
USING (madrasah_id = public.get_user_madrasah_id());

-- Update RLS policies for income table
DROP POLICY IF EXISTS "Allow public read access to income" ON public.income;
DROP POLICY IF EXISTS "Allow public insert to income" ON public.income;
DROP POLICY IF EXISTS "Allow public update to income" ON public.income;
DROP POLICY IF EXISTS "Allow public delete to income" ON public.income;

CREATE POLICY "Users can view their madrasah income"
ON public.income
FOR SELECT
USING (madrasah_id = public.get_user_madrasah_id());

CREATE POLICY "Users can insert their madrasah income"
ON public.income
FOR INSERT
WITH CHECK (madrasah_id = public.get_user_madrasah_id());

CREATE POLICY "Users can update their madrasah income"
ON public.income
FOR UPDATE
USING (madrasah_id = public.get_user_madrasah_id());

CREATE POLICY "Users can delete their madrasah income"
ON public.income
FOR DELETE
USING (madrasah_id = public.get_user_madrasah_id());

-- Update RLS policies for expense table
DROP POLICY IF EXISTS "Allow public read access to expense" ON public.expense;
DROP POLICY IF EXISTS "Allow public insert to expense" ON public.expense;
DROP POLICY IF EXISTS "Allow public update to expense" ON public.expense;
DROP POLICY IF EXISTS "Allow public delete to expense" ON public.expense;

CREATE POLICY "Users can view their madrasah expense"
ON public.expense
FOR SELECT
USING (madrasah_id = public.get_user_madrasah_id());

CREATE POLICY "Users can insert their madrasah expense"
ON public.expense
FOR INSERT
WITH CHECK (madrasah_id = public.get_user_madrasah_id());

CREATE POLICY "Users can update their madrasah expense"
ON public.expense
FOR UPDATE
USING (madrasah_id = public.get_user_madrasah_id());

CREATE POLICY "Users can delete their madrasah expense"
ON public.expense
FOR DELETE
USING (madrasah_id = public.get_user_madrasah_id());

-- Update RLS policies for salaries table
DROP POLICY IF EXISTS "Allow public read access to salaries" ON public.salaries;
DROP POLICY IF EXISTS "Allow public insert to salaries" ON public.salaries;
DROP POLICY IF EXISTS "Allow public update to salaries" ON public.salaries;
DROP POLICY IF EXISTS "Allow public delete to salaries" ON public.salaries;

CREATE POLICY "Users can view their madrasah salaries"
ON public.salaries
FOR SELECT
USING (madrasah_id = public.get_user_madrasah_id());

CREATE POLICY "Users can insert their madrasah salaries"
ON public.salaries
FOR INSERT
WITH CHECK (madrasah_id = public.get_user_madrasah_id());

CREATE POLICY "Users can update their madrasah salaries"
ON public.salaries
FOR UPDATE
USING (madrasah_id = public.get_user_madrasah_id());

CREATE POLICY "Users can delete their madrasah salaries"
ON public.salaries
FOR DELETE
USING (madrasah_id = public.get_user_madrasah_id());

-- Update RLS policies for loans table
DROP POLICY IF EXISTS "Allow public read access to loans" ON public.loans;
DROP POLICY IF EXISTS "Allow public insert to loans" ON public.loans;
DROP POLICY IF EXISTS "Allow public update to loans" ON public.loans;
DROP POLICY IF EXISTS "Allow public delete to loans" ON public.loans;

CREATE POLICY "Users can view their madrasah loans"
ON public.loans
FOR SELECT
USING (madrasah_id = public.get_user_madrasah_id());

CREATE POLICY "Users can insert their madrasah loans"
ON public.loans
FOR INSERT
WITH CHECK (madrasah_id = public.get_user_madrasah_id());

CREATE POLICY "Users can update their madrasah loans"
ON public.loans
FOR UPDATE
USING (madrasah_id = public.get_user_madrasah_id());

CREATE POLICY "Users can delete their madrasah loans"
ON public.loans
FOR DELETE
USING (madrasah_id = public.get_user_madrasah_id());

-- Create storage bucket for madrasah logos
INSERT INTO storage.buckets (id, name, public) 
VALUES ('madrasah-logos', 'madrasah-logos', true);

-- Storage policies for logos
CREATE POLICY "Public can view logos"
ON storage.objects
FOR SELECT
USING (bucket_id = 'madrasah-logos');

CREATE POLICY "Authenticated users can upload logos"
ON storage.objects
FOR INSERT
WITH CHECK (bucket_id = 'madrasah-logos' AND auth.uid() IS NOT NULL);

CREATE POLICY "Users can update their madrasah logo"
ON storage.objects
FOR UPDATE
USING (bucket_id = 'madrasah-logos' AND auth.uid() IS NOT NULL);

-- Trigger for updated_at
CREATE TRIGGER update_madrasah_updated_at
BEFORE UPDATE ON public.madrasah
FOR EACH ROW
EXECUTE FUNCTION public.update_updated_at_column();

CREATE TRIGGER update_profiles_updated_at
BEFORE UPDATE ON public.profiles
FOR EACH ROW
EXECUTE FUNCTION public.update_updated_at_column();