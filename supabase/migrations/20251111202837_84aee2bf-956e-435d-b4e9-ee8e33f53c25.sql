-- Create enum for user roles
CREATE TYPE public.app_role AS ENUM ('admin', 'teacher', 'manager', 'parent', 'user');

-- Create user_roles table
CREATE TABLE public.user_roles (
  id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  user_id UUID REFERENCES auth.users(id) ON DELETE CASCADE NOT NULL,
  madrasah_id UUID NOT NULL,
  role app_role NOT NULL,
  created_at TIMESTAMP WITH TIME ZONE DEFAULT now() NOT NULL,
  updated_at TIMESTAMP WITH TIME ZONE DEFAULT now() NOT NULL,
  UNIQUE(user_id, madrasah_id)
);

-- Enable RLS on user_roles
ALTER TABLE public.user_roles ENABLE ROW LEVEL SECURITY;

-- Create security definer function to check user role
CREATE OR REPLACE FUNCTION public.check_user_role(_user_id UUID, _role app_role)
RETURNS BOOLEAN
LANGUAGE SQL
STABLE
SECURITY DEFINER
SET search_path = public
AS $$
  SELECT EXISTS (
    SELECT 1
    FROM public.user_roles
    WHERE user_id = _user_id
      AND role = _role
      AND madrasah_id = get_user_madrasah_id()
  )
$$;

-- Create function to get user role for current madrasah
CREATE OR REPLACE FUNCTION public.get_user_role(_user_id UUID)
RETURNS app_role
LANGUAGE SQL
STABLE
SECURITY DEFINER
SET search_path = public
AS $$
  SELECT role
  FROM public.user_roles
  WHERE user_id = _user_id
    AND madrasah_id = get_user_madrasah_id()
  LIMIT 1
$$;

-- RLS policies for user_roles table
CREATE POLICY "Users can view roles in their madrasah"
ON public.user_roles
FOR SELECT
USING (madrasah_id = get_user_madrasah_id());

CREATE POLICY "Admins can insert roles in their madrasah"
ON public.user_roles
FOR INSERT
WITH CHECK (
  madrasah_id = get_user_madrasah_id() 
  AND check_user_role(auth.uid(), 'admin')
);

CREATE POLICY "Admins can update roles in their madrasah"
ON public.user_roles
FOR UPDATE
USING (
  madrasah_id = get_user_madrasah_id() 
  AND check_user_role(auth.uid(), 'admin')
);

CREATE POLICY "Admins can delete roles in their madrasah"
ON public.user_roles
FOR DELETE
USING (
  madrasah_id = get_user_madrasah_id() 
  AND check_user_role(auth.uid(), 'admin')
);

-- Update RLS policies for students table
DROP POLICY IF EXISTS "Users can insert their madrasah students" ON public.students;
DROP POLICY IF EXISTS "Users can update their madrasah students" ON public.students;
DROP POLICY IF EXISTS "Users can delete their madrasah students" ON public.students;

CREATE POLICY "Admins, teachers, and managers can insert students"
ON public.students
FOR INSERT
WITH CHECK (
  madrasah_id = get_user_madrasah_id() 
  AND (
    check_user_role(auth.uid(), 'admin') 
    OR check_user_role(auth.uid(), 'teacher')
    OR check_user_role(auth.uid(), 'manager')
  )
);

CREATE POLICY "Admins, teachers, and managers can update students"
ON public.students
FOR UPDATE
USING (
  madrasah_id = get_user_madrasah_id() 
  AND (
    check_user_role(auth.uid(), 'admin') 
    OR check_user_role(auth.uid(), 'teacher')
    OR check_user_role(auth.uid(), 'manager')
  )
);

CREATE POLICY "Only admins can delete students"
ON public.students
FOR DELETE
USING (
  madrasah_id = get_user_madrasah_id() 
  AND check_user_role(auth.uid(), 'admin')
);

