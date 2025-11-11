import { useState, useEffect } from "react";
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
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";
import { useLanguage } from "@/contexts/LanguageContext";
import { supabase } from "@/integrations/supabase/client";
import { useToast } from "@/hooks/use-toast";
import { Plus } from "lucide-react";

const classSchema = z.object({
  name: z.string().min(1, "Name is required"),
  description: z.string().optional(),
  teacher_id: z.string().optional(),
});

type ClassForm = z.infer<typeof classSchema>;

interface AddClassDialogProps {
  onAdded?: () => void;
}

const AddClassDialog = ({ onAdded }: AddClassDialogProps) => {
  const { t, isRTL } = useLanguage();
  const { toast } = useToast();
  const [open, setOpen] = useState(false);
  const [teachers, setTeachers] = useState<{ id: string; name: string }[]>([]);

  const { register, handleSubmit, reset, setValue, formState: { errors } } = useForm<ClassForm>({
    resolver: zodResolver(classSchema),
  });

  useEffect(() => {
    const fetchTeachers = async () => {
      const { data } = await supabase.from("teachers").select("id, name").order("name");
      setTeachers(data || []);
    };
    fetchTeachers();
  }, []);

  const onSubmit = async (data: ClassForm) => {
    try {
      const { error } = await supabase.from("classes").insert([{
        name: data.name,
        description: data.description || null,
        teacher_id: data.teacher_id || null,
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
      console.error("Error adding class:", error);
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
          {t("addClass")}
        </Button>
      </DialogTrigger>
      <DialogContent className="sm:max-w-[500px]" dir={isRTL ? "rtl" : "ltr"}>
        <DialogHeader>
          <DialogTitle>{t("addClass")}</DialogTitle>
        </DialogHeader>
        <form onSubmit={handleSubmit(onSubmit)} className="space-y-4">
          <div>
            <Label htmlFor="name">{t("className")}</Label>
            <Input id="name" {...register("name")} />
            {errors.name && <p className="text-sm text-destructive mt-1">{errors.name.message}</p>}
          </div>

          <div>
            <Label htmlFor="teacher_id">{t("teacher")}</Label>
            <Select onValueChange={(value) => setValue("teacher_id", value)}>
              <SelectTrigger>
                <SelectValue placeholder={t("selectTeacher")} />
              </SelectTrigger>
              <SelectContent>
                {teachers.map((teacher) => (
                  <SelectItem key={teacher.id} value={teacher.id}>
                    {teacher.name}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
          </div>

          <div>
            <Label htmlFor="description">{t("description")}</Label>
            <Textarea id="description" {...register("description")} />
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

export default AddClassDialog;