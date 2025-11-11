import { useLanguage } from "@/contexts/LanguageContext";

const Reports = () => {
  const { t } = useLanguage();

  return (
    <div className="space-y-6">
      <h1 className="text-3xl font-bold text-foreground">
        {t("reports")}
      </h1>
      <div className="bg-card rounded-lg border p-8 text-center">
        <p className="text-muted-foreground">
          رپورٹس کا صفحہ جلد دستیاب ہوگا
        </p>
      </div>
    </div>
  );
};

export default Reports;
