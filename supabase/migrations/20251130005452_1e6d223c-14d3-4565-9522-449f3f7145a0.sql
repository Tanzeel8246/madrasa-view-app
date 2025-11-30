-- Fix user_roles INSERT policy to work during signup
-- The existing "Authenticated users can insert roles" policy needs to be updated
-- to allow insertion during the signup process when the user is creating their own role

-- First, drop the old policy
DROP POLICY IF EXISTS "Authenticated users can insert roles" ON public.user_roles;

-- Create new policy that allows users to insert their own role
-- This works during signup by checking if the inserting user matches the user_id being inserted
CREATE POLICY "Users can insert their own role during signup"
ON public.user_roles
FOR INSERT
TO authenticated
WITH CHECK (
  -- Allow if the authenticated user is inserting their own role
  auth.uid() = user_id
);

-- Also ensure the "Admins can add roles to other users" policy exists and works correctly
-- This should already exist, but let's recreate it to be sure
DROP POLICY IF EXISTS "Admins can add roles to other users" ON public.user_roles;

CREATE POLICY "Admins can add roles to other users"
ON public.user_roles
FOR INSERT
TO authenticated  
WITH CHECK (
  -- Allow if user is admin of the madrasah they're adding a role for
  is_user_admin_of_madrasah(auth.uid(), madrasah_id)
);