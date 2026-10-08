'use client';

import { useState } from 'react';
import Link from 'next/link';

interface CandidateApp {
  id: number;
  name: string;
  post: string;
  qualification: string;
  experience: string;
  phone: string;
  status: 'شارٹ لسٹ' | 'زیرِ غور' | 'منتخب' | 'مسترد';
}

export default function RecruitmentPage() {
  const [candidates, setCandidates] = useState<CandidateApp[]>([
    { id: 1, name: 'مولانا عاصم منیر', post: 'استاذِ حدیث و فقہ', qualification: 'شہادۃ العالمیہ (وفاق المدارس)', experience: '5 سال تدریسی تجربہ', phone: '03009988771', status: 'شارٹ لسٹ' },
    { id: 2, name: 'قاری محمد عمران', post: 'استاذِ تجوید و حسن صوت', qualification: 'قراءت سبعہ و عشرہ', experience: '3 سال دارالقرآن', phone: '03018877662', status: 'منتخب' },
    { id: 3, name: 'محمد ساجد', post: 'باورچی / کک برائے مطبخ', qualification: 'مڈل', experience: '4 سال ہاسٹل کچن', phone: '03027766553', status: 'زیرِ غور' },
  ]);

  return (
    <div className="flex flex-col bg-slate-50 dark:bg-slate-900 font-urdu min-h-screen transition-colors" dir="rtl">
      <header className="h-20 bg-white/80 dark:bg-slate-800/80 backdrop-blur-md border-b border-slate-200 dark:border-slate-700 flex items-center justify-between px-8 sticky top-0 z-10 transition-colors">
        <div className="flex items-center gap-4">
          <Link href="/" className="text-theme-primary dark:text-theme-light hover:text-theme-hover transition-colors bg-theme-primary/10 p-2 rounded-lg">
            <span className="text-xl">🏠</span>
          </Link>
          <h2 className="text-2xl font-bold text-slate-800 dark:text-slate-100 flex items-center gap-2">
            <span>👔</span> بھرتی و تقرری مینیجمنٹ (Recruitment & Jobs)
          </h2>
        </div>
      </header>

      <main className="flex-1 p-8">
        <div className="bg-white dark:bg-slate-800 rounded-2xl shadow-sm border border-slate-200 dark:border-slate-700 overflow-hidden">
          <div className="p-4 bg-slate-50 dark:bg-slate-700/50 flex justify-between items-center border-b">
            <h3 className="text-lg font-bold">ملازمت کے لیے موصول شدہ درخواستیں</h3>
            <span className="text-sm font-bold text-slate-500">کل امیدوار: {candidates.length}</span>
          </div>

          <table className="w-full text-right border-collapse">
            <thead>
              <tr className="bg-slate-50 dark:bg-slate-700/50 border-b text-slate-600 dark:text-slate-300 font-bold">
                <th className="p-4">#</th>
                <th className="p-4">نام امیدوار</th>
                <th className="p-4">مطلوبہ عہدہ</th>
                <th className="p-4">تعلیم / قابلیت</th>
                <th className="p-4">تدریسی تجربہ</th>
                <th className="p-4">رابطہ نمبر</th>
                <th className="p-4 text-center">کیفیت (Status)</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-slate-100 dark:divide-slate-700">
              {candidates.map((c, i) => (
                <tr key={c.id} className="hover:bg-slate-50 dark:hover:bg-slate-700/50">
                  <td className="p-4 font-bold">{i + 1}</td>
                  <td className="p-4 font-bold text-slate-800 dark:text-white">{c.name}</td>
                  <td className="p-4 font-bold text-indigo-600">{c.post}</td>
                  <td className="p-4">{c.qualification}</td>
                  <td className="p-4 text-sm text-slate-500">{c.experience}</td>
                  <td className="p-4 font-sans text-sm" dir="ltr">{c.phone}</td>
                  <td className="p-4 text-center">
                    <span className={`px-3 py-1 rounded-full text-xs font-bold ${
                      c.status === 'منتخب' ? 'bg-emerald-100 text-emerald-800' :
                      c.status === 'شارٹ لسٹ' ? 'bg-indigo-100 text-indigo-800' :
                      c.status === 'زیرِ غور' ? 'bg-amber-100 text-amber-800' : 'bg-rose-100 text-rose-800'
                    }`}>
                      {c.status}
                    </span>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </main>
    </div>
  );
}
