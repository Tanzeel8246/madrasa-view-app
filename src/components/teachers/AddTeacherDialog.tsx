import { useState } from "react";
import { useForm } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import * as z from "zod";
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
import { useLanguage } from "@/contexts/LanguageContext";
import { supabase } from "@/integrations/supabase/client";
import { useToast } from "@/hooks/use-toast";
import { Plus } from "lucide-react";

const teacherSchema = z.object({
  name: z.string().min(1, "Name is required"),
  contact: z.string().optional(),
  email: z.string().email("Invalid email").optional().or(z.literal("")),
  subject: z.string().optional(),
  qualification: z.string().optional(),
  address: z.string().optional(),
});

type TeacherForm = z.infer<typeof teacherSchema>;

interface AddTeacherDialogProps {
  onAdded?: () => void;
}

const AddTeacherDialog = ({ onAdded }: AddTeacherDialogProps) => {
  const { t, isRTL } = useLanguage();
  const { toast } = useToast();
  const [open, setOpen] = useState(false);

  const { register, handleSubmit, reset, formState: { errors } } = useForm<TeacherForm>({
    resolver: zodResolver(teacherSchema),
  });

  const onSubmit = async (data: TeacherForm) => {
    try {
      const { error } = await supabase.from("teachers").insert([{
        name: data.name,
        contact: data.contact || null,
        email: data.email || null,
        subject: data.subject || null,
        qualification: data.qualification || null,
        address: data.address || null,
      }]);

      if (error) throw error;

      toast({
        title: t("addedSuccessfully"),
        description: `${data.name} ${t("addedSuccessfully")}`,
      });

      reset();
      setOpen(false);
      onAdded?.();
    } catch (error) {
      console.error("Error adding teacher:", error);
      toast({
        title: t("errorOccurred"),
        description: t("errorOccurred"),
        variant: "destructive",
      });
    }
  };

  return (
    <Dialog open={open} onOpenChange={setOpen}>
      <DialogTrigger asChild>
        <Button>
          <Plus className="h-4 w-4 mr-2" />
          {t("addTeacher")}
        </Button>
      </DialogTrigger>
      <DialogContent className="sm:max-w-[500px]" dir={isRTL ? "rtl" : "ltr"}>
        <DialogHeader>
          <DialogTitle>{t("addTeacher")}</DialogTitle>
        </DialogHeader>
        <form onSubmit={handleSubmit(onSubmit)} className="space-y-4">
          <div>
            <Label htmlFor="name">{t("teacherName")}</Label>
            <Input id="name" {...register("name")} />
            {errors.name && <p className="text-sm text-destructive mt-1">{errors.name.message}</p>}
          </div>

          <div>
            <Label htmlFor="subject">{t("subject")}</Label>
            <Input id="subject" {...register("subject")} />
          </div>

          <div>
            <Label htmlFor="qualification">{t("qualification")}</Label>
            <Input id="qualification" {...register("qualification")} />
          </div>

          <div>
            <Label htmlFor="contact">{t("contact")}</Label>
            <Input id="contact" {...register("contact")} />
          </div>

          <div>
            <Label htmlFor="email">{t("email")}</Label>
            <Input id="email" type="email" {...register("email")} />
            {errors.email && <p className="text-sm text-destructive mt-1">{errors.email.message}</p>}
          </div>

          <div>
            <Label htmlFor="address">{t("address")}</Label>
            <Textarea id="address" {...register("address")} />
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

export default AddTeacherDialog;