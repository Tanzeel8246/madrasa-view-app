-- Create salaries table for teacher salary management
CREATE TABLE public.salaries (
  id UUID NOT NULL DEFAULT gen_random_uuid() PRIMARY KEY,
  teacher_id UUID NOT NULL REFERENCES public.teachers(id) ON DELETE CASCADE,
  amount NUMERIC NOT NULL,
  month INTEGER NOT NULL CHECK (month >= 1 AND month <= 12),
  year INTEGER NOT NULL,
  payment_date TIMESTAMP WITH TIME ZONE,
  status TEXT NOT NULL DEFAULT 'pending' CHECK (status IN ('paid', 'pending')),
  notes TEXT,
  created_at TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT now(),
  updated_at TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT now(),
  UNIQUE(teacher_id, month, year)
);

-- Enable RLS
ALTER TABLE public.salaries ENABLE ROW LEVEL SECURITY;

-- Create policies
CREATE POLICY "Allow public read access to salaries"
ON public.salaries FOR SELECT
USING (true);

CREATE POLICY "Allow public insert to salaries"
ON public.salaries FOR INSERT
WITH CHECK (true);

CREATE POLICY "Allow public update to salaries"
ON public.salaries FOR UPDATE
USING (true);

CREATE POLICY "Allow public delete to salaries"
ON public.salaries FOR DELETE
USING (true);

-- Create trigger for timestamps
CREATE TRIGGER update_salaries_updated_at
BEFORE UPDATE ON public.salaries
FOR EACH ROW
EXECUTE FUNCTION public.update_updated_at_column();

-- Create loans table for tracking advances/loans to teachers
CREATE TABLE public.loans (
  id UUID NOT NULL DEFAULT gen_random_uuid() PRIMARY KEY,
  teacher_id UUID NOT NULL REFERENCES public.teachers(id) ON DELETE CASCADE,
  amount NUMERIC NOT NULL,
  loan_date DATE NOT NULL DEFAULT CURRENT_DATE,
  return_date DATE,
  status TEXT NOT NULL DEFAULT 'pending' CHECK (status IN ('pending', 'partial', 'paid')),
  paid_amount NUMERIC DEFAULT 0,
  notes TEXT,
  created_at TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT now(),
  updated_at TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT now()
);

-- Enable RLS
ALTER TABLE public.loans ENABLE ROW LEVEL SECURITY;

-- Create policies
CREATE POLICY "Allow public read access to loans"
ON public.loans FOR SELECT
USING (true);

CREATE POLICY "Allow public insert to loans"
ON public.loans FOR INSERT
WITH CHECK (true);

CREATE POLICY "Allow public update to loans"
ON public.loans FOR UPDATE
USING (true);

CREATE POLICY "Allow public delete to loans"
ON public.loans FOR DELETE
USING (true);

-- Create trigger for timestamps
CREATE TRIGGER update_loans_updated_at
BEFORE UPDATE ON public.loans
FOR EACH ROW
EXECUTE FUNCTION public.update_updated_at_column();