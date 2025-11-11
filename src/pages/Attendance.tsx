import { useState, useEffect } from "react";
import { useLanguage } from "@/contexts/LanguageContext";
import { Button } from "@/components/ui/button";
import { Calendar } from "@/components/ui/calendar";
import { RadioGroup, RadioGroupItem } from "@/components/ui/radio-group";
import { Label } from "@/components/ui/label";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";
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
import { getMadrasahId } from "@/lib/madrasahUtils";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";

type Student = {
  id: string;
  name: string;
  roll_number: string;
  classes: { name: string } | null;
};

type AttendanceStatus = "present" | "absent" | "leave" | "sick";
type TimeSlot = "morning" | "afternoon" | "evening" | "night";

type AttendanceRecord = {
  student_id: string;
  status: AttendanceStatus;
};

const Attendance = () => {
  const { t, isRTL } = useLanguage();
  const { toast } = useToast();
  const [date, setDate] = useState<Date | undefined>(new Date());
  const [timeSlot, setTimeSlot] = useState<TimeSlot>("morning");
  const [students, setStudents] = useState<Student[]>([]);
  const [attendance, setAttendance] = useState<Record<string, AttendanceStatus>>({});
  const [existingAttendance, setExistingAttendance] = useState<Set<string>>(new Set());
  const [loading, setLoading] = useState(false);

  useEffect(() => {
    fetchStudents();
  }, []);

  useEffect(() => {
    if (date) {
      fetchAttendanceForDate(date, timeSlot);
    }
  }, [date, timeSlot]);

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

  const fetchAttendanceForDate = async (selectedDate: Date, slot: TimeSlot) => {
    try {
      const dateStr = selectedDate.toISOString().split("T")[0];
      const { data, error } = await supabase
        .from("attendance")
        .select("student_id, status, time_slot")
        .eq("date", dateStr)
        .eq("time_slot", slot);

      if (error) throw error;

      const attendanceMap: Record<string, AttendanceStatus> = {};
      const existing = new Set<string>();
      
      data?.forEach((record) => {
        attendanceMap[record.student_id] = record.status as AttendanceStatus;
        existing.add(record.student_id);
      });
      
      setAttendance(attendanceMap);
      setExistingAttendance(existing);
    } catch (error) {
      console.error("Error fetching attendance:", error);
    }
  };

  const setStudentAttendance = (studentId: string, status: AttendanceStatus) => {
    setAttendance((prev) => ({
      ...prev,
      [studentId]: status,
    }));
  };

  const handleSubmit = async () => {
    if (!date) return;

    try {
      setLoading(true);
      const dateStr = date.toISOString().split("T")[0];
      const madrasahId = await getMadrasahId();

      if (!madrasahId) {
        throw new Error("Madrasah ID not found");
      }

      // Filter students who have attendance marked
      const recordsToSave = students
        .filter((student) => attendance[student.id])
        .map((student) => ({
          student_id: student.id,
          date: dateStr,
          time_slot: timeSlot,
          status: attendance[student.id],
          madrasah_id: madrasahId,
        }));

      if (recordsToSave.length === 0) {
        toast({
          title: isRTL ? "کوئی حاضری منتخب نہیں" : "No attendance selected",
          description: isRTL ? "براہ کرم کم از کم ایک طالب علم کی حاضری منتخب کریں" : "Please select at least one student",
          variant: "destructive",
        });
        setLoading(false);
        return;
      }

      // Delete existing attendance for these students on this date and time slot
      const studentIds = recordsToSave.map(r => r.student_id);
      await supabase
        .from("attendance")
        .delete()
        .eq("date", dateStr)
        .eq("time_slot", timeSlot)
        .in("student_id", studentIds);

      // Insert new attendance records
      const { error } = await supabase.from("attendance").insert(recordsToSave);

      if (error) throw error;

      toast({
        title: t("attendanceMarked"),
        description: `${recordsToSave.length} ${isRTL ? "طلباء کی حاضری محفوظ ہو گئی" : "students' attendance saved"}`,
      });

      // Refresh attendance
      fetchAttendanceForDate(date, timeSlot);
    } catch (error: any) {
      console.error("Error saving attendance:", error);
      toast({
        title: t("errorOccurred"),
        description: error.message || t("errorOccurred"),
        variant: "destructive",
      });
    } finally {
      setLoading(false);
    }
  };

  const getTimeSlotLabel = (slot: TimeSlot) => {
    const labels = {
      morning: isRTL ? "صبح" : "Morning",
      afternoon: isRTL ? "دوپہر" : "Afternoon",
      evening: isRTL ? "شام" : "Evening",
      night: isRTL ? "رات" : "Night",
    };
    return labels[slot];
  };

  const getStatusLabel = (status: AttendanceStatus) => {
    const labels = {
      present: isRTL ? "حاضر" : "Present",
      absent: isRTL ? "غیر حاضر" : "Absent",
      leave: isRTL ? "رخصت" : "Leave",
      sick: isRTL ? "بیمار" : "Sick",
    };
    return labels[status];
  };

  return (
    <div className="space-y-4 md:space-y-6">
      <h1 className="text-2xl md:text-3xl font-bold text-foreground">
        {t("markAttendance")}
      </h1>

      <div className="grid grid-cols-1 lg:grid-cols-3 gap-4 md:gap-6">
        <div className="lg:col-span-1 space-y-4">
          <Card>
            <CardHeader>
              <CardTitle>{t("date")}</CardTitle>
            </CardHeader>
            <CardContent>
              <Calendar
                mode="single"
                selected={date}
                onSelect={setDate}
                className="rounded-md border"
              />
            </CardContent>
          </Card>

          <Card>
            <CardHeader>
              <CardTitle>{isRTL ? "وقت منتخب کریں" : "Select Time"}</CardTitle>
            </CardHeader>
            <CardContent>
              <Select value={timeSlot} onValueChange={(value) => setTimeSlot(value as TimeSlot)}>
                <SelectTrigger>
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="morning">{getTimeSlotLabel("morning")}</SelectItem>
                  <SelectItem value="afternoon">{getTimeSlotLabel("afternoon")}</SelectItem>
                  <SelectItem value="evening">{getTimeSlotLabel("evening")}</SelectItem>
                  <SelectItem value="night">{getTimeSlotLabel("night")}</SelectItem>
                </SelectContent>
              </Select>
            </CardContent>
          </Card>
        </div>

        <div className="lg:col-span-2">
          <Card>
            <CardHeader>
              <div className="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-2">
                <div>
                  <CardTitle className="text-base md:text-lg">
                    {date?.toLocaleDateString(isRTL ? "ur-PK" : "en-US", {
                      weekday: "long",
                      year: "numeric",
                      month: "long",
                      day: "numeric",
                    })}
                  </CardTitle>
                  <p className="text-sm text-muted-foreground mt-1">
                    {getTimeSlotLabel(timeSlot)}
                  </p>
                </div>
                <div className="text-sm text-muted-foreground">
                  {isRTL ? "نشان زد" : "Marked"}: {Object.keys(attendance).length} / {students.length}
                </div>
              </div>
            </CardHeader>
            <CardContent>
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
                            {t("status")}
                          </TableHead>
                        </TableRow>
                      </TableHeader>
                      <TableBody>
                        {students.map((student) => {
                          const isAlreadyMarked = existingAttendance.has(student.id);
                          
                          return (
                            <TableRow key={student.id} className={isAlreadyMarked ? "bg-muted/50" : ""}>
                              <TableCell className={isRTL ? "text-right" : "text-left"}>
                                {student.roll_number}
                              </TableCell>
                              <TableCell
                                className={
                                  isRTL ? "text-right font-medium" : "text-left font-medium"
                                }
                              >
                                {student.name}
                                {isAlreadyMarked && (
                                  <span className="text-xs text-muted-foreground ml-2">
                                    ({isRTL ? "محفوظ شدہ" : "Saved"})
                                  </span>
                                )}
                              </TableCell>
                              <TableCell className={isRTL ? "text-right" : "text-left"}>
                                {student.classes?.name || "-"}
                              </TableCell>
                              <TableCell className={isRTL ? "text-right" : "text-left"}>
                                <RadioGroup
                                  value={attendance[student.id] || ""}
                                  onValueChange={(value) =>
                                    setStudentAttendance(student.id, value as AttendanceStatus)
                                  }
                                  className="flex gap-4"
                                >
                                  {(["present", "absent", "leave", "sick"] as AttendanceStatus[]).map(
                                    (status) => (
                                      <div key={status} className="flex items-center space-x-2 space-x-reverse">
                                        <RadioGroupItem value={status} id={`${student.id}-${status}`} />
                                        <Label
                                          htmlFor={`${student.id}-${status}`}
                                          className="text-xs cursor-pointer"
                                        >
                                          {getStatusLabel(status)}
                                        </Label>
                                      </div>
                                    )
                                  )}
                                </RadioGroup>
                              </TableCell>
                            </TableRow>
                          );
                        })}
                      </TableBody>
                    </Table>
                  </div>

                  <div className="mt-4 md:mt-6 flex justify-end">
                    <Button
                      onClick={handleSubmit}
                      className="w-full sm:w-auto px-6 md:px-8"
                      disabled={loading}
                      size="sm"
                    >
                      {loading ? t("loading") : t("submit")}
                    </Button>
                  </div>
                </>
              )}
            </CardContent>
          </Card>
        </div>
      </div>
    </div>
  );
};

export default Attendance;