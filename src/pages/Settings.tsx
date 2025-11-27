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
import { Upload, Save, LogOut, Download, FileUp, RefreshCw, X, Trash2 } from "lucide-react";
import { AlertDialog, AlertDialogAction, AlertDialogCancel, AlertDialogContent, AlertDialogDescription, AlertDialogFooter, AlertDialogHeader, AlertDialogTitle, AlertDialogTrigger } from "@/components/ui/alert-dialog";
import { useUserRole } from "@/hooks/useUserRole";
import { useNavigate } from "react-router-dom";

interface MadrasahSettings {
  id: string;
  name: string;
  madrasah_id: string;
  logo_url: string | null;
  address: string | null;
  contact: string | null;
  email: string | null;
  app_url: string | null;
}

const Settings = () => {
  const { t, language } = useLanguage();
  const { user, signOut, madrasahId, userRole } = useAuth();
  const { isAdmin } = useUserRole();
  const navigate = useNavigate();
  const [loading, setLoading] = useState(false);
  const [uploading, setUploading] = useState(false);
  const [backupLoading, setBackupLoading] = useState(false);
  const [restoreLoading, setRestoreLoading] = useState(false);
  const [deleteLoading, setDeleteLoading] = useState(false);
  const [settings, setSettings] = useState<MadrasahSettings | null>(null);
  const [backups, setBackups] = useState<any[]>([]);
  const [backupsLoading, setBackupsLoading] = useState(false);

  // Debug: Log userRole
  useEffect(() => {
    console.log('Current userRole:', userRole);
  }, [userRole]);

  useEffect(() => {
    if (madrasahId) {
      fetchSettings();
      fetchBackups();
    }
  }, [madrasahId]);

  const fetchBackups = async () => {
    if (!madrasahId) return;

    setBackupsLoading(true);
    const { data, error } = await supabase
      .from("backups")
      .select("id, backup_date, backup_type, notes")
      .eq("madrasah_id", madrasahId)
      .order("backup_date", { ascending: false })
      .limit(10);

    if (!error && data) {
      setBackups(data);
    }
    setBackupsLoading(false);
  };

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
        app_url: settings.app_url,
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
      const { data, error } = await supabase.functions.invoke('backup-data', {
        body: {
          madrasahId,
          backupType: 'manual',
          notes: 'Manual backup created from settings',
        },
      });

      if (error) throw error;

      toast.success(
        language === "ur" 
          ? "بیک اپ کامیابی سے محفوظ ہو گیا" 
          : "Backup saved successfully"
      );
      
      fetchBackups();
    } catch (error: any) {
      toast.error(language === "ur" ? "بیک اپ میں خرابی" : "Backup failed");
      console.error(error);
    } finally {
      setBackupLoading(false);
    }
  };

  const handleRestore = async (backupId: string) => {
    if (!madrasahId) return;

    const confirmed = window.confirm(
      language === "ur"
        ? "کیا آپ واقعی یہ بیک اپ بحال کرنا چاہتے ہیں؟ موجودہ ڈیٹا کا بیک اپ خودکار طور پر لے لیا جائے گا۔"
        : "Are you sure you want to restore this backup? Your current data will be backed up automatically before restoration."
    );

    if (!confirmed) return;

    setRestoreLoading(true);
    try {
      const { data, error } = await supabase.functions.invoke('restore-data', {
        body: {
          madrasahId,
          backupId,
        },
      });

      if (error) throw error;

      toast.success(
        language === "ur" 
          ? `ڈیٹا کامیابی سے بحال ہو گیا۔ ${data.recordsRestored} ریکارڈز بحال ہوئے۔` 
          : `Data restored successfully. ${data.recordsRestored} records restored.`
      );
      
      fetchBackups();
      
      // Reload page to reflect new data
      setTimeout(() => window.location.reload(), 1500);
    } catch (error: any) {
      toast.error(language === "ur" ? "ری سٹور میں خرابی" : "Restore failed");
      console.error(error);
    } finally {
      setRestoreLoading(false);
    }
  };

  const handleDeleteBackup = async (backupId: string) => {
    const confirmed = window.confirm(
      language === "ur"
        ? "کیا آپ واقعی یہ بیک اپ حذف کرنا چاہتے ہیں؟"
        : "Are you sure you want to delete this backup?"
    );

    if (!confirmed) return;

    const { error } = await supabase
      .from("backups")
      .delete()
      .eq("id", backupId);

    if (error) {
      toast.error(language === "ur" ? "حذف کرنے میں خرابی" : "Delete failed");
    } else {
      toast.success(language === "ur" ? "بیک اپ حذف ہو گیا" : "Backup deleted");
      fetchBackups();
    }
  };

  const handleDeleteAccount = async () => {
    if (!madrasahId) {
      toast.error(language === "ur" ? "مدرسہ ID نہیں ملا" : "Madrasah ID not found");
      return;
    }

    setDeleteLoading(true);

    try {
      // Call the database function to delete all data
      const { error } = await supabase.rpc('delete_madrasah_permanently', {
        _madrasah_id: madrasahId
      });

      if (error) {
        console.error('Delete error:', error);
        toast.error(
          language === "ur" 
            ? "اکاؤنٹ ڈیلیٹ کرنے میں خرابی: " + error.message
            : "Failed to delete account: " + error.message
        );
        return;
      }

      toast.success(
        language === "ur" 
          ? "اکاؤنٹ مکمل طور پر ڈیلیٹ ہو گیا"
          : "Account deleted permanently"
      );

      // Sign out and redirect to auth page
      setTimeout(async () => {
        await signOut();
        navigate("/auth");
      }, 1500);

    } catch (error: any) {
      console.error('Delete account error:', error);
      toast.error(
        language === "ur" 
          ? "اکاؤنٹ ڈیلیٹ کرنے میں خرابی"
          : "Failed to delete account"
      );
    } finally {
      setDeleteLoading(false);
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

          <div className="space-y-2">
            <Label htmlFor="app_url">{language === "ur" ? "ایپ کا URL (انوائٹ لنکس کے لیے)" : "App URL (for invite links)"}</Label>
            <Input
              id="app_url"
              value={settings.app_url || ""}
              onChange={(e) => setSettings({ ...settings, app_url: e.target.value })}
              placeholder={language === "ur" ? "مثال: https://your-app.lovable.app" : "e.g., https://your-app.lovable.app"}
            />
            <p className="text-xs text-muted-foreground">
              {language === "ur" 
                ? "اپنی published ایپ کا URL یہاں درج کریں تاکہ انوائٹ لنکس میں صحیح URL استعمال ہو" 
                : "Enter your published app URL here so invite links use the correct URL"}
            </p>
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
              ? "خودکار روزانہ بیک اپ فعال ہے۔ اپنا ڈیٹا محفوظ کریں یا پرانا بحال کریں" 
              : "Auto daily backup enabled. Backup your data or restore from previous versions"}
          </CardDescription>
        </CardHeader>
        <CardContent className="space-y-6">
          {/* Auto Backup Info */}
          <div className="bg-primary/10 p-4 rounded-lg border border-primary/20">
            <div className="flex items-start gap-3">
              <div className="flex-shrink-0 mt-0.5">
                <svg className="w-5 h-5 text-primary" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                </svg>
              </div>
              <div className="flex-1">
                <p className="text-sm font-medium">
                  {language === "ur" ? "خودکار بیک اپ فعال" : "Automatic Backup Active"}
                </p>
                <p className="text-xs text-muted-foreground mt-1">
                  {language === "ur" 
                    ? "آپ کا ڈیٹا ہر روز خودکار طور پر محفوظ ہوتا ہے۔ ری سٹور کرنے سے پہلے موجودہ ڈیٹا کا بیک اپ خودکار لے لیا جائے گا۔" 
                    : "Your data is automatically backed up daily. Before restoring, your current data will be backed up automatically."}
                </p>
              </div>
            </div>
          </div>

          {/* Manual Backup Button */}
          <div className="space-y-2">
            <Label>{language === "ur" ? "دستی بیک اپ بنائیں" : "Create Manual Backup"}</Label>
            <Button 
              onClick={handleBackup} 
              disabled={backupLoading}
              className="w-full sm:w-auto"
            >
              <Save className="h-4 w-4 mr-2" />
              {backupLoading 
                ? (language === "ur" ? "محفوظ ہو رہا ہے..." : "Saving...") 
                : (language === "ur" ? "نیا بیک اپ بنائیں" : "Create Backup")}
            </Button>
          </div>

          <Separator />

          {/* Backup History */}
          <div className="space-y-3">
            <div className="flex items-center justify-between">
              <Label>{language === "ur" ? "بیک اپ کی سرگزشت" : "Backup History"}</Label>
              <Button 
                variant="ghost" 
                size="sm" 
                onClick={fetchBackups}
                disabled={backupsLoading}
              >
                {backupsLoading ? (
                  <div className="animate-spin rounded-full h-4 w-4 border-b-2 border-primary"></div>
                ) : (
                  <RefreshCw className="h-4 w-4" />
                )}
              </Button>
            </div>
            
            {backups.length === 0 ? (
              <p className="text-sm text-muted-foreground text-center py-4">
                {language === "ur" ? "کوئی بیک اپ موجود نہیں" : "No backups found"}
              </p>
            ) : (
              <div className="space-y-2 max-h-96 overflow-y-auto">
                {backups.map((backup) => (
                  <div 
                    key={backup.id} 
                    className="flex items-center justify-between p-3 border rounded-lg hover:bg-accent/50 transition-colors"
                  >
                    <div className="flex-1">
                      <p className="text-sm font-medium">
                        {new Date(backup.backup_date).toLocaleString(language === "ur" ? "ur-PK" : "en-US")}
                      </p>
                      <div className="flex items-center gap-2 mt-1">
                        <span className={`text-xs px-2 py-0.5 rounded-full ${
                          backup.backup_type === 'auto' 
                            ? 'bg-blue-100 text-blue-700' 
                            : backup.backup_type === 'pre_restore'
                            ? 'bg-yellow-100 text-yellow-700'
                            : 'bg-green-100 text-green-700'
                        }`}>
                          {backup.backup_type === 'auto' 
                            ? (language === "ur" ? "خودکار" : "Auto")
                            : backup.backup_type === 'pre_restore'
                            ? (language === "ur" ? "ری سٹور سے پہلے" : "Pre-restore")
                            : (language === "ur" ? "دستی" : "Manual")}
                        </span>
                        {backup.notes && (
                          <span className="text-xs text-muted-foreground truncate">
                            {backup.notes}
                          </span>
                        )}
                      </div>
                    </div>
                    <div className="flex gap-2">
                      <Button
                        size="sm"
                        variant="outline"
                        onClick={() => handleRestore(backup.id)}
                        disabled={restoreLoading}
                      >
                        <Upload className="h-3 w-3 mr-1" />
                        {language === "ur" ? "بحال کریں" : "Restore"}
                      </Button>
                      <Button
                        size="sm"
                        variant="ghost"
                        onClick={() => handleDeleteBackup(backup.id)}
                      >
                        <X className="h-3 w-3" />
                      </Button>
                    </div>
                  </div>
                ))}
              </div>
            )}
          </div>
        </CardContent>
      </Card>

      {/* Delete Account Section - Only for Admins */}
      {userRole === 'admin' && (
        <Card className="border-destructive">
          <CardHeader>
            <CardTitle className="text-destructive flex items-center gap-2">
              <Trash2 className="h-5 w-5" />
              {language === "ur" ? "اکاؤنٹ مستقل طور پر ڈیلیٹ کریں" : "Delete Account Permanently"}
            </CardTitle>
            <CardDescription>
              {language === "ur" 
                ? "انتباہ: یہ عمل مکمل اکاؤنٹ اور تمام ڈیٹا کو ہمیشہ کے لیے ڈیلیٹ کر دے گا۔ اس عمل کو واپس نہیں کیا جا سکتا۔"
                : "Warning: This will permanently delete the entire account and all data. This action cannot be undone."}
            </CardDescription>
          </CardHeader>
          <CardContent className="space-y-4">
            <div className="rounded-lg bg-destructive/10 border border-destructive/20 p-4">
              <div className="flex gap-3">
                <div className="flex-shrink-0">
                  <svg className="w-5 h-5 text-destructive" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                  </svg>
                </div>
                <div className="flex-1">
                  <p className="text-sm font-medium text-destructive">
                    {language === "ur" ? "مستقل ڈیلیشن" : "Permanent Deletion"}
                  </p>
                  <ul className="text-xs text-muted-foreground mt-2 space-y-1 list-disc list-inside">
                    <li>{language === "ur" ? "تمام طلباء کا ڈیٹا" : "All students data"}</li>
                    <li>{language === "ur" ? "تمام اساتذہ کا ڈیٹا" : "All teachers data"}</li>
                    <li>{language === "ur" ? "تمام کلاسز کا ڈیٹا" : "All classes data"}</li>
                    <li>{language === "ur" ? "تمام حاضری ریکارڈ" : "All attendance records"}</li>
                    <li>{language === "ur" ? "تمام فیسوں کا ریکارڈ" : "All fee records"}</li>
                    <li>{language === "ur" ? "تمام مالیاتی ریکارڈز" : "All financial records"}</li>
                    <li>{language === "ur" ? "تمام بیک اپس" : "All backups"}</li>
                    <li>{language === "ur" ? "تمام یوزرز اور ان کے رولز" : "All users and their roles"}</li>
                  </ul>
                </div>
              </div>
            </div>

            <AlertDialog>
              <AlertDialogTrigger asChild>
                <Button 
                  variant="destructive" 
                  className="w-full"
                  disabled={deleteLoading}
                >
                  <Trash2 className="h-4 w-4 mr-2" />
                  {language === "ur" ? "اکاؤنٹ مستقل طور پر ڈیلیٹ کریں" : "Delete Account Permanently"}
                </Button>
              </AlertDialogTrigger>
              <AlertDialogContent>
                <AlertDialogHeader>
                  <AlertDialogTitle className="text-destructive">
                    {language === "ur" ? "کیا آپ واقعی یہ اکاؤنٹ ڈیلیٹ کرنا چاہتے ہیں؟" : "Are you sure you want to delete this account?"}
                  </AlertDialogTitle>
                  <AlertDialogDescription className="space-y-2">
                    <p>
                      {language === "ur" 
                        ? "یہ عمل مکمل اکاؤنٹ اور تمام ڈیٹا کو ہمیشہ کے لیے ڈیلیٹ کر دے گا۔"
                        : "This will permanently delete the entire account and all data."}
                    </p>
                    <p className="font-semibold text-destructive">
                      {language === "ur" 
                        ? "اس عمل کو واپس نہیں کیا جا سکتا!"
                        : "This action cannot be undone!"}
                    </p>
                  </AlertDialogDescription>
                </AlertDialogHeader>
                <AlertDialogFooter>
                  <AlertDialogCancel disabled={deleteLoading}>
                    {language === "ur" ? "منسوخ کریں" : "Cancel"}
                  </AlertDialogCancel>
                  <AlertDialogAction
                    onClick={handleDeleteAccount}
                    className="bg-destructive hover:bg-destructive/90"
                    disabled={deleteLoading}
                  >
                    {deleteLoading 
                      ? (language === "ur" ? "ڈیلیٹ ہو رہا ہے..." : "Deleting...") 
                      : (language === "ur" ? "ہاں، ڈیلیٹ کریں" : "Yes, Delete")}
                  </AlertDialogAction>
                </AlertDialogFooter>
              </AlertDialogContent>
            </AlertDialog>
          </CardContent>
        </Card>
      )}
    </div>
  );
};

export default Settings;
