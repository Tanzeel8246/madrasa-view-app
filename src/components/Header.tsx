import { useEffect, useState } from "react";
import { Button } from "@/components/ui/button";
import { Avatar, AvatarFallback, AvatarImage } from "@/components/ui/avatar";
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuLabel,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from "@/components/ui/dropdown-menu";
import { Badge } from "@/components/ui/badge";
import { useLanguage } from "@/contexts/LanguageContext";
import { useAuth } from "@/contexts/AuthContext";
import { Languages, Bell, LogOut, User, Settings } from "lucide-react";
import { ReactNode } from "react";
import { supabase } from "@/integrations/supabase/client";
import { useNavigate } from "react-router-dom";

interface HeaderProps {
  children?: ReactNode;
}

type MadrasahInfo = {
  name: string;
  logo_url: string | null;
};

type ProfileInfo = {
  full_name: string | null;
};

const Header = ({ children }: HeaderProps = {}) => {
  const { language, setLanguage, isRTL } = useLanguage();
  const { user, signOut, madrasahId } = useAuth();
  const navigate = useNavigate();
  const [madrasahInfo, setMadrasahInfo] = useState<MadrasahInfo | null>(null);
  const [profileInfo, setProfileInfo] = useState<ProfileInfo | null>(null);
  const [notificationCount, setNotificationCount] = useState(3);

  useEffect(() => {
    if (madrasahId) {
      fetchMadrasahInfo();
    }
    if (user) {
      fetchProfileInfo();
    }
  }, [madrasahId, user]);

  const fetchMadrasahInfo = async () => {
    const { data } = await supabase
      .from("madrasah")
      .select("name, logo_url")
      .eq("id", madrasahId)
      .maybeSingle();
    if (data) setMadrasahInfo(data);
  };

  const fetchProfileInfo = async () => {
    const { data } = await supabase
      .from("profiles")
      .select("full_name")
      .eq("user_id", user?.id)
      .maybeSingle();
    if (data) setProfileInfo(data);
  };

  const toggleLanguage = () => {
    setLanguage(language === "ur" ? "en" : "ur");
  };

  const handleSignOut = async () => {
    await signOut();
  };

  const getUserInitials = () => {
    if (profileInfo?.full_name) {
      return profileInfo.full_name
        .split(" ")
        .map((n) => n[0])
        .join("")
        .toUpperCase()
        .slice(0, 2);
    }
    return user?.email?.[0]?.toUpperCase() || "U";
  };

  return (
    <header className="h-16 md:h-20 bg-gradient-to-r from-primary via-primary to-primary/95 shadow-lg sticky top-0 z-50 border-b border-primary-foreground/10">
      <div className="h-full px-4 md:px-8 flex items-center justify-between">
        {/* Left Section - Logo & Madrasah Name */}
        <div className="flex items-center gap-3 md:gap-4">
          {children}
          
          {madrasahInfo && (
            <div className="flex items-center gap-2 md:gap-3">
              {madrasahInfo.logo_url && (
                <Avatar className="h-10 w-10 md:h-12 md:w-12 border-2 border-primary-foreground/20">
                  <AvatarImage src={madrasahInfo.logo_url} alt={madrasahInfo.name} />
                  <AvatarFallback className="bg-primary-foreground/10 text-primary-foreground">
                    {madrasahInfo.name[0]}
                  </AvatarFallback>
                </Avatar>
              )}
              
              <div className="hidden sm:flex flex-col">
                <h1 className="text-sm md:text-lg font-bold text-primary-foreground leading-tight">
                  {madrasahInfo.name}
                </h1>
                <p className="text-[10px] md:text-xs text-primary-foreground/70">
                  {isRTL ? "مدرسہ مینجمنٹ سسٹم" : "Madrasah Management"}
                </p>
              </div>
            </div>
          )}
        </div>

        {/* Right Section - Actions */}
        <div className="flex items-center gap-2 md:gap-3">
          {/* Language Switcher */}
          <Button
            variant="ghost"
            size="sm"
            onClick={toggleLanguage}
            className="text-primary-foreground hover:bg-primary-foreground/10 h-9 md:h-10 px-2 md:px-3"
          >
            <Languages className="w-4 h-4 md:w-5 md:h-5" />
            <span className="hidden sm:inline ml-2 text-xs md:text-sm font-medium">
              {language === "ur" ? "English" : "اردو"}
            </span>
          </Button>

          {/* Notifications */}
          <DropdownMenu>
            <DropdownMenuTrigger asChild>
              <Button
                variant="ghost"
                size="sm"
                className="relative text-primary-foreground hover:bg-primary-foreground/10 h-9 md:h-10 w-9 md:w-10 p-0"
              >
                <Bell className="w-4 h-4 md:w-5 md:h-5" />
                {notificationCount > 0 && (
                  <Badge
                    variant="destructive"
                    className="absolute -top-1 -right-1 h-5 w-5 flex items-center justify-center p-0 text-[10px]"
                  >
                    {notificationCount}
                  </Badge>
                )}
              </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end" className="w-80">
              <DropdownMenuLabel className={isRTL ? "text-right" : ""}>
                {isRTL ? "اطلاعات" : "Notifications"}
              </DropdownMenuLabel>
              <DropdownMenuSeparator />
              <div className="p-4 text-center text-sm text-muted-foreground">
                {isRTL ? "کوئی نئی اطلاع نہیں" : "No new notifications"}
              </div>
            </DropdownMenuContent>
          </DropdownMenu>

          {/* User Menu */}
          <DropdownMenu>
            <DropdownMenuTrigger asChild>
              <Button
                variant="ghost"
                className="flex items-center gap-2 text-primary-foreground hover:bg-primary-foreground/10 h-9 md:h-10 px-2 md:px-3"
              >
                <Avatar className="h-7 w-7 md:h-8 md:w-8 border-2 border-primary-foreground/20">
                  <AvatarFallback className="bg-primary-foreground/20 text-primary-foreground text-xs md:text-sm font-semibold">
                    {getUserInitials()}
                  </AvatarFallback>
                </Avatar>
                <span className="hidden md:block text-xs md:text-sm font-medium max-w-32 truncate">
                  {profileInfo?.full_name || user?.email?.split("@")[0]}
                </span>
              </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end" className="w-56">
              <DropdownMenuLabel className={isRTL ? "text-right" : ""}>
                <div className="flex flex-col space-y-1">
                  <p className="text-sm font-medium leading-none">
                    {profileInfo?.full_name || isRTL ? "صارف" : "User"}
                  </p>
                  <p className="text-xs leading-none text-muted-foreground">
                    {user?.email}
                  </p>
                </div>
              </DropdownMenuLabel>
              <DropdownMenuSeparator />
              <DropdownMenuItem
                onClick={() => navigate("/settings")}
                className={isRTL ? "flex-row-reverse" : ""}
              >
                <Settings className="mr-2 h-4 w-4" />
                <span>{isRTL ? "ترتیبات" : "Settings"}</span>
              </DropdownMenuItem>
              <DropdownMenuSeparator />
              <DropdownMenuItem
                onClick={handleSignOut}
                className={`${isRTL ? "flex-row-reverse" : ""} text-destructive focus:text-destructive`}
              >
                <LogOut className="mr-2 h-4 w-4" />
                <span>{isRTL ? "لاگ آؤٹ" : "Logout"}</span>
              </DropdownMenuItem>
            </DropdownMenuContent>
          </DropdownMenu>
        </div>
      </div>
    </header>
  );
};

export default Header;
