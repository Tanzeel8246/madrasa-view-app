import { useState, useEffect } from "react";
import { useNavigate, useSearchParams } from "react-router-dom";
import { useAuth } from "@/contexts/AuthContext";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { toast } from "sonner";
import { useLanguage } from "@/contexts/LanguageContext";
import { supabase } from "@/integrations/supabase/client";

const Auth = () => {
  const { signIn, signUp, signUpWithInvite, user } = useAuth();
  const navigate = useNavigate();
  const { language } = useLanguage();
  const [searchParams] = useSearchParams();
  const [loading, setLoading] = useState(false);
  const [isLogin, setIsLogin] = useState(true);
  
  // Invite-related state
  const inviteToken = searchParams.get('invite');
  const [inviteData, setInviteData] = useState<any>(null);
  const [loadingInvite, setLoadingInvite] = useState(false);

  // Form state
  const [email, setEmail] = useState("");
  const [password, setPassword] = useState("");
  const [confirmPassword, setConfirmPassword] = useState("");
  const [fullName, setFullName] = useState("");
  const [madrasahName, setMadrasahName] = useState("");
  const [madrasahId, setMadrasahId] = useState("");

  // Redirect authenticated users
  useEffect(() => {
    if (user) {
      if (inviteToken) {
        navigate(`/accept-invite?invite=${inviteToken}`);
      } else {
        navigate("/");
      }
    }
  }, [user, navigate, inviteToken]);

  // Validate invite token
  useEffect(() => {
    if (inviteToken) {
      validateInvite(inviteToken);
    }
  }, [inviteToken]);

  const validateInvite = async (token: string) => {
    setLoadingInvite(true);
    const { data, error } = await supabase
      .from('invites' as any)
      .select('*, madrasah:madrasah_id(*)')
      .eq('token', token)
      .eq('is_active', true)
      .maybeSingle();

    if (error || !data) {
      toast.error(language === "ur" ? "غلط یا ختم شدہ دعوت نامہ" : "Invalid or expired invite");
      navigate("/auth");
    } else {
      setInviteData(data);
    }
    setLoadingInvite(false);
  };

  const handleLogin = async (e: React.FormEvent) => {
    e.preventDefault();
    setLoading(true);

    const { error } = await signIn(email, password);

    if (error) {
      toast.error(language === "ur" ? "لاگ ان میں خرابی" : "Login failed", {
        description: error.message,
      });
    } else {
      toast.success(language === "ur" ? "کامیابی سے لاگ ان ہو گئے" : "Logged in successfully");
      navigate("/");
    }

    setLoading(false);
  };

  const handleSignUp = async (e: React.FormEvent) => {
    e.preventDefault();

    if (password !== confirmPassword) {
      toast.error(language === "ur" ? "پاس ورڈ مماثل نہیں" : "Passwords do not match");
      return;
    }

    if (password.length < 6) {
      toast.error(language === "ur" ? "پاس ورڈ کم از کم 6 حروف ہونا چاہیے" : "Password must be at least 6 characters");
      return;
    }

    setLoading(true);

    if (inviteToken && inviteData) {
      // Invite signup - simplified registration
      const { error } = await signUpWithInvite(email, password, inviteToken, fullName);
      if (error) {
        toast.error(language === "ur" ? "خرابی" : "Error", {
          description: error.message,
        });
      } else {
        toast.success(
          language === "ur"
            ? "اکاؤنٹ کامیابی سے بنایا گیا! براہ کرم اپنی ای میل چیک کریں"
            : "Account created successfully! Please check your email to verify"
        );
        navigate("/verify-email");
      }
    } else {
      // Admin signup - creates new madrasah
      if (!madrasahName || !madrasahId) {
        toast.error(language === "ur" ? "تمام فیلڈز بھریں" : "Please fill all fields");
        setLoading(false);
        return;
      }

      const { error } = await signUp(email, password, {
        name: madrasahName,
        madrasahId: madrasahId,
        fullName: fullName,
      });

      if (error) {
        toast.error(language === "ur" ? "خرابی" : "Error", {
          description: error.message,
        });
      } else {
        toast.success(
          language === "ur"
            ? "اکاؤنٹ کامیابی سے بنایا گیا! براہ کرم اپنی ای میل چیک کریں"
            : "Account created successfully! Please check your email to verify"
        );
        navigate("/verify-email");
      }
    }

    setLoading(false);
  };

  const getRoleLabel = (role: string) => {
    const labels: Record<string, { ur: string; en: string }> = {
      admin: { ur: "ایڈمن", en: "Admin" },
      teacher: { ur: "استاد", en: "Teacher" },
      manager: { ur: "منیجر", en: "Manager" },
      parent: { ur: "والدین", en: "Parent" },
      user: { ur: "یوزر", en: "User" },
    };
    return language === "ur" ? labels[role]?.ur || role : labels[role]?.en || role;
  };

  if (loadingInvite) {
    return (
      <div className="min-h-screen flex items-center justify-center bg-background">
        <div className="text-center">
          <div className="animate-spin rounded-full h-12 w-12 border-b-2 border-primary mx-auto mb-4"></div>
          <p className="text-muted-foreground">
            {language === "ur" ? "لوڈ ہو رہا ہے..." : "Loading..."}
          </p>
        </div>
      </div>
    );
  }

  return (
    <div className="min-h-screen flex items-center justify-center bg-background p-4">
      <Card className="w-full max-w-md">
        <CardHeader className="space-y-1 text-center">
          <CardTitle className="text-2xl font-bold">
            {language === "ur" ? "مدرسہ مینجمنٹ" : "Madrasa Management"}
          </CardTitle>
          <CardDescription>
            {inviteToken && inviteData
              ? language === "ur"
                ? `${inviteData.madrasah?.name} میں ${getRoleLabel(inviteData.role)} کے طور پر شامل ہوں`
                : `Join ${inviteData.madrasah?.name} as ${getRoleLabel(inviteData.role)}`
              : isLogin
                ? language === "ur"
                  ? "اپنے اکاؤنٹ میں لاگ ان کریں"
                  : "Sign in to your account"
                : language === "ur"
                  ? "نیا مدرسہ بنائیں (صرف ایڈمن)"
                  : "Create New Madrasa (Admin Only)"}
          </CardDescription>
        </CardHeader>

        <CardContent>
          <form onSubmit={isLogin ? handleLogin : handleSignUp} className="space-y-4">
            {!isLogin && (
              <div className="space-y-2">
                <Label htmlFor="fullName">
                  {language === "ur" ? "مکمل نام" : "Full Name"}
                </Label>
                <Input
                  id="fullName"
                  value={fullName}
                  onChange={(e) => setFullName(e.target.value)}
                  required
                  placeholder={language === "ur" ? "اپنا نام درج کریں" : "Enter your name"}
                />
              </div>
            )}

            <div className="space-y-2">
              <Label htmlFor="email">
                {language === "ur" ? "ای میل" : "Email"}
              </Label>
              <Input
                id="email"
                type="email"
                value={email}
                onChange={(e) => setEmail(e.target.value)}
                required
                placeholder={language === "ur" ? "ای میل درج کریں" : "Enter email"}
              />
            </div>

            <div className="space-y-2">
              <Label htmlFor="password">
                {language === "ur" ? "پاس ورڈ" : "Password"}
              </Label>
              <Input
                id="password"
                type="password"
                value={password}
                onChange={(e) => setPassword(e.target.value)}
                required
                placeholder={language === "ur" ? "پاس ورڈ درج کریں" : "Enter password"}
              />
            </div>

            {!isLogin && (
              <>
                <div className="space-y-2">
                  <Label htmlFor="confirmPassword">
                    {language === "ur" ? "پاس ورڈ کی تصدیق" : "Confirm Password"}
                  </Label>
                  <Input
                    id="confirmPassword"
                    type="password"
                    value={confirmPassword}
                    onChange={(e) => setConfirmPassword(e.target.value)}
                    required
                    placeholder={language === "ur" ? "پاس ورڈ دوبارہ درج کریں" : "Re-enter password"}
                  />
                </div>

                {!inviteToken && (
                  <>
                    <div className="space-y-2">
                      <Label htmlFor="madrasahName">
                        {language === "ur" ? "مدرسہ کا نام" : "Madrasa Name"}
                      </Label>
                      <Input
                        id="madrasahName"
                        value={madrasahName}
                        onChange={(e) => setMadrasahName(e.target.value)}
                        required
                        placeholder={language === "ur" ? "مدرسہ کا نام درج کریں" : "Enter madrasa name"}
                      />
                    </div>
                    <div className="space-y-2">
                      <Label htmlFor="madrasahId">
                        {language === "ur" ? "مدرسہ آئی ڈی" : "Madrasa ID"}
                      </Label>
                      <Input
                        id="madrasahId"
                        value={madrasahId}
                        onChange={(e) => setMadrasahId(e.target.value)}
                        required
                        placeholder={language === "ur" ? "یونیک آئی ڈی درج کریں" : "Enter unique ID"}
                      />
                    </div>
                  </>
                )}
              </>
            )}

            <Button type="submit" className="w-full" disabled={loading}>
              {loading ? (
                language === "ur" ? "براہ کرم انتظار کریں..." : "Please wait..."
              ) : inviteToken && inviteData ? (
                language === "ur" ? `${getRoleLabel(inviteData.role)} کے طور پر رجسٹر کریں` : `Register as ${getRoleLabel(inviteData.role)}`
              ) : isLogin ? (
                language === "ur" ? "لاگ ان" : "Sign In"
              ) : (
                language === "ur" ? "نیا مدرسہ بنائیں (ایڈمن)" : "Create Madrasa (Admin)"
              )}
            </Button>
          </form>

          {!inviteToken && (
            <div className="text-center text-sm mt-4">
              <button
                type="button"
                onClick={() => setIsLogin(!isLogin)}
                className="text-primary hover:underline"
              >
                {isLogin
                  ? language === "ur"
                    ? "نیا مدرسہ بنانا چاہتے ہیں؟ یہاں کلک کریں"
                    : "Want to create a new madrasa? Click here"
                  : language === "ur"
                    ? "پہلے سے اکاؤنٹ ہے؟ لاگ ان کریں"
                    : "Already have an account? Sign in"}
              </button>
            </div>
          )}
        </CardContent>
      </Card>
    </div>
  );
};

export default Auth;
