import { useState, useEffect } from "react";
import { useLanguage } from "@/contexts/LanguageContext";
import { supabase } from "@/integrations/supabase/client";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Plus, RefreshCw, Search, FileDown, Printer } from "lucide-react";
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

interface LoanRecord {
  id: string;
  teacher_id: string;
  teacher_name: string;
  amount: number;
  loan_date: string;
  return_date: string | null;
  status: string;
  paid_amount: number;
  notes: string | null;
}

interface Teacher {
  id: string;
  name: string;
}

const Loans = () => {
  const { t, language } = useLanguage();
  const [loanRecords, setLoanRecords] = useState<LoanRecord[]>([]);
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
    loan_date: new Date().toISOString().split("T")[0],
    status: "pending",
    paid_amount: "0",
    notes: "",
  });

  useEffect(() => {
    fetchTeachers();
    fetchLoans();
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

  const fetchLoans = async () => {
    const { data, error } = await supabase
      .from("loans")
      .select(`
        id,
        teacher_id,
        amount,
        loan_date,
        return_date,
        status,
        paid_amount,
        notes,
        teachers (name)
      `)
      .order("loan_date", { ascending: false });

    if (error) {
      toast.error(language === "ur" ? "ڈیٹا لوڈ میں خرابی" : "Error loading data");
      return;
    }

    const formattedData = data.map((record: any) => ({
      id: record.id,
      teacher_id: record.teacher_id,
      teacher_name: record.teachers?.name || "",
      amount: parseFloat(record.amount),
      loan_date: record.loan_date,
      return_date: record.return_date,
      status: record.status,
      paid_amount: parseFloat(record.paid_amount || 0),
      notes: record.notes,
    }));

    setLoanRecords(formattedData);

    const total = formattedData.reduce((sum, record) => sum + record.amount, 0);
    const paid = formattedData.reduce((sum, record) => sum + record.paid_amount, 0);
    const pending = total - paid;

    setTotalStats({ total, paid, pending });
  };

  const handleAddLoan = async (e: React.FormEvent) => {
    e.preventDefault();

    const { error } = await supabase.from("loans").insert([
      {
        teacher_id: formData.teacher_id,
        amount: parseFloat(formData.amount),
        loan_date: formData.loan_date,
        status: formData.status,
        paid_amount: parseFloat(formData.paid_amount),
        notes: formData.notes || null,
      },
    ]);

    if (error) {
      toast.error(language === "ur" ? "ڈیٹا محفوظ کرنے میں خرابی" : "Error saving data");
      return;
    }

    toast.success(language === "ur" ? "قرضہ شامل کر دیا گیا" : "Loan added successfully");
    setIsDialogOpen(false);
    setFormData({
      teacher_id: "",
      amount: "",
      loan_date: new Date().toISOString().split("T")[0],
      status: "pending",
      paid_amount: "0",
      notes: "",
    });
    fetchLoans();
  };

  const filteredRecords = loanRecords.filter((record) =>
    record.teacher_name.toLowerCase().includes(searchQuery.toLowerCase())
  );

  const handleExportPDF = () => {
    const headers = [
      t("teacherName"),
      t("loanAmount"),
      t("paidAmount"),
      t("pendingAmount"),
      t("loanDate"),
      t("status"),
      t("notes"),
    ];
    const data = filteredRecords.map(r => {
      const pending = r.amount - r.paid_amount;
      return [
        r.teacher_name,
        `Rs. ${r.amount.toLocaleString()}`,
        `Rs. ${r.paid_amount.toLocaleString()}`,
        `Rs. ${pending.toLocaleString()}`,
        new Date(r.loan_date).toLocaleDateString(),
        r.status === "paid" ? t("paid") : r.status === "partial" ? t("partial") : t("pending"),
        r.notes || "-",
      ];
    });
    generatePDF(
      t("loanManagement"),
      headers,
      data,
      "loans_list.pdf",
      language === "ur"
    );
  };

  const handlePrint = () => {
    printTable("loans-table");
  };

  return (
    <div className="space-y-6">
      <div className="flex items-center justify-between">
        <h1 className="text-3xl font-bold text-foreground">
          {t("loanManagement")}
        </h1>
        <div className="flex gap-2">
          <Button variant="outline" onClick={handleExportPDF} size="sm">
            <FileDown className="h-4 w-4 mr-2" />
            PDF
          </Button>
          <Button variant="outline" onClick={handlePrint} size="sm">
            <Printer className="h-4 w-4 mr-2" />
            {language === "ur" ? "پرنٹ" : "Print"}
          </Button>
          <Dialog open={isDialogOpen} onOpenChange={setIsDialogOpen}>
            <DialogTrigger asChild>
              <Button>
                <Plus className="h-4 w-4 mr-2" />
                {t("addLoan")}
              </Button>
            </DialogTrigger>
            <DialogContent className="sm:max-w-[500px]">
              <DialogHeader>
                <DialogTitle>{t("addLoan")}</DialogTitle>
              </DialogHeader>
              <form onSubmit={handleAddLoan} className="space-y-4">
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

                <div className="space-y-2">
                  <Label>{t("loanAmount")}</Label>
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
                  <Label>{t("loanDate")}</Label>
                  <Input
                    type="date"
                    value={formData.loan_date}
                    onChange={(e) =>
                      setFormData({ ...formData, loan_date: e.target.value })
                    }
                    required
                  />
                </div>

                <div className="space-y-2">
                  <Label>{t("paidAmount")}</Label>
                  <Input
                    type="number"
                    value={formData.paid_amount}
                    onChange={(e) =>
                      setFormData({ ...formData, paid_amount: e.target.value })
                    }
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
                      <SelectItem value="pending">{t("pending")}</SelectItem>
                      <SelectItem value="partial">{t("partial")}</SelectItem>
                      <SelectItem value="paid">{t("paid")}</SelectItem>
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

          <Button variant="outline" onClick={fetchLoans}>
            <RefreshCw className="h-4 w-4" />
          </Button>
        </div>
      </div>

      <div className="grid gap-4 md:grid-cols-3">
        <StatCard
          title={t("totalLoans")}
          value={`Rs. ${totalStats.total.toLocaleString()}`}
          icon={DollarSign}
        />
        <StatCard
          title={t("paidLoans")}
          value={`Rs. ${totalStats.paid.toLocaleString()}`}
          icon={DollarSign}
        />
        <StatCard
          title={t("pendingLoans")}
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

      <div className="bg-card rounded-lg border">
        <Table id="loans-table">
          <TableHeader>
            <TableRow>
              <TableHead>{t("teacherName")}</TableHead>
              <TableHead>{t("loanAmount")}</TableHead>
              <TableHead>{t("paidAmount")}</TableHead>
              <TableHead>{t("pendingAmount")}</TableHead>
              <TableHead>{t("loanDate")}</TableHead>
              <TableHead>{t("status")}</TableHead>
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
              filteredRecords.map((record) => {
                const pending = record.amount - record.paid_amount;
                return (
                  <TableRow key={record.id}>
                    <TableCell className="font-medium">{record.teacher_name}</TableCell>
                    <TableCell>Rs. {record.amount.toLocaleString()}</TableCell>
                    <TableCell>Rs. {record.paid_amount.toLocaleString()}</TableCell>
                    <TableCell>Rs. {pending.toLocaleString()}</TableCell>
                    <TableCell>
                      {new Date(record.loan_date).toLocaleDateString()}
                    </TableCell>
                    <TableCell>
                      <Badge
                        variant={
                          record.status === "paid"
                            ? "default"
                            : record.status === "partial"
                            ? "secondary"
                            : "outline"
                        }
                      >
                        {record.status === "paid"
                          ? t("paid")
                          : record.status === "partial"
                          ? t("partial")
                          : t("pending")}
                      </Badge>
                    </TableCell>
                    <TableCell>{record.notes || "-"}</TableCell>
                  </TableRow>
                );
              })
            )}
          </TableBody>
        </Table>
      </div>
    </div>
  );
};

export default Loans;