-- Update RLS policies for teachers table
DROP POLICY IF EXISTS "Users can insert their madrasah teachers" ON public.teachers;
DROP POLICY IF EXISTS "Users can update their madrasah teachers" ON public.teachers;
DROP POLICY IF EXISTS "Users can delete their madrasah teachers" ON public.teachers;

CREATE POLICY "Admins and managers can insert teachers"
ON public.teachers
FOR INSERT
WITH CHECK (
  madrasah_id = get_user_madrasah_id() 
  AND (
    check_user_role(auth.uid(), 'admin') 
    OR check_user_role(auth.uid(), 'manager')
  )
);

CREATE POLICY "Admins and managers can update teachers"
ON public.teachers
FOR UPDATE
USING (
  madrasah_id = get_user_madrasah_id() 
  AND (
    check_user_role(auth.uid(), 'admin') 
    OR check_user_role(auth.uid(), 'manager')
  )
);

CREATE POLICY "Only admins can delete teachers"
ON public.teachers
FOR DELETE
USING (
  madrasah_id = get_user_madrasah_id() 
  AND check_user_role(auth.uid(), 'admin')
);

-- Update RLS policies for learning_reports table
DROP POLICY IF EXISTS "Users can insert their madrasah learning reports" ON public.learning_reports;
DROP POLICY IF EXISTS "Users can update their madrasah learning reports" ON public.learning_reports;
DROP POLICY IF EXISTS "Users can delete their madrasah learning reports" ON public.learning_reports;

CREATE POLICY "Admins and teachers can insert learning reports"
ON public.learning_reports
FOR INSERT
WITH CHECK (
  madrasah_id = get_user_madrasah_id() 
  AND (
    check_user_role(auth.uid(), 'admin') 
    OR check_user_role(auth.uid(), 'teacher')
  )
);

CREATE POLICY "Admins and teachers can update learning reports"
ON public.learning_reports
FOR UPDATE
USING (
  madrasah_id = get_user_madrasah_id() 
  AND (
    check_user_role(auth.uid(), 'admin') 
    OR check_user_role(auth.uid(), 'teacher')
  )
);

CREATE POLICY "Only admins can delete learning reports"
ON public.learning_reports
FOR DELETE
USING (
  madrasah_id = get_user_madrasah_id() 
  AND check_user_role(auth.uid(), 'admin')
);

-- Update RLS policies for income table
DROP POLICY IF EXISTS "Users can insert their madrasah income" ON public.income;
DROP POLICY IF EXISTS "Users can update their madrasah income" ON public.income;
DROP POLICY IF EXISTS "Users can delete their madrasah income" ON public.income;

CREATE POLICY "Admins and managers can insert income"
ON public.income
FOR INSERT
WITH CHECK (
  madrasah_id = get_user_madrasah_id() 
  AND (
    check_user_role(auth.uid(), 'admin') 
    OR check_user_role(auth.uid(), 'manager')
  )
);

CREATE POLICY "Admins and managers can update income"
ON public.income
FOR UPDATE
USING (
  madrasah_id = get_user_madrasah_id() 
  AND (
    check_user_role(auth.uid(), 'admin') 
    OR check_user_role(auth.uid(), 'manager')
  )
);

CREATE POLICY "Only admins can delete income"
ON public.income
FOR DELETE
USING (
  madrasah_id = get_user_madrasah_id() 
  AND check_user_role(auth.uid(), 'admin')
);

-- Update RLS policies for expense table
DROP POLICY IF EXISTS "Users can insert their madrasah expense" ON public.expense;
DROP POLICY IF EXISTS "Users can update their madrasah expense" ON public.expense;
DROP POLICY IF EXISTS "Users can delete their madrasah expense" ON public.expense;

CREATE POLICY "Admins and managers can insert expense"
ON public.expense
FOR INSERT
WITH CHECK (
  madrasah_id = get_user_madrasah_id() 
  AND (
    check_user_role(auth.uid(), 'admin') 
    OR check_user_role(auth.uid(), 'manager')
  )
);

