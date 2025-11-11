import { Button } from "@/components/ui/button";
import { useLanguage } from "@/contexts/LanguageContext";
import { useAuth } from "@/contexts/AuthContext";
import { Languages } from "lucide-react";
import { ReactNode } from "react";

interface HeaderProps {
  children?: ReactNode;
}

const Header = ({ children }: HeaderProps = {}) => {
  const { language, setLanguage, isRTL } = useLanguage();
  const { user } = useAuth();

  const toggleLanguage = () => {
    setLanguage(language === "ur" ? "en" : "ur");
  };

  return (
    <header className="h-14 md:h-16 bg-gradient-to-r from-primary to-primary/90 shadow-md sticky top-0 z-10">
      <div className="h-full px-3 md:px-6 flex items-center justify-between">
        <div className="flex items-center gap-2 md:gap-4 text-primary-foreground">
          {children}
          <p className="hidden sm:block text-xs md:text-sm opacity-90">
            {isRTL ? "السلام علیکم" : "As-Salaam-Alaikum"}
          </p>
          {user && (
            <span className="hidden md:inline text-xs opacity-75">
              {user.email}
            </span>
          )}
        </div>
        <Button
          variant="outline"
          size="sm"
          onClick={toggleLanguage}
          className="bg-white/10 text-primary-foreground border-white/20 hover:bg-white/20 text-xs md:text-sm"
        >
          <Languages className="w-3 h-3 md:w-4 md:h-4 mr-1 md:mr-2" />
          <span className="hidden sm:inline">{language === "ur" ? "English" : "اردو"}</span>
          <span className="sm:hidden">{language === "ur" ? "EN" : "UR"}</span>
        </Button>
      </div>
    </header>
  );
};

export default Header;
