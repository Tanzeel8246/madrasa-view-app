-- Create a junction table for many-to-many relationship between classes and teachers
CREATE TABLE public.class_teachers (
  id UUID NOT NULL DEFAULT gen_random_uuid() PRIMARY KEY,
  class_id UUID NOT NULL REFERENCES public.classes(id) ON DELETE CASCADE,
  teacher_id UUID NOT NULL REFERENCES public.teachers(id) ON DELETE CASCADE,
  madrasah_id UUID NOT NULL,
  created_at TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT now(),
  updated_at TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT now(),
  UNIQUE(class_id, teacher_id)
);

-- Enable RLS
ALTER TABLE public.class_teachers ENABLE ROW LEVEL SECURITY;

-- RLS Policies
CREATE POLICY "Users can view their madrasah class_teachers"
  ON public.class_teachers
  FOR SELECT
  USING (madrasah_id = get_user_madrasah_id());

CREATE POLICY "Users can insert their madrasah class_teachers"
  ON public.class_teachers
  FOR INSERT
  WITH CHECK (madrasah_id = get_user_madrasah_id());

CREATE POLICY "Users can update their madrasah class_teachers"
  ON public.class_teachers
  FOR UPDATE
  USING (madrasah_id = get_user_madrasah_id());

CREATE POLICY "Users can delete their madrasah class_teachers"
  ON public.class_teachers
  FOR DELETE
  USING (madrasah_id = get_user_madrasah_id());

-- Trigger for updated_at
CREATE TRIGGER update_class_teachers_updated_at
  BEFORE UPDATE ON public.class_teachers
  FOR EACH ROW
  EXECUTE FUNCTION public.update_updated_at_column();

-- Indexes
CREATE INDEX idx_class_teachers_class_id ON public.class_teachers(class_id);
CREATE INDEX idx_class_teachers_teacher_id ON public.class_teachers(teacher_id);
CREATE INDEX idx_class_teachers_madrasah_id ON public.class_teachers(madrasah_id);

-- Migrate existing data from classes table
INSERT INTO public.class_teachers (class_id, teacher_id, madrasah_id)
SELECT id, teacher_id, madrasah_id 
FROM public.classes 
WHERE teacher_id IS NOT NULL;