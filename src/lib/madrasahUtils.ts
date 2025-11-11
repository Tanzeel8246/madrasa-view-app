import { supabase } from "@/integrations/supabase/client";

export const getMadrasahId = async (): Promise<string | null> => {
  const { data: { user } } = await supabase.auth.getUser();
  
  if (!user) return null;

  const { data } = await supabase
    .from("profiles")
    .select("madrasah_id")
    .eq("user_id", user.id)
    .single();

  return data?.madrasah_id || null;
};

export const addMadrasahId = async (data: any): Promise<any> => {
  const madrasahId = await getMadrasahId();
  if (!madrasahId) {
    throw new Error("Madrasah ID not found");
  }
  return { ...data, madrasah_id: madrasahId };
};
