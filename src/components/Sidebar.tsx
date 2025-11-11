import { Home, Users, ClipboardList, DollarSign, FileText, Settings, GraduationCap, BookOpen, TrendingUp, TrendingDown } from "lucide-react";
import { NavLink } from "@/components/NavLink";
import { useLanguage } from "@/contexts/LanguageContext";
import { cn } from "@/lib/utils";
import {
  Sidebar as SidebarPrimitive,
  SidebarContent,
  SidebarGroup,
  SidebarGroupContent,
  SidebarMenu,
  SidebarMenuButton,
  SidebarMenuItem,
  SidebarHeader,
  useSidebar,
} from "@/components/ui/sidebar";

const Sidebar = () => {
  const { t, isRTL } = useLanguage();
  const { setOpenMobile, isMobile } = useSidebar();
  
  const menuItems = [
    { icon: Home, label: t("dashboard"), path: "/" },
    { icon: Users, label: t("students"), path: "/students" },
    { icon: GraduationCap, label: t("teachers"), path: "/teachers" },
    { icon: BookOpen, label: t("classes"), path: "/classes" },
    { icon: ClipboardList, label: t("attendance"), path: "/attendance" },
    { icon: DollarSign, label: t("fees"), path: "/fees" },
    { icon: TrendingUp, label: t("income"), path: "/income" },
    { icon: TrendingDown, label: t("expense"), path: "/expense" },
    { icon: FileText, label: t("reports"), path: "/reports" },
    { icon: Settings, label: t("settings"), path: "/settings" },
  ];

  const handleLinkClick = () => {
    if (isMobile) {
      setOpenMobile(false);
    }
  };

  return (
    <SidebarPrimitive side={isRTL ? "right" : "left"} collapsible="offcanvas">
      <SidebarHeader className="border-b border-sidebar-border">
        <h1 className="text-xl font-bold text-sidebar-foreground text-center py-4">
          {isRTL ? "مدرسہ" : "Madrasa"}
        </h1>
      </SidebarHeader>
      <SidebarContent>
        <SidebarGroup>
          <SidebarGroupContent>
            <SidebarMenu>
              {menuItems.map((item) => (
                <SidebarMenuItem key={item.path}>
                  <SidebarMenuButton asChild>
                    <NavLink
                      to={item.path}
                      className={cn(
                        "flex items-center gap-3",
                        isRTL && "flex-row-reverse"
                      )}
                      activeClassName="bg-sidebar-accent text-sidebar-accent-foreground font-semibold"
                      onClick={handleLinkClick}
                    >
                      <item.icon className="w-5 h-5" />
                      <span>{item.label}</span>
                    </NavLink>
                  </SidebarMenuButton>
                </SidebarMenuItem>
              ))}
            </SidebarMenu>
          </SidebarGroupContent>
        </SidebarGroup>
      </SidebarContent>
    </SidebarPrimitive>
  );
};

export default Sidebar;
