import { useState, useEffect } from "react";
import { Users, UserCheck, DollarSign, Calendar, TrendingUp, TrendingDown, Wallet, HandCoins } from "lucide-react";
import StatCard from "@/components/StatCard";
import { useLanguage } from "@/contexts/LanguageContext";
import { supabase } from "@/integrations/supabase/client";

const Dashboard = () => {
  const { t, language } = useLanguage();
  const [madrasahName, setMadrasahName] = useState<string>("");
  const [stats, setStats] = useState({
    totalStudents: 0,
    presentToday: 0,
    pendingFees: 0,
    monthlyRevenue: 0,
    totalIncome: 0,
    totalExpense: 0,
    totalSalaries: 0,
    totalLoans: 0,
    netBalance: 0,
  });
  
  useEffect(() => {
    const fetchMadrasahName = async () => {
      try {
        const { data: { user } } = await supabase.auth.getUser();
        if (!user) return;

        const { data: profile } = await supabase
          .from("profiles")
          .select("madrasah_id")
          .eq("user_id", user.id)
          .single();

        if (profile?.madrasah_id) {
          const { data: madrasah } = await supabase
            .from("madrasah")
            .select("name")
            .eq("id", profile.madrasah_id)
            .single();

          if (madrasah?.name) {
            setMadrasahName(madrasah.name);
          }
        }
      } catch (error) {
        console.error("Error fetching madrasah name:", error);
      }
    };

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

        // Get total income (this month)
        const { data: incomeData } = await supabase
          .from("income")
          .select("amount")
          .gte("date", firstDay.toISOString().split("T")[0]);
        
        const totalIncome = incomeData?.reduce((sum, item) => sum + Number(item.amount), 0) || 0;

        // Get total expenses (this month)
        const { data: expenseData } = await supabase
          .from("expense")
          .select("amount")
          .gte("date", firstDay.toISOString().split("T")[0]);
        
        const totalExpense = expenseData?.reduce((sum, item) => sum + Number(item.amount), 0) || 0;

        // Get total salaries (this month)
        const currentMonth = new Date().getMonth() + 1;
        const currentYear = new Date().getFullYear();
        const { data: salariesData } = await supabase
          .from("salaries")
          .select("amount")
          .eq("month", currentMonth)
          .eq("year", currentYear)
          .eq("status", "paid");
        
        const totalSalaries = salariesData?.reduce((sum, item) => sum + Number(item.amount), 0) || 0;

        // Get total loans (pending)
        const { data: loansData } = await supabase
          .from("loans")
          .select("amount, paid_amount");
        
        const totalLoans = loansData?.reduce((sum, item) => sum + (Number(item.amount) - Number(item.paid_amount || 0)), 0) || 0;

        const netBalance = revenue + totalIncome - totalExpense - totalSalaries;

        setStats({
          totalStudents: studentCount || 0,
          presentToday: presentCount || 0,
          pendingFees: pendingCount || 0,
          monthlyRevenue: revenue,
          totalIncome,
          totalExpense,
          totalSalaries,
          totalLoans,
          netBalance,
        });
      } catch (error) {
        console.error("Error fetching stats:", error);
      }
    };

    fetchMadrasahName();
    fetchStats();
  }, []);
  
  return (
    <div className="space-y-4 md:space-y-6">
      <div>
        <h1 className="text-2xl md:text-3xl font-bold text-foreground mb-1 md:mb-2">
          {madrasahName 
            ? (language === "ur" ? `${madrasahName} میں خوش آمدید` : `Welcome to ${madrasahName}`)
            : t("welcomeMessage")
          }
        </h1>
        <p className="text-xs md:text-sm text-muted-foreground">
          {new Date().toLocaleDateString("ur-PK", { 
            weekday: "long", 
            year: "numeric", 
            month: "long", 
            day: "numeric" 
          })}
        </p>
      </div>

      <div>
        <h2 className="text-lg md:text-xl font-semibold text-foreground mb-3 md:mb-4">{t("financialSummary")}</h2>
        <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3 md:gap-4 lg:gap-6">
          <StatCard
            title={t("netBalance")}
            value={`Rs. ${stats.netBalance.toLocaleString()}`}
            icon={DollarSign}
          />
          <StatCard
            title={t("totalIncome")}
            value={`Rs. ${(stats.monthlyRevenue + stats.totalIncome).toLocaleString()}`}
            icon={TrendingUp}
          />
          <StatCard
            title={t("totalExpenditure")}
            value={`Rs. ${(stats.totalExpense + stats.totalSalaries).toLocaleString()}`}
            icon={TrendingDown}
          />
          <StatCard
            title={t("pendingLoans")}
            value={`Rs. ${stats.totalLoans.toLocaleString()}`}
            icon={HandCoins}
          />
        </div>
      </div>

      <div>
        <h2 className="text-lg md:text-xl font-semibold text-foreground mb-3 md:mb-4">{t("dashboard")}</h2>
        <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3 md:gap-4 lg:gap-6">
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
            value={`Rs. ${stats.monthlyRevenue.toLocaleString()}`}
            icon={Calendar}
          />
        </div>
      </div>
    </div>
  );
};

export default Dashboard;