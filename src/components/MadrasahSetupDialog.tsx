import { useState } from "react";
import { useAuth } from "@/contexts/AuthContext";
import { supabase } from "@/integrations/supabase/client";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog";
import { toast } from "sonner";
import { useLanguage } from "@/contexts/LanguageContext";

interface MadrasahSetupDialogProps {
  open: boolean;
  onComplete: () => void;
}

const MadrasahSetupDialog = ({ open, onComplete }: MadrasahSetupDialogProps) => {
  const { user } = useAuth();
  const { language } = useLanguage();
  const [loading, setLoading] = useState(false);
  const [formData, setFormData] = useState({
    fullName: user?.user_metadata?.full_name || "",
    madrasahName: "",
    madrasahId: "",
  });

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!user) return;

    setLoading(true);

    try {
      // Create madrasah
      const { data: madrasah, error: madrasahError } = await supabase
        .from("madrasah")
        .insert({
          name: formData.madrasahName,
          madrasah_id: formData.madrasahId,
        })
        .select()
        .maybeSingle();

      if (madrasahError || !madrasah) throw madrasahError || new Error("Failed to create madrasah");

      // Create profile
      const { error: profileError } = await supabase
        .from("profiles")
        .insert({
          user_id: user.id,
          madrasah_id: madrasah.id,
          full_name: formData.fullName,
          role: "admin",
        });

      if (profileError) throw profileError;

      toast.success(language === "ur" ? "مدرسہ کامیابی سے بنایا گیا" : "Madrasa created successfully");
      onComplete();
    } catch (error: any) {
      toast.error(language === "ur" ? "خرابی" : "Error", {
        description: error.message,
      });
    } finally {
      setLoading(false);
    }
  };

  return (
    <Dialog open={open} onOpenChange={() => {}}>
      <DialogContent className="sm:max-w-md" onInteractOutside={(e) => e.preventDefault()}>
        <DialogHeader>
          <DialogTitle>
            {language === "ur" ? "مدرسہ کی معلومات" : "Madrasa Information"}
          </DialogTitle>
          <DialogDescription>
            {language === "ur"
              ? "براہ کرم اپنے مدرسہ کی معلومات درج کریں"
              : "Please enter your madrasa information"}
          </DialogDescription>
        </DialogHeader>
        <form onSubmit={handleSubmit} className="space-y-4 mt-4">
          <div className="space-y-2">
            <Label htmlFor="fullName">{language === "ur" ? "مکمل نام" : "Full Name"}</Label>
            <Input
              id="fullName"
              type="text"
              value={formData.fullName}
              onChange={(e) => setFormData({ ...formData, fullName: e.target.value })}
              required
            />
          </div>
          <div className="space-y-2">
            <Label htmlFor="madrasahName">{language === "ur" ? "مدرسہ کا نام" : "Madrasa Name"}</Label>
            <Input
              id="madrasahName"
              type="text"
              value={formData.madrasahName}
              onChange={(e) => setFormData({ ...formData, madrasahName: e.target.value })}
              required
            />
          </div>
          <div className="space-y-2">
            <Label htmlFor="madrasahId">{language === "ur" ? "مدرسہ آئی ڈی" : "Madrasa ID"}</Label>
            <Input
              id="madrasahId"
              type="text"
              placeholder="unique-id"
              value={formData.madrasahId}
              onChange={(e) => setFormData({ ...formData, madrasahId: e.target.value })}
              required
            />
            <p className="text-xs text-muted-foreground">
              {language === "ur"
                ? "منفرد آئی ڈی جیسے: madrasah-123"
                : "A unique ID like: madrasah-123"}
            </p>
          </div>
          <Button type="submit" className="w-full" disabled={loading}>
            {loading
              ? (language === "ur" ? "محفوظ ہو رہا ہے..." : "Saving...")
              : (language === "ur" ? "محفوظ کریں" : "Save")}
          </Button>
        </form>
      </DialogContent>
    </Dialog>
  );
};

export default MadrasahSetupDialog;
