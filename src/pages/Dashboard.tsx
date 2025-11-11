import { Users, UserCheck, DollarSign, Calendar } from "lucide-react";
import StatCard from "@/components/StatCard";
import { useLanguage } from "@/contexts/LanguageContext";

const Dashboard = () => {
  const { t } = useLanguage();
  
  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-3xl font-bold text-foreground mb-2">
          {t("welcomeMessage")}
        </h1>
        <p className="text-muted-foreground">
          {new Date().toLocaleDateString("ur-PK", { 
            weekday: "long", 
            year: "numeric", 
            month: "long", 
            day: "numeric" 
          })}
        </p>
      </div>

      <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
        <StatCard
          title={t("totalStudents")}
          value="245"
          icon={Users}
        />
        <StatCard
          title={t("presentToday")}
          value="230"
          icon={UserCheck}
          subtitle="94%"
        />
        <StatCard
          title={t("pendingFees")}
          value="15"
          icon={DollarSign}
          subtitle={t("thisMonth")}
        />
        <StatCard
          title={t("thisMonth")}
          value="PKR 125,000"
          icon={Calendar}
        />
      </div>

      <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <div className="bg-card rounded-lg border p-6">
          <h2 className="text-xl font-semibold text-foreground mb-4">
            {t("attendance")} - {t("thisMonth")}
          </h2>
          <div className="space-y-3">
            {[
              { day: "پیر", date: "۱۱ نومبر", present: 235, total: 245 },
              { day: "منگل", date: "۱۰ نومبر", present: 230, total: 245 },
              { day: "بدھ", date: "۹ نومبر", present: 238, total: 245 },
            ].map((item, index) => (
              <div key={index} className="flex items-center justify-between p-3 bg-muted/50 rounded-lg">
                <div>
                  <p className="font-medium">{item.day}</p>
                  <p className="text-sm text-muted-foreground">{item.date}</p>
                </div>
                <div className="text-right">
                  <p className="font-semibold text-primary">
                    {item.present}/{item.total}
                  </p>
                  <p className="text-sm text-muted-foreground">
                    {Math.round((item.present / item.total) * 100)}%
                  </p>
                </div>
              </div>
            ))}
          </div>
        </div>

        <div className="bg-card rounded-lg border p-6">
          <h2 className="text-xl font-semibold text-foreground mb-4">
            {t("fees")} - حالیہ سرگرمیاں
          </h2>
          <div className="space-y-3">
            {[
              { name: "محمد احمد", amount: "PKR 5,000", status: "ادا شدہ", date: "۱۱ نومبر" },
              { name: "علی حسن", amount: "PKR 5,000", status: "ادا شدہ", date: "۱۰ نومبر" },
              { name: "فاطمہ زہرا", amount: "PKR 5,000", status: "بقایا", date: "۵ نومبر" },
            ].map((item, index) => (
              <div key={index} className="flex items-center justify-between p-3 bg-muted/50 rounded-lg">
                <div>
                  <p className="font-medium">{item.name}</p>
                  <p className="text-sm text-muted-foreground">{item.date}</p>
                </div>
                <div className="text-right">
                  <p className="font-semibold">{item.amount}</p>
                  <p className={`text-sm ${
                    item.status === "ادا شدہ" ? "text-green-600" : "text-red-600"
                  }`}>
                    {item.status}
                  </p>
                </div>
              </div>
            ))}
          </div>
        </div>
      </div>
    </div>
  );
};

export default Dashboard;
