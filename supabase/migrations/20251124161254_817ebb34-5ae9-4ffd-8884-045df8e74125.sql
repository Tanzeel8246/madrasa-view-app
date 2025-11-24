-- Drop the existing problematic INSERT policy
DROP POLICY IF EXISTS "Users can insert their own role or admins can add roles" ON public.user_roles;

-- Create a simpler INSERT policy that only allows users to insert their first role
-- This prevents recursion by not checking admin status during invite acceptance
CREATE POLICY "Users can insert their first role" 
ON public.user_roles
FOR INSERT 
TO authenticated
WITH CHECK (
  user_id = auth.uid() 
  AND NOT user_has_role_in_madrasah(auth.uid(), madrasah_id)
);

-- Create a separate policy for admins to add roles
-- This uses a simpler check that won't cause recursion
CREATE POLICY "Admins can add roles to their madrasah" 
ON public.user_roles
FOR INSERT 
TO authenticated
WITH CHECK (
  madrasah_id IN (
    SELECT madrasah_id 
    FROM public.user_roles 
    WHERE user_id = auth.uid() 
    AND role = 'admin'::app_role
    LIMIT 1
  )
);