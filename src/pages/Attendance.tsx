import { useState, useEffect } from "react";
import { useLanguage } from "@/contexts/LanguageContext";
import { Button } from "@/components/ui/button";
import { Calendar } from "@/components/ui/calendar";
import { Checkbox } from "@/components/ui/checkbox";
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

type Student = {
  id: string;
  name: string;
  roll_number: string;
  classes: { name: string } | null;
};

const Attendance = () => {
  const { t, isRTL } = useLanguage();
  const { toast } = useToast();
  const [date, setDate] = useState<Date | undefined>(new Date());
  const [students, setStudents] = useState<Student[]>([]);
  const [attendance, setAttendance] = useState<Record<string, boolean>>({});
  const [loading, setLoading] = useState(false);

  useEffect(() => {
    fetchStudents();
  }, []);

  useEffect(() => {
    if (date) {
      fetchAttendanceForDate(date);
    }
  }, [date]);

  const fetchStudents = async () => {
    try {
      const { data, error } = await supabase
        .from("students")
        .select(`
          id,
          name,
          roll_number,
          classes (
            name
          )
        `)
        .order("roll_number");

      if (error) throw error;
      setStudents(data || []);
    } catch (error) {
      console.error("Error fetching students:", error);
    }
  };

  const fetchAttendanceForDate = async (selectedDate: Date) => {
    try {
      const dateStr = selectedDate.toISOString().split("T")[0];
      const { data, error } = await supabase
        .from("attendance")
        .select("student_id, status")
        .eq("date", dateStr);

      if (error) throw error;

      const attendanceMap: Record<string, boolean> = {};
      data?.forEach((record) => {
        attendanceMap[record.student_id] = record.status === "present";
      });
      setAttendance(attendanceMap);
    } catch (error) {
      console.error("Error fetching attendance:", error);
    }
  };

  const toggleAttendance = (studentId: string) => {
    setAttendance((prev) => ({
      ...prev,
      [studentId]: !prev[studentId],
    }));
  };

  const handleSubmit = async () => {
    if (!date) return;

    try {
      setLoading(true);
      const dateStr = date.toISOString().split("T")[0];

      // Delete existing attendance for this date
      await supabase.from("attendance").delete().eq("date", dateStr);

      // Insert new attendance records
      const records = students.map((student) => ({
        student_id: student.id,
        date: dateStr,
        status: attendance[student.id] ? "present" : "absent",
      }));

      const { error } = await supabase.from("attendance").insert(records);

      if (error) throw error;

      toast({
        title: t("attendanceMarked"),
        description: t("attendanceMarked"),
      });
    } catch (error) {
      console.error("Error saving attendance:", error);
      toast({
        title: t("errorOccurred"),
        description: t("errorOccurred"),
        variant: "destructive",
      });
    } finally {
      setLoading(false);
    }
  };

  return (
    <div className="space-y-4 md:space-y-6">
      <h1 className="text-2xl md:text-3xl font-bold text-foreground">
        {t("markAttendance")}
      </h1>

      <div className="grid grid-cols-1 lg:grid-cols-3 gap-4 md:gap-6">
        <div className="lg:col-span-1">
          <div className="bg-card rounded-lg border p-3 sm:p-4">
            <h2 className="text-lg font-semibold mb-4">{t("date")}</h2>
            <Calendar
              mode="single"
              selected={date}
              onSelect={setDate}
              className="rounded-md border"
            />
          </div>
        </div>

        <div className="lg:col-span-2">
          <div className="bg-card rounded-lg border p-3 sm:p-4 md:p-6">
            <div className="flex flex-col sm:flex-row items-start sm:items-center justify-between mb-4 gap-2">
              <h2 className="text-base md:text-lg font-semibold">
                {date?.toLocaleDateString("ur-PK", {
                  weekday: "long",
                  year: "numeric",
                  month: "long",
                  day: "numeric",
                })}
              </h2>
              <div className="text-sm text-muted-foreground">
                {t("present")}: {Object.values(attendance).filter(Boolean).length} /{" "}
                {students.length}
              </div>
            </div>

            {students.length === 0 ? (
              <div className="text-center py-8 text-sm md:text-base text-muted-foreground">
                {t("noRecordsFound")}
              </div>
            ) : (
              <>
                <div className="overflow-x-auto">
                  <Table>
                    <TableHeader>
                    <TableRow>
                      <TableHead className={isRTL ? "text-right" : "text-left"}>
                        {t("rollNumber")}
                      </TableHead>
                      <TableHead className={isRTL ? "text-right" : "text-left"}>
                        {t("studentName")}
                      </TableHead>
                      <TableHead className={isRTL ? "text-right" : "text-left"}>
                        {t("class")}
                      </TableHead>
                      <TableHead className={isRTL ? "text-right" : "text-left"}>
                        {t("present")}
                      </TableHead>
                    </TableRow>
                  </TableHeader>
                  <TableBody>
                    {students.map((student) => (
                      <TableRow key={student.id}>
                        <TableCell className={isRTL ? "text-right" : "text-left"}>
                          {student.roll_number}
                        </TableCell>
                        <TableCell
                          className={
                            isRTL ? "text-right font-medium" : "text-left font-medium"
                          }
                        >
                          {student.name}
                        </TableCell>
                        <TableCell className={isRTL ? "text-right" : "text-left"}>
                          {student.classes?.name || "-"}
                        </TableCell>
                        <TableCell className={isRTL ? "text-right" : "text-left"}>
                          <Checkbox
                            checked={attendance[student.id] || false}
                            onCheckedChange={() => toggleAttendance(student.id)}
                          />
                        </TableCell>
                      </TableRow>
                    ))}
                    </TableBody>
                  </Table>
                </div>

                <div className="mt-4 md:mt-6 flex justify-end">
                  <Button onClick={handleSubmit} className="w-full sm:w-auto px-6 md:px-8" disabled={loading} size="sm">
                    {loading ? t("loading") : t("submit")}
                  </Button>
                </div>
              </>
            )}
          </div>
        </div>
      </div>
    </div>
  );
};

export default Attendance;