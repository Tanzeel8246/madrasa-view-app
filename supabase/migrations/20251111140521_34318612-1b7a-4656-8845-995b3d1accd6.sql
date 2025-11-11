-- Create income table
CREATE TABLE public.income (
  id UUID NOT NULL DEFAULT gen_random_uuid() PRIMARY KEY,
  title TEXT NOT NULL,
  amount NUMERIC NOT NULL,
  category TEXT NOT NULL,
  date DATE NOT NULL DEFAULT CURRENT_DATE,
  description TEXT,
  created_at TIMESTAMP WITH TIME ZONE DEFAULT now(),
  updated_at TIMESTAMP WITH TIME ZONE DEFAULT now()
);

-- Create expense table
CREATE TABLE public.expense (
  id UUID NOT NULL DEFAULT gen_random_uuid() PRIMARY KEY,
  title TEXT NOT NULL,
  amount NUMERIC NOT NULL,
  category TEXT NOT NULL,
  date DATE NOT NULL DEFAULT CURRENT_DATE,
  description TEXT,
  created_at TIMESTAMP WITH TIME ZONE DEFAULT now(),
  updated_at TIMESTAMP WITH TIME ZONE DEFAULT now()
);

-- Enable RLS
ALTER TABLE public.income ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.expense ENABLE ROW LEVEL SECURITY;

-- Create policies for income
CREATE POLICY "Allow public read access to income"
  ON public.income FOR SELECT
  USING (true);

CREATE POLICY "Allow public insert to income"
  ON public.income FOR INSERT
  WITH CHECK (true);

CREATE POLICY "Allow public update to income"
  ON public.income FOR UPDATE
  USING (true);

CREATE POLICY "Allow public delete to income"
  ON public.income FOR DELETE
  USING (true);

-- Create policies for expense
CREATE POLICY "Allow public read access to expense"
  ON public.expense FOR SELECT
  USING (true);

CREATE POLICY "Allow public insert to expense"
  ON public.expense FOR INSERT
  WITH CHECK (true);

CREATE POLICY "Allow public update to expense"
  ON public.expense FOR UPDATE
  USING (true);

CREATE POLICY "Allow public delete to expense"
  ON public.expense FOR DELETE
  USING (true);

-- Create triggers for updated_at
CREATE TRIGGER update_income_updated_at
  BEFORE UPDATE ON public.income
  FOR EACH ROW
  EXECUTE FUNCTION public.update_updated_at_column();

CREATE TRIGGER update_expense_updated_at
  BEFORE UPDATE ON public.expense
  FOR EACH ROW
  EXECUTE FUNCTION public.update_updated_at_column();