-- Drop both policies
DROP POLICY IF EXISTS "Allow role insertion for new users" ON public.user_roles;
DROP POLICY IF EXISTS "Admins can add roles to users" ON public.user_roles;

-- Create ONE simple policy that allows authenticated users to insert roles
-- WITHOUT any table self-reference to avoid recursion
-- Duplicate checking will be handled by application logic
CREATE POLICY "Authenticated users can insert roles" 
ON public.user_roles
FOR INSERT 
TO authenticated
WITH CHECK (
  -- Only allow if user is inserting for themselves
  user_id = auth.uid()
);