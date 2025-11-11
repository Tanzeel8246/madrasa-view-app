import { useState, useEffect } from "react";
import { useLanguage } from "@/contexts/LanguageContext";
import { supabase } from "@/integrations/supabase/client";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from "@/components/ui/table";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";
import { Label } from "@/components/ui/label";
import { FileDown, Filter, RefreshCw } from "lucide-react";
import { toast } from "sonner";
import StatCard from "@/components/StatCard";
import { DollarSign, TrendingUp, TrendingDown, Wallet } from "lucide-react";

type ReportType = "income" | "expense" | "salary" | "fee" | "loan";

interface ReportData {
  id: string;
  title?: string;
  category?: string;
  amount: number;
  date: string;
  status?: string;
  teacher_name?: string;
  student_name?: string;
}

const Reports = () => {
  const { t, language } = useLanguage();
  const [reportType, setReportType] = useState<ReportType>("income");
  const [reportData, setReportData] = useState<ReportData[]>([]);
  const [fromDate, setFromDate] = useState("");
  const [toDate, setToDate] = useState("");
  const [totalAmount, setTotalAmount] = useState(0);

  useEffect(() => {
    const now = new Date();
    const firstDay = new Date(now.getFullYear(), now.getMonth(), 1);
    setFromDate(firstDay.toISOString().split("T")[0]);
    setToDate(now.toISOString().split("T")[0]);
  }, []);

  useEffect(() => {
    if (fromDate && toDate) {
      fetchReportData();
    }
  }, [reportType, fromDate, toDate]);

  const fetchReportData = async () => {
    try {
      let data: any[] = [];
      
      switch (reportType) {
        case "income":
          const { data: incomeData } = await supabase
            .from("income")
            .select("*")
            .gte("date", fromDate)
            .lte("date", toDate)
            .order("date", { ascending: false });
          data = incomeData || [];
          break;

        case "expense":
          const { data: expenseData } = await supabase
            .from("expense")
            .select("*")
            .gte("date", fromDate)
            .lte("date", toDate)
            .order("date", { ascending: false });
          data = expenseData || [];
          break;

        case "salary":
          const { data: salaryData } = await supabase
            .from("salaries")
            .select("*, teachers (name)")
            .order("year", { ascending: false })
            .order("month", { ascending: false });

          data = salaryData?.map((item: any) => ({
            ...item,
            teacher_name: item.teachers?.name,
            date: `${item.year}-${String(item.month).padStart(2, "0")}-01`,
          })) || [];
          break;

        case "fee":
          const { data: feeData } = await supabase
            .from("fees")
            .select("*, students (name, roll_number)")
            .gte("payment_date", fromDate)
            .lte("payment_date", toDate)
            .order("payment_date", { ascending: false });

          data = feeData?.map((item: any) => ({
            ...item,
            student_name: item.students?.name,
            date: item.payment_date,
          })) || [];
          break;

        case "loan":
          const { data: loanData } = await supabase
            .from("loans")
            .select("*, teachers (name)")
            .gte("loan_date", fromDate)
            .lte("loan_date", toDate)
            .order("loan_date", { ascending: false });

          data = loanData?.map((item: any) => ({
            ...item,
            teacher_name: item.teachers?.name,
            date: item.loan_date,
          })) || [];
          break;
      }

      setReportData(data);
      const total = data.reduce((sum, item) => sum + Number(item.amount || 0), 0);
      setTotalAmount(total);
    } catch (error) {
      toast.error(language === "ur" ? "ڈیٹا لوڈ میں خرابی" : "Error loading data");
    }
  };

  const exportToCSV = () => {
    if (reportData.length === 0) {
      toast.error(language === "ur" ? "کوئی ڈیٹا نہیں ہے" : "No data to export");
      return;
    }

    const headers = Object.keys(reportData[0]).join(",");
    const rows = reportData.map((row) => Object.values(row).join(","));
    const csv = [headers, ...rows].join("\n");

    const blob = new Blob([csv], { type: "text/csv" });
    const url = window.URL.createObjectURL(blob);
    const a = document.createElement("a");
    a.href = url;
    a.download = `${reportType}_report_${fromDate}_to_${toDate}.csv`;
    a.click();

    toast.success(language === "ur" ? "رپورٹ ڈاؤن لوڈ ہو گئی" : "Report downloaded");
  };

  return (
    <div className="space-y-6">
      <div className="flex items-center justify-between">
        <h1 className="text-3xl font-bold text-foreground">
          {t("detailedReports")}
        </h1>
        <div className="flex gap-2">
          <Button variant="outline" onClick={exportToCSV}>
            <FileDown className="h-4 w-4 mr-2" />
            {t("exportPDF")}
          </Button>
          <Button variant="outline" onClick={fetchReportData}>
            <RefreshCw className="h-4 w-4" />
          </Button>
        </div>
      </div>

      <div className="bg-card rounded-lg border p-6 space-y-4">
        <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
          <div className="space-y-2">
            <Label>{t("selectCategory")}</Label>
            <Select value={reportType} onValueChange={(value: ReportType) => setReportType(value)}>
              <SelectTrigger>
                <SelectValue />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value="income">{t("incomeReport")}</SelectItem>
                <SelectItem value="expense">{t("expenseReport")}</SelectItem>
                <SelectItem value="salary">{t("salaryReport")}</SelectItem>
                <SelectItem value="fee">{t("feeReport")}</SelectItem>
                <SelectItem value="loan">{t("loanReport")}</SelectItem>
              </SelectContent>
            </Select>
          </div>

          <div className="space-y-2">
            <Label>{t("fromDate")}</Label>
            <Input
              type="date"
              value={fromDate}
              onChange={(e) => setFromDate(e.target.value)}
            />
          </div>

          <div className="space-y-2">
            <Label>{t("toDate")}</Label>
            <Input
              type="date"
              value={toDate}
              onChange={(e) => setToDate(e.target.value)}
            />
          </div>
        </div>

        <Button onClick={fetchReportData} className="w-full md:w-auto">
          <Filter className="h-4 w-4 mr-2" />
          {t("applyFilter")}
        </Button>
      </div>

      <StatCard
        title={
          reportType === "income" ? t("totalIncome") :
          reportType === "expense" ? t("totalExpense") :
          reportType === "salary" ? t("totalSalaries") :
          reportType === "fee" ? t("totalIncome") : t("totalLoans")
        }
        value={`Rs. ${totalAmount.toLocaleString()}`}
        icon={
          reportType === "income" ? TrendingUp :
          reportType === "expense" ? TrendingDown :
          reportType === "salary" ? Wallet : DollarSign
        }
      />

      <div className="bg-card rounded-lg border">
        <Table>
          <TableHeader>
            <TableRow>
              {reportType === "income" && (
                <>
                  <TableHead>{t("title")}</TableHead>
                  <TableHead>{t("category")}</TableHead>
                  <TableHead>{t("amount")}</TableHead>
                  <TableHead>{t("date")}</TableHead>
                  <TableHead>{t("description")}</TableHead>
                </>
              )}
              {reportType === "expense" && (
                <>
                  <TableHead>{t("title")}</TableHead>
                  <TableHead>{t("category")}</TableHead>
                  <TableHead>{t("amount")}</TableHead>
                  <TableHead>{t("date")}</TableHead>
                  <TableHead>{t("description")}</TableHead>
                </>
              )}
              {reportType === "salary" && (
                <>
                  <TableHead>{t("teacherName")}</TableHead>
                  <TableHead>{t("month")}</TableHead>
                  <TableHead>{t("year")}</TableHead>
                  <TableHead>{t("amount")}</TableHead>
                  <TableHead>{t("status")}</TableHead>
                </>
              )}
              {reportType === "fee" && (
                <>
                  <TableHead>{t("studentName")}</TableHead>
                  <TableHead>{t("rollNumber")}</TableHead>
                  <TableHead>{t("amount")}</TableHead>
                  <TableHead>{t("paidAmount")}</TableHead>
                  <TableHead>{t("paymentDate")}</TableHead>
                  <TableHead>{t("status")}</TableHead>
                </>
              )}
              {reportType === "loan" && (
                <>
                  <TableHead>{t("teacherName")}</TableHead>
                  <TableHead>{t("loanAmount")}</TableHead>
                  <TableHead>{t("paidAmount")}</TableHead>
                  <TableHead>{t("pendingAmount")}</TableHead>
                  <TableHead>{t("loanDate")}</TableHead>
                  <TableHead>{t("status")}</TableHead>
                </>
              )}
            </TableRow>
          </TableHeader>
          <TableBody>
            {reportData.length === 0 ? (
              <TableRow>
                <TableCell colSpan={6} className="text-center text-muted-foreground">
                  {language === "ur" ? "کوئی ریکارڈ نہیں ملا" : "No records found"}
                </TableCell>
              </TableRow>
            ) : (
              reportData.map((record: any) => (
                <TableRow key={record.id}>
                  {reportType === "income" && (
                    <>
                      <TableCell>{record.title}</TableCell>
                      <TableCell>{record.category}</TableCell>
                      <TableCell>Rs. {Number(record.amount).toLocaleString()}</TableCell>
                      <TableCell>{new Date(record.date).toLocaleDateString()}</TableCell>
                      <TableCell>{record.description || "-"}</TableCell>
                    </>
                  )}
                  {reportType === "expense" && (
                    <>
                      <TableCell>{record.title}</TableCell>
                      <TableCell>{record.category}</TableCell>
                      <TableCell>Rs. {Number(record.amount).toLocaleString()}</TableCell>
                      <TableCell>{new Date(record.date).toLocaleDateString()}</TableCell>
                      <TableCell>{record.description || "-"}</TableCell>
                    </>
                  )}
                  {reportType === "salary" && (
                    <>
                      <TableCell>{record.teacher_name}</TableCell>
                      <TableCell>{record.month}</TableCell>
                      <TableCell>{record.year}</TableCell>
                      <TableCell>Rs. {Number(record.amount).toLocaleString()}</TableCell>
                      <TableCell>{record.status === "paid" ? t("paid") : t("pending")}</TableCell>
                    </>
                  )}
                  {reportType === "fee" && (
                    <>
                      <TableCell>{record.student_name}</TableCell>
                      <TableCell>{record.students?.roll_number}</TableCell>
                      <TableCell>Rs. {Number(record.amount).toLocaleString()}</TableCell>
                      <TableCell>Rs. {Number(record.paid_amount).toLocaleString()}</TableCell>
                      <TableCell>{new Date(record.date).toLocaleDateString()}</TableCell>
                      <TableCell>{record.status === "paid" ? t("paid") : t("pending")}</TableCell>
                    </>
                  )}
                  {reportType === "loan" && (
                    <>
                      <TableCell>{record.teacher_name}</TableCell>
                      <TableCell>Rs. {Number(record.amount).toLocaleString()}</TableCell>
                      <TableCell>Rs. {Number(record.paid_amount || 0).toLocaleString()}</TableCell>
                      <TableCell>Rs. {(Number(record.amount) - Number(record.paid_amount || 0)).toLocaleString()}</TableCell>
                      <TableCell>{new Date(record.date).toLocaleDateString()}</TableCell>
                      <TableCell>{record.status === "paid" ? t("paid") : t("pending")}</TableCell>
                    </>
                  )}
                </TableRow>
              ))
            )}
          </TableBody>
        </Table>
      </div>
    </div>
  );
};

export default Reports;
