-- Allow authenticated users to insert new madrasah records
CREATE POLICY "Authenticated users can create madrasah"
ON public.madrasah
FOR INSERT
TO authenticated
WITH CHECK (true);

-- Allow authenticated users to insert their own profile
CREATE POLICY "Users can insert their own profile"
ON public.profiles
FOR INSERT
TO authenticated
WITH CHECK (user_id = auth.uid());