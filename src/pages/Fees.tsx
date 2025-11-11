import { useState, useEffect } from "react";
import { useLanguage } from "@/contexts/LanguageContext";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Search, Plus, FileDown, Printer } from "lucide-react";
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
import { Label } from "@/components/ui/label";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";
import { Badge } from "@/components/ui/badge";
import { supabase } from "@/integrations/supabase/client";
import { useToast } from "@/hooks/use-toast";
import { useForm } from "react-hook-form";
import { getMadrasahId } from "@/lib/madrasahUtils";

type FeeRecord = {
  id: string;
  student_id: string;
  amount: number;
  paid_amount: number | null;
  month: number;
  year: number;
  status: string;
  students: {
    name: string;
    roll_number: string;
  } | null;
};

const Fees = () => {
  const { t, isRTL } = useLanguage();
  const { toast } = useToast();
  const [searchQuery, setSearchQuery] = useState("");
  const [feeRecords, setFeeRecords] = useState<FeeRecord[]>([]);
  const [students, setStudents] = useState<{ id: string; name: string; roll_number: string }[]>([]);
  const [stats, setStats] = useState({ totalIncome: 0, pending: 0, expected: 0 });
  const [openAddDialog, setOpenAddDialog] = useState(false);
  const { register, handleSubmit, reset, setValue } = useForm();

  useEffect(() => {
    fetchFeeRecords();
    fetchStudents();
  }, []);

  const fetchStudents = async () => {
    const { data } = await supabase.from("students").select("id, name, roll_number").order("roll_number");
    setStudents(data || []);
  };

  const fetchFeeRecords = async () => {
    try {
      const { data, error } = await supabase
        .from("fees")
        .select(`
          *,
          students (
            name,
            roll_number
          )
        `)
        .order("year", { ascending: false })
        .order("month", { ascending: false });

      if (error) throw error;

      setFeeRecords(data || []);

      // Calculate stats
      const totalIncome = data?.reduce((sum, fee) => sum + (Number(fee.paid_amount) || 0), 0) || 0;
      const expected = data?.reduce((sum, fee) => sum + Number(fee.amount), 0) || 0;
      const pending = expected - totalIncome;

      setStats({ totalIncome, pending, expected });
    } catch (error) {
      console.error("Error fetching fees:", error);
    }
  };

  const handleAddFee = async (formData: any) => {
    try {
      const madrasahId = await getMadrasahId();
      const { error } = await supabase.from("fees").insert({
        student_id: formData.student_id,
        amount: Number(formData.amount),
        paid_amount: Number(formData.paid_amount) || 0,
        month: Number(formData.month),
        year: Number(formData.year),
        status: Number(formData.paid_amount) >= Number(formData.amount) ? "paid" : "pending",
        payment_date: Number(formData.paid_amount) > 0 ? new Date().toISOString() : null,
        madrasah_id: madrasahId,
      });

      if (error) throw error;

      toast({
        title: t("addedSuccessfully"),
        description: t("addedSuccessfully"),
      });

      reset();
      setOpenAddDialog(false);
      fetchFeeRecords();
    } catch (error: any) {
      toast({
        title: t("errorOccurred"),
        description: error.message,
        variant: "destructive",
      });
    }
  };

  const getMonthName = (monthIndex: number): string => {
    const monthKeys = [
      "january", "february", "march", "april", "may", "june",
      "july", "august", "september", "october", "november", "december"
    ];
    return t(monthKeys[monthIndex]);
  };

  const monthNames = Array.from({ length: 12 }, (_, i) => getMonthName(i));

  const filteredRecords = feeRecords.filter((record) =>
    record.students?.name.toLowerCase().includes(searchQuery.toLowerCase()) ||
    record.students?.roll_number.toLowerCase().includes(searchQuery.toLowerCase())
  );

  const handleExportPDF = () => {
    const headers = [
      t("rollNumber"),
      t("studentName"),
      t("month") + " / " + t("year"),
      t("amount"),
      t("paidAmount"),
      t("pendingAmount"),
      t("status"),
    ];
    const data = filteredRecords.map(r => [
      r.students?.roll_number || "-",
      r.students?.name || "-",
      `${monthNames[r.month - 1]} ${r.year}`,
      `PKR ${Number(r.amount).toLocaleString()}`,
      `PKR ${(Number(r.paid_amount) || 0).toLocaleString()}`,
      `PKR ${(Number(r.amount) - (Number(r.paid_amount) || 0)).toLocaleString()}`,
      r.status === "paid" ? t("paid") : t("pending"),
    ]);
    generatePDF(
      t("feeManagement"),
      headers,
      data,
      "fees_list.pdf",
      isRTL
    );
  };

  const handlePrint = () => {
    printTable("fees-table");
  };

  return (
    <div className="space-y-4 md:space-y-6">
      <div className="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-3">
        <h1 className="text-2xl md:text-3xl font-bold text-foreground">
          {t("feeManagement")}
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
          <Dialog open={openAddDialog} onOpenChange={setOpenAddDialog}>
            <DialogTrigger asChild>
              <Button size="sm">
                <Plus className="h-3 w-3 md:h-4 md:w-4 mr-1 md:mr-2" />
                <span className="text-xs md:text-sm">{t("addFee")}</span>
              </Button>
            </DialogTrigger>
          <DialogContent dir={isRTL ? "rtl" : "ltr"}>
            <DialogHeader>
              <DialogTitle>{t("addFee")}</DialogTitle>
            </DialogHeader>
            <form onSubmit={handleSubmit(handleAddFee)} className="space-y-4">
              <div>
                <Label>{t("studentName")}</Label>
                <Select onValueChange={(value) => setValue("student_id", value)}>
                  <SelectTrigger>
                    <SelectValue placeholder={t("studentName")} />
                  </SelectTrigger>
                  <SelectContent>
                    {students.map((student) => (
                      <SelectItem key={student.id} value={student.id}>
                        {student.roll_number} - {student.name}
                      </SelectItem>
                    ))}
                  </SelectContent>
                </Select>
              </div>
              <div className="grid grid-cols-2 gap-4">
                <div>
                  <Label>{t("month")}</Label>
                  <Select onValueChange={(value) => setValue("month", value)}>
                    <SelectTrigger>
                      <SelectValue placeholder={t("month")} />
                    </SelectTrigger>
                    <SelectContent>
                      {monthNames.map((month, idx) => (
                        <SelectItem key={idx} value={(idx + 1).toString()}>
                          {month}
                        </SelectItem>
                      ))}
                    </SelectContent>
                  </Select>
                </div>
                <div>
                  <Label>{t("year")}</Label>
                  <Input type="number" {...register("year")} defaultValue={new Date().getFullYear()} />
                </div>
              </div>
              <div>
                <Label>{t("amount")}</Label>
                <Input type="number" {...register("amount")} />
              </div>
              <div>
                <Label>{t("paidAmount")}</Label>
                <Input type="number" {...register("paid_amount")} defaultValue="0" />
              </div>
              <div className="flex justify-end gap-2">
                <Button type="button" variant="outline" onClick={() => setOpenAddDialog(false)}>
                  {t("cancel")}
                </Button>
                <Button type="submit">{t("save")}</Button>
              </div>
            </form>
          </DialogContent>
          </Dialog>
        </div>
      </div>

      <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3 md:gap-4 lg:gap-6">
        <div className="bg-card rounded-lg border p-3 sm:p-4 md:p-6">
          <p className="text-xs md:text-sm text-muted-foreground mb-1 md:mb-2">{t("totalIncome")}</p>
          <h3 className="text-2xl md:text-3xl font-bold text-primary">PKR {stats.totalIncome.toLocaleString()}</h3>
        </div>
        <div className="bg-card rounded-lg border p-3 sm:p-4 md:p-6">
          <p className="text-xs md:text-sm text-muted-foreground mb-1 md:mb-2">{t("pendingAmount")}</p>
          <h3 className="text-2xl md:text-3xl font-bold text-destructive">PKR {stats.pending.toLocaleString()}</h3>
        </div>
        <div className="bg-card rounded-lg border p-3 sm:p-4 md:p-6">
          <p className="text-xs md:text-sm text-muted-foreground mb-1 md:mb-2">{t("expectedRevenue")}</p>
          <h3 className="text-2xl md:text-3xl font-bold text-accent">PKR {stats.expected.toLocaleString()}</h3>
        </div>
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
        <Table id="fees-table">
          <TableHeader>
            <TableRow>
              <TableHead className={isRTL ? "text-right" : "text-left"}>
                {t("rollNumber")}
              </TableHead>
              <TableHead className={isRTL ? "text-right" : "text-left"}>
                {t("studentName")}
              </TableHead>
              <TableHead className={isRTL ? "text-right" : "text-left"}>
                {t("month")} / {t("year")}
              </TableHead>
              <TableHead className={isRTL ? "text-right" : "text-left"}>
                {t("amount")}
              </TableHead>
              <TableHead className={isRTL ? "text-right" : "text-left"}>
                {t("paidAmount")}
              </TableHead>
              <TableHead className={isRTL ? "text-right" : "text-left"}>
                {t("pendingAmount")}
              </TableHead>
              <TableHead className={isRTL ? "text-right" : "text-left"}>
                {t("status")}
              </TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            {filteredRecords.length === 0 ? (
              <TableRow>
                <TableCell colSpan={7} className="text-center text-muted-foreground">
                  {t("noRecordsFound")}
                </TableCell>
              </TableRow>
            ) : (
              filteredRecords.map((record) => (
                  <TableRow key={record.id}>
                    <TableCell className={isRTL ? "text-right" : "text-left"}>
                      {record.students?.roll_number}
                    </TableCell>
                    <TableCell className={isRTL ? "text-right font-medium" : "text-left font-medium"}>
                      {record.students?.name}
                    </TableCell>
                    <TableCell className={isRTL ? "text-right" : "text-left"}>
                      {monthNames[record.month - 1]} {record.year}
                    </TableCell>
                    <TableCell className={isRTL ? "text-right" : "text-left"}>
                      PKR {Number(record.amount).toLocaleString()}
                    </TableCell>
                    <TableCell className={isRTL ? "text-right" : "text-left"}>
                      PKR {(Number(record.paid_amount) || 0).toLocaleString()}
                    </TableCell>
                    <TableCell className={isRTL ? "text-right" : "text-left"}>
                      PKR {(Number(record.amount) - (Number(record.paid_amount) || 0)).toLocaleString()}
                    </TableCell>
                    <TableCell className={isRTL ? "text-right" : "text-left"}>
                      <Badge
                        variant={
                          record.status === "paid"
                            ? "default"
                            : "destructive"
                        }
                      >
                        {record.status === "paid" ? t("paid") : t("pending")}
                      </Badge>
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

export default Fees;