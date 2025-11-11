import { useState, useEffect } from "react";
import { useLanguage } from "@/contexts/LanguageContext";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";
import { Textarea } from "@/components/ui/textarea";
import { supabase } from "@/integrations/supabase/client";
import { getMadrasahId } from "@/lib/madrasahUtils";
import { toast } from "@/hooks/use-toast";
import { BookOpen } from "lucide-react";

interface Student {
  id: string;
  name: string;
  father_name: string;
  class: string;
  classes?: {
    name: string;
  };
}

interface Teacher {
  id: string;
  name: string;
}

const LearningReport = () => {
  const { t, isRTL } = useLanguage();
  const [students, setStudents] = useState<Student[]>([]);
  const [teachers, setTeachers] = useState<Teacher[]>([]);
  const [selectedStudent, setSelectedStudent] = useState<Student | null>(null);
  const [date, setDate] = useState(new Date().toISOString().split('T')[0]);
  const [classType, setClassType] = useState<string>("");
  const [loading, setLoading] = useState(false);

  // Hifz fields
  const [sabaqAmount, setSabaqAmount] = useState("");
  const [sabaqPara, setSabaqPara] = useState("");
  const [sabaqLinesPages, setSabaqLinesPages] = useState("");
  const [sabqiPara, setSabqiPara] = useState("");
  const [sabqiAmount, setSabqiAmount] = useState("");
  const [sabqiListenerName, setSabqiListenerName] = useState("");
  const [sabqiListenerType, setSabqiListenerType] = useState<"teacher" | "other">("teacher");
  const [sabqiCustomListenerName, setSabqiCustomListenerName] = useState("");
  const [manzilAmount, setManzilAmount] = useState("");
  const [manzilParas, setManzilParas] = useState("");
  const [manzilListenerName, setManzilListenerName] = useState("");
  const [manzilListenerType, setManzilListenerType] = useState<"teacher" | "other">("teacher");
  const [manzilCustomListenerName, setManzilCustomListenerName] = useState("");
  const [manzilSelectedParas, setManzilSelectedParas] = useState<number[]>([]);

  // Dars Nizami & Modern Education fields
  const [period1, setPeriod1] = useState("");
  const [period2, setPeriod2] = useState("");
  const [period3, setPeriod3] = useState("");
  const [period4, setPeriod4] = useState("");
  const [period5, setPeriod5] = useState("");
  const [period6, setPeriod6] = useState("");

  const [notes, setNotes] = useState("");

  useEffect(() => {
    fetchStudents();
    fetchTeachers();
  }, []);

  const fetchStudents = async () => {
    const madrasahId = await getMadrasahId();
    if (!madrasahId) return;

    const { data, error } = await supabase
      .from("students")
      .select("id, name, father_name, class, classes(name)")
      .eq("madrasah_id", madrasahId)
      .order("name");

    if (error) {
      toast({
        title: t("errorOccurred"),
        description: error.message,
        variant: "destructive",
      });
    } else {
      setStudents(data || []);
    }
  };

  const fetchTeachers = async () => {
    const madrasahId = await getMadrasahId();
    if (!madrasahId) return;

    const { data, error } = await supabase
      .from("teachers")
      .select("id, name")
      .eq("madrasah_id", madrasahId)
      .order("name");

    if (error) {
      console.error("Error fetching teachers:", error);
    } else {
      setTeachers(data || []);
    }
  };

  const handleStudentSelect = (studentId: string) => {
    const student = students.find((s) => s.id === studentId);
    if (student) {
      setSelectedStudent(student);
      
      // Auto-detect class type based on class name
      const className = (student.classes?.name || student.class || "").toLowerCase();
      if (className.includes("حفظ") || className.includes("hifz")) {
        setClassType("quran_hifz");
      } else if (className.includes("ناظرہ") || className.includes("nazira")) {
        setClassType("quran_nazira");
      } else if (className.includes("درس نظامی") || className.includes("dars")) {
        setClassType("dars_nizami");
      } else {
        setClassType("modern_education");
      }
    }
  };

  const resetForm = () => {
    setSabaqAmount("");
    setSabaqPara("");
    setSabaqLinesPages("");
    setSabqiPara("");
    setSabqiAmount("");
    setSabqiListenerName("");
    setSabqiListenerType("teacher");
    setSabqiCustomListenerName("");
    setManzilAmount("");
    setManzilParas("");
    setManzilListenerName("");
    setManzilListenerType("teacher");
    setManzilCustomListenerName("");
    setManzilSelectedParas([]);
    setPeriod1("");
    setPeriod2("");
    setPeriod3("");
    setPeriod4("");
    setPeriod5("");
    setPeriod6("");
    setNotes("");
  };

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!selectedStudent) {
      toast({
        title: t("errorOccurred"),
        description: isRTL ? "طالب علم منتخب کریں" : "Please select a student",
        variant: "destructive",
      });
      return;
    }

    setLoading(true);
    const madrasahId = await getMadrasahId();

    // Check if any learning data is entered
    const hasLearningData = (classType === "quran_hifz" || classType === "quran_nazira") 
      ? (sabaqAmount || sabaqPara || sabqiAmount || sabqiPara || manzilAmount)
      : (period1 || period2 || period3 || period4 || period5 || period6);

    const reportData: any = {
      madrasah_id: madrasahId,
      student_id: selectedStudent.id,
      date,
      class_type: classType,
      notes: hasLearningData ? notes : (isRTL ? "غیر حاضر" : "Absent"),
    };

    // Add fields based on class type
    if (classType === "quran_hifz") {
      reportData.sabaq_amount = sabaqAmount;
      reportData.sabaq_para_number = sabaqPara ? parseInt(sabaqPara) : null;
      reportData.sabaq_lines_pages = sabaqLinesPages;
      reportData.sabqi_para = sabqiPara ? parseInt(sabqiPara) : null;
      reportData.sabqi_amount = sabqiAmount;
      reportData.sabqi_listener_name = sabqiListenerType === "other" ? sabqiCustomListenerName : sabqiListenerName;
      reportData.manzil_amount = manzilAmount;
      reportData.manzil_paras = manzilParas;
      reportData.manzil_listener_name = manzilListenerType === "other" ? manzilCustomListenerName : manzilListenerName;
      reportData.manzil_selected_paras = manzilSelectedParas;
    } else if (classType === "quran_nazira") {
      reportData.sabaq_amount = sabaqAmount;
      reportData.sabaq_para_number = sabaqPara ? parseInt(sabaqPara) : null;
      reportData.sabaq_lines_pages = sabaqLinesPages;
      reportData.manzil_amount = manzilAmount;
      reportData.manzil_paras = manzilParas;
      reportData.manzil_listener_name = manzilListenerType === "other" ? manzilCustomListenerName : manzilListenerName;
      reportData.manzil_selected_paras = manzilSelectedParas;
    } else if (classType === "dars_nizami" || classType === "modern_education") {
      reportData.period_1 = period1;
      reportData.period_2 = period2;
      reportData.period_3 = period3;
      reportData.period_4 = period4;
      reportData.period_5 = period5;
      reportData.period_6 = period6;
    }

    const { error } = await supabase
      .from("learning_reports")
      .insert(reportData);

    if (error) {
      toast({
        title: t("errorOccurred"),
        description: error.message,
        variant: "destructive",
      });
    } else {
      toast({
        title: t("addedSuccessfully"),
        description: isRTL ? "تعلیمی کارکردگی محفوظ ہو گئی" : "Learning report saved successfully",
      });
      resetForm();
      setSelectedStudent(null);
    }

    setLoading(false);
  };

  return (
    <div className="space-y-6">
      <div className="flex items-center justify-between">
        <div className="flex items-center gap-3">
          <BookOpen className="w-8 h-8 text-primary" />
          <h1 className="text-3xl font-bold">
            {isRTL ? "تعلیمی کارکردگی" : "Learning Report"}
          </h1>
        </div>
      </div>

      <Card>
        <CardHeader>
          <CardTitle>{isRTL ? "طالب علم کی تعلیمی کارکردگی درج کریں" : "Record Student Learning Performance"}</CardTitle>
        </CardHeader>
        <CardContent>
          <form onSubmit={handleSubmit} className="space-y-6">
            {/* Student Selection */}
            <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
              <div className="space-y-2">
                <Label>{t("student")}</Label>
                <Select
                  value={selectedStudent?.id || ""}
                  onValueChange={handleStudentSelect}
                >
                  <SelectTrigger>
                    <SelectValue placeholder={isRTL ? "طالب علم منتخب کریں" : "Select Student"} />
                  </SelectTrigger>
                  <SelectContent>
                    {students.map((student) => (
                      <SelectItem key={student.id} value={student.id}>
                        {student.name}
                      </SelectItem>
                    ))}
                  </SelectContent>
                </Select>
              </div>

              <div className="space-y-2">
                <Label>{t("fatherName")}</Label>
                <Input
                  value={selectedStudent?.father_name || ""}
                  disabled
                  className="bg-muted"
                />
              </div>

              <div className="space-y-2">
                <Label>{t("class")}</Label>
                <Input
                  value={selectedStudent?.classes?.name || selectedStudent?.class || ""}
                  disabled
                  className="bg-muted"
                />
              </div>
            </div>

            <div className="space-y-2">
              <Label>{t("date")}</Label>
              <Input
                type="date"
                value={date}
                onChange={(e) => setDate(e.target.value)}
                required
              />
            </div>

            {/* Quran Hifz Fields */}
            {classType === "quran_hifz" && (
              <>
                <div className="border-t pt-4">
                  <h3 className="text-lg font-semibold mb-4">
                    {isRTL ? "سبق" : "Sabaq (Lesson)"}
                  </h3>
                  <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
                    <div className="space-y-2">
                      <Label>{isRTL ? "پارہ نمبر" : "Para Number"}</Label>
                      <Select value={sabaqPara} onValueChange={setSabaqPara}>
                        <SelectTrigger>
                          <SelectValue placeholder={isRTL ? "پارہ منتخب کریں" : "Select Para"} />
                        </SelectTrigger>
                        <SelectContent>
                          {Array.from({ length: 30 }, (_, i) => i + 1).map((num) => (
                            <SelectItem key={num} value={num.toString()}>
                              {isRTL ? `پارہ ${num}` : `Para ${num}`}
                            </SelectItem>
                          ))}
                        </SelectContent>
                      </Select>
                    </div>
                    <div className="space-y-2">
                      <Label>{isRTL ? "سطریں / صفحات" : "Lines / Pages"}</Label>
                      <Select value={sabaqLinesPages} onValueChange={setSabaqLinesPages}>
                        <SelectTrigger>
                          <SelectValue placeholder={isRTL ? "منتخب کریں" : "Select"} />
                        </SelectTrigger>
                        <SelectContent>
                          <SelectItem value="lines">{isRTL ? "سطریں" : "Lines"}</SelectItem>
                          <SelectItem value="pages">{isRTL ? "صفحات" : "Pages"}</SelectItem>
                        </SelectContent>
                      </Select>
                    </div>
                    <div className="space-y-2">
                      <Label>{isRTL ? "سبق کی مقدار" : "Sabaq Amount"}</Label>
                      <Input
                        value={sabaqAmount}
                        onChange={(e) => setSabaqAmount(e.target.value)}
                        placeholder={
                          sabaqLinesPages === "lines" 
                            ? (isRTL ? "مثلاً: 5 سطریں" : "e.g., 5 lines")
                            : sabaqLinesPages === "pages"
                            ? (isRTL ? "مثلاً: 2 صفحات" : "e.g., 2 pages")
                            : (isRTL ? "مقدار درج کریں" : "Enter amount")
                        }
                      />
                    </div>
                  </div>
                </div>

                <div className="border-t pt-4">
                  <h3 className="text-lg font-semibold mb-4">
                    {isRTL ? "سبقی" : "Sabqi (Revision)"}
                  </h3>
                  <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
                    <div className="space-y-2">
                      <Label>{isRTL ? "سبقی پارہ" : "Sabqi Para"}</Label>
                      <Select value={sabqiPara} onValueChange={setSabqiPara}>
                        <SelectTrigger>
                          <SelectValue placeholder={isRTL ? "پارہ منتخب کریں" : "Select Para"} />
                        </SelectTrigger>
                        <SelectContent>
                          {Array.from({ length: 30 }, (_, i) => i + 1).map((num) => (
                            <SelectItem key={num} value={num.toString()}>
                              {isRTL ? `پارہ ${num}` : `Para ${num}`}
                            </SelectItem>
                          ))}
                        </SelectContent>
                      </Select>
                    </div>
                    <div className="space-y-2">
                      <Label>{isRTL ? "سبقی کی مقدار" : "Sabqi Amount"}</Label>
                      <Select value={sabqiAmount} onValueChange={setSabqiAmount}>
                        <SelectTrigger>
                          <SelectValue placeholder={isRTL ? "منتخب کریں" : "Select"} />
                        </SelectTrigger>
                        <SelectContent>
                          <SelectItem value="rub">{isRTL ? "ربع" : "Rub"}</SelectItem>
                          <SelectItem value="nisf">{isRTL ? "نصف" : "Nisf"}</SelectItem>
                          <SelectItem value="salasa">{isRTL ? "ثلاثۃ" : "Salasa"}</SelectItem>
                          <SelectItem value="para">{isRTL ? "پارہ" : "Para"}</SelectItem>
                        </SelectContent>
                      </Select>
                    </div>
                    <div className="space-y-2">
                      <Label>{isRTL ? "سننے والے کا نام" : "Listener Name"}</Label>
                      <Select 
                        value={sabqiListenerType === "teacher" ? sabqiListenerName : "other"} 
                        onValueChange={(value) => {
                          if (value === "other") {
                            setSabqiListenerType("other");
                            setSabqiListenerName("");
                          } else {
                            setSabqiListenerType("teacher");
                            setSabqiListenerName(value);
                          }
                        }}
                      >
                        <SelectTrigger>
                          <SelectValue placeholder={isRTL ? "منتخب کریں" : "Select"} />
                        </SelectTrigger>
                        <SelectContent>
                          {teachers.map((teacher) => (
                            <SelectItem key={teacher.id} value={teacher.name}>
                              {teacher.name}
                            </SelectItem>
                          ))}
                          <SelectItem value="other">{isRTL ? "دیگر" : "Other"}</SelectItem>
                        </SelectContent>
                      </Select>
                      {sabqiListenerType === "other" && (
                        <Input
                          value={sabqiCustomListenerName}
                          onChange={(e) => setSabqiCustomListenerName(e.target.value)}
                          placeholder={isRTL ? "نام درج کریں" : "Enter name"}
                          className="mt-2"
                        />
                      )}
                    </div>
                  </div>
                </div>

                <div className="border-t pt-4">
                  <h3 className="text-lg font-semibold mb-4">
                    {isRTL ? "منزل" : "Manzil"}
                  </h3>
                  <div className="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                    <div className="space-y-2">
                      <Label>{isRTL ? "منزل کی مقدار" : "Manzil Amount"}</Label>
                      <Select value={manzilAmount} onValueChange={setManzilAmount}>
                        <SelectTrigger>
                          <SelectValue placeholder={isRTL ? "منتخب کریں" : "Select"} />
                        </SelectTrigger>
                        <SelectContent>
                          <SelectItem value="1">{isRTL ? "ایک پارہ" : "1 Para"}</SelectItem>
                          <SelectItem value="2">{isRTL ? "دو پارے" : "2 Paras"}</SelectItem>
                          <SelectItem value="3">{isRTL ? "تین پارے" : "3 Paras"}</SelectItem>
                          <SelectItem value="4">{isRTL ? "چار پارے" : "4 Paras"}</SelectItem>
                          <SelectItem value="5">{isRTL ? "پانچ پارے" : "5 Paras"}</SelectItem>
                        </SelectContent>
                      </Select>
                    </div>
                    <div className="space-y-2">
                      <Label>{isRTL ? "سننے والے کا نام" : "Listener Name"}</Label>
                      <Select 
                        value={manzilListenerType === "teacher" ? manzilListenerName : "other"} 
                        onValueChange={(value) => {
                          if (value === "other") {
                            setManzilListenerType("other");
                            setManzilListenerName("");
                          } else {
                            setManzilListenerType("teacher");
                            setManzilListenerName(value);
                          }
                        }}
                      >
                        <SelectTrigger>
                          <SelectValue placeholder={isRTL ? "منتخب کریں" : "Select"} />
                        </SelectTrigger>
                        <SelectContent>
                          {teachers.map((teacher) => (
                            <SelectItem key={teacher.id} value={teacher.name}>
                              {teacher.name}
                            </SelectItem>
                          ))}
                          <SelectItem value="other">{isRTL ? "دیگر" : "Other"}</SelectItem>
                        </SelectContent>
                      </Select>
                      {manzilListenerType === "other" && (
                        <Input
                          value={manzilCustomListenerName}
                          onChange={(e) => setManzilCustomListenerName(e.target.value)}
                          placeholder={isRTL ? "نام درج کریں" : "Enter name"}
                          className="mt-2"
                        />
                      )}
                    </div>
                  </div>
                  <div className="space-y-2">
                    <Label>{isRTL ? "پارے منتخب کریں" : "Select Paras"}</Label>
                    <div className="grid grid-cols-5 md:grid-cols-10 gap-2">
                      {Array.from({ length: 30 }, (_, i) => i + 1).map((num) => {
                        const maxParas = manzilAmount ? parseInt(manzilAmount) : 30;
                        const isDisabled = !manzilSelectedParas.includes(num) && manzilSelectedParas.length >= maxParas;
                        
                        return (
                          <label key={num} className={`flex items-center space-x-2 ${isDisabled ? 'cursor-not-allowed opacity-50' : 'cursor-pointer'}`}>
                            <input
                              type="checkbox"
                              checked={manzilSelectedParas.includes(num)}
                              disabled={isDisabled}
                              onChange={(e) => {
                                if (e.target.checked) {
                                  if (manzilSelectedParas.length < maxParas) {
                                    setManzilSelectedParas([...manzilSelectedParas, num]);
                                  }
                                } else {
                                  setManzilSelectedParas(manzilSelectedParas.filter(p => p !== num));
                                }
                              }}
                              className="rounded border-gray-300"
                            />
                            <span className="text-sm">{num}</span>
                          </label>
                        );
                      })}
                    </div>
                  </div>
                </div>
              </>
            )}

            {/* Quran Nazira Fields */}
            {classType === "quran_nazira" && (
              <>
                <div className="border-t pt-4">
                  <h3 className="text-lg font-semibold mb-4">
                    {isRTL ? "سبق" : "Sabaq (Lesson)"}
                  </h3>
                  <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
                    <div className="space-y-2">
                      <Label>{isRTL ? "پارہ نمبر" : "Para Number"}</Label>
                      <Select value={sabaqPara} onValueChange={setSabaqPara}>
                        <SelectTrigger>
                          <SelectValue placeholder={isRTL ? "پارہ منتخب کریں" : "Select Para"} />
                        </SelectTrigger>
                        <SelectContent>
                          {Array.from({ length: 30 }, (_, i) => i + 1).map((num) => (
                            <SelectItem key={num} value={num.toString()}>
                              {isRTL ? `پارہ ${num}` : `Para ${num}`}
                            </SelectItem>
                          ))}
                        </SelectContent>
                      </Select>
                    </div>
                    <div className="space-y-2">
                      <Label>{isRTL ? "سطریں / صفحات" : "Lines / Pages"}</Label>
                      <Select value={sabaqLinesPages} onValueChange={setSabaqLinesPages}>
                        <SelectTrigger>
                          <SelectValue placeholder={isRTL ? "منتخب کریں" : "Select"} />
                        </SelectTrigger>
                        <SelectContent>
                          <SelectItem value="lines">{isRTL ? "سطریں" : "Lines"}</SelectItem>
                          <SelectItem value="pages">{isRTL ? "صفحات" : "Pages"}</SelectItem>
                        </SelectContent>
                      </Select>
                    </div>
                    <div className="space-y-2">
                      <Label>{isRTL ? "سبق کی مقدار" : "Sabaq Amount"}</Label>
                      <Input
                        value={sabaqAmount}
                        onChange={(e) => setSabaqAmount(e.target.value)}
                        placeholder={
                          sabaqLinesPages === "lines" 
                            ? (isRTL ? "مثلاً: 5 سطریں" : "e.g., 5 lines")
                            : sabaqLinesPages === "pages"
                            ? (isRTL ? "مثلاً: 2 صفحات" : "e.g., 2 pages")
                            : (isRTL ? "مقدار درج کریں" : "Enter amount")
                        }
                      />
                    </div>
                  </div>
                </div>

                <div className="border-t pt-4">
                  <h3 className="text-lg font-semibold mb-4">
                    {isRTL ? "منزل" : "Manzil"}
                  </h3>
                  <div className="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                    <div className="space-y-2">
                      <Label>{isRTL ? "منزل کی مقدار" : "Manzil Amount"}</Label>
                      <Select value={manzilAmount} onValueChange={setManzilAmount}>
                        <SelectTrigger>
                          <SelectValue placeholder={isRTL ? "منتخب کریں" : "Select"} />
                        </SelectTrigger>
                        <SelectContent>
                          <SelectItem value="1">{isRTL ? "ایک پارہ" : "1 Para"}</SelectItem>
                          <SelectItem value="2">{isRTL ? "دو پارے" : "2 Paras"}</SelectItem>
                          <SelectItem value="3">{isRTL ? "تین پارے" : "3 Paras"}</SelectItem>
                          <SelectItem value="4">{isRTL ? "چار پارے" : "4 Paras"}</SelectItem>
                          <SelectItem value="5">{isRTL ? "پانچ پارے" : "5 Paras"}</SelectItem>
                        </SelectContent>
                      </Select>
                    </div>
                    <div className="space-y-2">
                      <Label>{isRTL ? "سننے والے کا نام" : "Listener Name"}</Label>
                      <Select 
                        value={manzilListenerType === "teacher" ? manzilListenerName : "other"} 
                        onValueChange={(value) => {
                          if (value === "other") {
                            setManzilListenerType("other");
                            setManzilListenerName("");
                          } else {
                            setManzilListenerType("teacher");
                            setManzilListenerName(value);
                          }
                        }}
                      >
                        <SelectTrigger>
                          <SelectValue placeholder={isRTL ? "منتخب کریں" : "Select"} />
                        </SelectTrigger>
                        <SelectContent>
                          {teachers.map((teacher) => (
                            <SelectItem key={teacher.id} value={teacher.name}>
                              {teacher.name}
                            </SelectItem>
                          ))}
                          <SelectItem value="other">{isRTL ? "دیگر" : "Other"}</SelectItem>
                        </SelectContent>
                      </Select>
                      {manzilListenerType === "other" && (
                        <Input
                          value={manzilCustomListenerName}
                          onChange={(e) => setManzilCustomListenerName(e.target.value)}
                          placeholder={isRTL ? "نام درج کریں" : "Enter name"}
                          className="mt-2"
                        />
                      )}
                    </div>
                  </div>
                  <div className="space-y-2">
                    <Label>{isRTL ? "پارے منتخب کریں" : "Select Paras"}</Label>
                    <div className="grid grid-cols-5 md:grid-cols-10 gap-2">
                      {Array.from({ length: 30 }, (_, i) => i + 1).map((num) => {
                        const maxParas = manzilAmount ? parseInt(manzilAmount) : 30;
                        const isDisabled = !manzilSelectedParas.includes(num) && manzilSelectedParas.length >= maxParas;
                        
                        return (
                          <label key={num} className={`flex items-center space-x-2 ${isDisabled ? 'cursor-not-allowed opacity-50' : 'cursor-pointer'}`}>
                            <input
                              type="checkbox"
                              checked={manzilSelectedParas.includes(num)}
                              disabled={isDisabled}
                              onChange={(e) => {
                                if (e.target.checked) {
                                  if (manzilSelectedParas.length < maxParas) {
                                    setManzilSelectedParas([...manzilSelectedParas, num]);
                                  }
                                } else {
                                  setManzilSelectedParas(manzilSelectedParas.filter(p => p !== num));
                                }
                              }}
                              className="rounded border-gray-300"
                            />
                            <span className="text-sm">{num}</span>
                          </label>
                        );
                      })}
                    </div>
                  </div>
                </div>
              </>
            )}

            {/* Dars Nizami & Modern Education Fields */}
            {(classType === "dars_nizami" || classType === "modern_education") && (
              <div className="border-t pt-4">
                <h3 className="text-lg font-semibold mb-4">
                  {isRTL ? "پیریڈز کی کارکردگی" : "Period Performance"}
                </h3>
                <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                  {[1, 2, 3, 4, 5, 6].map((num) => {
                    const period = num === 1 ? period1 : num === 2 ? period2 : num === 3 ? period3 : num === 4 ? period4 : num === 5 ? period5 : period6;
                    const setPeriod = num === 1 ? setPeriod1 : num === 2 ? setPeriod2 : num === 3 ? setPeriod3 : num === 4 ? setPeriod4 : num === 5 ? setPeriod5 : setPeriod6;
                    
                    return (
                      <div key={num} className="space-y-2">
                        <Label>{isRTL ? `پیریڈ ${num}` : `Period ${num}`}</Label>
                        <Input
                          value={period}
                          onChange={(e) => setPeriod(e.target.value)}
                          placeholder={isRTL ? "کارکردگی درج کریں" : "Enter performance"}
                        />
                      </div>
                    );
                  })}
                </div>
              </div>
            )}

            <div className="space-y-2">
              <Label>{t("notes")}</Label>
              <Textarea
                value={notes}
                onChange={(e) => setNotes(e.target.value)}
                placeholder={isRTL ? "اضافی نوٹس" : "Additional notes"}
                rows={3}
              />
            </div>

            <div className="flex gap-4">
              <Button type="submit" disabled={loading || !selectedStudent}>
                {loading ? t("loading") : t("save")}
              </Button>
              <Button
                type="button"
                variant="outline"
                onClick={resetForm}
              >
                {t("cancel")}
              </Button>
            </div>
          </form>
        </CardContent>
      </Card>
    </div>
  );
};

export default LearningReport;
