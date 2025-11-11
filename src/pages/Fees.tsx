import { useState } from "react";
import { useLanguage } from "@/contexts/LanguageContext";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Search } from "lucide-react";
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from "@/components/ui/table";
import { Badge } from "@/components/ui/badge";

const Fees = () => {
  const { t, isRTL } = useLanguage();
  const [searchQuery, setSearchQuery] = useState("");

  const feeRecords = [
    { id: 1, name: "محمد احمد", rollNumber: "001", monthlyFee: 5000, paid: 5000, status: "paid", month: "نومبر ۲۰۲۵" },
    { id: 2, name: "علی حسن", rollNumber: "002", monthlyFee: 5000, paid: 5000, status: "paid", month: "نومبر ۲۰۲۵" },
    { id: 3, name: "فاطمہ زہرا", rollNumber: "003", monthlyFee: 5000, paid: 0, status: "pending", month: "نومبر ۲۰۲۵" },
    { id: 4, name: "عائشہ صدیقہ", rollNumber: "004", monthlyFee: 4500, paid: 4500, status: "paid", month: "نومبر ۲۰۲۵" },
    { id: 5, name: "عمر فاروق", rollNumber: "005", monthlyFee: 5000, paid: 2500, status: "partial", month: "نومبر ۲۰۲۵" },
  ];

  return (
    <div className="space-y-6">
      <div className="flex items-center justify-between">
        <h1 className="text-3xl font-bold text-foreground">
          {t("feeManagement")}
        </h1>
      </div>

      <div className="grid grid-cols-1 md:grid-cols-3 gap-6">
        <div className="bg-card rounded-lg border p-6">
          <p className="text-sm text-muted-foreground mb-2">کل آمدنی - {t("thisMonth")}</p>
          <h3 className="text-3xl font-bold text-primary">PKR 22,000</h3>
        </div>
        <div className="bg-card rounded-lg border p-6">
          <p className="text-sm text-muted-foreground mb-2">بقایا - {t("thisMonth")}</p>
          <h3 className="text-3xl font-bold text-destructive">PKR 7,500</h3>
        </div>
        <div className="bg-card rounded-lg border p-6">
          <p className="text-sm text-muted-foreground mb-2">مجموعی متوقع</p>
          <h3 className="text-3xl font-bold text-accent">PKR 29,500</h3>
        </div>
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
                مہینہ
              </TableHead>
              <TableHead className={isRTL ? "text-right" : "text-left"}>
                {t("monthlyFee")}
              </TableHead>
              <TableHead className={isRTL ? "text-right" : "text-left"}>
                {t("paid")}
              </TableHead>
              <TableHead className={isRTL ? "text-right" : "text-left"}>
                {t("pending")}
              </TableHead>
              <TableHead className={isRTL ? "text-right" : "text-left"}>
                حالت
              </TableHead>
              <TableHead className={isRTL ? "text-right" : "text-left"}>
                {t("actions")}
              </TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            {feeRecords.map((record) => (
              <TableRow key={record.id}>
                <TableCell className={isRTL ? "text-right" : "text-left"}>
                  {record.rollNumber}
                </TableCell>
                <TableCell className={isRTL ? "text-right font-medium" : "text-left font-medium"}>
                  {record.name}
                </TableCell>
                <TableCell className={isRTL ? "text-right" : "text-left"}>
                  {record.month}
                </TableCell>
                <TableCell className={isRTL ? "text-right" : "text-left"}>
                  PKR {record.monthlyFee.toLocaleString()}
                </TableCell>
                <TableCell className={isRTL ? "text-right" : "text-left"}>
                  PKR {record.paid.toLocaleString()}
                </TableCell>
                <TableCell className={isRTL ? "text-right" : "text-left"}>
                  PKR {(record.monthlyFee - record.paid).toLocaleString()}
                </TableCell>
                <TableCell className={isRTL ? "text-right" : "text-left"}>
                  <Badge
                    variant={
                      record.status === "paid"
                        ? "default"
                        : record.status === "pending"
                        ? "destructive"
                        : "secondary"
                    }
                  >
                    {record.status === "paid" ? "ادا شدہ" : record.status === "pending" ? "بقایا" : "جزوی"}
                  </Badge>
                </TableCell>
                <TableCell className={isRTL ? "text-right" : "text-left"}>
                  <Button variant="outline" size="sm">
                    ادائیگی
                  </Button>
                </TableCell>
              </TableRow>
            ))}
          </TableBody>
        </Table>
      </div>
    </div>
  );
};

export default Fees;
