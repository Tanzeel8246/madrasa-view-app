-- Add receipt_url to fees table and create storage bucket for fee receipts

-- 1) Add column to fees for storing receipt image URL
ALTER TABLE public.fees
ADD COLUMN IF NOT EXISTS receipt_url TEXT;

-- 2) Create a public bucket for fee receipts (idempotent)
INSERT INTO storage.buckets (id, name, public)
VALUES ('fee-receipts', 'fee-receipts', true)
ON CONFLICT (id) DO NOTHING;

-- 3) Storage RLS policies for the bucket
DO $$ BEGIN
  CREATE POLICY "Public read fee receipts"
  ON storage.objects
  FOR SELECT
  USING (bucket_id = 'fee-receipts');
EXCEPTION WHEN duplicate_object THEN NULL; END $$;

DO $$ BEGIN
  CREATE POLICY "Authenticated upload fee receipts"
  ON storage.objects
  FOR INSERT TO authenticated
  WITH CHECK (bucket_id = 'fee-receipts');
EXCEPTION WHEN duplicate_object THEN NULL; END $$;

DO $$ BEGIN
  CREATE POLICY "Owner update fee receipts"
  ON storage.objects
  FOR UPDATE TO authenticated
  USING (bucket_id = 'fee-receipts' AND owner = auth.uid())
  WITH CHECK (bucket_id = 'fee-receipts' AND owner = auth.uid());
EXCEPTION WHEN duplicate_object THEN NULL; END $$;

DO $$ BEGIN
  CREATE POLICY "Owner delete fee receipts"
  ON storage.objects
  FOR DELETE TO authenticated
  USING (bucket_id = 'fee-receipts' AND owner = auth.uid());
EXCEPTION WHEN duplicate_object THEN NULL; END $$;