CREATE POLICY "Admins and managers can update expense"
ON public.expense
FOR UPDATE
USING (
  madrasah_id = get_user_madrasah_id() 
  AND (
    check_user_role(auth.uid(), 'admin') 
    OR check_user_role(auth.uid(), 'manager')
  )
);

CREATE POLICY "Only admins can delete expense"
ON public.expense
FOR DELETE
USING (
  madrasah_id = get_user_madrasah_id() 
  AND check_user_role(auth.uid(), 'admin')
);

-- Update RLS policies for fees table
DROP POLICY IF EXISTS "Users can insert their madrasah fees" ON public.fees;
DROP POLICY IF EXISTS "Users can update their madrasah fees" ON public.fees;
DROP POLICY IF EXISTS "Users can delete their madrasah fees" ON public.fees;

CREATE POLICY "Admins, managers, and parents can insert fees"
ON public.fees
FOR INSERT
WITH CHECK (
  madrasah_id = get_user_madrasah_id() 
  AND (
    check_user_role(auth.uid(), 'admin') 
    OR check_user_role(auth.uid(), 'manager')
    OR check_user_role(auth.uid(), 'parent')
  )
);

CREATE POLICY "Admins, managers, and parents can update fees"
ON public.fees
FOR UPDATE
USING (
  madrasah_id = get_user_madrasah_id() 
  AND (
    check_user_role(auth.uid(), 'admin') 
    OR check_user_role(auth.uid(), 'manager')
    OR check_user_role(auth.uid(), 'parent')
  )
);

CREATE POLICY "Only admins can delete fees"
ON public.fees
FOR DELETE
USING (
  madrasah_id = get_user_madrasah_id() 
  AND check_user_role(auth.uid(), 'admin')
);

-- Update RLS policies for classes table
DROP POLICY IF EXISTS "Users can insert their madrasah classes" ON public.classes;
DROP POLICY IF EXISTS "Users can update their madrasah classes" ON public.classes;
DROP POLICY IF EXISTS "Users can delete their madrasah classes" ON public.classes;

CREATE POLICY "Admins can insert classes"
ON public.classes
FOR INSERT
WITH CHECK (
  madrasah_id = get_user_madrasah_id() 
  AND check_user_role(auth.uid(), 'admin')
);

CREATE POLICY "Admins can update classes"
ON public.classes
FOR UPDATE
USING (
  madrasah_id = get_user_madrasah_id() 
  AND check_user_role(auth.uid(), 'admin')
);

CREATE POLICY "Admins can delete classes"
ON public.classes
FOR DELETE
USING (
  madrasah_id = get_user_madrasah_id() 
  AND check_user_role(auth.uid(), 'admin')
);

-- Update RLS policies for attendance table
DROP POLICY IF EXISTS "Users can insert their madrasah attendance" ON public.attendance;
DROP POLICY IF EXISTS "Users can update their madrasah attendance" ON public.attendance;
DROP POLICY IF EXISTS "Users can delete their madrasah attendance" ON public.attendance;

CREATE POLICY "Admins and teachers can insert attendance"
ON public.attendance
FOR INSERT
WITH CHECK (
  madrasah_id = get_user_madrasah_id() 
  AND (
    check_user_role(auth.uid(), 'admin') 
    OR check_user_role(auth.uid(), 'teacher')
  )
);

CREATE POLICY "Admins and teachers can update attendance"
ON public.attendance
FOR UPDATE
USING (
  madrasah_id = get_user_madrasah_id() 
  AND (
    check_user_role(auth.uid(), 'admin') 
    OR check_user_role(auth.uid(), 'teacher')
  )
);

CREATE POLICY "Only admins can delete attendance"
ON public.attendance
FOR DELETE
USING (
  madrasah_id = get_user_madrasah_id() 
  AND check_user_role(auth.uid(), 'admin')
);

-- Update RLS policies for salaries table
DROP POLICY IF EXISTS "Users can insert their madrasah salaries" ON public.salaries;
DROP POLICY IF EXISTS "Users can update their madrasah salaries" ON public.salaries;
DROP POLICY IF EXISTS "Users can delete their madrasah salaries" ON public.salaries;

