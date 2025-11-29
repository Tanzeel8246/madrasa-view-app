import { useState, useEffect } from "react";
import { useNavigate, useSearchParams } from "react-router-dom";
import { useAuth } from "@/contexts/AuthContext";
import { supabase } from "@/integrations/supabase/client";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Alert, AlertDescription } from "@/components/ui/alert";
import { toast } from "sonner";
import { useLanguage } from "@/contexts/LanguageContext";
import { CheckCircle, XCircle, Loader2 } from "lucide-react";

const AcceptInvite = () => {
  const { user, madrasahId, signUpWithInvite } = useAuth();
  const navigate = useNavigate();
  const { language } = useLanguage();
  const [searchParams] = useSearchParams();
  const [loading, setLoading] = useState(true);
  const [inviteData, setInviteData] = useState<any>(null);
  const [processing, setProcessing] = useState(false);
  const [fullName, setFullName] = useState("");
  const [email, setEmail] = useState("");
  const [password, setPassword] = useState("");
  const [confirmPassword, setConfirmPassword] = useState("");

  useEffect(() => {
    const token = searchParams.get('invite');
    if (!token) {
      navigate("/auth");
      return;
    }

    validateInvite(token);
  }, [searchParams]);


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

  const handleJoinWithSignup = async () => {
    if (!fullName.trim() || !email.trim() || !password.trim()) {
      toast.error(language === "ur" ? "تمام فیلڈز ضروری ہیں" : "All fields are required");
      return;
    }

    if (password !== confirmPassword) {
      toast.error(language === "ur" ? "پاس ورڈ مماثل نہیں" : "Passwords do not match");
      return;
    }

    if (password.length < 6) {
      toast.error(language === "ur" ? "پاس ورڈ کم از کم 6 حروف ہونا چاہیے" : "Password must be at least 6 characters");
      return;
    }

    if (!inviteData) return;
    setProcessing(true);

    const token = searchParams.get('invite');
    if (!token) {
      toast.error(language === "ur" ? "غلط دعوت نامہ" : "Invalid invite");
      setProcessing(false);
      return;
    }

    // Use the AuthContext's signUpWithInvite which correctly joins existing madrasah
    const { error } = await signUpWithInvite(email.trim(), password, token, fullName.trim());

    if (error) {
      toast.error(language === "ur" ? "خرابی" : "Error", {
        description: error.message,
      });
      setProcessing(false);
    } else {
      toast.success(
        language === "ur" 
          ? `${inviteData.madrasah?.name} میں کامیابی سے شامل ہو گئے` 
          : `Successfully joined ${inviteData.madrasah?.name}`
      );
      // Auto logged in, redirect to dashboard
      window.location.href = "/";
    }
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

  // Show join form for new users
  if (!user) {
    return (
      <div className="min-h-screen flex items-center justify-center bg-background p-4">
        <Card className="w-full max-w-md">
          <CardHeader className="text-center">
            <CardTitle className="text-2xl">
              {language === "ur" ? "مدرسے میں شامل ہوں" : "Join Madrasa"}
            </CardTitle>
            <CardDescription>
              {language === "ur" 
                ? `${inviteData.madrasah?.name} میں ${inviteData.role} کے طور پر شامل ہوں` 
                : `Join ${inviteData.madrasah?.name} as ${inviteData.role}`}
            </CardDescription>
          </CardHeader>
          <CardContent className="space-y-6">
            <Alert>
              <AlertDescription className="text-sm">
                {language === "ur" 
                  ? "اپنا نام، ای میل اور پاس ورڈ درج کر کے اس مدرسے میں شامل ہوں۔" 
                  : "Enter your name, email and password to join this madrasa."}
              </AlertDescription>
            </Alert>

            <div className="space-y-4">
              <div className="space-y-2">
                <Label htmlFor="fullName">
                  {language === "ur" ? "مکمل نام *" : "Full Name *"}
                </Label>
                <Input
                  id="fullName"
                  value={fullName}
                  onChange={(e) => setFullName(e.target.value)}
                  placeholder={language === "ur" ? "اپنا نام درج کریں" : "Enter your name"}
                  required
                />
              </div>

              <div className="space-y-2">
                <Label htmlFor="email">
                  {language === "ur" ? "ای میل *" : "Email *"}
                </Label>
                <Input
                  id="email"
                  type="email"
                  value={email}
                  onChange={(e) => setEmail(e.target.value)}
                  placeholder={language === "ur" ? "ای میل درج کریں" : "Enter your email"}
                  required
                />
              </div>

              <div className="space-y-2">
                <Label htmlFor="password">
                  {language === "ur" ? "پاس ورڈ *" : "Password *"}
                </Label>
                <Input
                  id="password"
                  type="password"
                  value={password}
                  onChange={(e) => setPassword(e.target.value)}
                  placeholder={language === "ur" ? "پاس ورڈ درج کریں (کم از کم 6 حروف)" : "Enter password (min 6 chars)"}
                  required
                />
              </div>

              <div className="space-y-2">
                <Label htmlFor="confirmPassword">
                  {language === "ur" ? "پاس ورڈ کی تصدیق *" : "Confirm Password *"}
                </Label>
                <Input
                  id="confirmPassword"
                  type="password"
                  value={confirmPassword}
                  onChange={(e) => setConfirmPassword(e.target.value)}
                  placeholder={language === "ur" ? "پاس ورڈ دوبارہ درج کریں" : "Re-enter password"}
                  required
                />
              </div>
            </div>

            <div className="bg-muted p-4 rounded-lg space-y-2">
              <div className="flex justify-between text-sm">
                <span className="text-muted-foreground">
                  {language === "ur" ? "مدرسہ:" : "Madrasa:"}
                </span>
                <span className="font-semibold">{inviteData.madrasah?.name}</span>
              </div>
              <div className="flex justify-between text-sm">
                <span className="text-muted-foreground">
                  {language === "ur" ? "رول:" : "Role:"}
                </span>
                <span className="font-semibold capitalize">{inviteData.role}</span>
              </div>
            </div>

            <div className="flex gap-3">
              <Button
                onClick={handleJoinWithSignup}
                disabled={processing}
                className="flex-1"
              >
                {processing ? (
                  <Loader2 className="h-4 w-4 animate-spin mx-auto" />
                ) : (
                  <>
                    <CheckCircle className="h-4 w-4 me-2" />
                    {language === "ur" ? "شامل ہوں" : "Join Now"}
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
  }

  // Show accept/decline for already logged-in users
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
