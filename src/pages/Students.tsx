import { useEffect, useMemo, useState } from "react";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { useLanguage } from "@/contexts/LanguageContext";
import { Search, FileDown, Printer } from "lucide-react";
import { generatePDF, printTable } from "@/lib/pdfUtils";
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from "@/components/ui/table";
import AddStudentDialog from "@/components/students/AddStudentDialog";
import { supabase } from "@/integrations/supabase/client";
import { cn } from "@/lib/utils";

type StudentRow = {
  id: string;
  name: string;
  father_name: string;
  roll_number: string;
  contact: string | null;
  address: string | null;
  classes: { name: string } | null;
};

const Students = () => {
  const { t, isRTL } = useLanguage();
  const [searchQuery, setSearchQuery] = useState("");
  const [students, setStudents] = useState<StudentRow[]>([]);
  const [loading, setLoading] = useState(false);

  useEffect(() => {
    document.title = isRTL ? "طلباء کی فہرست - مدرسہ" : "Students - Madrasa";
  }, [isRTL]);

  const fetchStudents = async () => {
    setLoading(true);
    const { data, error } = await supabase
      .from("students")
      .select(`
        id,
        name,
        father_name,
        roll_number,
        contact,
        address,
        classes (
          name
        )
      `)
      .order("roll_number", { ascending: true });
    if (!error && data) setStudents(data);
    setLoading(false);
  };

  useEffect(() => {
    fetchStudents();
  }, []);

  const filtered = useMemo(() => {
    const q = searchQuery.trim().toLowerCase();
    if (!q) return students;
    return students.filter((s) =>
      [s.name, s.father_name, s.roll_number, s.classes?.name, s.contact, s.address]
        .filter(Boolean)
        .some((v) => String(v).toLowerCase().includes(q)),
    );
  }, [searchQuery, students]);

  const handleExportPDF = () => {
    const headers = [
      isRTL ? "رول نمبر" : "Roll Number",
      isRTL ? "طالب علم کا نام" : "Student Name",
      isRTL ? "والد کا نام" : "Father Name",
      isRTL ? "کلاس" : "Class",
      isRTL ? "رابطہ" : "Contact",
      isRTL ? "پتہ" : "Address",
    ];
    const data = filtered.map(s => [
      s.roll_number,
      s.name,
      s.father_name,
      s.classes?.name || "-",
      s.contact || "-",
      s.address || "-",
    ]);
    generatePDF(
      isRTL ? "طلباء کی فہرست" : "Students List",
      headers,
      data,
      "students_list.pdf",
      isRTL
    );
  };

  const handlePrint = () => {
    printTable("students-table", isRTL ? "طلباء کی فہرست" : "Students List", isRTL);
  };

  return (
    <div className="space-y-4 md:space-y-6">
      <div className="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-3">
        <h1 className="text-2xl md:text-3xl font-bold text-foreground">{t("studentsList")}</h1>
        <div className="flex flex-wrap items-center gap-2">
          <Button variant="outline" onClick={handleExportPDF} size="sm">
            <FileDown className="h-3 w-3 md:h-4 md:w-4 mr-1 md:mr-2" />
            <span className="text-xs md:text-sm">PDF</span>
          </Button>
          <Button variant="outline" onClick={handlePrint} size="sm">
            <Printer className="h-3 w-3 md:h-4 md:w-4 mr-1 md:mr-2" />
            <span className="text-xs md:text-sm">{isRTL ? "پرنٹ" : "Print"}</span>
          </Button>
          <AddStudentDialog onAdded={fetchStudents} />
          <Button variant="secondary" onClick={fetchStudents} disabled={loading} size="sm">
            {loading ? t("loading") : t("refresh")}
          </Button>
        </div>
      </div>

      <div className="flex gap-4">
        <div className="relative flex-1">
          <Search
            className={cn(
              "absolute top-1/2 -translate-y-1/2 text-muted-foreground w-4 h-4",
              isRTL ? "right-3" : "left-3",
            )}
          />
          <Input
            placeholder={t("search")}
            value={searchQuery}
            onChange={(e) => setSearchQuery(e.target.value)}
            className={cn(isRTL ? "pr-10" : "pl-10")}
          />
        </div>
      </div>

      <div className="bg-card rounded-lg border overflow-x-auto">
        <Table id="students-table">
          <TableHeader>
            <TableRow>
              <TableHead className={isRTL ? "text-right" : "text-left"}>{t("rollNumber")}</TableHead>
              <TableHead className={isRTL ? "text-right" : "text-left"}>{t("studentName")}</TableHead>
              <TableHead className={isRTL ? "text-right" : "text-left"}>{t("fatherName")}</TableHead>
              <TableHead className={isRTL ? "text-right" : "text-left"}>{t("class")}</TableHead>
              <TableHead className={isRTL ? "text-right" : "text-left"}>{t("contact")}</TableHead>
              <TableHead className={isRTL ? "text-right" : "text-left"}>{t("address")}</TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            {filtered.map((student) => (
              <TableRow key={student.id}>
                <TableCell className={isRTL ? "text-right" : "text-left"}>{student.roll_number}</TableCell>
                <TableCell className={cn(isRTL ? "text-right" : "text-left", "font-medium")}>{student.name}</TableCell>
                <TableCell className={isRTL ? "text-right" : "text-left"}>{student.father_name}</TableCell>
                <TableCell className={isRTL ? "text-right" : "text-left"}>{student.classes?.name || "-"}</TableCell>
                <TableCell className={isRTL ? "text-right" : "text-left"}>{student.contact || "-"}</TableCell>
                <TableCell className={isRTL ? "text-right" : "text-left"}>{student.address || "-"}</TableCell>
              </TableRow>
            ))}
            {filtered.length === 0 && (
              <TableRow>
                <TableCell colSpan={6} className="text-center text-muted-foreground">
                  {t("noRecordsFound")}
                </TableCell>
              </TableRow>
            )}
          </TableBody>
        </Table>
      </div>
    </div>
  );
};

export default Students;