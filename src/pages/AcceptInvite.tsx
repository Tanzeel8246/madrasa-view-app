import { useState, useEffect } from "react";
import { useNavigate, useSearchParams } from "react-router-dom";
import { useAuth } from "@/contexts/AuthContext";
import { supabase } from "@/integrations/supabase/client";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { toast } from "sonner";
import { useLanguage } from "@/contexts/LanguageContext";
import { CheckCircle, XCircle, Loader2 } from "lucide-react";

const AcceptInvite = () => {
  const { user, madrasahId } = useAuth();
  const navigate = useNavigate();
  const { language } = useLanguage();
  const [searchParams] = useSearchParams();
  const [loading, setLoading] = useState(true);
  const [inviteData, setInviteData] = useState<any>(null);
  const [processing, setProcessing] = useState(false);

  useEffect(() => {
    const token = searchParams.get('invite');
    if (!token) {
      navigate("/auth");
      return;
    }

    if (!user) {
      navigate(`/auth?invite=${token}`);
      return;
    }

    validateInvite(token);
  }, [searchParams, user]);

  const validateInvite = async (token: string) => {
    setLoading(true);
    const { data, error } = await supabase
      .from('invites' as any)
      .select('*, madrasah:madrasah_id(*)')
      .eq('token', token)
      .eq('is_active', true)
      .maybeSingle();

    if (error || !data) {
      toast.error(language === "ur" ? "غلط یا ختم شدہ دعوت نامہ" : "Invalid or expired invite");
      setTimeout(() => navigate("/"), 2000);
    } else {
      setInviteData(data);
    }
    setLoading(false);
  };

  const handleAccept = async () => {
    if (!user || !inviteData) return;

    setProcessing(true);

    try {
      // Check if user already has a role in this madrasah
      const { data: existingRole } = await supabase
        .from("user_roles")
        .select("*")
        .eq("user_id", user.id)
        .eq("madrasah_id", inviteData.madrasah_id)
        .maybeSingle();

      if (existingRole) {
        toast.error(language === "ur" ? "آپ پہلے سے اس مدرسے میں شامل ہیں" : "You are already a member of this madrasa");
        setTimeout(() => navigate("/"), 2000);
        return;
      }

      // Add user to madrasah with the invite's role
      const { error: roleError } = await supabase
        .from("user_roles")
        .insert({
          user_id: user.id,
          madrasah_id: inviteData.madrasah_id,
          role: inviteData.role,
        });

      if (roleError) throw roleError;

      // Update profile
      const { error: profileError } = await supabase
        .from("profiles")
        .upsert({
          user_id: user.id,
          madrasah_id: inviteData.madrasah_id,
          full_name: user.user_metadata?.full_name || "",
          role: inviteData.role,
        });

      if (profileError) throw profileError;

      // Increment used count
      await supabase
        .from('invites' as any)
        .update({ used_count: inviteData.used_count + 1 })
        .eq('id', inviteData.id);

      toast.success(language === "ur" ? "کامیابی سے شامل ہو گئے" : "Successfully joined");
      
      // Force page reload to update auth context
      window.location.href = "/";
    } catch (error: any) {
      toast.error(language === "ur" ? "خرابی" : "Error", {
        description: error.message,
      });
    } finally {
      setProcessing(false);
    }
  };

  const handleDecline = () => {
    toast.info(language === "ur" ? "دعوت نامہ مسترد کر دیا گیا" : "Invite declined");
    navigate("/");
  };

  if (loading) {
    return (
      <div className="min-h-screen flex items-center justify-center bg-background">
        <div className="text-center">
          <Loader2 className="h-12 w-12 animate-spin text-primary mx-auto mb-4" />
          <p className="text-muted-foreground">
            {language === "ur" ? "لوڈ ہو رہا ہے..." : "Loading..."}
          </p>
        </div>
      </div>
    );
  }

  if (!inviteData) {
    return null;
  }

  return (
    <div className="min-h-screen flex items-center justify-center bg-background p-4">
      <Card className="w-full max-w-md">
        <CardHeader className="text-center">
          <CardTitle className="text-2xl">
            {language === "ur" ? "دعوت نامہ" : "Invitation"}
          </CardTitle>
          <CardDescription>
            {language === "ur" 
              ? "آپ کو مدرسے میں شامل ہونے کی دعوت دی گئی ہے" 
              : "You've been invited to join a madrasa"}
          </CardDescription>
        </CardHeader>
        <CardContent className="space-y-6">
          <div className="bg-muted p-4 rounded-lg space-y-2">
            <div className="flex justify-between">
              <span className="text-muted-foreground">
                {language === "ur" ? "مدرسہ:" : "Madrasa:"}
              </span>
              <span className="font-semibold">{inviteData.madrasah?.name}</span>
            </div>
            <div className="flex justify-between">
              <span className="text-muted-foreground">
                {language === "ur" ? "رول:" : "Role:"}
              </span>
              <span className="font-semibold capitalize">{inviteData.role}</span>
            </div>
          </div>

          <div className="flex gap-3">
            <Button
              onClick={handleAccept}
              disabled={processing}
              className="flex-1"
            >
              {processing ? (
                <Loader2 className="h-4 w-4 animate-spin mx-auto" />
              ) : (
                <>
                  <CheckCircle className="h-4 w-4 me-2" />
                  {language === "ur" ? "قبول کریں" : "Accept"}
                </>
              )}
            </Button>
            <Button
              onClick={handleDecline}
              variant="outline"
              disabled={processing}
              className="flex-1"
            >
              <XCircle className="h-4 w-4 me-2" />
              {language === "ur" ? "مسترد کریں" : "Decline"}
            </Button>
          </div>
        </CardContent>
      </Card>
    </div>
  );
};

export default AcceptInvite;
