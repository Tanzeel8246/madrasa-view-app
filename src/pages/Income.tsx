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

type IncomeRecord = {
  id: string;
  title: string;
  amount: number;
  category: string;
  date: string;
  description: string | null;
};

const Income = () => {
  const { t, isRTL } = useLanguage();
  const { toast } = useToast();
  const [searchQuery, setSearchQuery] = useState("");
  const [incomeRecords, setIncomeRecords] = useState<IncomeRecord[]>([]);
  const [totalIncome, setTotalIncome] = useState(0);
  const [openDialog, setOpenDialog] = useState(false);
  const { register, handleSubmit, reset, setValue } = useForm();

  const categories = [
    { value: "feeIncome", label: t("feeIncome") },
    { value: "donations", label: t("donations") },
    { value: "other", label: t("other") },
  ];

  useEffect(() => {
    fetchIncome();
  }, []);

  const fetchIncome = async () => {
    try {
      const { data, error } = await supabase
        .from("income")
        .select("*")
        .order("date", { ascending: false });

      if (error) throw error;

      setIncomeRecords(data || []);
      const total = data?.reduce((sum, record) => sum + Number(record.amount), 0) || 0;
      setTotalIncome(total);
    } catch (error) {
      console.error("Error fetching income:", error);
      toast({
        title: t("errorOccurred"),
        variant: "destructive",
      });
    }
  };

  const handleAddIncome = async (formData: any) => {
    try {
      const { error } = await supabase.from("income").insert([{
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
      fetchIncome();
    } catch (error: any) {
      toast({
        title: t("errorOccurred"),
        description: error.message,
        variant: "destructive",
      });
    }
  };

  const filteredRecords = incomeRecords.filter((record) =>
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
      t("incomeManagement"),
      headers,
      data,
      "income_list.pdf",
      isRTL
    );
  };

  const handlePrint = () => {
    printTable("income-table");
  };

  return (
    <div className="space-y-4 md:space-y-6">
      <div className="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-3">
        <h1 className="text-2xl md:text-3xl font-bold text-foreground">
          {t("incomeManagement")}
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
                <span className="text-xs md:text-sm">{t("addIncome")}</span>
              </Button>
            </DialogTrigger>
            <DialogContent dir={isRTL ? "rtl" : "ltr"}>
              <DialogHeader>
                <DialogTitle>{t("addIncome")}</DialogTitle>
              </DialogHeader>
              <form onSubmit={handleSubmit(handleAddIncome)} className="space-y-4">
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
          <Button onClick={fetchIncome} variant="outline" size="icon">
            <RefreshCw className="h-4 w-4" />
          </Button>
        </div>
      </div>

      <div className="bg-card rounded-lg border p-3 sm:p-4 md:p-6">
        <p className="text-xs md:text-sm text-muted-foreground mb-1 md:mb-2">{t("totalIncome")}</p>
        <h3 className="text-2xl md:text-3xl font-bold text-primary">PKR {totalIncome.toLocaleString()}</h3>
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
        <Table id="income-table">
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

export default Income;
