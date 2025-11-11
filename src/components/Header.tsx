import { Button } from "@/components/ui/button";
import { useLanguage } from "@/contexts/LanguageContext";
import { Languages } from "lucide-react";
import { ReactNode } from "react";

interface HeaderProps {
  children?: ReactNode;
}

const Header = ({ children }: HeaderProps = {}) => {
  const { language, setLanguage, isRTL } = useLanguage();

  const toggleLanguage = () => {
    setLanguage(language === "ur" ? "en" : "ur");
  };

  return (
    <header className="h-16 bg-gradient-to-r from-primary to-primary/90 shadow-md sticky top-0 z-10">
      <div className="h-full px-4 md:px-6 flex items-center justify-between">
        <div className="flex items-center gap-2 md:gap-4 text-primary-foreground">
          {children}
          <p className="text-xs md:text-sm opacity-90">
            {isRTL ? "السلام علیکم" : "As-Salaam-Alaikum"}
          </p>
        </div>
        <Button
          variant="outline"
          size="sm"
          onClick={toggleLanguage}
          className="bg-white/10 text-primary-foreground border-white/20 hover:bg-white/20"
        >
          <Languages className="w-4 h-4 mr-2" />
          {language === "ur" ? "English" : "اردو"}
        </Button>
      </div>
    </header>
  );
};

export default Header;
