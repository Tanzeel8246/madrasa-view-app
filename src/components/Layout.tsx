import { ReactNode } from "react";
import Sidebar from "@/components/Sidebar";
import Header from "@/components/Header";
import { useLanguage } from "@/contexts/LanguageContext";
import { cn } from "@/lib/utils";
import { SidebarProvider, SidebarTrigger, SidebarInset } from "@/components/ui/sidebar";

interface LayoutProps {
  children: ReactNode;
}

const Layout = ({ children }: LayoutProps) => {
  const { isRTL } = useLanguage();
  
  return (
    <SidebarProvider defaultOpen={true}>
      <div className={cn("flex min-h-screen w-full bg-background", isRTL && "flex-row-reverse")}>
        <Sidebar />
        <SidebarInset className={cn("flex-1", isRTL ? "mr-0" : "ml-0")}>
          <Header>
            <SidebarTrigger className={cn(isRTL ? "mr-2" : "ml-2")} />
          </Header>
          <main className="flex-1 p-3 sm:p-4 md:p-6 lg:p-8 w-full">
            {children}
          </main>
        </SidebarInset>
      </div>
    </SidebarProvider>
  );
};

export default Layout;
