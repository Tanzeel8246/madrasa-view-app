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
import { Checkbox } from "@/components/ui/checkbox";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";
import { useLanguage } from "@/contexts/LanguageContext";
import { useAuth } from "@/contexts/AuthContext";
import { supabase } from "@/integrations/supabase/client";
import { useToast } from "@/hooks/use-toast";
import { Plus } from "lucide-react";

const CLASS_CATEGORIES = {
  "قرآن کریم": ["حفظ", "ناظرہ", "گردان", "تجوید و قراءت"],
  "درس نظامی": [
    "درجہ اولیٰ",
    "اولیٰ نہم",
    "اولیٰ دہم",
    "ثانویہ",
    "ثالثہ",
    "رابعہ",
    "خامسہ",
    "سادسہ",
    "موقوف علیہ",
    "دورہ حدیث شریف",
  ],
  "عصری علوم": ["کلاس ہشتم", "کلاس نہم", "کلاس دہم"],
};

const classSchema = z.object({
  category: z.string().min(1, "Category is required"),
  subCategory: z.string().min(1, "Sub-category is required"),
  description: z.string().optional(),
});

type ClassForm = z.infer<typeof classSchema>;

interface AddClassDialogProps {
  onAdded?: () => void;
}

const AddClassDialog = ({ onAdded }: AddClassDialogProps) => {
  const { t, isRTL } = useLanguage();
  const { madrasahId } = useAuth();
  const { toast } = useToast();
  const [open, setOpen] = useState(false);
  const [teachers, setTeachers] = useState<{ id: string; name: string }[]>([]);
  const [selectedCategory, setSelectedCategory] = useState<string>("");
  const [selectedTeachers, setSelectedTeachers] = useState<string[]>([]);

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

  const toggleTeacher = (teacherId: string) => {
    setSelectedTeachers(prev =>
      prev.includes(teacherId)
        ? prev.filter(id => id !== teacherId)
        : [...prev, teacherId]
    );
  };

  const onSubmit = async (data: ClassForm) => {
    try {
      if (!madrasahId) {
        toast({
          title: t("errorOccurred"),
          description: "Madrasah ID not found",
          variant: "destructive",
        });
        return;
      }

      const className = `${data.category} - ${data.subCategory}`;
      
      // Insert class first
      const { data: classData, error: classError } = await supabase
        .from("classes")
        .insert([{
          name: className,
          description: data.description || null,
          teacher_id: selectedTeachers[0] || null, // Keep for backward compatibility
          madrasah_id: madrasahId,
        }])
        .select()
        .single();

      if (classError) throw classError;

      // Insert class-teacher relationships
      if (selectedTeachers.length > 0) {
        const classTeacherRecords = selectedTeachers.map(teacherId => ({
          class_id: classData.id,
          teacher_id: teacherId,
          madrasah_id: madrasahId,
        }));

        const { error: relationError } = await supabase
          .from("class_teachers")
          .insert(classTeacherRecords);

        if (relationError) throw relationError;
      }

      toast({
        title: t("addedSuccessfully"),
        description: `${className} ${t("addedSuccessfully")}`,
      });

      reset();
      setSelectedCategory("");
      setSelectedTeachers([]);
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
            <Label htmlFor="category">زمرہ منتخب کریں</Label>
            <Select
              onValueChange={(value) => {
                setSelectedCategory(value);
                setValue("category", value);
                setValue("subCategory", "");
              }}
            >
              <SelectTrigger>
                <SelectValue placeholder="زمرہ منتخب کریں" />
              </SelectTrigger>
              <SelectContent>
                {Object.keys(CLASS_CATEGORIES).map((category) => (
                  <SelectItem key={category} value={category}>
                    {category}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
            {errors.category && (
              <p className="text-sm text-destructive mt-1">{errors.category.message}</p>
            )}
          </div>

          {selectedCategory && (
            <div>
              <Label htmlFor="subCategory">کلاس منتخب کریں</Label>
              <Select onValueChange={(value) => setValue("subCategory", value)}>
                <SelectTrigger>
                  <SelectValue placeholder="کلاس منتخب کریں" />
                </SelectTrigger>
                <SelectContent>
                  {CLASS_CATEGORIES[selectedCategory as keyof typeof CLASS_CATEGORIES].map(
                    (subCategory) => (
                      <SelectItem key={subCategory} value={subCategory}>
                        {subCategory}
                      </SelectItem>
                    )
                  )}
                </SelectContent>
              </Select>
              {errors.subCategory && (
                <p className="text-sm text-destructive mt-1">{errors.subCategory.message}</p>
              )}
            </div>
          )}

          <div>
            <Label>{isRTL ? "اساتذہ منتخب کریں" : "Select Teachers"}</Label>
            <div className="border rounded-md p-3 max-h-48 overflow-y-auto space-y-2">
              {teachers.length === 0 ? (
                <p className="text-sm text-muted-foreground">
                  {isRTL ? "کوئی استاد موجود نہیں" : "No teachers available"}
                </p>
              ) : (
                teachers.map((teacher) => (
                  <div key={teacher.id} className="flex items-center space-x-2 space-x-reverse">
                    <Checkbox
                      id={teacher.id}
                      checked={selectedTeachers.includes(teacher.id)}
                      onCheckedChange={() => toggleTeacher(teacher.id)}
                    />
                    <label
                      htmlFor={teacher.id}
                      className="text-sm font-medium leading-none peer-disabled:cursor-not-allowed peer-disabled:opacity-70 cursor-pointer"
                    >
                      {teacher.name}
                    </label>
                  </div>
                ))
              )}
            </div>
            {selectedTeachers.length > 0 && (
              <p className="text-xs text-muted-foreground mt-1">
                {isRTL 
                  ? `${selectedTeachers.length} اساتذہ منتخب ہیں` 
                  : `${selectedTeachers.length} teacher(s) selected`}
              </p>
            )}
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