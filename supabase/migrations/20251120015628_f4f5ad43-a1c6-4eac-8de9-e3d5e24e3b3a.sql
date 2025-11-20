-- Create function to delete all madrasah data permanently
-- This is a dangerous operation and should only be called by admins
CREATE OR REPLACE FUNCTION public.delete_madrasah_permanently(_madrasah_id uuid)
RETURNS void
LANGUAGE plpgsql
SECURITY DEFINER
SET search_path = public
AS $$
BEGIN
  -- Only allow admins to delete
  IF NOT check_user_role(auth.uid(), 'admin'::app_role) THEN
    RAISE EXCEPTION 'Only admins can delete madrasah data';
  END IF;

  -- Verify the user belongs to this madrasah
  IF NOT EXISTS (
    SELECT 1 FROM profiles 
    WHERE user_id = auth.uid() 
    AND madrasah_id = _madrasah_id
  ) THEN
    RAISE EXCEPTION 'User does not belong to this madrasah';
  END IF;

  -- Delete all related data in correct order
  -- Delete attendance records
  DELETE FROM attendance WHERE madrasah_id = _madrasah_id;
  
  -- Delete learning reports
  DELETE FROM learning_reports WHERE madrasah_id = _madrasah_id;
  
  -- Delete fees
  DELETE FROM fees WHERE madrasah_id = _madrasah_id;
  
  -- Delete salaries
  DELETE FROM salaries WHERE madrasah_id = _madrasah_id;
  
  -- Delete loans
  DELETE FROM loans WHERE madrasah_id = _madrasah_id;
  
  -- Delete expenses
  DELETE FROM expense WHERE madrasah_id = _madrasah_id;
  
  -- Delete income
  DELETE FROM income WHERE madrasah_id = _madrasah_id;
  
  -- Delete class_teachers
  DELETE FROM class_teachers WHERE madrasah_id = _madrasah_id;
  
  -- Delete students
  DELETE FROM students WHERE madrasah_id = _madrasah_id;
  
  -- Delete teachers
  DELETE FROM teachers WHERE madrasah_id = _madrasah_id;
  
  -- Delete classes
  DELETE FROM classes WHERE madrasah_id = _madrasah_id;
  
  -- Delete backups
  DELETE FROM backups WHERE madrasah_id = _madrasah_id;
  
  -- Delete notifications
  DELETE FROM notifications WHERE madrasah_id = _madrasah_id;
  
  -- Delete invites
  DELETE FROM invites WHERE madrasah_id = _madrasah_id;
  
  -- Delete user roles for this madrasah
  DELETE FROM user_roles WHERE madrasah_id = _madrasah_id;
  
  -- Delete profiles for this madrasah
  DELETE FROM profiles WHERE madrasah_id = _madrasah_id;
  
  -- Finally delete the madrasah itself
  DELETE FROM madrasah WHERE id = _madrasah_id;
  
END;
$$;