import { ReactNode, useState } from "react";
import {
  AlertDialog,
  AlertDialogAction,
  AlertDialogCancel,
  AlertDialogContent,
  AlertDialogDescription,
  AlertDialogFooter,
  AlertDialogHeader,
  AlertDialogTitle,
  AlertDialogTrigger,
} from "@/components/ui/alert-dialog";
import { Button } from "@/components/ui/button";
import { Trash2 } from "lucide-react";
import { useLanguage } from "@/contexts/LanguageContext";

type DeleteConfirmDialogProps = {
  onConfirm: () => Promise<void>;
  trigger?: ReactNode;
  title?: string;
  description?: string;
};

const DeleteConfirmDialog = ({
  onConfirm,
  trigger,
  title,
  description,
}: DeleteConfirmDialogProps) => {
  const { isRTL } = useLanguage();
  const [open, setOpen] = useState(false);
  const [loading, setLoading] = useState(false);

  const handleConfirm = async () => {
    setLoading(true);
    try {
      await onConfirm();
      setOpen(false);
    } catch (error) {
      console.error("Delete error:", error);
    } finally {
      setLoading(false);
    }
  };

  return (
    <AlertDialog open={open} onOpenChange={setOpen}>
      <AlertDialogTrigger asChild>
        {trigger || (
          <Button variant="ghost" size="sm">
            <Trash2 className="h-4 w-4 text-destructive" />
          </Button>
        )}
      </AlertDialogTrigger>
      <AlertDialogContent dir={isRTL ? "rtl" : "ltr"}>
        <AlertDialogHeader>
          <AlertDialogTitle>
            {title || (isRTL ? "کیا آپ واقعی حذف کرنا چاہتے ہیں؟" : "Are you sure you want to delete?")}
          </AlertDialogTitle>
          <AlertDialogDescription>
            {description ||
              (isRTL
                ? "یہ عمل واپس نہیں ہو سکتا۔ یہ مستقل طور پر اس ریکارڈ کو حذف کر دے گا۔"
                : "This action cannot be undone. This will permanently delete this record.")}
          </AlertDialogDescription>
        </AlertDialogHeader>
        <AlertDialogFooter>
          <AlertDialogCancel disabled={loading}>{isRTL ? "منسوخ" : "Cancel"}</AlertDialogCancel>
          <AlertDialogAction onClick={handleConfirm} disabled={loading} className="bg-destructive text-destructive-foreground hover:bg-destructive/90">
            {loading ? (isRTL ? "حذف ہو رہا ہے..." : "Deleting...") : (isRTL ? "حذف کریں" : "Delete")}
          </AlertDialogAction>
        </AlertDialogFooter>
      </AlertDialogContent>
    </AlertDialog>
  );
};

export default DeleteConfirmDialog;
