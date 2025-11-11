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
    teachers: "اساتذہ",
    classes: "کلاسز",
    attendance: "حاضری",
    fees: "فیس",
    reports: "رپورٹس",
    settings: "ترتیبات",
    
    // Dashboard
    welcomeMessage: "مدرسہ مینجمنٹ سسٹم میں خوش آمدید",
    totalStudents: "کل طلباء",
    totalTeachers: "کل اساتذہ",
    totalClasses: "کل کلاسز",
    presentToday: "آج حاضر",
    pendingFees: "بقایا فیس",
    thisMonth: "اس ماہ",
    monthlyRevenue: "ماہانہ آمدن",
    
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
    refresh: "تازہ کریں",
    noRecordsFound: "کوئی ریکارڈ نہیں ملا",
    loading: "لوڈ ہو رہا ہے...",
    
    // Teachers
    teachersList: "اساتذہ کی فہرست",
    addTeacher: "استاد شامل کریں",
    teacherName: "استاد کا نام",
    email: "ای میل",
    subject: "مضمون",
    qualification: "تعلیمی قابلیت",
    
    // Classes
    classesList: "کلاسز کی فہرست",
    addClass: "کلاس شامل کریں",
    className: "کلاس کا نام",
    description: "تفصیل",
    teacher: "استاد",
    selectTeacher: "استاد منتخب کریں",
    selectClass: "کلاس منتخب کریں",
    
    // Attendance
    markAttendance: "حاضری لگائیں",
    present: "حاضر",
    absent: "غیر حاضر",
    date: "تاریخ",
    status: "حالت",
    notes: "نوٹس",
    
    // Fees
    feeManagement: "فیس کا انتظام",
    addFee: "فیس شامل کریں",
    monthlyFee: "ماہانہ فیس",
    paid: "ادا شدہ",
    pending: "بقایا",
    amount: "رقم",
    paidAmount: "ادا شدہ رقم",
    paymentDate: "ادائیگی کی تاریخ",
    month: "مہینہ",
    year: "سال",
    
    // Common
    save: "محفوظ کریں",
    cancel: "منسوخ کریں",
    edit: "ترمیم",
    delete: "حذف کریں",
    search: "تلاش کریں",
    view: "دیکھیں",
    submit: "جمع کروائیں",
    name: "نام",
    addedSuccessfully: "کامیابی سے شامل ہو گیا",
    errorOccurred: "ایک خرابی پیش آئی",
  },
  en: {
    // Navigation
    dashboard: "Dashboard",
    students: "Students",
    teachers: "Teachers",
    classes: "Classes",
    attendance: "Attendance",
    fees: "Fees",
    reports: "Reports",
    settings: "Settings",
    
    // Dashboard
    welcomeMessage: "Welcome to Madrasa Management System",
    totalStudents: "Total Students",
    totalTeachers: "Total Teachers",
    totalClasses: "Total Classes",
    presentToday: "Present Today",
    pendingFees: "Pending Fees",
    thisMonth: "This Month",
    monthlyRevenue: "Monthly Revenue",
    
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
    refresh: "Refresh",
    noRecordsFound: "No records found",
    loading: "Loading...",
    
    // Teachers
    teachersList: "Teachers List",
    addTeacher: "Add Teacher",
    teacherName: "Teacher Name",
    email: "Email",
    subject: "Subject",
    qualification: "Qualification",
    
    // Classes
    classesList: "Classes List",
    addClass: "Add Class",
    className: "Class Name",
    description: "Description",
    teacher: "Teacher",
    selectTeacher: "Select Teacher",
    selectClass: "Select Class",
    
    // Attendance
    markAttendance: "Mark Attendance",
    present: "Present",
    absent: "Absent",
    date: "Date",
    status: "Status",
    notes: "Notes",
    
    // Fees
    feeManagement: "Fee Management",
    addFee: "Add Fee",
    monthlyFee: "Monthly Fee",
    paid: "Paid",
    pending: "Pending",
    amount: "Amount",
    paidAmount: "Paid Amount",
    paymentDate: "Payment Date",
    month: "Month",
    year: "Year",
    
    // Common
    save: "Save",
    cancel: "Cancel",
    edit: "Edit",
    delete: "Delete",
    search: "Search",
    view: "View",
    submit: "Submit",
    name: "Name",
    addedSuccessfully: "Added successfully",
    errorOccurred: "An error occurred",
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
