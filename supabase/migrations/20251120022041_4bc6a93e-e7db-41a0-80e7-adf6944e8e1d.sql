-- Fix infinite recursion in user_roles INSERT policy
-- Allow users to join a madrasah via invite without requiring admin check

DROP POLICY IF EXISTS "Admins can insert roles in their madrasah" ON public.user_roles;

-- New policy: Allow users to insert their own first role OR admins to add others
CREATE POLICY "Users can insert their own role or admins can add roles"
ON public.user_roles
FOR INSERT
WITH CHECK (
  -- Users can insert their own role if they don't have one in this madrasah yet
  (
    user_id = auth.uid()
    AND NOT EXISTS (
      SELECT 1 FROM public.user_roles ur
      WHERE ur.user_id = auth.uid()
      AND ur.madrasah_id = user_roles.madrasah_id
    )
  )
  OR
  -- OR existing admins can add roles for others
  (
    EXISTS (
      SELECT 1 FROM public.user_roles ur
      WHERE ur.user_id = auth.uid()
      AND ur.madrasah_id = user_roles.madrasah_id
      AND ur.role = 'admin'::app_role
    )
  )
);