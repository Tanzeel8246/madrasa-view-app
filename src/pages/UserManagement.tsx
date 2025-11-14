import { useState, useEffect } from "react";
import { useAuth } from "@/contexts/AuthContext";
import { useUserRole } from "@/hooks/useUserRole";
import { useLanguage } from "@/contexts/LanguageContext";
import { supabase } from "@/integrations/supabase/client";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select";
import { toast } from "sonner";
import { Loader2, UserPlus } from "lucide-react";
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogTrigger } from "@/components/ui/dialog";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";

import Layout from "@/components/Layout";

const UserManagement = () => {
  const { madrasahId } = useAuth();
  const { isAdmin } = useUserRole();
  const { t, isRTL } = useLanguage();
  const [users, setUsers] = useState<any[]>([]);
  const [loading, setLoading] = useState(true);
  const [newUserEmail, setNewUserEmail] = useState("");
  const [newUserRole, setNewUserRole] = useState<"admin" | "teacher" | "manager" | "parent" | "user">("user");
  const [addingUser, setAddingUser] = useState(false);

  const getRoleLabel = (role: string) => {
    const labels: Record<string, { ur: string; en: string }> = {
      admin: { ur: "ایڈمن", en: "Admin" },
      teacher: { ur: "استاد", en: "Teacher" },
      manager: { ur: "منیجر", en: "Manager" },
      parent: { ur: "والدین", en: "Parent" },
      user: { ur: "یوزر", en: "User" },
    };
    return isRTL ? labels[role]?.ur || role : labels[role]?.en || role;
  };

  useEffect(() => {
    if (madrasahId) {
      fetchUsers();
    }
  }, [madrasahId]);

  const fetchUsers = async () => {
    if (!madrasahId) return;

    setLoading(true);
    const { data: rolesData, error: rolesError } = await supabase
      .from("user_roles")
      .select("user_id, role")
      .eq("madrasah_id", madrasahId);

    if (rolesError) {
      toast.error(isRTL ? "یوزر لوڈ کرنے میں خرابی" : "Error loading users");
      setLoading(false);
      return;
    }

    // Fetch profile data for each user
    const userPromises = rolesData.map(async (roleData) => {
      const { data: profile } = await supabase
        .from("profiles")
        .select("full_name, user_id")
        .eq("user_id", roleData.user_id)
        .eq("madrasah_id", madrasahId)
        .single();

      return {
        ...roleData,
        full_name: profile?.full_name || "Unknown",
      };
    });

    const usersWithProfiles = await Promise.all(userPromises);
    setUsers(usersWithProfiles);
    setLoading(false);
  };

  const handleRoleChange = async (userId: string, newRole: "admin" | "teacher" | "manager" | "parent" | "user") => {
    if (!madrasahId) return;

    const { error } = await supabase
      .from("user_roles")
      .update({ role: newRole })
      .eq("user_id", userId)
      .eq("madrasah_id", madrasahId);

    if (error) {
      toast.error(isRTL ? "رول تبدیل کرنے میں خرابی" : "Error updating role");
    } else {
      toast.success(isRTL ? "رول کامیابی سے تبدیل ہو گیا" : "Role updated successfully");
      fetchUsers();
    }
  };

  const handleAddUser = async () => {
    if (!madrasahId || !newUserEmail) return;

    setAddingUser(true);

    try {
      // Validate UUID format
      const uuidRegex = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i;
      if (!uuidRegex.test(newUserEmail)) {
        toast.error(isRTL 
          ? "براہ کرم صحیح یوزر ID (UUID) درج کریں" 
          : "Please enter a valid User ID (UUID)");
        setAddingUser(false);
        return;
      }

      // Check if user already has a role in this madrasah
      const { data: existingRole } = await supabase
        .from("user_roles")
        .select("*")
        .eq("user_id", newUserEmail)
        .eq("madrasah_id", madrasahId)
        .maybeSingle();

      if (existingRole) {
        toast.error(isRTL ? "یہ یوزر پہلے سے موجود ہے" : "This user already exists");
        setAddingUser(false);
        return;
      }

      // Add user role
      const { error: roleError } = await supabase
        .from("user_roles")
        .insert([{
          user_id: newUserEmail,
          madrasah_id: madrasahId,
          role: newUserRole,
        }]);

      if (roleError) throw roleError;

      toast.success(isRTL ? "یوزر کامیابی سے شامل ہو گیا" : "User added successfully");
      setNewUserEmail("");
      setNewUserRole("user");
      fetchUsers();
    } catch (error: any) {
      toast.error(isRTL 
        ? `یوزر شامل کرنے میں خرابی: ${error.message}` 
        : `Error adding user: ${error.message}`);
    }

    setAddingUser(false);
  };

  if (!isAdmin()) {
    return (
      <Layout>
        <div className="min-h-screen flex items-center justify-center">
          <Card>
            <CardContent className="p-6">
              <p className={isRTL ? "font-urdu" : ""}>
                {isRTL ? "آپ کو اس صفحے تک رسائی کی اجازت نہیں" : "You don't have permission to access this page"}
              </p>
            </CardContent>
          </Card>
        </div>
      </Layout>
    );
  }

  if (loading) {
    return (
      <Layout>
        <div className="min-h-screen flex items-center justify-center">
          <Loader2 className="w-8 h-8 animate-spin" />
        </div>
      </Layout>
    );
  }

  return (
    <Layout>
      <div className="container mx-auto p-4 space-y-6">
      <Card>
        <CardHeader className="flex flex-row items-center justify-between">
          <CardTitle className={isRTL ? "font-urdu" : ""}>
            {isRTL ? "یوزر مینجمنٹ" : "User Management"}
          </CardTitle>
          <Dialog>
            <DialogTrigger asChild>
              <Button className={isRTL ? "font-urdu" : ""}>
                <UserPlus className="w-4 h-4 mr-2" />
                {isRTL ? "نیا یوزر شامل کریں" : "Add New User"}
              </Button>
            </DialogTrigger>
            <DialogContent>
              <DialogHeader>
                <DialogTitle className={isRTL ? "font-urdu" : ""}>
                  {isRTL ? "نیا یوزر شامل کریں" : "Add New User"}
                </DialogTitle>
              </DialogHeader>
              <div className="space-y-4">
                <div>
                  <Label className={isRTL ? "font-urdu" : ""}>
                    {isRTL ? "یوزر ID (UUID)" : "User ID (UUID)"}
                  </Label>
                  <Input
                    type="text"
                    value={newUserEmail}
                    onChange={(e) => setNewUserEmail(e.target.value)}
                    placeholder={isRTL ? "یوزر کا UUID" : "User's UUID"}
                    className="font-mono text-sm"
                  />
                  <p className="text-xs text-muted-foreground mt-1">
                    {isRTL 
                      ? "UUID فارمیٹ: xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx" 
                      : "UUID format: xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx"}
                  </p>
                </div>
                <div>
                  <Label className={isRTL ? "font-urdu" : ""}>
                    {isRTL ? "رول" : "Role"}
                  </Label>
                  <Select value={newUserRole} onValueChange={(value) => setNewUserRole(value as typeof newUserRole)}>
                    <SelectTrigger>
                      <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                      <SelectItem value="admin">{getRoleLabel("admin")}</SelectItem>
                      <SelectItem value="teacher">{getRoleLabel("teacher")}</SelectItem>
                      <SelectItem value="manager">{getRoleLabel("manager")}</SelectItem>
                      <SelectItem value="parent">{getRoleLabel("parent")}</SelectItem>
                      <SelectItem value="user">{getRoleLabel("user")}</SelectItem>
                    </SelectContent>
                  </Select>
                </div>
                <Button onClick={handleAddUser} disabled={addingUser} className="w-full">
                  {addingUser ? (
                    <Loader2 className="w-4 h-4 animate-spin" />
                  ) : (
                    isRTL ? "شامل کریں" : "Add User"
                  )}
                </Button>
              </div>
            </DialogContent>
          </Dialog>
        </CardHeader>
        <CardContent>
          <div className="space-y-4">
            {users.map((user) => (
              <div
                key={user.user_id}
                className="flex items-center justify-between p-4 border rounded-lg"
              >
                <div>
                  <p className={`font-medium ${isRTL ? "font-urdu" : ""}`}>
                    {user.full_name}
                  </p>
                </div>
                <Select
                  value={user.role}
                  onValueChange={(value) => handleRoleChange(user.user_id, value as typeof newUserRole)}
                >
                  <SelectTrigger className="w-40">
                    <SelectValue />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value="admin">{getRoleLabel("admin")}</SelectItem>
                    <SelectItem value="teacher">{getRoleLabel("teacher")}</SelectItem>
                    <SelectItem value="manager">{getRoleLabel("manager")}</SelectItem>
                    <SelectItem value="parent">{getRoleLabel("parent")}</SelectItem>
                    <SelectItem value="user">{getRoleLabel("user")}</SelectItem>
                  </SelectContent>
                </Select>
              </div>
            ))}
          </div>
        </CardContent>
      </Card>
    </div>
    </Layout>
  );
};

export default UserManagement;
