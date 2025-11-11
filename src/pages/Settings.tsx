import { useState, useEffect } from "react";
import { useLanguage } from "@/contexts/LanguageContext";
import { useAuth } from "@/contexts/AuthContext";
import { supabase } from "@/integrations/supabase/client";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { Separator } from "@/components/ui/separator";
import { Avatar, AvatarFallback, AvatarImage } from "@/components/ui/avatar";
import { toast } from "sonner";
import { Upload, Save, LogOut, Download, FileUp } from "lucide-react";

interface MadrasahSettings {
  id: string;
  name: string;
  madrasah_id: string;
  logo_url: string | null;
  address: string | null;
  contact: string | null;
  email: string | null;
}

const Settings = () => {
  const { t, language } = useLanguage();
  const { user, signOut, madrasahId } = useAuth();
  const [loading, setLoading] = useState(false);
  const [uploading, setUploading] = useState(false);
  const [backupLoading, setBackupLoading] = useState(false);
  const [restoreLoading, setRestoreLoading] = useState(false);
  const [settings, setSettings] = useState<MadrasahSettings | null>(null);

  useEffect(() => {
    if (madrasahId) {
      fetchSettings();
    }
  }, [madrasahId]);

  const fetchSettings = async () => {
    if (!madrasahId) return;

    const { data, error } = await supabase
      .from("madrasah")
      .select("*")
      .eq("id", madrasahId)
      .maybeSingle();

    if (error) {
      toast.error(language === "ur" ? "ڈیٹا لوڈ میں خرابی" : "Error loading data");
      return;
    }

    if (data) {
      setSettings(data);
    }
  };

  const handleLogoUpload = async (event: React.ChangeEvent<HTMLInputElement>) => {
    const file = event.target.files?.[0];
    if (!file || !madrasahId) return;

    const fileExt = file.name.split(".").pop();
    const fileName = `${madrasahId}.${fileExt}`;
    const filePath = `${fileName}`;

    setUploading(true);

    try {
      // Upload file
      const { error: uploadError } = await supabase.storage
        .from("madrasah-logos")
        .upload(filePath, file, { upsert: true });

      if (uploadError) throw uploadError;

      // Get public URL
      const { data: { publicUrl } } = supabase.storage
        .from("madrasah-logos")
        .getPublicUrl(filePath);

      // Update madrasah record
      const { error: updateError } = await supabase
        .from("madrasah")
        .update({ logo_url: publicUrl })
        .eq("id", madrasahId);

      if (updateError) throw updateError;

      toast.success(language === "ur" ? "لوگو اپ لوڈ ہو گیا" : "Logo uploaded successfully");
      fetchSettings();
    } catch (error: any) {
      toast.error(language === "ur" ? "اپ لوڈ میں خرابی" : "Upload failed");
      console.error(error);
    } finally {
      setUploading(false);
    }
  };

  const handleSave = async () => {
    if (!settings || !madrasahId) return;

    setLoading(true);

    const { error } = await supabase
      .from("madrasah")
      .update({
        name: settings.name,
        address: settings.address,
        contact: settings.contact,
        email: settings.email,
      })
      .eq("id", madrasahId);

    if (error) {
      toast.error(language === "ur" ? "محفوظ کرنے میں خرابی" : "Error saving");
    } else {
      toast.success(language === "ur" ? "تبدیلیاں محفوظ ہو گئیں" : "Changes saved successfully");
    }

    setLoading(false);
  };

  const handleBackup = async () => {
    if (!madrasahId) return;

    setBackupLoading(true);
    try {
      // Fetch all data from all tables
      const [
        studentsData,
        teachersData,
        classesData,
        attendanceData,
        feesData,
        incomeData,
        expenseData,
        salariesData,
        loansData,
      ] = await Promise.all([
        supabase.from("students").select("*").eq("madrasah_id", madrasahId),
        supabase.from("teachers").select("*").eq("madrasah_id", madrasahId),
        supabase.from("classes").select("*").eq("madrasah_id", madrasahId),
        supabase.from("attendance").select("*").eq("madrasah_id", madrasahId),
        supabase.from("fees").select("*").eq("madrasah_id", madrasahId),
        supabase.from("income").select("*").eq("madrasah_id", madrasahId),
        supabase.from("expense").select("*").eq("madrasah_id", madrasahId),
        supabase.from("salaries").select("*").eq("madrasah_id", madrasahId),
        supabase.from("loans").select("*").eq("madrasah_id", madrasahId),
      ]);

      const backupData = {
        version: "1.0",
        exportDate: new Date().toISOString(),
        madrasah: settings,
        students: studentsData.data || [],
        teachers: teachersData.data || [],
        classes: classesData.data || [],
        attendance: attendanceData.data || [],
        fees: feesData.data || [],
        income: incomeData.data || [],
        expense: expenseData.data || [],
        salaries: salariesData.data || [],
        loans: loansData.data || [],
      };

      // Create and download JSON file
      const blob = new Blob([JSON.stringify(backupData, null, 2)], {
        type: "application/json",
      });
      const url = URL.createObjectURL(blob);
      const link = document.createElement("a");
      link.href = url;
      link.download = `madrasah_backup_${settings.madrasah_id}_${new Date().toISOString().split('T')[0]}.json`;
      document.body.appendChild(link);
      link.click();
      document.body.removeChild(link);
      URL.revokeObjectURL(url);

      toast.success(language === "ur" ? "بیک اپ کامیابی سے ڈاؤن لوڈ ہو گیا" : "Backup downloaded successfully");
    } catch (error: any) {
      toast.error(language === "ur" ? "بیک اپ میں خرابی" : "Backup failed");
      console.error(error);
    } finally {
      setBackupLoading(false);
    }
  };

  const handleRestore = async (event: React.ChangeEvent<HTMLInputElement>) => {
    const file = event.target.files?.[0];
    if (!file || !madrasahId) return;

    setRestoreLoading(true);
    try {
      const fileContent = await file.text();
      const backupData = JSON.parse(fileContent);

      // Validate backup data
      if (!backupData.version || !backupData.madrasah) {
        throw new Error("Invalid backup file");
      }

      // Confirm with user
      const confirmed = window.confirm(
        language === "ur"
          ? "کیا آپ واقعی موجودہ ڈیٹا کو نئے ڈیٹا سے تبدیل کرنا چاہتے ہیں؟ یہ عمل واپس نہیں ہو سکتا۔"
          : "Are you sure you want to restore this backup? This will replace your current data and cannot be undone."
      );

      if (!confirmed) {
        setRestoreLoading(false);
        return;
      }

      // Delete existing data
      await Promise.all([
        supabase.from("attendance").delete().eq("madrasah_id", madrasahId),
        supabase.from("fees").delete().eq("madrasah_id", madrasahId),
        supabase.from("salaries").delete().eq("madrasah_id", madrasahId),
        supabase.from("loans").delete().eq("madrasah_id", madrasahId),
        supabase.from("income").delete().eq("madrasah_id", madrasahId),
        supabase.from("expense").delete().eq("madrasah_id", madrasahId),
        supabase.from("students").delete().eq("madrasah_id", madrasahId),
        supabase.from("classes").delete().eq("madrasah_id", madrasahId),
        supabase.from("teachers").delete().eq("madrasah_id", madrasahId),
      ]);

      // Insert restored data with current madrasah_id
      const insertPromises = [];

      if (backupData.teachers?.length) {
        const teachers = backupData.teachers.map((t: any) => ({
          ...t,
          madrasah_id: madrasahId,
        }));
        insertPromises.push(supabase.from("teachers").insert(teachers));
      }

      if (backupData.classes?.length) {
        const classes = backupData.classes.map((c: any) => ({
          ...c,
          madrasah_id: madrasahId,
        }));
        insertPromises.push(supabase.from("classes").insert(classes));
      }

      if (backupData.students?.length) {
        const students = backupData.students.map((s: any) => ({
          ...s,
          madrasah_id: madrasahId,
        }));
        insertPromises.push(supabase.from("students").insert(students));
      }

      if (backupData.attendance?.length) {
        const attendance = backupData.attendance.map((a: any) => ({
          ...a,
          madrasah_id: madrasahId,
        }));
        insertPromises.push(supabase.from("attendance").insert(attendance));
      }

      if (backupData.fees?.length) {
        const fees = backupData.fees.map((f: any) => ({
          ...f,
          madrasah_id: madrasahId,
        }));
        insertPromises.push(supabase.from("fees").insert(fees));
      }

      if (backupData.income?.length) {
        const income = backupData.income.map((i: any) => ({
          ...i,
          madrasah_id: madrasahId,
        }));
        insertPromises.push(supabase.from("income").insert(income));
      }

      if (backupData.expense?.length) {
        const expense = backupData.expense.map((e: any) => ({
          ...e,
          madrasah_id: madrasahId,
        }));
        insertPromises.push(supabase.from("expense").insert(expense));
      }

      if (backupData.salaries?.length) {
        const salaries = backupData.salaries.map((s: any) => ({
          ...s,
          madrasah_id: madrasahId,
        }));
        insertPromises.push(supabase.from("salaries").insert(salaries));
      }

      if (backupData.loans?.length) {
        const loans = backupData.loans.map((l: any) => ({
          ...l,
          madrasah_id: madrasahId,
        }));
        insertPromises.push(supabase.from("loans").insert(loans));
      }

      await Promise.all(insertPromises);

      toast.success(language === "ur" ? "ڈیٹا کامیابی سے بحال ہو گیا" : "Data restored successfully");
      
      // Reset file input
      event.target.value = "";
    } catch (error: any) {
      toast.error(language === "ur" ? "ری سٹور میں خرابی" : "Restore failed");
      console.error(error);
    } finally {
      setRestoreLoading(false);
    }
  };

  if (!settings) {
    return (
      <div className="flex items-center justify-center min-h-[400px]">
        <div className="text-center">
          <div className="animate-spin rounded-full h-12 w-12 border-b-2 border-primary mx-auto"></div>
          <p className="mt-4 text-muted-foreground">{language === "ur" ? "لوڈ ہو رہا ہے..." : "Loading..."}</p>
        </div>
      </div>
    );
  }

  return (
    <div className="space-y-4 md:space-y-6 max-w-4xl">
      <div className="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-3">
        <div>
          <h1 className="text-2xl md:text-3xl font-bold text-foreground">
            {t("settings")}
          </h1>
          <p className="text-xs md:text-sm text-muted-foreground mt-1">
            {language === "ur" ? "مدرسہ کی ترتیبات کا نظم کریں" : "Manage your madrasa settings"}
          </p>
        </div>
        <Button variant="destructive" onClick={signOut} size="sm">
          <LogOut className="h-3 w-3 md:h-4 md:w-4 mr-1 md:mr-2" />
          <span className="text-xs md:text-sm">{language === "ur" ? "لاگ آؤٹ" : "Logout"}</span>
        </Button>
      </div>

      {/* User Info Card */}
      <Card>
        <CardHeader>
          <CardTitle>{language === "ur" ? "صارف کی معلومات" : "User Information"}</CardTitle>
          <CardDescription>
            {language === "ur" ? "آپ کی اکاؤنٹ کی تفصیلات" : "Your account details"}
          </CardDescription>
        </CardHeader>
        <CardContent className="space-y-3 md:space-y-4">
          <div className="space-y-2">
            <Label>{language === "ur" ? "ای میل" : "Email"}</Label>
            <Input value={user?.email || ""} disabled />
          </div>
        </CardContent>
      </Card>

      {/* Madrasah Settings Card */}
      <Card>
        <CardHeader>
          <CardTitle>{language === "ur" ? "مدرسہ کی ترتیبات" : "Madrasa Settings"}</CardTitle>
          <CardDescription>
            {language === "ur" ? "اپنے مدرسہ کی معلومات تبدیل کریں" : "Update your madrasa information"}
          </CardDescription>
        </CardHeader>
        <CardContent className="space-y-4 md:space-y-6">
          {/* Logo Section */}
          <div className="flex flex-col sm:flex-row items-start sm:items-center gap-4">
            <Avatar className="h-20 w-20 md:h-24 md:w-24">
              <AvatarImage src={settings.logo_url || ""} />
              <AvatarFallback className="text-xl md:text-2xl">
                {settings.name.substring(0, 2).toUpperCase()}
              </AvatarFallback>
            </Avatar>
            <div className="space-y-2 flex-1">
              <Label htmlFor="logo-upload">{language === "ur" ? "مدرسہ لوگو" : "Madrasa Logo"}</Label>
              <div className="flex gap-2">
                <Input
                  id="logo-upload"
                  type="file"
                  accept="image/*"
                  onChange={handleLogoUpload}
                  disabled={uploading}
                  className="flex-1"
                />
                {uploading && (
                  <div className="flex items-center">
                    <div className="animate-spin rounded-full h-5 w-5 border-b-2 border-primary"></div>
                  </div>
                )}
              </div>
              <p className="text-xs text-muted-foreground">
                {language === "ur" ? "PNG، JPG یا WEBP (زیادہ سے زیادہ 2MB)" : "PNG, JPG or WEBP (Max 2MB)"}
              </p>
            </div>
          </div>

          <Separator />

          {/* Madrasah Details */}
          <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div className="space-y-2">
              <Label htmlFor="name">{language === "ur" ? "مدرسہ کا نام" : "Madrasa Name"} *</Label>
              <Input
                id="name"
                value={settings.name}
                onChange={(e) => setSettings({ ...settings, name: e.target.value })}
              />
            </div>

            <div className="space-y-2">
              <Label htmlFor="madrasah_id">{language === "ur" ? "مدرسہ آئی ڈی" : "Madrasa ID"}</Label>
              <Input
                id="madrasah_id"
                value={settings.madrasah_id}
                disabled
                className="bg-muted"
              />
            </div>
          </div>

          <div className="space-y-2">
            <Label htmlFor="address">{language === "ur" ? "پتہ" : "Address"}</Label>
            <Input
              id="address"
              value={settings.address || ""}
              onChange={(e) => setSettings({ ...settings, address: e.target.value })}
              placeholder={language === "ur" ? "مدرسہ کا پتہ درج کریں" : "Enter madrasa address"}
            />
          </div>

          <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div className="space-y-2">
              <Label htmlFor="contact">{language === "ur" ? "رابطہ نمبر" : "Contact Number"}</Label>
              <Input
                id="contact"
                value={settings.contact || ""}
                onChange={(e) => setSettings({ ...settings, contact: e.target.value })}
                placeholder={language === "ur" ? "رابطہ نمبر درج کریں" : "Enter contact number"}
              />
            </div>

            <div className="space-y-2">
              <Label htmlFor="email">{language === "ur" ? "ای میل" : "Email"}</Label>
              <Input
                id="email"
                type="email"
                value={settings.email || ""}
                onChange={(e) => setSettings({ ...settings, email: e.target.value })}
                placeholder={language === "ur" ? "ای میل ایڈریس درج کریں" : "Enter email address"}
              />
            </div>
          </div>

          <div className="flex justify-end pt-4">
            <Button onClick={handleSave} disabled={loading}>
              <Save className="h-4 w-4 mr-2" />
              {loading ? (language === "ur" ? "محفوظ ہو رہا ہے..." : "Saving...") : (language === "ur" ? "محفوظ کریں" : "Save Changes")}
            </Button>
          </div>
        </CardContent>
      </Card>

      {/* Backup & Restore Card */}
      <Card>
        <CardHeader>
          <CardTitle>{language === "ur" ? "ڈیٹا بیک اپ اور ری سٹور" : "Data Backup & Restore"}</CardTitle>
          <CardDescription>
            {language === "ur" 
              ? "اپنے مدرسہ کا ڈیٹا محفوظ کریں یا پرانا ڈیٹا بحال کریں" 
              : "Backup your madrasa data or restore from a previous backup"}
          </CardDescription>
        </CardHeader>
        <CardContent className="space-y-4">
          {/* Backup Section */}
          <div className="space-y-2">
            <Label>{language === "ur" ? "ڈیٹا بیک اپ" : "Backup Data"}</Label>
            <p className="text-xs md:text-sm text-muted-foreground mb-2">
              {language === "ur"
                ? "تمام طلباء، اساتذہ، کلاسز، حاضری، فیسیں اور دیگر ڈیٹا کو JSON فائل میں ڈاؤن لوڈ کریں"
                : "Download all students, teachers, classes, attendance, fees and other data as JSON file"}
            </p>
            <Button 
              onClick={handleBackup} 
              disabled={backupLoading}
              className="w-full sm:w-auto"
            >
              <Download className="h-4 w-4 mr-2" />
              {backupLoading 
                ? (language === "ur" ? "ڈاؤن لوڈ ہو رہا ہے..." : "Downloading...") 
                : (language === "ur" ? "بیک اپ ڈاؤن لوڈ کریں" : "Download Backup")}
            </Button>
          </div>

          <Separator />

          {/* Restore Section */}
          <div className="space-y-2">
            <Label htmlFor="restore-file">{language === "ur" ? "ڈیٹا ری سٹور" : "Restore Data"}</Label>
            <p className="text-xs md:text-sm text-muted-foreground mb-2">
              {language === "ur"
                ? "پرانا بیک اپ اپ لوڈ کریں۔ نوٹ: یہ موجودہ ڈیٹا کو تبدیل کر دے گا"
                : "Upload a previous backup. Note: This will replace your current data"}
            </p>
            <div className="flex gap-2">
              <Input
                id="restore-file"
                type="file"
                accept=".json"
                onChange={handleRestore}
                disabled={restoreLoading}
                className="flex-1"
              />
              {restoreLoading && (
                <div className="flex items-center">
                  <div className="animate-spin rounded-full h-5 w-5 border-b-2 border-primary"></div>
                </div>
              )}
            </div>
            <p className="text-xs text-destructive">
              {language === "ur"
                ? "⚠️ خبردار: ری سٹور کرنے سے تمام موجودہ ڈیٹا حذف ہو جائے گا"
                : "⚠️ Warning: Restoring will delete all current data"}
            </p>
          </div>
        </CardContent>
      </Card>
    </div>
  );
};

export default Settings;
