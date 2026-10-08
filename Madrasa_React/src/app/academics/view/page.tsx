'use client';

import { useState } from 'react';
import Link from 'next/link';

export default function AcademicViewPage() {
  const [selectedStudent, setSelectedStudent] = useState('1');

  const studentsList = [
    { id: '1', name: 'محمد عثمان', roll: '1001', class: 'حفظ کلاس A', completedParas: 15, currentPara: 'پارہ 16', monthlyDays: 26, presentDays: 25, naaghah: 1, grade: 'A+' },
    { id: '2', name: 'علی حسن', roll: '1002', class: 'ناظرہ قرآن', completedParas: 8, currentPara: 'پارہ 9', monthlyDays: 26, presentDays: 23, naaghah: 3, grade: 'B+' },
    { id: '3', name: 'ابوبکر صدیق', roll: '1003', class: 'نورانی قاعدہ', completedParas: 2, currentPara: 'تختی 12', monthlyDays: 26, presentDays: 24, naaghah: 2, grade: 'B' },
  ];

  const currentStd = studentsList.find(s => s.id === selectedStudent) || studentsList[0];

  return (
    <div className="flex flex-col bg-slate-50 dark:bg-slate-900 font-urdu min-h-screen transition-colors" dir="rtl">
      <header className="h-20 bg-white/80 dark:bg-slate-800/80 backdrop-blur-md border-b border-slate-200 dark:border-slate-700 flex items-center justify-between px-8 sticky top-0 z-10 print:hidden transition-colors">
        <div className="flex items-center gap-4">
          <Link href="/academics/entry" className="text-theme-primary dark:text-theme-light hover:text-theme-hover transition-colors bg-theme-primary/10 p-2 rounded-lg">
            <span className="text-xl">⬅️</span>
          </Link>
          <h2 className="text-2xl font-bold text-slate-800 dark:text-slate-100 flex items-center gap-2">
            <span>📈</span> تعلیمی کارکردگی و پروگریس رپورٹ (Academic Performance)
          </h2>
        </div>
        <button onClick={() => window.print()} className="px-5 py-2 bg-emerald-600 hover:bg-emerald-700 text-white rounded-xl font-bold shadow-md">
          🖨️ تعلیمی رپورٹ پرنٹ کریں
        </button>
      </header>

      <main className="flex-1 p-8 print:p-0">
        {/* Selector Bar */}
        <div className="bg-white dark:bg-slate-800 p-4 rounded-2xl shadow-sm border border-slate-200 dark:border-slate-700 mb-6 flex gap-4 items-center print:hidden">
          <span className="font-bold text-slate-700 dark:text-slate-300">طالب علم منتخب کریں:</span>
          <select 
            value={selectedStudent} 
            onChange={e => setSelectedStudent(e.target.value)}
            className="px-4 py-2 rounded-xl border border-slate-300 dark:border-slate-600 bg-slate-50 dark:bg-slate-700 font-bold text-base"
          >
            {studentsList.map(s => (
              <option key={s.id} value={s.id}>{s.name} (رول: {s.roll}) - {s.class}</option>
            ))}
          </select>
        </div>

        {/* Student Progress Card */}
        <div className="bg-white dark:bg-slate-800 rounded-3xl shadow-sm border border-slate-200 dark:border-slate-700 p-8">
          <div className="flex justify-between items-center border-b pb-6 mb-6">
            <div>
              <h2 className="text-3xl font-extrabold text-slate-800 dark:text-white">{currentStd.name}</h2>
              <p className="text-slate-500 font-bold mt-1">کلاس: {currentStd.class} | رول نمبر: <span className="font-sans" dir="ltr">{currentStd.roll}</span></p>
            </div>
            <div className="text-left">
              <span className="text-sm font-bold text-slate-400 block">مجموعی گریڈ</span>
              <span className="text-4xl font-extrabold text-emerald-600 font-sans">{currentStd.grade}</span>
            </div>
          </div>

          {/* Metrics */}
          <div className="grid grid-cols-1 md:grid-cols-4 gap-6 mb-8">
            <div className="bg-slate-50 dark:bg-slate-700/50 p-4 rounded-2xl border text-center">
              <span className="text-xs font-bold text-slate-500 block mb-1">حفظ مکمل پارے</span>
              <span className="text-3xl font-black text-indigo-600 font-sans">{currentStd.completedParas} / 30</span>
            </div>
            <div className="bg-slate-50 dark:bg-slate-700/50 p-4 rounded-2xl border text-center">
              <span className="text-xs font-bold text-slate-500 block mb-1">زیرِ سبق پارہ</span>
              <span className="text-2xl font-bold text-slate-800 dark:text-white">{currentStd.currentPara}</span>
            </div>
            <div className="bg-slate-50 dark:bg-slate-700/50 p-4 rounded-2xl border text-center">
              <span className="text-xs font-bold text-slate-500 block mb-1">ماہانہ حاضری دن</span>
              <span className="text-2xl font-bold text-emerald-600 font-sans">{currentStd.presentDays} / {currentStd.monthlyDays}</span>
            </div>
            <div className="bg-slate-50 dark:bg-slate-700/50 p-4 rounded-2xl border text-center">
              <span className="text-xs font-bold text-slate-500 block mb-1">ماہانہ ناغہ (چھٹیاں)</span>
              <span className="text-2xl font-bold text-rose-600 font-sans">{currentStd.naaghah} دن</span>
            </div>
          </div>

          {/* Quran Paras Visual Grid */}
          <h3 className="text-xl font-bold mb-4 flex items-center gap-2">
            <span>📖</span> قرآن کریم کے 30 پارے پروگریس چارٹ
          </h3>
          <div className="grid grid-cols-5 md:grid-cols-10 gap-3">
            {Array.from({ length: 30 }, (_, i) => i + 1).map(paraNo => {
              const isDone = paraNo <= currentStd.completedParas;
              const isCurrent = paraNo === currentStd.completedParas + 1;
              return (
                <div 
                  key={paraNo}
                  className={`p-3 rounded-xl text-center font-bold font-sans transition-all border ${
                    isDone 
                      ? 'bg-emerald-600 text-white border-emerald-700 shadow-sm' 
                      : isCurrent 
                      ? 'bg-amber-400 text-amber-900 border-amber-500 animate-pulse' 
                      : 'bg-slate-100 dark:bg-slate-700 text-slate-400 border-slate-200 dark:border-slate-600'
                  }`}
                >
                  <span className="text-xs block font-urdu">پارہ</span>
                  <span className="text-lg">{paraNo}</span>
                  <span className="text-[10px] block mt-1 font-urdu">{isDone ? 'مکمل' : isCurrent ? 'جاری' : 'باقی'}</span>
                </div>
              );
            })}
          </div>
        </div>
      </main>
    </div>
  );
}
