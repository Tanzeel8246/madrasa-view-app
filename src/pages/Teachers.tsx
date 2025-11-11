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
import AddTeacherDialog from "@/components/teachers/AddTeacherDialog";

type TeacherRow = {
  id: string;
  name: string;
  contact: string | null;
  email: string | null;
  subject: string | null;
  qualification: string | null;
};

const Teachers = () => {
  const { t, isRTL } = useLanguage();
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
        .select("*")
        .order("name");

      if (error) throw error;
      setTeachers(data || []);
    } catch (error) {
      console.error("Error fetching teachers:", error);
      toast({
        title: t("errorOccurred"),
        description: "Failed to fetch teachers",
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

  return (
    <div className="space-y-6">
      <div className="flex items-center justify-between">
        <h1 className="text-3xl font-bold text-foreground">{t("teachersList")}</h1>
        <div className="flex gap-2">
          <AddTeacherDialog onAdded={fetchTeachers} />
          <Button onClick={fetchTeachers} variant="outline" size="icon">
            <RefreshCw className="h-4 w-4" />
          </Button>
        </div>
      </div>

      <div className="bg-card rounded-lg border p-6">
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
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead className={isRTL ? "text-right" : "text-left"}>
                  {t("teacherName")}
                </TableHead>
                <TableHead className={isRTL ? "text-right" : "text-left"}>
                  {t("subject")}
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
              {filtered.map((teacher) => (
                <TableRow key={teacher.id}>
                  <TableCell className={isRTL ? "text-right font-medium" : "text-left font-medium"}>
                    {teacher.name}
                  </TableCell>
                  <TableCell className={isRTL ? "text-right" : "text-left"}>
                    {teacher.subject || "-"}
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
              ))}
            </TableBody>
          </Table>
        )}
      </div>
    </div>
  );
};

export default Teachers;