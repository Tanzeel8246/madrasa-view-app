import { useState, useEffect } from "react";
import { useLanguage } from "@/contexts/LanguageContext";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Textarea } from "@/components/ui/textarea";
import { Search, Plus, RefreshCw, FileDown, Printer } from "lucide-react";
import { generatePDF, printTable } from "@/lib/pdfUtils";
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
    { value: "construction", label: isRTL ? "تعمیرات" : "Construction" },
    { value: "medical", label: isRTL ? "علاج" : "Medical" },
    { value: "repair", label: isRTL ? "مرمت" : "Repair" },
    { value: "vegetables", label: isRTL ? "سبزی" : "Vegetables" },
    { value: "groceries", label: isRTL ? "راشن" : "Groceries" },
    { value: "clothes", label: isRTL ? "کپڑے" : "Clothes" },
    { value: "shoes", label: isRTL ? "جوتے" : "Shoes" },
    { value: "uniforms", label: isRTL ? "وردیاں" : "Uniforms" },
    { value: "fruits", label: isRTL ? "پھل" : "Fruits" },
    { value: "electricBill", label: isRTL ? "بل بجلی" : "Electric Bill" },
    { value: "gasBill", label: isRTL ? "بل گیس" : "Gas Bill" },
    { value: "meat", label: isRTL ? "گوشت" : "Meat" },
    { value: "awards", label: isRTL ? "انعامات" : "Awards" },
    { value: "teacherService", label: isRTL ? "خدمت اساتذہ" : "Teacher Service" },
    { value: "transport", label: isRTL ? "ٹرانسپورٹ" : "Transport" },
    { value: "stationery", label: isRTL ? "اسٹیشنری" : "Stationery" },
    { value: "books", label: isRTL ? "کتب" : "Books" },
    { value: "furniture", label: isRTL ? "فرنیچر" : "Furniture" },
    { value: "cleaning", label: isRTL ? "صفائی" : "Cleaning" },
    { value: "security", label: isRTL ? "سیکیورٹی" : "Security" },
    { value: "waterBill", label: isRTL ? "بل پانی" : "Water Bill" },
    { value: "internet", label: isRTL ? "انٹرنیٹ" : "Internet" },
    { value: "phone", label: isRTL ? "ٹیلیفون" : "Telephone" },
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
      const { data: { user } } = await supabase.auth.getUser();
      
      if (!user) {
        toast({
          title: t("errorOccurred"),
          description: "User not found. Please login again.",
          variant: "destructive",
        });
        return;
      }

      const { data: profile, error: profileError } = await supabase
        .from("profiles")
        .select("madrasah_id")
        .eq("user_id", user.id)
        .maybeSingle();

      if (profileError) {
        console.error("Error fetching profile:", profileError);
        throw profileError;
      }

      if (!profile?.madrasah_id) {
        toast({
          title: t("errorOccurred"),
          description: "Madrasah ID not found. Please refresh the page.",
          variant: "destructive",
        });
        return;
      }

      console.log("Submitting expense with madrasah_id:", profile.madrasah_id);

      const { data: insertedData, error } = await supabase.from("expense").insert([{
        title: formData.title,
        amount: Number(formData.amount),
        category: formData.category,
        date: formData.date || new Date().toISOString().split("T")[0],
        description: formData.description || null,
        madrasah_id: profile.madrasah_id,
      }]).select();

      if (error) {
        console.error("Error inserting expense:", error);
        throw error;
      }

      console.log("Expense added successfully:", insertedData);

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

  const handleExportPDF = () => {
    const headers = [t("date"), t("title"), t("category"), t("amount"), t("description")];
    const data = filteredRecords.map(r => [
      new Date(r.date).toLocaleDateString(isRTL ? "ur-PK" : "en-US"),
      r.title,
      categories.find(c => c.value === r.category)?.label || r.category,
      `PKR ${Number(r.amount).toLocaleString()}`,
      r.description || "-",
    ]);
    generatePDF(
      t("expenseManagement"),
      headers,
      data,
      "expense_list.pdf",
      isRTL
    );
  };

  const handlePrint = () => {
    printTable("expense-table");
  };

  return (
    <div className="space-y-4 md:space-y-6">
      <div className="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-3">
        <h1 className="text-2xl md:text-3xl font-bold text-foreground">
          {t("expenseManagement")}
        </h1>
        <div className="flex flex-wrap gap-2">
          <Button variant="outline" onClick={handleExportPDF} size="sm">
            <FileDown className="h-3 w-3 md:h-4 md:w-4 mr-1 md:mr-2" />
            <span className="text-xs md:text-sm">PDF</span>
          </Button>
          <Button variant="outline" onClick={handlePrint} size="sm">
            <Printer className="h-3 w-3 md:h-4 md:w-4 mr-1 md:mr-2" />
            <span className="text-xs md:text-sm">{isRTL ? "پرنٹ" : "Print"}</span>
          </Button>
          <Dialog open={openDialog} onOpenChange={setOpenDialog}>
            <DialogTrigger asChild>
              <Button size="sm">
                <Plus className="h-3 w-3 md:h-4 md:w-4 mr-1 md:mr-2" />
                <span className="text-xs md:text-sm">{t("addExpense")}</span>
              </Button>
            </DialogTrigger>
            <DialogContent dir={isRTL ? "rtl" : "ltr"}>
              <DialogHeader>
                <DialogTitle>{t("addExpense")}</DialogTitle>
              </DialogHeader>
              <form onSubmit={handleSubmit(handleAddExpense)} className="space-y-4">
                <div>
                  <Label>{isRTL ? "تفصیل خرچ" : "Expense Detail"}</Label>
                  <Input {...register("title")} placeholder={isRTL ? "تفصیل خرچ درج کریں" : "Enter Expense Detail"} />
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

      <div className="bg-card rounded-lg border p-3 sm:p-4 md:p-6">
        <p className="text-xs md:text-sm text-muted-foreground mb-1 md:mb-2">{t("totalExpense")}</p>
        <h3 className="text-2xl md:text-3xl font-bold text-destructive">PKR {totalExpense.toLocaleString()}</h3>
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

      <div className="bg-card rounded-lg border overflow-x-auto">
        <Table id="expense-table">
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
