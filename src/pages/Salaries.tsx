import { useState, useEffect } from "react";
import { useLanguage } from "@/contexts/LanguageContext";
import { supabase } from "@/integrations/supabase/client";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Plus, RefreshCw, Search, FileDown, Printer } from "lucide-react";
import { generatePDF, printTable } from "@/lib/pdfUtils";
import { getMadrasahId } from "@/lib/madrasahUtils";
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
  DialogFooter,
} from "@/components/ui/dialog";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";
import { Label } from "@/components/ui/label";
import { Badge } from "@/components/ui/badge";
import { toast } from "sonner";
import StatCard from "@/components/StatCard";
import { DollarSign } from "lucide-react";

interface SalaryRecord {
  id: string;
  teacher_id: string;
  teacher_name: string;
  amount: number;
  month: number;
  year: number;
  payment_date: string | null;
  status: string;
  notes: string | null;
}

interface Teacher {
  id: string;
  name: string;
}

const Salaries = () => {
  const { t, language } = useLanguage();
  const [salaryRecords, setSalaryRecords] = useState<SalaryRecord[]>([]);
  const [teachers, setTeachers] = useState<Teacher[]>([]);
  const [searchQuery, setSearchQuery] = useState("");
  const [isDialogOpen, setIsDialogOpen] = useState(false);
  const [totalStats, setTotalStats] = useState({
    total: 0,
    paid: 0,
    pending: 0,
  });

  const [formData, setFormData] = useState({
    teacher_id: "",
    amount: "",
    month: new Date().getMonth() + 1,
    year: new Date().getFullYear(),
    status: "pending",
    notes: "",
  });

  const months = [
    { value: 1, label: language === "ur" ? "جنوری" : "January" },
    { value: 2, label: language === "ur" ? "فروری" : "February" },
    { value: 3, label: language === "ur" ? "مارچ" : "March" },
    { value: 4, label: language === "ur" ? "اپریل" : "April" },
    { value: 5, label: language === "ur" ? "مئی" : "May" },
    { value: 6, label: language === "ur" ? "جون" : "June" },
    { value: 7, label: language === "ur" ? "جولائی" : "July" },
    { value: 8, label: language === "ur" ? "اگست" : "August" },
    { value: 9, label: language === "ur" ? "ستمبر" : "September" },
    { value: 10, label: language === "ur" ? "اکتوبر" : "October" },
    { value: 11, label: language === "ur" ? "نومبر" : "November" },
    { value: 12, label: language === "ur" ? "دسمبر" : "December" },
  ];

  useEffect(() => {
    fetchTeachers();
    fetchSalaries();
  }, []);

  const fetchTeachers = async () => {
    const { data, error } = await supabase
      .from("teachers")
      .select("id, name")
      .order("name");

    if (error) {
      toast.error(language === "ur" ? "ڈیٹا لوڈ میں خرابی" : "Error loading data");
      return;
    }

    setTeachers(data || []);
  };

  const fetchSalaries = async () => {
    const { data, error } = await supabase
      .from("salaries")
      .select(`
        id,
        teacher_id,
        amount,
        month,
        year,
        payment_date,
        status,
        notes,
        teachers (name)
      `)
      .order("year", { ascending: false })
      .order("month", { ascending: false });

    if (error) {
      toast.error(language === "ur" ? "ڈیٹا لوڈ میں خرابی" : "Error loading data");
      return;
    }

    const formattedData = data.map((record: any) => ({
      id: record.id,
      teacher_id: record.teacher_id,
      teacher_name: record.teachers?.name || "",
      amount: parseFloat(record.amount),
      month: record.month,
      year: record.year,
      payment_date: record.payment_date,
      status: record.status,
      notes: record.notes,
    }));

    setSalaryRecords(formattedData);

    const total = formattedData.reduce((sum, record) => sum + record.amount, 0);
    const paid = formattedData
      .filter((r) => r.status === "paid")
      .reduce((sum, record) => sum + record.amount, 0);
    const pending = total - paid;

    setTotalStats({ total, paid, pending });
  };

  const handleAddSalary = async (e: React.FormEvent) => {
    e.preventDefault();

    const madrasahId = await getMadrasahId();
    const { error } = await supabase.from("salaries").insert([
      {
        teacher_id: formData.teacher_id,
        amount: parseFloat(formData.amount),
        month: formData.month,
        year: formData.year,
        status: formData.status,
        payment_date: formData.status === "paid" ? new Date().toISOString() : null,
        notes: formData.notes || null,
        madrasah_id: madrasahId,
      },
    ]);

    if (error) {
      toast.error(language === "ur" ? "ڈیٹا محفوظ کرنے میں خرابی" : "Error saving data");
      return;
    }

    toast.success(language === "ur" ? "تنخواہ شامل کر دی گئی" : "Salary added successfully");
    setIsDialogOpen(false);
    setFormData({
      teacher_id: "",
      amount: "",
      month: new Date().getMonth() + 1,
      year: new Date().getFullYear(),
      status: "pending",
      notes: "",
    });
    fetchSalaries();
  };

  const filteredRecords = salaryRecords.filter((record) =>
    record.teacher_name.toLowerCase().includes(searchQuery.toLowerCase())
  );

  const handleExportPDF = () => {
    const headers = [
      t("teacherName"),
      t("month"),
      t("year"),
      t("amount"),
      t("status"),
      t("paymentDate"),
      t("notes"),
    ];
    const data = filteredRecords.map(r => [
      r.teacher_name,
      months.find((m) => m.value === r.month)?.label || r.month,
      r.year,
      `Rs. ${r.amount.toLocaleString()}`,
      r.status === "paid" ? t("paid") : t("pending"),
      r.payment_date ? new Date(r.payment_date).toLocaleDateString() : "-",
      r.notes || "-",
    ]);
    generatePDF(
      t("salaryManagement"),
      headers,
      data,
      "salaries_list.pdf",
      language === "ur"
    );
  };

  const handlePrint = () => {
    printTable("salaries-table");
  };

  return (
    <div className="space-y-4 md:space-y-6">
      <div className="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-3">
        <h1 className="text-2xl md:text-3xl font-bold text-foreground">
          {t("salaryManagement")}
        </h1>
        <div className="flex flex-wrap gap-2">
          <Button variant="outline" onClick={handleExportPDF} size="sm">
            <FileDown className="h-3 w-3 md:h-4 md:w-4 mr-1 md:mr-2" />
            <span className="text-xs md:text-sm">PDF</span>
          </Button>
          <Button variant="outline" onClick={handlePrint} size="sm">
            <Printer className="h-3 w-3 md:h-4 md:w-4 mr-1 md:mr-2" />
            <span className="text-xs md:text-sm">{language === "ur" ? "پرنٹ" : "Print"}</span>
          </Button>
          <Dialog open={isDialogOpen} onOpenChange={setIsDialogOpen}>
            <DialogTrigger asChild>
              <Button size="sm">
                <Plus className="h-3 w-3 md:h-4 md:w-4 mr-1 md:mr-2" />
                <span className="text-xs md:text-sm">{t("addSalary")}</span>
              </Button>
            </DialogTrigger>
            <DialogContent className="sm:max-w-[500px]">
              <DialogHeader>
                <DialogTitle>{t("addSalary")}</DialogTitle>
              </DialogHeader>
              <form onSubmit={handleAddSalary} className="space-y-4">
                <div className="space-y-2">
                  <Label>{t("selectTeacher")}</Label>
                  <Select
                    value={formData.teacher_id}
                    onValueChange={(value) =>
                      setFormData({ ...formData, teacher_id: value })
                    }
                    required
                  >
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

                <div className="grid grid-cols-2 gap-4">
                  <div className="space-y-2">
                    <Label>{t("selectMonth")}</Label>
                    <Select
                      value={formData.month.toString()}
                      onValueChange={(value) =>
                        setFormData({ ...formData, month: parseInt(value) })
                      }
                    >
                      <SelectTrigger>
                        <SelectValue />
                      </SelectTrigger>
                      <SelectContent>
                        {months.map((month) => (
                          <SelectItem key={month.value} value={month.value.toString()}>
                            {month.label}
                          </SelectItem>
                        ))}
                      </SelectContent>
                    </Select>
                  </div>

                  <div className="space-y-2">
                    <Label>{t("selectYear")}</Label>
                    <Input
                      type="number"
                      value={formData.year}
                      onChange={(e) =>
                        setFormData({ ...formData, year: parseInt(e.target.value) })
                      }
                      required
                    />
                  </div>
                </div>

                <div className="space-y-2">
                  <Label>{t("amount")}</Label>
                  <Input
                    type="number"
                    value={formData.amount}
                    onChange={(e) =>
                      setFormData({ ...formData, amount: e.target.value })
                    }
                    required
                  />
                </div>

                <div className="space-y-2">
                  <Label>{t("status")}</Label>
                  <Select
                    value={formData.status}
                    onValueChange={(value) =>
                      setFormData({ ...formData, status: value })
                    }
                  >
                    <SelectTrigger>
                      <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                      <SelectItem value="paid">{t("paid")}</SelectItem>
                      <SelectItem value="pending">{t("pending")}</SelectItem>
                    </SelectContent>
                  </Select>
                </div>

                <div className="space-y-2">
                  <Label>{t("notes")}</Label>
                  <Input
                    value={formData.notes}
                    onChange={(e) =>
                      setFormData({ ...formData, notes: e.target.value })
                    }
                  />
                </div>

                <DialogFooter>
                  <Button type="submit">{t("save")}</Button>
                </DialogFooter>
              </form>
            </DialogContent>
          </Dialog>

          <Button variant="outline" onClick={fetchSalaries}>
            <RefreshCw className="h-4 w-4" />
          </Button>
        </div>
      </div>

      <div className="grid gap-3 md:gap-4 grid-cols-1 sm:grid-cols-2 lg:grid-cols-3">
        <StatCard
          title={t("totalSalaries")}
          value={`Rs. ${totalStats.total.toLocaleString()}`}
          icon={DollarSign}
        />
        <StatCard
          title={t("paidSalaries")}
          value={`Rs. ${totalStats.paid.toLocaleString()}`}
          icon={DollarSign}
        />
        <StatCard
          title={t("pendingSalaries")}
          value={`Rs. ${totalStats.pending.toLocaleString()}`}
          icon={DollarSign}
        />
      </div>

      <div className="flex items-center gap-4">
        <div className="relative flex-1">
          <Search className="absolute left-3 top-1/2 transform -translate-y-1/2 h-4 w-4 text-muted-foreground" />
          <Input
            placeholder={language === "ur" ? "استاد کا نام تلاش کریں" : "Search teacher name"}
            value={searchQuery}
            onChange={(e) => setSearchQuery(e.target.value)}
            className="pl-10"
          />
        </div>
      </div>

      <div className="bg-card rounded-lg border overflow-x-auto">
        <Table id="salaries-table">
          <TableHeader>
            <TableRow>
              <TableHead>{t("teacherName")}</TableHead>
              <TableHead>{t("month")}</TableHead>
              <TableHead>{t("year")}</TableHead>
              <TableHead>{t("amount")}</TableHead>
              <TableHead>{t("status")}</TableHead>
              <TableHead>{t("paymentDate")}</TableHead>
              <TableHead>{t("notes")}</TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            {filteredRecords.length === 0 ? (
              <TableRow>
                <TableCell colSpan={7} className="text-center text-muted-foreground">
                  {language === "ur" ? "کوئی ریکارڈ نہیں ملا" : "No records found"}
                </TableCell>
              </TableRow>
            ) : (
              filteredRecords.map((record) => (
                <TableRow key={record.id}>
                  <TableCell className="font-medium">{record.teacher_name}</TableCell>
                  <TableCell>
                    {months.find((m) => m.value === record.month)?.label}
                  </TableCell>
                  <TableCell>{record.year}</TableCell>
                  <TableCell>Rs. {record.amount.toLocaleString()}</TableCell>
                  <TableCell>
                    <Badge variant={record.status === "paid" ? "default" : "secondary"}>
                      {record.status === "paid" ? t("paid") : t("pending")}
                    </Badge>
                  </TableCell>
                  <TableCell>
                    {record.payment_date
                      ? new Date(record.payment_date).toLocaleDateString()
                      : "-"}
                  </TableCell>
                  <TableCell>{record.notes || "-"}</TableCell>
                </TableRow>
              ))
            )}
          </TableBody>
        </Table>
      </div>
    </div>
  );
};

export default Salaries;
