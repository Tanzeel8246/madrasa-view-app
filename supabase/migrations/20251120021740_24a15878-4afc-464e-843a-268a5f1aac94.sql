-- Fix circular dependency in RLS policies for user_roles table
-- The issue: check_user_role() queries user_roles, which triggers RLS,
-- which calls check_user_role() again, creating a circular dependency

-- Drop existing policies
DROP POLICY IF EXISTS "Users can view roles in their madrasah" ON public.user_roles;
DROP POLICY IF EXISTS "Admins can insert roles in their madrasah" ON public.user_roles;
DROP POLICY IF EXISTS "Admins can update roles in their madrasah" ON public.user_roles;
DROP POLICY IF EXISTS "Admins can delete roles in their madrasah" ON public.user_roles;

-- Recreate policies without circular dependency
-- Allow users to view their own role records (no admin check needed for viewing)
CREATE POLICY "Users can view roles in their madrasah"
ON public.user_roles
FOR SELECT
USING (
  user_id = auth.uid() 
  OR madrasah_id IN (
    SELECT madrasah_id FROM public.profiles WHERE user_id = auth.uid()
  )
);

-- Admins can insert roles (keep admin check but use optimized approach)
CREATE POLICY "Admins can insert roles in their madrasah"
ON public.user_roles
FOR INSERT
WITH CHECK (
  madrasah_id = get_user_madrasah_id() 
  AND EXISTS (
    SELECT 1 FROM public.user_roles ur
    WHERE ur.user_id = auth.uid()
    AND ur.madrasah_id = get_user_madrasah_id()
    AND ur.role = 'admin'::app_role
  )
);

-- Admins can update roles
CREATE POLICY "Admins can update roles in their madrasah"
ON public.user_roles
FOR UPDATE
USING (
  madrasah_id = get_user_madrasah_id() 
  AND EXISTS (
    SELECT 1 FROM public.user_roles ur
    WHERE ur.user_id = auth.uid()
    AND ur.madrasah_id = get_user_madrasah_id()
    AND ur.role = 'admin'::app_role
  )
);

-- Admins can delete roles
CREATE POLICY "Admins can delete roles in their madrasah"
ON public.user_roles
FOR DELETE
USING (
  madrasah_id = get_user_madrasah_id() 
  AND EXISTS (
    SELECT 1 FROM public.user_roles ur
    WHERE ur.user_id = auth.uid()
    AND ur.madrasah_id = get_user_madrasah_id()
    AND ur.role = 'admin'::app_role
  )
);