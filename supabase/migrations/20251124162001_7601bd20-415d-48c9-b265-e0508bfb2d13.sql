-- Drop the problematic policy
DROP POLICY IF EXISTS "Allow authenticated users to insert roles" ON public.user_roles;

-- Create a simple INSERT policy without any recursion
-- This policy allows users to insert their own role OR if they're an admin
-- We check admin status using the existing security definer function
CREATE POLICY "Users and admins can insert roles" 
ON public.user_roles
FOR INSERT 
TO authenticated
WITH CHECK (
  -- Allow if user is inserting for themselves (for invite acceptance)
  user_id = auth.uid()
  OR
  -- OR if user is an admin of this madrasah (using security definer function to avoid recursion)
  (
    madrasah_id IN (
      SELECT p.madrasah_id 
      FROM public.profiles p 
      WHERE p.user_id = auth.uid()
    )
    AND check_user_role(auth.uid(), 'admin'::app_role)
  )
);