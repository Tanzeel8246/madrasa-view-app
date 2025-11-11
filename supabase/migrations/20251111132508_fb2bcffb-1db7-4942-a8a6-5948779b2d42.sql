-- Create students table
CREATE TABLE public.students (
  id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  name TEXT NOT NULL,
  father_name TEXT NOT NULL,
  class TEXT NOT NULL,
  roll_number TEXT UNIQUE NOT NULL,
  contact TEXT,
  address TEXT,
  date_of_birth DATE,
  created_at TIMESTAMP WITH TIME ZONE DEFAULT now(),
  updated_at TIMESTAMP WITH TIME ZONE DEFAULT now()
);

-- Create attendance table
CREATE TABLE public.attendance (
  id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  student_id UUID REFERENCES public.students(id) ON DELETE CASCADE NOT NULL,
  date DATE NOT NULL,
  status TEXT NOT NULL CHECK (status IN ('present', 'absent', 'leave')),
  notes TEXT,
  created_at TIMESTAMP WITH TIME ZONE DEFAULT now(),
  UNIQUE(student_id, date)
);

-- Create fees table
CREATE TABLE public.fees (
  id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  student_id UUID REFERENCES public.students(id) ON DELETE CASCADE NOT NULL,
  month INTEGER NOT NULL CHECK (month >= 1 AND month <= 12),
  year INTEGER NOT NULL,
  amount DECIMAL(10, 2) NOT NULL,
  paid_amount DECIMAL(10, 2) DEFAULT 0,
  status TEXT NOT NULL CHECK (status IN ('paid', 'pending', 'partial')),
  payment_date TIMESTAMP WITH TIME ZONE,
  notes TEXT,
  created_at TIMESTAMP WITH TIME ZONE DEFAULT now(),
  updated_at TIMESTAMP WITH TIME ZONE DEFAULT now(),
  UNIQUE(student_id, month, year)
);

-- Enable Row Level Security
ALTER TABLE public.students ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.attendance ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.fees ENABLE ROW LEVEL SECURITY;

-- Create policies for students (allow all operations for now - we'll add auth later)
CREATE POLICY "Allow public read access to students"
  ON public.students FOR SELECT
  USING (true);

CREATE POLICY "Allow public insert to students"
  ON public.students FOR INSERT
  WITH CHECK (true);

CREATE POLICY "Allow public update to students"
  ON public.students FOR UPDATE
  USING (true);

CREATE POLICY "Allow public delete to students"
  ON public.students FOR DELETE
  USING (true);

-- Create policies for attendance
CREATE POLICY "Allow public read access to attendance"
  ON public.attendance FOR SELECT
  USING (true);

CREATE POLICY "Allow public insert to attendance"
  ON public.attendance FOR INSERT
  WITH CHECK (true);

CREATE POLICY "Allow public update to attendance"
  ON public.attendance FOR UPDATE
  USING (true);

CREATE POLICY "Allow public delete to attendance"
  ON public.attendance FOR DELETE
  USING (true);

-- Create policies for fees
CREATE POLICY "Allow public read access to fees"
  ON public.fees FOR SELECT
  USING (true);

CREATE POLICY "Allow public insert to fees"
  ON public.fees FOR INSERT
  WITH CHECK (true);

CREATE POLICY "Allow public update to fees"
  ON public.fees FOR UPDATE
  USING (true);

CREATE POLICY "Allow public delete to fees"
  ON public.fees FOR DELETE
  USING (true);

-- Create function to automatically update updated_at
CREATE OR REPLACE FUNCTION public.update_updated_at_column()
RETURNS TRIGGER AS $$
BEGIN
  NEW.updated_at = now();
  RETURN NEW;
END;
$$ LANGUAGE plpgsql;

-- Create triggers for updated_at
CREATE TRIGGER update_students_updated_at
  BEFORE UPDATE ON public.students
  FOR EACH ROW
  EXECUTE FUNCTION public.update_updated_at_column();

CREATE TRIGGER update_fees_updated_at
  BEFORE UPDATE ON public.fees
  FOR EACH ROW
  EXECUTE FUNCTION public.update_updated_at_column();

-- Create indexes for better performance
CREATE INDEX idx_students_roll_number ON public.students(roll_number);
CREATE INDEX idx_attendance_student_date ON public.attendance(student_id, date);
CREATE INDEX idx_fees_student_month_year ON public.fees(student_id, month, year);
CREATE INDEX idx_fees_status ON public.fees(status);