import { useState } from "react";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { useLanguage } from "@/contexts/LanguageContext";
import { Search, Plus } from "lucide-react";
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from "@/components/ui/table";

const Students = () => {
  const { t, isRTL } = useLanguage();
  const [searchQuery, setSearchQuery] = useState("");

  const students = [
    { id: 1, name: "محمد احمد", fatherName: "احمد علی", class: "حفظ", rollNumber: "001", contact: "0300-1234567" },
    { id: 2, name: "علی حسن", fatherName: "حسن محمد", class: "ناظرہ", rollNumber: "002", contact: "0301-2345678" },
    { id: 3, name: "فاطمہ زہرا", fatherName: "محمد یوسف", class: "حفظ", rollNumber: "003", contact: "0302-3456789" },
    { id: 4, name: "عائشہ صدیقہ", fatherName: "عبداللہ خان", class: "قاعدہ", rollNumber: "004", contact: "0303-4567890" },
    { id: 5, name: "عمر فاروق", fatherName: "فاروق عمر", class: "ناظرہ", rollNumber: "005", contact: "0304-5678901" },
  ];

  return (
    <div className="space-y-6">
      <div className="flex items-center justify-between">
        <h1 className="text-3xl font-bold text-foreground">
          {t("studentsList")}
        </h1>
        <Button className="gap-2">
          <Plus className="w-4 h-4" />
          {t("addStudent")}
        </Button>
      </div>

      <div className="flex gap-4">
        <div className="relative flex-1">
          <Search className="absolute left-3 top-1/2 transform -translate-y-1/2 text-muted-foreground w-4 h-4" />
          <Input
            placeholder={t("search")}
            value={searchQuery}
            onChange={(e) => setSearchQuery(e.target.value)}
            className="pl-10"
          />
        </div>
      </div>

      <div className="bg-card rounded-lg border">
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
                {t("fatherName")}
              </TableHead>
              <TableHead className={isRTL ? "text-right" : "text-left"}>
                {t("class")}
              </TableHead>
              <TableHead className={isRTL ? "text-right" : "text-left"}>
                {t("contact")}
              </TableHead>
              <TableHead className={isRTL ? "text-right" : "text-left"}>
                {t("actions")}
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
                  {student.fatherName}
                </TableCell>
                <TableCell className={isRTL ? "text-right" : "text-left"}>
                  {student.class}
                </TableCell>
                <TableCell className={isRTL ? "text-right" : "text-left"}>
                  {student.contact}
                </TableCell>
                <TableCell className={isRTL ? "text-right" : "text-left"}>
                  <div className="flex gap-2">
                    <Button variant="outline" size="sm">
                      {t("view")}
                    </Button>
                    <Button variant="outline" size="sm">
                      {t("edit")}
                    </Button>
                  </div>
                </TableCell>
              </TableRow>
            ))}
          </TableBody>
        </Table>
      </div>
    </div>
  );
};

export default Students;
