import { useState, useEffect } from "react";
import { useAuth } from "@/contexts/AuthContext";
import { useUserRole } from "@/hooks/useUserRole";
import { supabase } from "@/integrations/supabase/client";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle, DialogTrigger } from "@/components/ui/dialog";
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table";
import { Badge } from "@/components/ui/badge";
import { toast } from "sonner";
import { useLanguage } from "@/contexts/LanguageContext";
import { Copy, Plus, Trash2 } from "lucide-react";
import Layout from "@/components/Layout";

const InviteManagement = () => {
  const { madrasahId } = useAuth();
  const { isAdmin } = useUserRole();
  const { language } = useLanguage();
  const [invites, setInvites] = useState<any[]>([]);
  const [loading, setLoading] = useState(true);
  const [dialogOpen, setDialogOpen] = useState(false);
  const [newInvite, setNewInvite] = useState({
    role: "teacher" as "admin" | "teacher" | "manager" | "parent",
  });

  useEffect(() => {
    if (madrasahId) {
      console.log('Fetching invites for madrasah:', madrasahId);
      fetchInvites();
    } else {
      console.log('No madrasahId available yet');
    }
  }, [madrasahId]);

  const fetchInvites = async () => {
    if (!madrasahId) {
      console.error('Cannot fetch invites: madrasahId is missing');
      return;
    }
    
    setLoading(true);
    console.log('Querying invites with madrasah_id:', madrasahId);
    
    const { data, error } = await supabase
      .from("invites" as any)
      .select("*")
      .eq("madrasah_id", madrasahId)
      .order("created_at", { ascending: false });

    console.log('Invites query result:', { data, error });

    if (error) {
      console.error('Error fetching invites:', error);
      toast.error(language === "ur" ? "ڈیٹا لوڈ کرنے میں خرابی" : "Error loading data");
    } else {
      console.log('Fetched invites:', data);
      setInvites(data || []);
    }
    setLoading(false);
  };

  const generateToken = () => {
    return Math.random().toString(36).substring(2, 15) + Math.random().toString(36).substring(2, 15);
  };

  const handleCreateInvite = async () => {
    if (!madrasahId) {
      toast.error(language === "ur" ? "مدرسہ کی معلومات دستیاب نہیں" : "Madrasa information not available");
      return;
    }

    const token = generateToken();
    const user = (await supabase.auth.getUser()).data.user;
    
    if (!user) {
      toast.error(language === "ur" ? "آپ لاگ ان نہیں ہیں" : "You are not logged in");
      return;
    }

    console.log('Creating invite with:', { madrasah_id: madrasahId, role: newInvite.role, token, created_by: user.id });

    const { error } = await supabase
      .from("invites" as any)
      .insert({
        madrasah_id: madrasahId,
        role: newInvite.role,
        token,
        created_by: user.id,
      });

    console.log('Create invite result:', { error });

    if (error) {
      console.error('Error creating invite:', error);
      toast.error(language === "ur" ? "دعوت نامہ بنانے میں خرابی" : "Error creating invite", {
        description: error.message
      });
    } else {
      toast.success(language === "ur" ? "دعوت نامہ کامیابی سے بنایا گیا" : "Invite created successfully");
      setDialogOpen(false);
      fetchInvites();
      setNewInvite({ role: "teacher" });
    }
  };

  const handleCopyInviteLink = (token: string) => {
    const inviteUrl = `${window.location.origin}/auth?invite=${token}`;
    navigator.clipboard.writeText(inviteUrl);
    toast.success(language === "ur" ? "دعوت نامہ لنک کاپی ہو گیا - اسے شیئر کریں" : "Invite link copied - Share it with users");
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

  const handleDeleteInvite = async (id: string) => {
    const { error } = await supabase
      .from("invites" as any)
      .delete()
      .eq("id", id);

    if (error) {
      toast.error(language === "ur" ? "حذف کرنے میں خرابی" : "Error deleting invite");
    } else {
      toast.success(language === "ur" ? "دعوت نامہ حذف ہو گیا" : "Invite deleted");
      fetchInvites();
    }
  };

  const handleToggleActive = async (id: string, currentStatus: boolean) => {
    const { error } = await supabase
      .from("invites" as any)
      .update({ is_active: !currentStatus })
      .eq("id", id);

    if (error) {
      toast.error(language === "ur" ? "اپ ڈیٹ کرنے میں خرابی" : "Error updating invite");
    } else {
      toast.success(language === "ur" ? "اپ ڈیٹ ہو گیا" : "Updated successfully");
      fetchInvites();
    }
  };

  if (!madrasahId) {
    return (
      <Layout>
        <div className="flex items-center justify-center min-h-[60vh]">
          <Card className="w-full max-w-md">
            <CardContent className="pt-6 text-center space-y-4">
              <div className="animate-spin rounded-full h-12 w-12 border-b-2 border-primary mx-auto"></div>
              <p className="text-muted-foreground">
                {language === "ur" ? "لوڈ ہو رہا ہے..." : "Loading..."}
              </p>
            </CardContent>
          </Card>
        </div>
      </Layout>
    );
  }

  if (!isAdmin()) {
    return (
      <Layout>
        <div className="flex items-center justify-center min-h-[60vh]">
          <Card className="w-full max-w-md">
            <CardContent className="pt-6">
              <p className="text-center text-muted-foreground">
                {language === "ur" ? "آپ کو اس صفحے تک رسائی نہیں ہے" : "You don't have permission to access this page"}
              </p>
            </CardContent>
          </Card>
        </div>
      </Layout>
    );
  }

  return (
    <Layout>
      <div className="container mx-auto py-6 space-y-6">
        <div className="flex justify-between items-center">
          <div>
            <h1 className="text-3xl font-bold">
              {language === "ur" ? "دعوت نامے کا انتظام" : "Invite Management"}
            </h1>
            <p className="text-muted-foreground mt-1">
              {language === "ur" ? "نئے صارفین کو مدعو کریں" : "Invite new users to your madrasah"}
            </p>
          </div>
          
          <Dialog open={dialogOpen} onOpenChange={setDialogOpen}>
            <DialogTrigger asChild>
              <Button>
                <Plus className="w-4 h-4 mr-2" />
                {language === "ur" ? "نیا دعوت نامہ" : "New Invite"}
              </Button>
            </DialogTrigger>
            <DialogContent>
              <DialogHeader>
                <DialogTitle>
                  {language === "ur" ? "نیا دعوت نامہ بنائیں" : "Create New Invite"}
                </DialogTitle>
                <DialogDescription>
                  {language === "ur" ? "صارف کا کردار منتخب کریں" : "Select the role for the new user"}
                </DialogDescription>
              </DialogHeader>
              <div className="space-y-4 pt-4">
                <div className="space-y-2">
                  <Label>{language === "ur" ? "کردار" : "Role"}</Label>
                  <Select
                    value={newInvite.role}
                    onValueChange={(value: any) => setNewInvite({ ...newInvite, role: value })}
                  >
                    <SelectTrigger>
                      <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                      <SelectItem value="admin">{language === "ur" ? "ایڈمن" : "Admin"}</SelectItem>
                      <SelectItem value="teacher">{language === "ur" ? "استاد" : "Teacher"}</SelectItem>
                      <SelectItem value="manager">{language === "ur" ? "منیجر" : "Manager"}</SelectItem>
                      <SelectItem value="parent">{language === "ur" ? "والدین" : "Parent"}</SelectItem>
                      <SelectItem value="user">{language === "ur" ? "یوزر" : "User"}</SelectItem>
                    </SelectContent>
                  </Select>
                </div>
                <Button onClick={handleCreateInvite} className="w-full">
                  {language === "ur" ? "بنائیں" : "Create"}
                </Button>
              </div>
            </DialogContent>
          </Dialog>
        </div>

        <Card>
          <CardHeader>
            <CardTitle>{language === "ur" ? "موجودہ دعوت نامے" : "Active Invites"}</CardTitle>
          </CardHeader>
          <CardContent>
            {loading ? (
              <div className="text-center py-8">
                <p className="text-muted-foreground">
                  {language === "ur" ? "لوڈ ہو رہا ہے..." : "Loading..."}
                </p>
              </div>
            ) : invites.length === 0 ? (
              <div className="text-center py-8">
                <p className="text-muted-foreground">
                  {language === "ur" ? "کوئی دعوت نامہ نہیں ملا" : "No invites found"}
                </p>
              </div>
            ) : (
              <Table>
                <TableHeader>
                  <TableRow>
                    <TableHead>{language === "ur" ? "کردار" : "Role"}</TableHead>
                    <TableHead>{language === "ur" ? "استعمال شدہ" : "Used"}</TableHead>
                    <TableHead>{language === "ur" ? "حالت" : "Status"}</TableHead>
                    <TableHead>{language === "ur" ? "تاریخ" : "Created"}</TableHead>
                    <TableHead className="text-right">{language === "ur" ? "اقدامات" : "Actions"}</TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {invites.map((invite) => (
                    <TableRow key={invite.id}>
                      <TableCell className="font-medium">
                        {getRoleLabel(invite.role)}
                      </TableCell>
                      <TableCell>{invite.used_count}</TableCell>
                      <TableCell>
                        <Badge 
                          variant={invite.is_active ? "default" : "secondary"}
                          className="cursor-pointer"
                          onClick={() => handleToggleActive(invite.id, invite.is_active)}
                        >
                          {invite.is_active 
                            ? (language === "ur" ? "فعال" : "Active")
                            : (language === "ur" ? "غیر فعال" : "Inactive")}
                        </Badge>
                      </TableCell>
                      <TableCell>
                        {new Date(invite.created_at).toLocaleDateString(language === "ur" ? "ur-PK" : "en-US")}
                      </TableCell>
                      <TableCell className="text-right space-x-2">
                        <Button
                          variant="outline"
                          size="sm"
                          onClick={() => handleCopyInviteLink(invite.token)}
                        >
                          <Copy className="w-4 h-4" />
                        </Button>
                        <Button
                          variant="destructive"
                          size="sm"
                          onClick={() => handleDeleteInvite(invite.id)}
                        >
                          <Trash2 className="w-4 h-4" />
                        </Button>
                      </TableCell>
                    </TableRow>
                  ))}
                </TableBody>
              </Table>
            )}
          </CardContent>
        </Card>
      </div>
    </Layout>
  );
};

export default InviteManagement;
