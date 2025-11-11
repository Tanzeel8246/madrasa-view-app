import React, { createContext, useContext, useState, ReactNode } from "react";

type Language = "ur" | "en";

interface LanguageContextType {
  language: Language;
  setLanguage: (lang: Language) => void;
  t: (key: string) => string;
  isRTL: boolean;
}

const translations = {
  ur: {
    // Navigation
    dashboard: "ڈیش بورڈ",
    students: "طلباء",
    attendance: "حاضری",
    fees: "فیس",
    reports: "رپورٹس",
    settings: "ترتیبات",
    
    // Dashboard
    welcomeMessage: "مدرسہ مینجمنٹ سسٹم میں خوش آمدید",
    totalStudents: "کل طلباء",
    presentToday: "آج حاضر",
    pendingFees: "بقایا فیس",
    thisMonth: "اس ماہ",
    
    // Students
    studentsList: "طلباء کی فہرست",
    addStudent: "طالب علم شامل کریں",
    studentName: "طالب علم کا نام",
    fatherName: "والد کا نام",
    class: "جماعت",
    rollNumber: "رول نمبر",
    contact: "رابطہ",
    address: "پتہ",
    actions: "اعمال",
    
    // Attendance
    markAttendance: "حاضری لگائیں",
    present: "حاضر",
    absent: "غیر حاضر",
    date: "تاریخ",
    
    // Fees
    feeManagement: "فیس کا انتظام",
    monthlyFee: "ماہانہ فیس",
    paid: "ادا شدہ",
    pending: "بقایا",
    amount: "رقم",
    paymentDate: "ادائیگی کی تاریخ",
    
    // Common
    save: "محفوظ کریں",
    cancel: "منسوخ کریں",
    edit: "ترمیم",
    delete: "حذف کریں",
    search: "تلاش کریں",
    view: "دیکھیں",
    submit: "جمع کروائیں",
  },
  en: {
    // Navigation
    dashboard: "Dashboard",
    students: "Students",
    attendance: "Attendance",
    fees: "Fees",
    reports: "Reports",
    settings: "Settings",
    
    // Dashboard
    welcomeMessage: "Welcome to Madrasa Management System",
    totalStudents: "Total Students",
    presentToday: "Present Today",
    pendingFees: "Pending Fees",
    thisMonth: "This Month",
    
    // Students
    studentsList: "Students List",
    addStudent: "Add Student",
    studentName: "Student Name",
    fatherName: "Father's Name",
    class: "Class",
    rollNumber: "Roll Number",
    contact: "Contact",
    address: "Address",
    actions: "Actions",
    
    // Attendance
    markAttendance: "Mark Attendance",
    present: "Present",
    absent: "Absent",
    date: "Date",
    
    // Fees
    feeManagement: "Fee Management",
    monthlyFee: "Monthly Fee",
    paid: "Paid",
    pending: "Pending",
    amount: "Amount",
    paymentDate: "Payment Date",
    
    // Common
    save: "Save",
    cancel: "Cancel",
    edit: "Edit",
    delete: "Delete",
    search: "Search",
    view: "View",
    submit: "Submit",
  },
};

const LanguageContext = createContext<LanguageContextType | undefined>(undefined);

export const LanguageProvider = ({ children }: { children: ReactNode }) => {
  const [language, setLanguage] = useState<Language>("ur");
  
  const t = (key: string): string => {
    return translations[language][key as keyof typeof translations.ur] || key;
  };

  const isRTL = language === "ur";

  return (
    <LanguageContext.Provider value={{ language, setLanguage, t, isRTL }}>
      <div dir={isRTL ? "rtl" : "ltr"}>
        {children}
      </div>
    </LanguageContext.Provider>
  );
};

export const useLanguage = () => {
  const context = useContext(LanguageContext);
  if (context === undefined) {
    throw new Error("useLanguage must be used within a LanguageProvider");
  }
  return context;
};
