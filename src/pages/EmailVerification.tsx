import { useEffect, useState } from "react";
import { useNavigate } from "react-router-dom";
import { useAuth } from "@/contexts/AuthContext";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { Mail, RefreshCw } from "lucide-react";
import { supabase } from "@/integrations/supabase/client";
import { toast } from "sonner";
import { useLanguage } from "@/contexts/LanguageContext";

const EmailVerification = () => {
  const { user, loading } = useAuth();
  const { language } = useLanguage();
  const navigate = useNavigate();
  const [resending, setResending] = useState(false);

  useEffect(() => {
    if (!loading && !user) {
      navigate("/auth");
    }
    if (!loading && user?.email_confirmed_at) {
      navigate("/");
    }
  }, [user, loading, navigate]);

  const handleResendEmail = async () => {
    if (!user?.email) return;
    
    setResending(true);
    try {
      const { error } = await supabase.auth.resend({
        type: 'signup',
        email: user.email,
      });
      
      if (error) throw error;
      
      toast.success(
        language === "ur" 
          ? "تصدیقی ای میل دوبارہ بھیج دی گئی" 
          : "Verification email resent successfully"
      );
    } catch (error: any) {
      toast.error(
        language === "ur" ? "خرابی" : "Error",
        { description: error.message }
      );
    } finally {
      setResending(false);
    }
  };

  if (loading) {
    return (
      <div className="min-h-screen flex items-center justify-center">
        <div className="text-center">
          <div className="animate-spin rounded-full h-12 w-12 border-b-2 border-primary mx-auto"></div>
          <p className="mt-4 text-muted-foreground">
            {language === "ur" ? "لوڈ ہو رہا ہے..." : "Loading..."}
          </p>
        </div>
      </div>
    );
  }

  return (
    <div className="min-h-screen flex items-center justify-center p-4 bg-background">
      <Card className="w-full max-w-md">
        <CardHeader className="text-center">
          <div className="mx-auto mb-4 flex h-12 w-12 items-center justify-center rounded-full bg-primary/10">
            <Mail className="h-6 w-6 text-primary" />
          </div>
          <CardTitle>
            {language === "ur" ? "اپنی ای میل کی تصدیق کریں" : "Verify Your Email"}
          </CardTitle>
          <CardDescription>
            {language === "ur" 
              ? "ہم نے آپ کی ای میل پر ایک تصدیقی لنک بھیجا ہے" 
              : "We've sent a verification link to your email"}
          </CardDescription>
        </CardHeader>
        <CardContent className="space-y-4">
          <div className="text-center space-y-2">
            <p className="text-sm text-muted-foreground">
              {user?.email}
            </p>
            <p className="text-sm">
              {language === "ur" 
                ? "براہ کرم اپنا ای میل چیک کریں اور تصدیقی لنک پر کلک کریں" 
                : "Please check your email and click the verification link"}
            </p>
          </div>

          <Button
            variant="outline"
            className="w-full"
            onClick={handleResendEmail}
            disabled={resending}
          >
            {resending ? (
              <>
                <RefreshCw className="mr-2 h-4 w-4 animate-spin" />
                {language === "ur" ? "بھیجا جا رہا ہے..." : "Sending..."}
              </>
            ) : (
              <>
                <RefreshCw className="mr-2 h-4 w-4" />
                {language === "ur" ? "دوبارہ ای میل بھیجیں" : "Resend Email"}
              </>
            )}
          </Button>

          <Button
            variant="ghost"
            className="w-full"
            onClick={() => {
              supabase.auth.signOut();
              navigate("/auth");
            }}
          >
            {language === "ur" ? "مختلف اکاؤنٹ استعمال کریں" : "Use Different Account"}
          </Button>
        </CardContent>
      </Card>
    </div>
  );
};

export default EmailVerification;
