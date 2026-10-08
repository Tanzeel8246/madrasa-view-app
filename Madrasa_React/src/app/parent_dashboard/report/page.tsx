'use client';

import { useState } from 'react';
import Link from 'next/link';

export default function ParentReportPage() {
  return (
    <div className="flex flex-col bg-slate-50 dark:bg-slate-900 font-urdu min-h-screen transition-colors" dir="rtl">
      <header className="h-20 bg-white/80 dark:bg-slate-800/80 backdrop-blur-md border-b border-slate-200 dark:border-slate-700 flex items-center justify-between px-8 sticky top-0 z-10 transition-colors">
        <div className="flex items-center gap-4">
          <Link href="/parent_dashboard" className="text-theme-primary dark:text-theme-light hover:text-theme-hover transition-colors bg-theme-primary/10 p-2 rounded-lg">
            <span className="text-xl">⬅️</span>
          </Link>
          <h2 className="text-2xl font-bold text-slate-800 dark:text-slate-100 flex items-center gap-2">
            <span>📊</span> بچے کی تعلیمی و حاضری پروگریس رپورٹ
          </h2>
        </div>
      </header>

      <main className="flex-1 p-8 max-w-4xl mx-auto w-full">
        <div className="bg-white dark:bg-slate-800 rounded-3xl p-8 shadow-sm border border-slate-200 dark:border-slate-700">
          <div className="flex justify-between items-center border-b pb-4 mb-6">
            <div>
              <h2 className="text-2xl font-bold">محمد عثمان</h2>
              <p className="text-slate-500 font-bold">کلاس: حفظ القرآن (درجہ A) | رول نمبر: 1001</p>
            </div>
            <span className="px-4 py-1.5 bg-emerald-100 text-emerald-800 font-bold rounded-full text-sm">
              فعال و باقاعدہ طالب علم
            </span>
          </div>

          <div className="grid grid-cols-1 md:grid-cols-3 gap-6 mb-8">
            <div className="bg-slate-50 dark:bg-slate-700 p-4 rounded-2xl text-center">
              <span className="text-xs font-bold text-slate-400 block mb-1">حفظ مکمل پارے</span>
              <span className="text-3xl font-extrabold text-indigo-600 font-sans">15 / 30</span>
            </div>
            <div className="bg-slate-50 dark:bg-slate-700 p-4 rounded-2xl text-center">
              <span className="text-xs font-bold text-slate-400 block mb-1">ماہانہ حاضری فیصد</span>
              <span className="text-3xl font-extrabold text-emerald-600 font-sans">96%</span>
            </div>
            <div className="bg-slate-50 dark:bg-slate-700 p-4 rounded-2xl text-center">
              <span className="text-xs font-bold text-slate-400 block mb-1">آخری امتحان پوزیشن</span>
              <span className="text-2xl font-extrabold text-amber-600 font-sans">پہلی پوزیشن 🥇</span>
            </div>
          </div>

          <h3 className="text-lg font-bold mb-3">حالیہ روزانہ کارکردگی نوٹ (استاد صاحب کی طرف سے):</h3>
          <div className="bg-emerald-50 dark:bg-emerald-950/20 border border-emerald-200 dark:border-emerald-800 p-4 rounded-2xl">
            <p className="text-emerald-900 dark:text-emerald-300 font-bold text-base leading-relaxed">
              "ماشاءاللہ طالب علم محمد عثمان روزانہ پابندی سے اپنا سبق اور سبقی یاد کر کے سناتے ہیں۔ تلفظ اور تجوید کے قواعد پر خصوصی محنت جاری ہے۔ گھر میں بھی روزانہ منزل دہرانے کی تلقین فرمائیں۔"
            </p>
            <span className="text-xs text-emerald-700 dark:text-emerald-400 block mt-2 font-bold">— قاری صاحب (نگرانِ کلاس)</span>
          </div>
        </div>
      </main>
    </div>
  );
}
