import { useState, useEffect } from "react";
import { Users, UserCheck, DollarSign, Calendar } from "lucide-react";
import StatCard from "@/components/StatCard";
import { useLanguage } from "@/contexts/LanguageContext";
import { supabase } from "@/integrations/supabase/client";

const Dashboard = () => {
  const { t } = useLanguage();
  const [stats, setStats] = useState({
    totalStudents: 0,
    presentToday: 0,
    pendingFees: 0,
    monthlyRevenue: 0,
  });
  
  useEffect(() => {
    const fetchStats = async () => {
      try {
        // Get total students
        const { count: studentCount } = await supabase
          .from("students")
          .select("*", { count: "exact", head: true });

        // Get today's attendance
        const today = new Date().toISOString().split("T")[0];
        const { count: presentCount } = await supabase
          .from("attendance")
          .select("*", { count: "exact", head: true })
          .eq("date", today)
          .eq("status", "present");

        // Get pending fees
        const { count: pendingCount } = await supabase
          .from("fees")
          .select("*", { count: "exact", head: true })
          .eq("status", "pending");

        // Get monthly revenue (paid fees this month)
        const firstDay = new Date(new Date().getFullYear(), new Date().getMonth(), 1);
        const { data: paidFees } = await supabase
          .from("fees")
          .select("paid_amount")
          .gte("payment_date", firstDay.toISOString())
          .eq("status", "paid");

        const revenue = paidFees?.reduce((sum, fee) => sum + (Number(fee.paid_amount) || 0), 0) || 0;

        setStats({
          totalStudents: studentCount || 0,
          presentToday: presentCount || 0,
          pendingFees: pendingCount || 0,
          monthlyRevenue: revenue,
        });
      } catch (error) {
        console.error("Error fetching stats:", error);
      }
    };

    fetchStats();
  }, []);
  
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
          value={stats.totalStudents.toString()}
          icon={Users}
        />
        <StatCard
          title={t("presentToday")}
          value={stats.presentToday.toString()}
          icon={UserCheck}
          subtitle={stats.totalStudents > 0 ? `${Math.round((stats.presentToday / stats.totalStudents) * 100)}%` : "0%"}
        />
        <StatCard
          title={t("pendingFees")}
          value={stats.pendingFees.toString()}
          icon={DollarSign}
          subtitle={t("students")}
        />
        <StatCard
          title={t("monthlyRevenue")}
          value={`PKR ${stats.monthlyRevenue.toLocaleString()}`}
          icon={Calendar}
        />
      </div>
    </div>
  );
};

export default Dashboard;