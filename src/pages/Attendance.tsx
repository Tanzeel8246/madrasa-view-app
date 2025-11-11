import { useState } from "react";
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

const Attendance = () => {
  const { t, isRTL } = useLanguage();
  const [date, setDate] = useState<Date | undefined>(new Date());
  const [attendance, setAttendance] = useState<Record<number, boolean>>({});

  const students = [
    { id: 1, name: "محمد احمد", rollNumber: "001", class: "حفظ" },
    { id: 2, name: "علی حسن", rollNumber: "002", class: "ناظرہ" },
    { id: 3, name: "فاطمہ زہرا", rollNumber: "003", class: "حفظ" },
    { id: 4, name: "عائشہ صدیقہ", rollNumber: "004", class: "قاعدہ" },
    { id: 5, name: "عمر فاروق", rollNumber: "005", class: "ناظرہ" },
  ];

  const toggleAttendance = (studentId: number) => {
    setAttendance(prev => ({
      ...prev,
      [studentId]: !prev[studentId]
    }));
  };

  const handleSubmit = () => {
    console.log("Attendance submitted:", attendance);
  };

  return (
    <div className="space-y-6">
      <h1 className="text-3xl font-bold text-foreground">
        {t("markAttendance")}
      </h1>

      <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <div className="lg:col-span-1">
          <div className="bg-card rounded-lg border p-4">
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
          <div className="bg-card rounded-lg border p-6">
            <div className="flex items-center justify-between mb-4">
              <h2 className="text-lg font-semibold">
                {date?.toLocaleDateString("ur-PK", {
                  weekday: "long",
                  year: "numeric",
                  month: "long",
                  day: "numeric",
                })}
              </h2>
              <div className="text-sm text-muted-foreground">
                {t("present")}: {Object.values(attendance).filter(Boolean).length} / {students.length}
              </div>
            </div>

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
                      {student.rollNumber}
                    </TableCell>
                    <TableCell className={isRTL ? "text-right font-medium" : "text-left font-medium"}>
                      {student.name}
                    </TableCell>
                    <TableCell className={isRTL ? "text-right" : "text-left"}>
                      {student.class}
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

            <div className="mt-6 flex justify-end">
              <Button onClick={handleSubmit} className="px-8">
                {t("submit")}
              </Button>
            </div>
          </div>
        </div>
      </div>
    </div>
  );
};

export default Attendance;
