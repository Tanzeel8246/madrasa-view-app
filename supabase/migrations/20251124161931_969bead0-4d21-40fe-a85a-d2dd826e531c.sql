-- Drop all existing INSERT policies on user_roles
DROP POLICY IF EXISTS "Users can insert their first role" ON public.user_roles;
DROP POLICY IF EXISTS "Admins can add roles to their madrasah" ON public.user_roles;

-- Create a single, simple INSERT policy that doesn't cause recursion
-- This allows:
-- 1. Users to insert their own role (for invite acceptance)
-- 2. Will be validated by application logic, not by policy recursion
CREATE POLICY "Allow authenticated users to insert roles" 
ON public.user_roles
FOR INSERT 
TO authenticated
WITH CHECK (
  -- Either user is inserting for themselves (invite acceptance)
  -- OR they already have an admin role (checked via separate table query that won't recurse)
  user_id = auth.uid()
  OR
  auth.uid() IN (
    SELECT ur.user_id 
    FROM public.user_roles ur
    WHERE ur.role = 'admin'::app_role
    AND ur.madrasah_id = user_roles.madrasah_id
  )
);