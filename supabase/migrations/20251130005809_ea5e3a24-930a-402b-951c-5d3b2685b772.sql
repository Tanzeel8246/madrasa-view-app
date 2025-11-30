-- Fix the UPDATE policy for user_roles to avoid infinite recursion
-- The current policy queries user_roles table within a policy on user_roles, causing recursion
-- Instead, use the existing check_user_role security definer function

-- Drop the problematic UPDATE policy
DROP POLICY IF EXISTS "Admins can update roles in their madrasah" ON public.user_roles;

-- Create new UPDATE policy using security definer function
CREATE POLICY "Admins can update roles in their madrasah"
ON public.user_roles
FOR UPDATE
TO authenticated
USING (
  -- Check if the user is admin using security definer function
  madrasah_id = get_user_madrasah_id() 
  AND check_user_role(auth.uid(), 'admin'::app_role)
);

-- Also fix the DELETE policy to avoid similar issues
DROP POLICY IF EXISTS "Admins can delete roles in their madrasah" ON public.user_roles;

CREATE POLICY "Admins can delete roles in their madrasah"
ON public.user_roles
FOR DELETE
TO authenticated
USING (
  madrasah_id = get_user_madrasah_id() 
  AND check_user_role(auth.uid(), 'admin'::app_role)
);