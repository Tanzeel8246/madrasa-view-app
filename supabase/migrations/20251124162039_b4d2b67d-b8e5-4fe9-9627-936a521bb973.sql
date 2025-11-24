-- Drop the current policy
DROP POLICY IF EXISTS "Users and admins can insert roles" ON public.user_roles;

-- Create the simplest possible INSERT policy
-- Allow authenticated users to insert a role for themselves
-- AND ensure they don't already have a role in that madrasah (using security definer function)
CREATE POLICY "Allow role insertion for new users" 
ON public.user_roles
FOR INSERT 
TO authenticated
WITH CHECK (
  user_id = auth.uid()
  AND NOT user_has_role_in_madrasah(auth.uid(), madrasah_id)
);

-- Create a separate policy for admins to add roles
-- This uses a different approach - check if admin role exists in profiles
CREATE POLICY "Admins can add roles to users" 
ON public.user_roles
FOR INSERT 
TO authenticated
WITH CHECK (
  EXISTS (
    SELECT 1 
    FROM public.profiles p
    INNER JOIN public.user_roles ur ON ur.user_id = p.user_id AND ur.madrasah_id = p.madrasah_id
    WHERE p.user_id = auth.uid() 
    AND p.madrasah_id = user_roles.madrasah_id
    AND ur.role = 'admin'::app_role
  )
);