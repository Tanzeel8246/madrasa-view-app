import { useState, useEffect } from "react";
import { z } from "zod";
import { useForm } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogFooter, DialogTrigger } from "@/components/ui/dialog";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";
import { useLanguage } from "@/contexts/LanguageContext";
import { toast } from "@/hooks/use-toast";
import { supabase } from "@/integrations/supabase/client";
import type { Database } from "@/integrations/supabase/types";
import { Plus } from "lucide-react";

const studentSchema = z.object({
  name: z.string().trim().min(1, "Required").max(100),
  father_name: z.string().trim().min(1, "Required").max(100),
  class_id: z.string().optional(),
  roll_number: z.string().trim().min(1, "Required").max(20),
  contact: z.string().trim().max(20).optional(),
  address: z.string().trim().max(255).optional(),
});

type StudentForm = z.infer<typeof studentSchema>;

interface AddStudentDialogProps {
  onAdded?: () => void;
}

const AddStudentDialog = ({ onAdded }: AddStudentDialogProps) => {
  const { t, isRTL } = useLanguage();
  const [open, setOpen] = useState(false);
  const [classes, setClasses] = useState<{ id: string; name: string }[]>([]);

  const form = useForm<StudentForm>({
    resolver: zodResolver(studentSchema),
    defaultValues: {
      name: "",
      father_name: "",
      class_id: "",
      roll_number: "",
      contact: "",
      address: "",
    },
  });

  useEffect(() => {
    const fetchClasses = async () => {
      const { data } = await supabase.from("classes").select("id, name").order("name");
      setClasses(data || []);
    };
    fetchClasses();
  }, []);

  const onSubmit = async (values: StudentForm) => {
    try {
      const payload: Database["public"]["Tables"]["students"]["Insert"] = {
        name: values.name,
        father_name: values.father_name,
        class: "", // Keep for backward compatibility
        class_id: values.class_id || null,
        roll_number: values.roll_number,
        contact: values.contact || null,
        address: values.address || null,
      };
      const { error } = await supabase.from("students").insert([payload]);
      if (error) throw error;
      toast({ title: t("addedSuccessfully"), description: `${values.name} ${t("addedSuccessfully")}` });
      setOpen(false);
      form.reset();
      onAdded?.();
    } catch (err: any) {
      toast({ title: t("errorOccurred"), description: err.message, variant: "destructive" });
    }
  };

  return (
    <Dialog open={open} onOpenChange={setOpen}>
      <DialogTrigger asChild>
        <Button className="gap-2">
          <Plus className="w-4 h-4" />
          {t("addStudent")}
        </Button>
      </DialogTrigger>
      <DialogContent dir={isRTL ? "rtl" : "ltr"}>
        <DialogHeader>
          <DialogTitle>{t("addStudent")}</DialogTitle>
        </DialogHeader>
        <form
          className="grid grid-cols-1 md:grid-cols-2 gap-4"
          onSubmit={form.handleSubmit(onSubmit)}
        >
          <div>
            <Label>{t("studentName")}</Label>
            <Input {...form.register("name")} />
          </div>
          <div>
            <Label>{t("fatherName")}</Label>
            <Input {...form.register("father_name")} />
          </div>
          <div>
            <Label>{t("class")}</Label>
            <Select onValueChange={(value) => form.setValue("class_id", value)}>
              <SelectTrigger>
                <SelectValue placeholder={t("selectClass")} />
              </SelectTrigger>
              <SelectContent>
                {classes.map((cls) => (
                  <SelectItem key={cls.id} value={cls.id}>
                    {cls.name}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
          </div>
          <div>
            <Label>{t("rollNumber")}</Label>
            <Input {...form.register("roll_number")} />
          </div>
          <div>
            <Label>{t("contact")}</Label>
            <Input {...form.register("contact")} />
          </div>
          <div className="md:col-span-2">
            <Label>{t("address")}</Label>
            <Input {...form.register("address")} />
          </div>
          <DialogFooter className="md:col-span-2">
            <Button type="submit">{t("save")}</Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  );
};

export default AddStudentDialog;