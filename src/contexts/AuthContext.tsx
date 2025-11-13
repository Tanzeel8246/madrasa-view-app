import { createContext, useContext, useEffect, useState, ReactNode } from "react";
import { User, Session } from "@supabase/supabase-js";
import { supabase } from "@/integrations/supabase/client";
import { useNavigate } from "react-router-dom";

interface AuthContextType {
  user: User | null;
  session: Session | null;
  madrasahId: string | null;
  userRole: string | null;
  signIn: (email: string, password: string) => Promise<{ error: any }>;
  signUp: (email: string, password: string, madrasahData: {
    name: string;
    madrasahId: string;
    fullName: string;
  }) => Promise<{ error: any }>;
  signUpWithInvite: (email: string, password: string, inviteToken: string, fullName: string) => Promise<{ error: any }>;
  signOut: () => Promise<void>;
  loading: boolean;
}

const AuthContext = createContext<AuthContextType | undefined>(undefined);

export const AuthProvider = ({ children }: { children: ReactNode }) => {
  const [user, setUser] = useState<User | null>(null);
  const [session, setSession] = useState<Session | null>(null);
  const [madrasahId, setMadrasahId] = useState<string | null>(null);
  const [userRole, setUserRole] = useState<string | null>(null);
  const [loading, setLoading] = useState(true);
  const navigate = useNavigate();

  useEffect(() => {
    // Set up auth state listener FIRST
    const { data: { subscription } } = supabase.auth.onAuthStateChange(
      (event, session) => {
        setSession(session);
        setUser(session?.user ?? null);
        
        // Fetch madrasah_id when user logs in
        if (session?.user) {
          setTimeout(() => {
            fetchMadrasahId(session.user.id);
          }, 0);
        } else {
          setMadrasahId(null);
        }
      }
    );

    // THEN check for existing session
    supabase.auth.getSession().then(({ data: { session } }) => {
      setSession(session);
      setUser(session?.user ?? null);
      
      if (session?.user) {
        fetchMadrasahId(session.user.id);
      }
      setLoading(false);
    });

    return () => subscription.unsubscribe();
  }, []);

  const fetchMadrasahId = async (userId: string) => {
    const { data } = await supabase
      .from("profiles")
      .select("madrasah_id")
      .eq("user_id", userId)
      .maybeSingle();
    
    if (data) {
      setMadrasahId(data.madrasah_id);
      // Fetch user role
      const { data: roleData } = await supabase
        .from("user_roles")
        .select("role")
        .eq("user_id", userId)
        .eq("madrasah_id", data.madrasah_id)
        .maybeSingle();
      
      setUserRole(roleData?.role || null);
    }
  };

  const signIn = async (email: string, password: string) => {
    const { error } = await supabase.auth.signInWithPassword({
      email,
      password,
    });
    return { error };
  };

  const signUp = async (
    email: string,
    password: string,
    madrasahData: { name: string; madrasahId: string; fullName: string }
  ) => {
    const redirectUrl = `${window.location.origin}/`;
    
    const { data: authData, error: authError } = await supabase.auth.signUp({
      email,
      password,
      options: {
        emailRedirectTo: redirectUrl,
      },
    });

    if (authError) return { error: authError };

    if (authData.user) {
      // Create madrasah
      const { data: madrasah, error: madrasahError } = await supabase
        .from("madrasah")
        .insert({
          name: madrasahData.name,
          madrasah_id: madrasahData.madrasahId,
        })
        .select()
        .maybeSingle();

      if (madrasahError || !madrasah) return { error: madrasahError || new Error("Failed to create madrasah") };

      // Create profile
      const { error: profileError } = await supabase
        .from("profiles")
        .insert({
          user_id: authData.user.id,
          madrasah_id: madrasah.id,
          full_name: madrasahData.fullName,
          role: "admin",
        });

      if (profileError) return { error: profileError };

      // Assign admin role to the user
      const { error: roleError } = await supabase
        .from("user_roles")
        .insert({
          user_id: authData.user.id,
          madrasah_id: madrasah.id,
          role: "admin",
        });

      if (roleError) return { error: roleError };
    }

    return { error: null };
  };

  const signUpWithInvite = async (
    email: string,
    password: string,
    inviteToken: string,
    fullName: string
  ) => {
    // First validate the invite
    const { data: invite, error: inviteError } = await supabase
      .from('invites' as any)
      .select('*')
      .eq('token', inviteToken)
      .eq('is_active', true)
      .maybeSingle();

    if (inviteError || !invite) {
      return { error: new Error("Invalid or expired invite") };
    }

    const redirectUrl = `${window.location.origin}/`;
    
    const { data: authData, error: authError } = await supabase.auth.signUp({
      email,
      password,
      options: {
        emailRedirectTo: redirectUrl,
      },
    });

    if (authError) return { error: authError };

    if (authData.user) {
      // Create profile with invited madrasah
      const { error: profileError } = await supabase
        .from("profiles")
        .insert({
          user_id: authData.user.id,
          madrasah_id: (invite as any).madrasah_id,
          full_name: fullName,
          role: (invite as any).role,
        });

      if (profileError) return { error: profileError };

      // Assign role from invite
      const { error: roleError } = await supabase
        .from("user_roles")
        .insert({
          user_id: authData.user.id,
          madrasah_id: (invite as any).madrasah_id,
          role: (invite as any).role,
        });

      if (roleError) return { error: roleError };

      // Increment used count
      await supabase
        .from('invites' as any)
        .update({ used_count: (invite as any).used_count + 1 })
        .eq('id', (invite as any).id);
    }

    return { error: null };
  };

  const signOut = async () => {
    await supabase.auth.signOut();
    setMadrasahId(null);
    setUserRole(null);
    navigate("/auth");
  };

  return (
    <AuthContext.Provider
      value={{
        user,
        session,
        madrasahId,
        userRole,
        signIn,
        signUp,
        signUpWithInvite,
        signOut,
        loading,
      }}
    >
      {children}
    </AuthContext.Provider>
  );
};

export const useAuth = () => {
  const context = useContext(AuthContext);
  if (context === undefined) {
    throw new Error("useAuth must be used within an AuthProvider");
  }
  return context;
};
