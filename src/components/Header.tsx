import { Button } from "@/components/ui/button";
import { useLanguage } from "@/contexts/LanguageContext";
import { Languages } from "lucide-react";

const Header = () => {
  const { language, setLanguage, isRTL } = useLanguage();

  const toggleLanguage = () => {
    setLanguage(language === "ur" ? "en" : "ur");
  };

  return (
    <header className="h-16 bg-gradient-to-r from-primary to-primary/90 shadow-md sticky top-0 z-10">
      <div className="h-full px-6 flex items-center justify-between">
        <div className="text-primary-foreground">
          <p className="text-sm opacity-90">
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
