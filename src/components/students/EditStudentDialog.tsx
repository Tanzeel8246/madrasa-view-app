import { useState, useEffect } from "react";
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
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";
import { useToast } from "@/hooks/use-toast";
import { useLanguage } from "@/contexts/LanguageContext";
import { supabase } from "@/integrations/supabase/client";
import { Edit } from "lucide-react";
import { useForm } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import * as z from "zod";

const studentSchema = z.object({
  name: z.string().min(1, "Name is required"),
  father_name: z.string().min(1, "Father name is required"),
  class_id: z.string().min(1, "Class is required"),
  roll_number: z.string().min(1, "Roll number is required"),
  contact: z.string().optional(),
  address: z.string().optional(),
});

type StudentForm = z.infer<typeof studentSchema>;

type EditStudentDialogProps = {
  student: {
    id: string;
    name: string;
    father_name: string;
    class_id: string | null;
    roll_number: string;
    contact: string | null;
    address: string | null;
  };
  onUpdated: () => void;
};

const EditStudentDialog = ({ student, onUpdated }: EditStudentDialogProps) => {
  const { toast } = useToast();
  const { t, isRTL } = useLanguage();
  const [open, setOpen] = useState(false);
  const [classes, setClasses] = useState<{ id: string; name: string }[]>([]);

  const form = useForm<StudentForm>({
    resolver: zodResolver(studentSchema),
    defaultValues: {
      name: student.name,
      father_name: student.father_name,
      class_id: student.class_id || "",
      roll_number: student.roll_number,
      contact: student.contact || "",
      address: student.address || "",
    },
  });

  useEffect(() => {
    fetchClasses();
  }, []);

  const fetchClasses = async () => {
    const { data } = await supabase
      .from("classes")
      .select("id, name")
      .order("name");
    setClasses(data || []);
  };

  const onSubmit = async (data: StudentForm) => {
    try {
      const { error } = await supabase
        .from("students")
        .update({
          name: data.name,
          father_name: data.father_name,
          class_id: data.class_id,
          class: classes.find(c => c.id === data.class_id)?.name || "",
          roll_number: data.roll_number,
          contact: data.contact || null,
          address: data.address || null,
        })
        .eq("id", student.id);

      if (error) throw error;

      toast({
        title: t("updatedSuccessfully"),
        description: isRTL ? "طالب علم کی معلومات اپ ڈیٹ ہو گئیں" : "Student information updated successfully",
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
          <DialogTitle>{isRTL ? "طالب علم میں ترمیم کریں" : "Edit Student"}</DialogTitle>
        </DialogHeader>
        <form onSubmit={form.handleSubmit(onSubmit)} className="space-y-4">
          <div>
            <Label>{t("studentName")}</Label>
            <Input {...form.register("name")} />
            {form.formState.errors.name && (
              <span className="text-sm text-destructive">{form.formState.errors.name.message}</span>
            )}
          </div>
          <div>
            <Label>{t("fatherName")}</Label>
            <Input {...form.register("father_name")} />
            {form.formState.errors.father_name && (
              <span className="text-sm text-destructive">{form.formState.errors.father_name.message}</span>
            )}
          </div>
          <div>
            <Label>{t("class")}</Label>
            <Select value={form.watch("class_id")} onValueChange={(value) => form.setValue("class_id", value)}>
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
            {form.formState.errors.class_id && (
              <span className="text-sm text-destructive">{form.formState.errors.class_id.message}</span>
            )}
          </div>
          <div>
            <Label>{t("rollNumber")}</Label>
            <Input {...form.register("roll_number")} />
            {form.formState.errors.roll_number && (
              <span className="text-sm text-destructive">{form.formState.errors.roll_number.message}</span>
            )}
          </div>
          <div>
            <Label>{t("contact")}</Label>
            <Input {...form.register("contact")} />
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

export default EditStudentDialog;
