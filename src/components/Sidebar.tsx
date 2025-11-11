import { Home, Users, ClipboardList, DollarSign, FileText, Settings, GraduationCap, BookOpen } from "lucide-react";
import { NavLink } from "@/components/NavLink";
import { useLanguage } from "@/contexts/LanguageContext";
import { cn } from "@/lib/utils";

const Sidebar = () => {
  const { t, isRTL } = useLanguage();
  
  const menuItems = [
    { icon: Home, label: t("dashboard"), path: "/" },
    { icon: Users, label: t("students"), path: "/students" },
    { icon: GraduationCap, label: t("teachers"), path: "/teachers" },
    { icon: BookOpen, label: t("classes"), path: "/classes" },
    { icon: ClipboardList, label: t("attendance"), path: "/attendance" },
    { icon: DollarSign, label: t("fees"), path: "/fees" },
    { icon: FileText, label: t("reports"), path: "/reports" },
    { icon: Settings, label: t("settings"), path: "/settings" },
  ];

  return (
    <aside className={cn(
      "w-64 bg-sidebar h-screen sticky top-0",
      isRTL ? "border-l border-sidebar-border" : "border-r border-sidebar-border"
    )}>
      <div className="p-6">
        <h1 className="text-2xl font-bold text-sidebar-foreground mb-8 text-center">
          {isRTL ? "مدرسہ" : "Madrasa"}
        </h1>
        <nav className="space-y-2">
          {menuItems.map((item) => (
            <NavLink
              key={item.path}
              to={item.path}
              className={cn(
                "flex items-center gap-3 px-4 py-3 rounded-lg text-sidebar-foreground/80 hover:bg-sidebar-accent hover:text-sidebar-foreground transition-colors",
                isRTL && "flex-row-reverse"
              )}
              activeClassName="bg-sidebar-accent text-sidebar-foreground font-semibold"
            >
              <item.icon className="w-5 h-5" />
              <span>{item.label}</span>
            </NavLink>
          ))}
        </nav>
      </div>
    </aside>
  );
};

export default Sidebar;
