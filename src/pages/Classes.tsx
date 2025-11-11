import { useState, useEffect, useMemo } from "react";
import { useLanguage } from "@/contexts/LanguageContext";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Search, RefreshCw } from "lucide-react";
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
import AddClassDialog from "@/components/classes/AddClassDialog";
import { Printer, FileDown } from "lucide-react";
import { printTable, generatePDF } from "@/lib/pdfUtils";

type ClassRow = {
  id: string;
  name: string;
  description: string | null;
  teacher_id: string | null;
  teachers?: {
    name: string;
  } | null;
};

const Classes = () => {
  const { t, isRTL } = useLanguage();
  const { toast } = useToast();
  const [searchQuery, setSearchQuery] = useState("");
  const [classes, setClasses] = useState<ClassRow[]>([]);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    document.title = `${t("classes")} - مدرسہ مینجمنٹ`;
  }, [t]);

  const fetchClasses = async () => {
    try {
      setLoading(true);
      const { data, error } = await supabase
        .from("classes")
        .select(`
          *,
          teachers (
            name
          )
        `)
        .order("name");

      if (error) throw error;
      setClasses(data || []);
    } catch (error) {
      console.error("Error fetching classes:", error);
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
    fetchClasses();
  }, []);

  const filtered = useMemo(() => {
    if (!searchQuery) return classes;
    return classes.filter((cls) =>
      cls.name.toLowerCase().includes(searchQuery.toLowerCase()) ||
      cls.teachers?.name?.toLowerCase().includes(searchQuery.toLowerCase())
    );
  }, [classes, searchQuery]);

  const handlePrint = () => {
    printTable("classes-table", isRTL ? "کلاسز کی فہرست" : "Classes List", isRTL);
  };

  const handleExportPDF = () => {
    const headers = [
      t("className"),
      t("teacher"),
      t("description"),
    ];
    const data = filtered.map(cls => [
      cls.name,
      cls.teachers?.name || "-",
      cls.description || "-",
    ]);
    generatePDF(
      isRTL ? "کلاسز کی فہرست" : "Classes List",
      headers,
      data,
      "classes_list.pdf",
      isRTL
    );
  };

  return (
    <div className="space-y-4 md:space-y-6">
      <div className="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-3">
        <h1 className="text-2xl md:text-3xl font-bold text-foreground">{t("classesList")}</h1>
        <div className="flex flex-wrap gap-2">
          <Button variant="outline" onClick={handleExportPDF} size="sm">
            <FileDown className="h-3 w-3 md:h-4 md:w-4 mr-1 md:mr-2" />
            <span className="text-xs md:text-sm">PDF</span>
          </Button>
          <Button variant="outline" onClick={handlePrint} size="sm">
            <Printer className="h-3 w-3 md:h-4 md:w-4 mr-1 md:mr-2" />
            <span className="text-xs md:text-sm">{isRTL ? "پرنٹ" : "Print"}</span>
          </Button>
          <AddClassDialog onAdded={fetchClasses} />
          <Button onClick={fetchClasses} variant="outline" size="icon">
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
          <div className="text-center py-8 text-sm md:text-base text-muted-foreground">
            {t("loading")}
          </div>
        ) : filtered.length === 0 ? (
          <div className="text-center py-8 text-sm md:text-base text-muted-foreground">
            {t("noRecordsFound")}
          </div>
        ) : (
          <div className="overflow-x-auto">
            <Table id="classes-table">
              <TableHeader>
              <TableRow>
                <TableHead className={isRTL ? "text-right" : "text-left"}>
                  {t("className")}
                </TableHead>
                <TableHead className={isRTL ? "text-right" : "text-left"}>
                  {t("teacher")}
                </TableHead>
                <TableHead className={isRTL ? "text-right" : "text-left"}>
                  {t("description")}
                </TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {filtered.map((cls) => (
                <TableRow key={cls.id}>
                  <TableCell className={isRTL ? "text-right font-medium" : "text-left font-medium"}>
                    {cls.name}
                  </TableCell>
                  <TableCell className={isRTL ? "text-right" : "text-left"}>
                    {cls.teachers?.name || "-"}
                  </TableCell>
                  <TableCell className={isRTL ? "text-right" : "text-left"}>
                    {cls.description || "-"}
                  </TableCell>
                </TableRow>
              ))}
              </TableBody>
            </Table>
          </div>
        )}
      </div>
    </div>
  );
};

export default Classes;