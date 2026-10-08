'use client';

import { useState } from 'react';
import Link from 'next/link';

interface ExamResult {
  id: number;
  student_name: string;
  father_name: string;
  roll_no: string;
  class_name: string;
  total_marks: number;
  obtained_marks: number;
  percentage: number;
  grade: string;
  position: string;
  marks_detail: { subject: string; max: number; obt: number }[];
}

export default function ExamsPage() {
  const [examType, setExamType] = useState('سالانہ امتحان 1447ھ');
  const [selectedResult, setSelectedResult] = useState<ExamResult | null>(null);

  const [results, setResults] = useState<ExamResult[]>([
    {
      id: 1,
      student_name: 'محمد عثمان',
      father_name: 'عبدالرشید',
      roll_no: '1001',
      class_name: 'حفظ القرآن',
      total_marks: 300,
      obtained_marks: 285,
      percentage: 95,
      grade: 'ممتاز (A+)',
      position: 'پہلی پوزیشن 🥇',
      marks_detail: [
        { subject: 'حفظ و حسنِ صوت', max: 100, obt: 98 },
        { subject: 'تجوید و قواعد', max: 100, obt: 95 },
        { subject: 'نماز و دعائیں', max: 100, obt: 92 },
      ]
    },
    {
      id: 2,
      student_name: 'علی حسن',
      father_name: 'محمد اکرم',
      roll_no: '1002',
      class_name: 'حفظ القرآن',
      total_marks: 300,
      obtained_marks: 264,
      percentage: 88,
      grade: 'جید جداً (A)',
      position: 'دوسری پوزیشن 🥈',
      marks_detail: [
        { subject: 'حفظ و حسنِ صوت', max: 100, obt: 88 },
        { subject: 'تجوید و قواعد', max: 100, obt: 90 },
        { subject: 'نماز و دعائیں', max: 100, obt: 86 },
      ]
    },
    {
      id: 3,
      student_name: 'ابوبکر صدیق',
      father_name: 'طارق محمود',
      roll_no: '1003',
      class_name: 'حفظ القرآن',
      total_marks: 300,
      obtained_marks: 240,
      percentage: 80,
      grade: 'جید (B)',
      position: 'تیسری پوزیشن 🥉',
      marks_detail: [
        { subject: 'حفظ و حسنِ صوت', max: 100, obt: 80 },
        { subject: 'تجوید و قواعد', max: 100, obt: 82 },
        { subject: 'نماز و دعائیں', max: 100, obt: 78 },
      ]
    }
  ]);

  return (
    <div className="flex flex-col bg-slate-50 dark:bg-slate-900 font-urdu min-h-screen transition-colors" dir="rtl">
      <header className="h-20 bg-white/80 dark:bg-slate-800/80 backdrop-blur-md border-b border-slate-200 dark:border-slate-700 flex items-center justify-between px-8 sticky top-0 z-10 print:hidden transition-colors">
        <div className="flex items-center gap-4">
          <Link href="/" className="text-theme-primary dark:text-theme-light hover:text-theme-hover transition-colors bg-theme-primary/10 p-2 rounded-lg">
            <span className="text-xl">🏠</span>
          </Link>
          <h2 className="text-2xl font-bold text-slate-800 dark:text-slate-100 flex items-center gap-2">
            <span>📑</span> امتحانات، کشف الدرجات و رزلٹ کارڈز (Exams & Results)
          </h2>
        </div>
        <div className="flex items-center gap-3">
          <select 
            value={examType} 
            onChange={e => setExamType(e.target.value)}
            className="px-4 py-2 rounded-xl border border-slate-300 dark:border-slate-600 bg-slate-50 dark:bg-slate-700 font-bold"
          >
            <option value="سالانہ امتحان 1447ھ">سالانہ امتحان 1447ھ</option>
            <option value="ششماہی امتحان 1447ھ">ششماہی امتحان 1447ھ</option>
            <option value="سہ ماہی ٹیسٹ">سہ ماہی ٹیسٹ</option>
          </select>
        </div>
      </header>

      <main className="flex-1 p-8 print:p-0">
        <div className="bg-white dark:bg-slate-800 rounded-2xl shadow-sm border border-slate-200 dark:border-slate-700 overflow-hidden print:hidden">
          <div className="p-4 bg-slate-50 dark:bg-slate-700/50 flex justify-between items-center border-b">
            <h3 className="text-lg font-bold">{examType} — نتائج گزٹ (Gazette)</h3>
            <span className="text-sm font-bold text-slate-500">کل طلبہ: {results.length}</span>
          </div>

          <table className="w-full text-right border-collapse">
            <thead>
              <tr className="bg-slate-50 dark:bg-slate-700/50 border-b text-slate-600 dark:text-slate-300 font-bold">
                <th className="p-4">رول نمبر</th>
                <th className="p-4">طالب علم کا نام و ولدیت</th>
                <th className="p-4">کلاس</th>
                <th className="p-4">کل نمبر</th>
                <th className="p-4">حاصل کردہ</th>
                <th className="p-4">فیصد (%)</th>
                <th className="p-4">گریڈ</th>
                <th className="p-4">پوزیشن</th>
                <th className="p-4 text-center">رزلٹ کارڈ</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-slate-100 dark:divide-slate-700">
              {results.map(r => (
                <tr key={r.id} className="hover:bg-slate-50 dark:hover:bg-slate-700/50">
                  <td className="p-4 font-sans font-bold" dir="ltr">{r.roll_no}</td>
                  <td className="p-4">
                    <div className="font-bold text-slate-800 dark:text-slate-100">{r.student_name}</div>
                    <div className="text-xs text-slate-500">ولد {r.father_name}</div>
                  </td>
                  <td className="p-4 font-bold">{r.class_name}</td>
                  <td className="p-4 font-sans font-bold">{r.total_marks}</td>
                  <td className="p-4 font-sans font-bold text-emerald-600">{r.obtained_marks}</td>
                  <td className="p-4 font-sans font-bold">{r.percentage}%</td>
                  <td className="p-4 font-bold text-indigo-600">{r.grade}</td>
                  <td className="p-4 font-bold">{r.position}</td>
                  <td className="p-4 text-center">
                    <button 
                      onClick={() => setSelectedResult(r)}
                      className="px-4 py-1.5 bg-theme-primary text-white rounded-lg text-sm font-bold hover:bg-theme-secondary transition"
                    >
                      🪪 کشف الدرجات دیکھیں
                    </button>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </main>

      {/* Result Card Modal */}
      {selectedResult && (
        <div className="fixed inset-0 bg-slate-900/60 backdrop-blur-sm flex items-center justify-center z-50 p-4">
          <div className="bg-white rounded-3xl shadow-2xl w-full max-w-2xl overflow-hidden border border-slate-300 print:m-0 print:border-none print:shadow-none print:w-full">
            <div className="p-4 bg-slate-100 border-b flex justify-between items-center print:hidden">
              <span className="font-bold text-slate-700">کشف الدرجات پیش نظارہ (Result Card)</span>
              <div className="flex gap-2">
                <button onClick={() => window.print()} className="px-4 py-1.5 bg-emerald-600 text-white rounded-lg font-bold text-sm">پرنٹ کریں</button>
                <button onClick={() => setSelectedResult(null)} className="px-3 py-1.5 bg-slate-200 text-slate-700 rounded-lg text-sm font-bold">بند کریں</button>
              </div>
            </div>

            <div className="p-8 text-slate-900 font-urdu border-4 border-double border-slate-900 m-4 rounded-xl bg-slate-50">
              <div className="text-center border-b-2 border-slate-800 pb-4 mb-4">
                <h1 className="text-3xl font-extrabold text-emerald-900">مدرسہ اشرف المدارس</h1>
                <h3 className="text-xl font-bold mt-1">کشف الدرجات (Result Card) — {examType}</h3>
              </div>

              <div className="grid grid-cols-2 gap-4 mb-6 text-base font-bold bg-white p-4 rounded-xl border">
                <div>نام طالب علم: <span className="text-emerald-800">{selectedResult.student_name}</span></div>
                <div>ولدیت: <span>{selectedResult.father_name}</span></div>
                <div>رول نمبر: <span className="font-sans" dir="ltr">{selectedResult.roll_no}</span></div>
                <div>کلاس: <span>{selectedResult.class_name}</span></div>
              </div>

              {/* Marks Table */}
              <table className="w-full text-center border-collapse border border-slate-400 mb-6 bg-white">
                <thead>
                  <tr className="bg-slate-100 border-b border-slate-400 font-bold">
                    <th className="p-2 border border-slate-400">نمبر شمار</th>
                    <th className="p-2 border border-slate-400 text-right pr-4">مضمون / کتاب</th>
                    <th className="p-2 border border-slate-400">کل نمبر</th>
                    <th className="p-2 border border-slate-400">حاصل کردہ نمبر</th>
                  </tr>
                </thead>
                <tbody>
                  {selectedResult.marks_detail.map((m, idx) => (
                    <tr key={idx} className="border-b border-slate-300">
                      <td className="p-2 border border-slate-400 font-bold">{idx + 1}</td>
                      <td className="p-2 border border-slate-400 text-right pr-4 font-bold">{m.subject}</td>
                      <td className="p-2 border border-slate-400 font-sans">{m.max}</td>
                      <td className="p-2 border border-slate-400 font-sans font-bold text-emerald-700">{m.obt}</td>
                    </tr>
                  ))}
                  <tr className="bg-slate-100 font-bold text-lg">
                    <td colSpan={2} className="p-2 border border-slate-400 text-center">میزان (Total)</td>
                    <td className="p-2 border border-slate-400 font-sans">{selectedResult.total_marks}</td>
                    <td className="p-2 border border-slate-400 font-sans font-extrabold text-emerald-800">{selectedResult.obtained_marks}</td>
                  </tr>
                </tbody>
              </table>

              <div className="flex justify-between items-center bg-emerald-50 border border-emerald-200 p-4 rounded-xl mb-8">
                <div>فیصد: <span className="font-sans font-bold text-xl">{selectedResult.percentage}%</span></div>
                <div>گریڈ: <span className="font-bold text-xl text-emerald-800">{selectedResult.grade}</span></div>
                <div>پوزیشن: <span className="font-bold text-xl text-indigo-800">{selectedResult.position}</span></div>
              </div>

              <div className="flex justify-between items-end mt-12 pt-4">
                <div className="text-center">
                  <div className="w-32 border-b-2 border-black mb-1"></div>
                  <span className="font-bold text-sm">دستخط نگران امتحان</span>
                </div>
                <div className="text-center">
                  <div className="w-32 border-b-2 border-black mb-1"></div>
                  <span className="font-bold text-sm">دستخط ناظمِ تعلیمات</span>
                </div>
                <div className="text-center">
                  <div className="w-32 border-b-2 border-black mb-1"></div>
                  <span className="font-bold text-sm">مہر و دستخط مہتمم</span>
                </div>
              </div>
            </div>
          </div>
        </div>
      )}
    </div>
  );
}
