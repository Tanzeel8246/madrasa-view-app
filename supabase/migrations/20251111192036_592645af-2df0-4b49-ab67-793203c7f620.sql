-- Create learning_reports table for student performance tracking
CREATE TABLE public.learning_reports (
  id UUID NOT NULL DEFAULT gen_random_uuid() PRIMARY KEY,
  madrasah_id UUID NOT NULL,
  student_id UUID NOT NULL,
  date DATE NOT NULL DEFAULT CURRENT_DATE,
  class_type TEXT NOT NULL CHECK (class_type IN ('quran_hifz', 'quran_nazira', 'dars_nizami', 'modern_education')),
  
  -- Quran Hifz/Nazira fields
  sabaq_amount TEXT,
  sabaq_para_number INTEGER,
  sabaq_lines_pages TEXT,
  
  -- Hifz only
  sabqi_para INTEGER,
  sabqi_amount TEXT,
  manzil_amount TEXT,
  manzil_paras TEXT,
  
  -- Dars Nizami and Modern Education fields (6 periods)
  period_1 TEXT,
  period_2 TEXT,
  period_3 TEXT,
  period_4 TEXT,
  period_5 TEXT,
  period_6 TEXT,
  
  notes TEXT,
  created_at TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT now(),
  updated_at TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT now()
);

-- Enable RLS
ALTER TABLE public.learning_reports ENABLE ROW LEVEL SECURITY;

-- RLS Policies
CREATE POLICY "Users can view their madrasah learning reports"
  ON public.learning_reports
  FOR SELECT
  USING (madrasah_id = get_user_madrasah_id());

CREATE POLICY "Users can insert their madrasah learning reports"
  ON public.learning_reports
  FOR INSERT
  WITH CHECK (madrasah_id = get_user_madrasah_id());

CREATE POLICY "Users can update their madrasah learning reports"
  ON public.learning_reports
  FOR UPDATE
  USING (madrasah_id = get_user_madrasah_id());

CREATE POLICY "Users can delete their madrasah learning reports"
  ON public.learning_reports
  FOR DELETE
  USING (madrasah_id = get_user_madrasah_id());

-- Trigger for updated_at
CREATE TRIGGER update_learning_reports_updated_at
  BEFORE UPDATE ON public.learning_reports
  FOR EACH ROW
  EXECUTE FUNCTION public.update_updated_at_column();

-- Indexes
CREATE INDEX idx_learning_reports_madrasah_id ON public.learning_reports(madrasah_id);
CREATE INDEX idx_learning_reports_student_id ON public.learning_reports(student_id);
CREATE INDEX idx_learning_reports_date ON public.learning_reports(date DESC);