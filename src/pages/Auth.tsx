import { useState } from "react";
import { useNavigate } from "react-router-dom";
import { useAuth } from "@/contexts/AuthContext";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { Tabs, TabsContent, TabsList, TabsTrigger } from "@/components/ui/tabs";
import { toast } from "sonner";
import { useLanguage } from "@/contexts/LanguageContext";
import { supabase } from "@/integrations/supabase/client";

const Auth = () => {
  const { signIn, signUp } = useAuth();
  const navigate = useNavigate();
  const { language } = useLanguage();
  const [loading, setLoading] = useState(false);

  const [loginData, setLoginData] = useState({
    email: "",
    password: "",
  });

  const [signupData, setSignupData] = useState({
    email: "",
    password: "",
    confirmPassword: "",
    fullName: "",
    madrasahName: "",
    madrasahId: "",
  });

  const handleLogin = async (e: React.FormEvent) => {
    e.preventDefault();
    setLoading(true);

    const { error } = await signIn(loginData.email, loginData.password);

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

  const handleSignup = async (e: React.FormEvent) => {
    e.preventDefault();

    if (signupData.password !== signupData.confirmPassword) {
      toast.error(language === "ur" ? "پاس ورڈ مماثل نہیں" : "Passwords do not match");
      return;
    }

    if (signupData.password.length < 6) {
      toast.error(language === "ur" ? "پاس ورڈ کم از کم 6 حروف ہونا چاہیے" : "Password must be at least 6 characters");
      return;
    }

    setLoading(true);

    const { error } = await signUp(
      signupData.email,
      signupData.password,
      {
        name: signupData.madrasahName,
        madrasahId: signupData.madrasahId,
        fullName: signupData.fullName,
      }
    );

    if (error) {
      toast.error(language === "ur" ? "رجسٹریشن میں خرابی" : "Signup failed", {
        description: error.message,
      });
    } else {
      toast.success(language === "ur" ? "کامیابی سے رجسٹر ہو گئے" : "Signed up successfully");
      navigate("/");
    }

    setLoading(false);
  };

  const handleGoogleSignIn = async () => {
    setLoading(true);
    const { error } = await supabase.auth.signInWithOAuth({
      provider: 'google',
      options: {
        redirectTo: `${window.location.origin}/`,
        queryParams: {
          access_type: 'offline',
          prompt: 'consent',
        },
      },
    });

    if (error) {
      toast.error(language === "ur" ? "Google سائن ان میں خرابی" : "Google sign in failed", {
        description: error.message,
      });
      setLoading(false);
    }
  };

  return (
    <div className="min-h-screen flex items-center justify-center bg-gradient-to-br from-primary/10 via-background to-primary/5 p-4">
      <Card className="w-full max-w-md">
        <CardHeader className="text-center">
          <CardTitle className="text-3xl font-bold">
            {language === "ur" ? "مدرسہ مینجمنٹ" : "Madrasa Management"}
          </CardTitle>
          <CardDescription>
            {language === "ur" ? "اپنے اکاؤنٹ میں لاگ ان کریں یا نیا اکاؤنٹ بنائیں" : "Login to your account or create a new one"}
          </CardDescription>
        </CardHeader>
        <CardContent>
          <Tabs defaultValue="login" className="w-full">
            <TabsList className="grid w-full grid-cols-2">
              <TabsTrigger value="login">{language === "ur" ? "لاگ ان" : "Login"}</TabsTrigger>
              <TabsTrigger value="signup">{language === "ur" ? "رجسٹر" : "Signup"}</TabsTrigger>
            </TabsList>

            <TabsContent value="login">
              <form onSubmit={handleLogin} className="space-y-4">
                <div className="space-y-2">
                  <Label htmlFor="login-email">{language === "ur" ? "ای میل" : "Email"}</Label>
                  <Input
                    id="login-email"
                    type="email"
                    placeholder="example@email.com"
                    value={loginData.email}
                    onChange={(e) => setLoginData({ ...loginData, email: e.target.value })}
                    required
                  />
                </div>
                <div className="space-y-2">
                  <Label htmlFor="login-password">{language === "ur" ? "پاس ورڈ" : "Password"}</Label>
                  <Input
                    id="login-password"
                    type="password"
                    value={loginData.password}
                    onChange={(e) => setLoginData({ ...loginData, password: e.target.value })}
                    required
                  />
                </div>
                <Button type="submit" className="w-full" disabled={loading}>
                  {loading ? (language === "ur" ? "براہ کرم انتظار کریں..." : "Please wait...") : (language === "ur" ? "لاگ ان" : "Login")}
                </Button>
                
                <div className="relative">
                  <div className="absolute inset-0 flex items-center">
                    <span className="w-full border-t" />
                  </div>
                  <div className="relative flex justify-center text-xs uppercase">
                    <span className="bg-background px-2 text-muted-foreground">
                      {language === "ur" ? "یا" : "Or"}
                    </span>
                  </div>
                </div>

                <Button
                  type="button"
                  variant="outline"
                  className="w-full"
                  onClick={handleGoogleSignIn}
                  disabled={loading}
                >
                  <svg className="mr-2 h-4 w-4" viewBox="0 0 24 24">
                    <path
                      d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z"
                      fill="#4285F4"
                    />
                    <path
                      d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z"
                      fill="#34A853"
                    />
                    <path
                      d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l2.85-2.22.81-.62z"
                      fill="#FBBC05"
                    />
                    <path
                      d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z"
                      fill="#EA4335"
                    />
                  </svg>
                  {language === "ur" ? "Google سے سائن ان کریں" : "Sign in with Google"}
                </Button>
              </form>
            </TabsContent>

            <TabsContent value="signup">
              <form onSubmit={handleSignup} className="space-y-4">
                <div className="space-y-2">
                  <Label htmlFor="fullName">{language === "ur" ? "مکمل نام" : "Full Name"}</Label>
                  <Input
                    id="fullName"
                    type="text"
                    value={signupData.fullName}
                    onChange={(e) => setSignupData({ ...signupData, fullName: e.target.value })}
                    required
                  />
                </div>
                <div className="space-y-2">
                  <Label htmlFor="madrasahName">{language === "ur" ? "مدرسہ کا نام" : "Madrasa Name"}</Label>
                  <Input
                    id="madrasahName"
                    type="text"
                    value={signupData.madrasahName}
                    onChange={(e) => setSignupData({ ...signupData, madrasahName: e.target.value })}
                    required
                  />
                </div>
                <div className="space-y-2">
                  <Label htmlFor="madrasahId">{language === "ur" ? "مدرسہ آئی ڈی" : "Madrasa ID"}</Label>
                  <Input
                    id="madrasahId"
                    type="text"
                    placeholder="unique-id"
                    value={signupData.madrasahId}
                    onChange={(e) => setSignupData({ ...signupData, madrasahId: e.target.value })}
                    required
                  />
                </div>
                <div className="space-y-2">
                  <Label htmlFor="signup-email">{language === "ur" ? "ای میل" : "Email"}</Label>
                  <Input
                    id="signup-email"
                    type="email"
                    placeholder="example@email.com"
                    value={signupData.email}
                    onChange={(e) => setSignupData({ ...signupData, email: e.target.value })}
                    required
                  />
                </div>
                <div className="space-y-2">
                  <Label htmlFor="signup-password">{language === "ur" ? "پاس ورڈ" : "Password"}</Label>
                  <Input
                    id="signup-password"
                    type="password"
                    value={signupData.password}
                    onChange={(e) => setSignupData({ ...signupData, password: e.target.value })}
                    required
                  />
                </div>
                <div className="space-y-2">
                  <Label htmlFor="confirm-password">{language === "ur" ? "پاس ورڈ کی تصدیق" : "Confirm Password"}</Label>
                  <Input
                    id="confirm-password"
                    type="password"
                    value={signupData.confirmPassword}
                    onChange={(e) => setSignupData({ ...signupData, confirmPassword: e.target.value })}
                    required
                  />
                </div>
                <Button type="submit" className="w-full" disabled={loading}>
                  {loading ? (language === "ur" ? "براہ کرم انتظار کریں..." : "Please wait...") : (language === "ur" ? "رجسٹر کریں" : "Sign Up")}
                </Button>
              </form>
            </TabsContent>
          </Tabs>
        </CardContent>
      </Card>
    </div>
  );
};

export default Auth;
