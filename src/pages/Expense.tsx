import { useState, useEffect } from "react";
import { useLanguage } from "@/contexts/LanguageContext";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Textarea } from "@/components/ui/textarea";
import { Search, Plus, RefreshCw } from "lucide-react";
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from "@/components/ui/table";
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
  DialogTrigger,
} from "@/components/ui/dialog";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";
import { supabase } from "@/integrations/supabase/client";
import { useToast } from "@/hooks/use-toast";
import { useForm } from "react-hook-form";

type ExpenseRecord = {
  id: string;
  title: string;
  amount: number;
  category: string;
  date: string;
  description: string | null;
};

const Expense = () => {
  const { t, isRTL } = useLanguage();
  const { toast } = useToast();
  const [searchQuery, setSearchQuery] = useState("");
  const [expenseRecords, setExpenseRecords] = useState<ExpenseRecord[]>([]);
  const [totalExpense, setTotalExpense] = useState(0);
  const [openDialog, setOpenDialog] = useState(false);
  const { register, handleSubmit, reset, setValue } = useForm();

  const categories = [
    { value: "salaries", label: t("salaries") },
    { value: "utilities", label: t("utilities") },
    { value: "maintenance", label: t("maintenance") },
    { value: "supplies", label: t("supplies") },
    { value: "other", label: t("other") },
  ];

  useEffect(() => {
    fetchExpense();
  }, []);

  const fetchExpense = async () => {
    try {
      const { data, error } = await supabase
        .from("expense")
        .select("*")
        .order("date", { ascending: false });

      if (error) throw error;

      setExpenseRecords(data || []);
      const total = data?.reduce((sum, record) => sum + Number(record.amount), 0) || 0;
      setTotalExpense(total);
    } catch (error) {
      console.error("Error fetching expense:", error);
      toast({
        title: t("errorOccurred"),
        variant: "destructive",
      });
    }
  };

  const handleAddExpense = async (formData: any) => {
    try {
      const { error } = await supabase.from("expense").insert([{
        title: formData.title,
        amount: Number(formData.amount),
        category: formData.category,
        date: formData.date || new Date().toISOString().split("T")[0],
        description: formData.description || null,
      }]);

      if (error) throw error;

      toast({
        title: t("addedSuccessfully"),
      });

      reset();
      setOpenDialog(false);
      fetchExpense();
    } catch (error: any) {
      toast({
        title: t("errorOccurred"),
        description: error.message,
        variant: "destructive",
      });
    }
  };

  const filteredRecords = expenseRecords.filter((record) =>
    record.title.toLowerCase().includes(searchQuery.toLowerCase()) ||
    record.category.toLowerCase().includes(searchQuery.toLowerCase())
  );

  return (
    <div className="space-y-6">
      <div className="flex items-center justify-between">
        <h1 className="text-3xl font-bold text-foreground">
          {t("expenseManagement")}
        </h1>
        <div className="flex gap-2">
          <Dialog open={openDialog} onOpenChange={setOpenDialog}>
            <DialogTrigger asChild>
              <Button>
                <Plus className="h-4 w-4 mr-2" />
                {t("addExpense")}
              </Button>
            </DialogTrigger>
            <DialogContent dir={isRTL ? "rtl" : "ltr"}>
              <DialogHeader>
                <DialogTitle>{t("addExpense")}</DialogTitle>
              </DialogHeader>
              <form onSubmit={handleSubmit(handleAddExpense)} className="space-y-4">
                <div>
                  <Label>{t("title")}</Label>
                  <Input {...register("title")} placeholder={t("enterTitle")} />
                </div>
                <div>
                  <Label>{t("category")}</Label>
                  <Select onValueChange={(value) => setValue("category", value)}>
                    <SelectTrigger>
                      <SelectValue placeholder={t("selectCategory")} />
                    </SelectTrigger>
                    <SelectContent>
                      {categories.map((cat) => (
                        <SelectItem key={cat.value} value={cat.value}>
                          {cat.label}
                        </SelectItem>
                      ))}
                    </SelectContent>
                  </Select>
                </div>
                <div>
                  <Label>{t("amount")}</Label>
                  <Input type="number" {...register("amount")} placeholder={t("enterAmount")} />
                </div>
                <div>
                  <Label>{t("date")}</Label>
                  <Input type="date" {...register("date")} defaultValue={new Date().toISOString().split("T")[0]} />
                </div>
                <div>
                  <Label>{t("description")}</Label>
                  <Textarea {...register("description")} />
                </div>
                <div className="flex justify-end gap-2">
                  <Button type="button" variant="outline" onClick={() => setOpenDialog(false)}>
                    {t("cancel")}
                  </Button>
                  <Button type="submit">{t("save")}</Button>
                </div>
              </form>
            </DialogContent>
          </Dialog>
          <Button onClick={fetchExpense} variant="outline" size="icon">
            <RefreshCw className="h-4 w-4" />
          </Button>
        </div>
      </div>

      <div className="bg-card rounded-lg border p-6">
        <p className="text-sm text-muted-foreground mb-2">{t("totalExpense")}</p>
        <h3 className="text-3xl font-bold text-destructive">PKR {totalExpense.toLocaleString()}</h3>
      </div>

      <div className="flex gap-4">
        <div className="relative flex-1">
          <Search className="absolute left-3 top-1/2 transform -translate-y-1/2 text-muted-foreground w-4 h-4" />
          <Input
            placeholder={t("search")}
            value={searchQuery}
            onChange={(e) => setSearchQuery(e.target.value)}
            className="pl-10"
          />
        </div>
      </div>

      <div className="bg-card rounded-lg border">
        <Table>
          <TableHeader>
            <TableRow>
              <TableHead className={isRTL ? "text-right" : "text-left"}>{t("date")}</TableHead>
              <TableHead className={isRTL ? "text-right" : "text-left"}>{t("title")}</TableHead>
              <TableHead className={isRTL ? "text-right" : "text-left"}>{t("category")}</TableHead>
              <TableHead className={isRTL ? "text-right" : "text-left"}>{t("amount")}</TableHead>
              <TableHead className={isRTL ? "text-right" : "text-left"}>{t("description")}</TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            {filteredRecords.length === 0 ? (
              <TableRow>
                <TableCell colSpan={5} className="text-center text-muted-foreground">
                  {t("noRecordsFound")}
                </TableCell>
              </TableRow>
            ) : (
              filteredRecords.map((record) => (
                <TableRow key={record.id}>
                  <TableCell className={isRTL ? "text-right" : "text-left"}>
                    {new Date(record.date).toLocaleDateString(isRTL ? "ur-PK" : "en-US")}
                  </TableCell>
                  <TableCell className={isRTL ? "text-right font-medium" : "text-left font-medium"}>
                    {record.title}
                  </TableCell>
                  <TableCell className={isRTL ? "text-right" : "text-left"}>
                    {categories.find(c => c.value === record.category)?.label || record.category}
                  </TableCell>
                  <TableCell className={isRTL ? "text-right" : "text-left"}>
                    PKR {Number(record.amount).toLocaleString()}
                  </TableCell>
                  <TableCell className={isRTL ? "text-right" : "text-left"}>
                    {record.description || "-"}
                  </TableCell>
                </TableRow>
              ))
            )}
          </TableBody>
        </Table>
      </div>
    </div>
  );
};

export default Expense;