CREATE POLICY "Only admins can insert salaries"
ON public.salaries
FOR INSERT
WITH CHECK (
  madrasah_id = get_user_madrasah_id() 
  AND check_user_role(auth.uid(), 'admin')
);

CREATE POLICY "Only admins can update salaries"
ON public.salaries
FOR UPDATE
USING (
  madrasah_id = get_user_madrasah_id() 
  AND check_user_role(auth.uid(), 'admin')
);

CREATE POLICY "Only admins can delete salaries"
ON public.salaries
FOR DELETE
USING (
  madrasah_id = get_user_madrasah_id() 
  AND check_user_role(auth.uid(), 'admin')
);

-- Update RLS policies for loans table
DROP POLICY IF EXISTS "Users can insert their madrasah loans" ON public.loans;
DROP POLICY IF EXISTS "Users can update their madrasah loans" ON public.loans;
DROP POLICY IF EXISTS "Users can delete their madrasah loans" ON public.loans;

CREATE POLICY "Only admins can insert loans"
ON public.loans
FOR INSERT
WITH CHECK (
  madrasah_id = get_user_madrasah_id() 
  AND check_user_role(auth.uid(), 'admin')
);

CREATE POLICY "Only admins can update loans"
ON public.loans
FOR UPDATE
USING (
  madrasah_id = get_user_madrasah_id() 
  AND check_user_role(auth.uid(), 'admin')
);

CREATE POLICY "Only admins can delete loans"
ON public.loans
FOR DELETE
USING (
  madrasah_id = get_user_madrasah_id() 
  AND check_user_role(auth.uid(), 'admin')
);

-- Update RLS policies for notifications table
DROP POLICY IF EXISTS "Users can insert their madrasah notifications" ON public.notifications;
DROP POLICY IF EXISTS "Users can update their madrasah notifications" ON public.notifications;
DROP POLICY IF EXISTS "Users can delete their madrasah notifications" ON public.notifications;

CREATE POLICY "Only admins can insert notifications"
ON public.notifications
FOR INSERT
WITH CHECK (
  madrasah_id = get_user_madrasah_id() 
  AND check_user_role(auth.uid(), 'admin')
);

CREATE POLICY "Users can update their own notifications"
ON public.notifications
FOR UPDATE
USING (madrasah_id = get_user_madrasah_id());

CREATE POLICY "Only admins can delete notifications"
ON public.notifications
FOR DELETE
USING (
  madrasah_id = get_user_madrasah_id() 
  AND check_user_role(auth.uid(), 'admin')
);

-- Update RLS policies for class_teachers table
DROP POLICY IF EXISTS "Users can insert their madrasah class_teachers" ON public.class_teachers;
DROP POLICY IF EXISTS "Users can update their madrasah class_teachers" ON public.class_teachers;
DROP POLICY IF EXISTS "Users can delete their madrasah class_teachers" ON public.class_teachers;

CREATE POLICY "Only admins can insert class_teachers"
ON public.class_teachers
FOR INSERT
WITH CHECK (
  madrasah_id = get_user_madrasah_id() 
  AND check_user_role(auth.uid(), 'admin')
);

CREATE POLICY "Only admins can update class_teachers"
ON public.class_teachers
FOR UPDATE
USING (
  madrasah_id = get_user_madrasah_id() 
  AND check_user_role(auth.uid(), 'admin')
);

CREATE POLICY "Only admins can delete class_teachers"
ON public.class_teachers
FOR DELETE
USING (
  madrasah_id = get_user_madrasah_id() 
  AND check_user_role(auth.uid(), 'admin')
);

-- Update RLS policies for backups table
DROP POLICY IF EXISTS "Users can insert their madrasah backups" ON public.backups;

CREATE POLICY "Only admins can insert backups"
ON public.backups
FOR INSERT
WITH CHECK (
  madrasah_id = get_user_madrasah_id() 
  AND check_user_role(auth.uid(), 'admin')
);