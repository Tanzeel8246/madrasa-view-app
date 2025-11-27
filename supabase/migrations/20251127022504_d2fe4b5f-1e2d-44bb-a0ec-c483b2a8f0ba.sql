-- Clean up users who don't have profiles (incomplete signups)
-- This will allow them to signup again with the fixed code

DO $$
DECLARE
  user_record RECORD;
BEGIN
  -- Find all users without profiles
  FOR user_record IN 
    SELECT u.id, u.email
    FROM auth.users u
    LEFT JOIN public.profiles p ON p.user_id = u.id
    WHERE p.id IS NULL
  LOOP
    -- Delete the user (this will cascade to any related records)
    DELETE FROM auth.users WHERE id = user_record.id;
    
    RAISE NOTICE 'Deleted incomplete user: % (%)', user_record.email, user_record.id;
  END LOOP;
END $$;