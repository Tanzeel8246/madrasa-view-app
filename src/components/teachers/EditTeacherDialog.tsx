import { useState } from "react";
import { Button } from "@/components/ui/button";
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
  DialogTrigger,
} from "@/components/ui/dialog";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Textarea } from "@/components/ui/textarea";
import { useToast } from "@/hooks/use-toast";
import { useLanguage } from "@/contexts/LanguageContext";
import { supabase } from "@/integrations/supabase/client";
import { Edit } from "lucide-react";
import { useForm } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import * as z from "zod";

const teacherSchema = z.object({
  name: z.string().min(1, "Name is required"),
  contact: z.string().optional(),
  email: z.string().email().optional().or(z.literal("")),
  subject: z.string().optional(),
  qualification: z.string().optional(),
  address: z.string().optional(),
});

type TeacherForm = z.infer<typeof teacherSchema>;

type EditTeacherDialogProps = {
  teacher: {
    id: string;
    name: string;
    contact: string | null;
    email: string | null;
    subject: string | null;
    qualification: string | null;
    address: string | null;
  };
  onUpdated: () => void;
};

const EditTeacherDialog = ({ teacher, onUpdated }: EditTeacherDialogProps) => {
  const { toast } = useToast();
  const { t, isRTL } = useLanguage();
  const [open, setOpen] = useState(false);

  const form = useForm<TeacherForm>({
    resolver: zodResolver(teacherSchema),
    defaultValues: {
      name: teacher.name,
      contact: teacher.contact || "",
      email: teacher.email || "",
      subject: teacher.subject || "",
      qualification: teacher.qualification || "",
      address: teacher.address || "",
    },
  });

  const onSubmit = async (data: TeacherForm) => {
    try {
      const { error } = await supabase
        .from("teachers")
        .update({
          name: data.name,
          contact: data.contact || null,
          email: data.email || null,
          subject: data.subject || null,
          qualification: data.qualification || null,
          address: data.address || null,
        })
        .eq("id", teacher.id);

      if (error) throw error;

      toast({
        title: t("updatedSuccessfully"),
        description: isRTL ? "استاد کی معلومات اپ ڈیٹ ہو گئیں" : "Teacher information updated successfully",
      });

      setOpen(false);
      onUpdated();
    } catch (error: any) {
      toast({
        title: t("errorOccurred"),
        description: error.message,
        variant: "destructive",
      });
    }
  };

  return (
    <Dialog open={open} onOpenChange={setOpen}>
      <DialogTrigger asChild>
        <Button variant="ghost" size="sm">
          <Edit className="h-4 w-4" />
        </Button>
      </DialogTrigger>
      <DialogContent dir={isRTL ? "rtl" : "ltr"}>
        <DialogHeader>
          <DialogTitle>{isRTL ? "استاد میں ترمیم کریں" : "Edit Teacher"}</DialogTitle>
        </DialogHeader>
        <form onSubmit={form.handleSubmit(onSubmit)} className="space-y-4">
          <div>
            <Label>{t("teacherName")}</Label>
            <Input {...form.register("name")} />
            {form.formState.errors.name && (
              <span className="text-sm text-destructive">{form.formState.errors.name.message}</span>
            )}
          </div>
          <div>
            <Label>{t("contact")}</Label>
            <Input {...form.register("contact")} />
          </div>
          <div>
            <Label>{t("email")}</Label>
            <Input type="email" {...form.register("email")} />
            {form.formState.errors.email && (
              <span className="text-sm text-destructive">{form.formState.errors.email.message}</span>
            )}
          </div>
          <div>
            <Label>{t("subject")}</Label>
            <Input {...form.register("subject")} />
          </div>
          <div>
            <Label>{t("qualification")}</Label>
            <Input {...form.register("qualification")} />
          </div>
          <div>
            <Label>{t("address")}</Label>
            <Textarea {...form.register("address")} />
          </div>
          <div className="flex justify-end gap-2">
            <Button type="button" variant="outline" onClick={() => setOpen(false)}>
              {t("cancel")}
            </Button>
            <Button type="submit">{t("save")}</Button>
          </div>
        </form>
      </DialogContent>
    </Dialog>
  );
};

export default EditTeacherDialog;
