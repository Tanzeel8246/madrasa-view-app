import { useState, useEffect, useMemo } from "react";
import { useLanguage } from "@/contexts/LanguageContext";
import { useUserRole } from "@/hooks/useUserRole";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Search, RefreshCw, FileDown, Printer } from "lucide-react";
import { generatePDF, printTable } from "@/lib/pdfUtils";
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from "@/components/ui/table";
import { supabase } from "@/integrations/supabase/client";
import { useToast } from "@/hooks/use-toast";
import AddTeacherDialog from "@/components/teachers/AddTeacherDialog";

type TeacherRow = {
  id: string;
  name: string;
  contact: string | null;
  email: string | null;
  subject: string | null;
  qualification: string | null;
  class_teachers?: Array<{
    classes: {
      name: string;
    };
  }>;
};

const Teachers = () => {
  const { t, isRTL } = useLanguage();
  const { canAddTeachers } = useUserRole();
  const { toast } = useToast();
  const [searchQuery, setSearchQuery] = useState("");
  const [teachers, setTeachers] = useState<TeacherRow[]>([]);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    document.title = `${t("teachers")} - مدرسہ مینجمنٹ`;
  }, [t]);

  const fetchTeachers = async () => {
    try {
      setLoading(true);
      const { data, error } = await supabase
        .from("teachers")
        .select(`
          *,
          class_teachers (
            classes (
              name
            )
          )
        `)
        .order("name");

      if (error) throw error;
      setTeachers(data || []);
    } catch (error) {
      console.error("Error fetching teachers:", error);
      toast({
        title: t("errorOccurred"),
        description: t("errorOccurred"),
        variant: "destructive",
      });
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    fetchTeachers();
  }, []);

  const filtered = useMemo(() => {
    if (!searchQuery) return teachers;
    return teachers.filter((teacher) =>
      teacher.name.toLowerCase().includes(searchQuery.toLowerCase()) ||
      teacher.subject?.toLowerCase().includes(searchQuery.toLowerCase())
    );
  }, [teachers, searchQuery]);

  const handleExportPDF = () => {
    const headers = [
      t("teacherName"),
      t("subject"),
      t("qualification"),
      t("contact"),
      t("email"),
    ];
    const data = filtered.map(t => [
      t.name,
      t.subject || "-",
      t.qualification || "-",
      t.contact || "-",
      t.email || "-",
    ]);
    generatePDF(
      isRTL ? "اساتذہ کی فہرست" : "Teachers List",
      headers,
      data,
      "teachers_list.pdf",
      isRTL
    );
  };

  const handlePrint = () => {
    printTable("teachers-table", isRTL ? "اساتذہ کی فہرست" : "Teachers List", isRTL);
  };

  return (
    <div className="space-y-4 md:space-y-6">
      <div className="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-3">
        <h1 className="text-2xl md:text-3xl font-bold text-foreground">{t("teachersList")}</h1>
        <div className="flex flex-wrap gap-2">
          <Button variant="outline" onClick={handleExportPDF} size="sm">
            <FileDown className="h-3 w-3 md:h-4 md:w-4 mr-1 md:mr-2" />
            <span className="text-xs md:text-sm">PDF</span>
          </Button>
          <Button variant="outline" onClick={handlePrint} size="sm">
            <Printer className="h-3 w-3 md:h-4 md:w-4 mr-1 md:mr-2" />
            <span className="text-xs md:text-sm">{isRTL ? "پرنٹ" : "Print"}</span>
          </Button>
          {canAddTeachers() && <AddTeacherDialog onAdded={fetchTeachers} />}
          <Button onClick={fetchTeachers} variant="outline" size="icon">
            <RefreshCw className="h-3 w-3 md:h-4 md:w-4" />
          </Button>
        </div>
      </div>

      <div className="bg-card rounded-lg border p-3 sm:p-4 md:p-6">
        <div className="mb-4 relative">
          <Search className="absolute left-3 top-1/2 transform -translate-y-1/2 text-muted-foreground h-4 w-4" />
          <Input
            placeholder={t("search")}
            value={searchQuery}
            onChange={(e) => setSearchQuery(e.target.value)}
            className="pl-10"
          />
        </div>

        {loading ? (
          <div className="text-center py-8 text-muted-foreground">
            {t("loading")}
          </div>
        ) : filtered.length === 0 ? (
          <div className="text-center py-8 text-muted-foreground">
            {t("noRecordsFound")}
          </div>
        ) : (
          <div className="overflow-x-auto">
            <Table id="teachers-table">
              <TableHeader>
              <TableRow>
                <TableHead className={isRTL ? "text-right" : "text-left"}>
                  {t("teacherName")}
                </TableHead>
                <TableHead className={isRTL ? "text-right" : "text-left"}>
                  {t("subject")}
                </TableHead>
                <TableHead className={isRTL ? "text-right" : "text-left"}>
                  {isRTL ? "کلاسز" : "Classes"}
                </TableHead>
                <TableHead className={isRTL ? "text-right" : "text-left"}>
                  {t("qualification")}
                </TableHead>
                <TableHead className={isRTL ? "text-right" : "text-left"}>
                  {t("contact")}
                </TableHead>
                <TableHead className={isRTL ? "text-right" : "text-left"}>
                  {t("email")}
                </TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {filtered.map((teacher) => {
                const classNames = teacher.class_teachers?.map(ct => ct.classes.name) || [];
                return (
                  <TableRow key={teacher.id}>
                    <TableCell className={isRTL ? "text-right font-medium" : "text-left font-medium"}>
                      {teacher.name}
                    </TableCell>
                    <TableCell className={isRTL ? "text-right" : "text-left"}>
                      {teacher.subject || "-"}
                    </TableCell>
                    <TableCell className={isRTL ? "text-right" : "text-left"}>
                      {classNames.length > 0 ? (
                        <div className="space-y-1">
                          {classNames.map((name, idx) => (
                            <div key={idx} className="text-xs">
                              {name}
                            </div>
                          ))}
                        </div>
                      ) : "-"}
                    </TableCell>
                    <TableCell className={isRTL ? "text-right" : "text-left"}>
                      {teacher.qualification || "-"}
                    </TableCell>
                    <TableCell className={isRTL ? "text-right" : "text-left"}>
                      {teacher.contact || "-"}
                    </TableCell>
                    <TableCell className={isRTL ? "text-right" : "text-left"}>
                      {teacher.email || "-"}
                    </TableCell>
                  </TableRow>
                );
              })}
            </TableBody>
          </Table>
          </div>
        )}
      </div>
    </div>
  );
};

export default Teachers;