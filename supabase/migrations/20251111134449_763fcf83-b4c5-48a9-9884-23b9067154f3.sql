-- Create classes table
CREATE TABLE public.classes (
  id uuid NOT NULL DEFAULT gen_random_uuid() PRIMARY KEY,
  name text NOT NULL,
  description text,
  created_at timestamp with time zone DEFAULT now(),
  updated_at timestamp with time zone DEFAULT now()
);

-- Create teachers table
CREATE TABLE public.teachers (
  id uuid NOT NULL DEFAULT gen_random_uuid() PRIMARY KEY,
  name text NOT NULL,
  contact text,
  email text,
  subject text,
  qualification text,
  address text,
  created_at timestamp with time zone DEFAULT now(),
  updated_at timestamp with time zone DEFAULT now()
);

-- Add class_id to students table
ALTER TABLE public.students 
ADD COLUMN class_id uuid REFERENCES public.classes(id) ON DELETE SET NULL;

-- Add teacher_id to classes table
ALTER TABLE public.classes 
ADD COLUMN teacher_id uuid REFERENCES public.teachers(id) ON DELETE SET NULL;

-- Enable RLS on classes
ALTER TABLE public.classes ENABLE ROW LEVEL SECURITY;

-- Enable RLS on teachers
ALTER TABLE public.teachers ENABLE ROW LEVEL SECURITY;

-- Create policies for classes
CREATE POLICY "Allow public read access to classes" 
ON public.classes FOR SELECT USING (true);

CREATE POLICY "Allow public insert to classes" 
ON public.classes FOR INSERT WITH CHECK (true);

CREATE POLICY "Allow public update to classes" 
ON public.classes FOR UPDATE USING (true);

CREATE POLICY "Allow public delete to classes" 
ON public.classes FOR DELETE USING (true);

-- Create policies for teachers
CREATE POLICY "Allow public read access to teachers" 
ON public.teachers FOR SELECT USING (true);

CREATE POLICY "Allow public insert to teachers" 
ON public.teachers FOR INSERT WITH CHECK (true);

CREATE POLICY "Allow public update to teachers" 
ON public.teachers FOR UPDATE USING (true);

CREATE POLICY "Allow public delete to teachers" 
ON public.teachers FOR DELETE USING (true);

-- Create trigger for classes updated_at
CREATE TRIGGER update_classes_updated_at
BEFORE UPDATE ON public.classes
FOR EACH ROW
EXECUTE FUNCTION public.update_updated_at_column();

-- Create trigger for teachers updated_at
CREATE TRIGGER update_teachers_updated_at
BEFORE UPDATE ON public.teachers
FOR EACH ROW
EXECUTE FUNCTION public.update_updated_at_column();