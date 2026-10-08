'use client';

import { useState } from 'react';
import Link from 'next/link';

interface AlumniStudent {
  id: number;
  name: string;
  father_name: string;
  roll_no: string;
  completion_year: string;
  degree_type: string;
  current_status: string;
  phone: string;
}

export default function AlumniPage() {
  const [alumni, setAlumni] = useState<AlumniStudent[]>([
    { id: 1, name: 'حافظ عمیر احمد', father_name: 'محمد بشیر', roll_no: '850', completion_year: '2024', degree_type: 'حفظِ قرآن کریم', current_status: 'جامع مسجد فروکہ میں تراویح و امامت', phone: '03001234567' },
    { id: 2, name: 'مولانا سعد الرحمن', father_name: 'عبدالرزاق', roll_no: '820', completion_year: '2023', degree_type: 'درس نظامی (عالمیہ)', current_status: 'تدریس مدرسہ عربیہ', phone: '03019876543' },
    { id: 3, name: 'قاری اسامہ نور', father_name: 'نور محمد', roll_no: '810', completion_year: '2023', degree_type: 'تجوید و قراءت سبعہ', current_status: 'دارالقرآن لاہور', phone: '03024567890' },
  ]);

  return (
    <div className="flex flex-col bg-slate-50 dark:bg-slate-900 font-urdu min-h-screen transition-colors" dir="rtl">
      <header className="h-20 bg-white/80 dark:bg-slate-800/80 backdrop-blur-md border-b border-slate-200 dark:border-slate-700 flex items-center justify-between px-8 sticky top-0 z-10 transition-colors">
        <div className="flex items-center gap-4">
          <Link href="/students" className="text-theme-primary dark:text-theme-light hover:text-theme-hover transition-colors bg-theme-primary/10 p-2 rounded-lg">
            <span className="text-xl">⬅️</span>
          </Link>
          <h2 className="text-2xl font-bold text-slate-800 dark:text-slate-100 flex items-center gap-2">
            <span>🏅</span> فارغ التحصیل طلباء کا ریکارڈ (Alumni Students Record)
          </h2>
        </div>
      </header>

      <main className="flex-1 p-8">
        <div className="bg-white dark:bg-slate-800 rounded-2xl shadow-sm border border-slate-200 dark:border-slate-700 overflow-hidden">
          <div className="p-4 bg-slate-50 dark:bg-slate-700/50 flex justify-between items-center border-b">
            <h3 className="text-lg font-bold">فارغ التحصیل حفاظ و علماء کی فہرست</h3>
            <span className="text-sm font-bold text-slate-500">کل تعداد: {alumni.length}</span>
          </div>

          <table className="w-full text-right border-collapse">
            <thead>
              <tr className="bg-slate-50 dark:bg-slate-700/50 border-b text-slate-600 dark:text-slate-300 font-bold">
                <th className="p-4">#</th>
                <th className="p-4">رول نمبر</th>
                <th className="p-4">نام و ولدیت</th>
                <th className="p-4">سند / تکمیل</th>
                <th className="p-4">سالِ فراغت</th>
                <th className="p-4">موجودہ مشغولیت / ادارہ</th>
                <th className="p-4">رابطہ نمبر</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-slate-100 dark:divide-slate-700">
              {alumni.map((a, idx) => (
                <tr key={a.id} className="hover:bg-slate-50 dark:hover:bg-slate-700/50">
                  <td className="p-4 font-bold">{idx + 1}</td>
                  <td className="p-4 font-sans font-bold" dir="ltr">{a.roll_no}</td>
                  <td className="p-4">
                    <div className="font-bold text-slate-800 dark:text-white">{a.name}</div>
                    <div className="text-xs text-slate-400">ولد {a.father_name}</div>
                  </td>
                  <td className="p-4 font-bold text-emerald-700 dark:text-emerald-400">{a.degree_type}</td>
                  <td className="p-4 font-sans font-bold">{a.completion_year}ء</td>
                  <td className="p-4">{a.current_status}</td>
                  <td className="p-4 font-sans text-sm" dir="ltr">{a.phone}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </main>
    </div>
  );
}
