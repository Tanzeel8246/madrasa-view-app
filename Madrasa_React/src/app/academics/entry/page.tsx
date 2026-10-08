'use client';

import { useState, useEffect } from 'react';
import Link from 'next/link';

interface StudentEval {
  id: number;
  name: string;
  admission_number: string;
  sabak: string;
  sabqi: string;
  manzil: string;
  mistakes: number;
  status: 'بہترین' | 'تسلی بخش' | 'محنت طلب' | 'ناغہ';
}

export default function AcademicEntryPage() {
  const [date, setDate] = useState(new Date().toISOString().split('T')[0]);
  const [selectedClass, setSelectedClass] = useState('حفظ کلاس A');
  const [students, setStudents] = useState<StudentEval[]>([]);
  const [saving, setSaving] = useState(false);
  const [message, setMessage] = useState<string | null>(null);

  useEffect(() => {
    setStudents([
      { id: 1, name: 'محمد عثمان', admission_number: '1001', sabak: 'پارہ 15 صفحہ 10', sabqi: 'پارہ 14', manzil: 'پارہ 1 تا 3', mistakes: 0, status: 'بہترین' },
      { id: 2, name: 'علی حسن', admission_number: '1002', sabak: 'پارہ 8 صفحہ 4', sabqi: 'پارہ 7', manzil: 'پارہ 1', mistakes: 2, status: 'تسلی بخش' },
      { id: 3, name: 'ابوبکر صدیق', admission_number: '1003', sabak: 'پارہ 3 صفحہ 1', sabqi: 'پارہ 2', manzil: 'پارہ 1', mistakes: 5, status: 'محنت طلب' },
      { id: 4, name: 'عبدالرحمن', admission_number: '1004', sabak: 'ناغہ (غیر حاضر)', sabqi: '-', manzil: '-', mistakes: 0, status: 'ناغہ' },
    ]);
  }, [selectedClass]);

  const handleUpdate = (id: number, field: keyof StudentEval, val: any) => {
    setStudents(students.map(s => s.id === id ? { ...s, [field]: val } : s));
  };

  const handleSaveAll = () => {
    setSaving(true);
    setTimeout(() => {
      setSaving(false);
      setMessage('آج کی تعلیمی کارکردگی کا اندراج کامیابی سے محفوظ کر لیا گیا!');
      setTimeout(() => setMessage(null), 3000);
    }, 600);
  };

  return (
    <div className="flex flex-col bg-slate-50 dark:bg-slate-900 font-urdu min-h-screen transition-colors" dir="rtl">
      <header className="h-20 bg-white/80 dark:bg-slate-800/80 backdrop-blur-md border-b border-slate-200 dark:border-slate-700 flex items-center justify-between px-8 sticky top-0 z-10 transition-colors">
        <div className="flex items-center gap-4">
          <Link href="/" className="text-theme-primary dark:text-theme-light hover:text-theme-hover transition-colors bg-theme-primary/10 p-2 rounded-lg">
            <span className="text-xl">🏠</span>
          </Link>
          <h2 className="text-2xl font-bold text-slate-800 dark:text-slate-100 flex items-center gap-2">
            <span>📖</span> روزانہ تعلیمی کارکردگی (سبق، سبقی، منزل اندراج)
          </h2>
        </div>
        <div className="flex items-center gap-3">
          <Link href="/academics/view" className="px-4 py-2 bg-slate-100 dark:bg-slate-700 text-slate-700 dark:text-slate-200 rounded-xl font-bold text-sm">
            📈 تعلیمی رپورٹ دیکھیں
          </Link>
          <button 
            onClick={handleSaveAll} 
            disabled={saving}
            className="px-6 py-2.5 bg-emerald-600 hover:bg-emerald-700 text-white font-bold rounded-xl shadow-md transition"
          >
            {saving ? 'محفوظ ہو رہا ہے...' : '💾 تمام ریکارڈ محفوظ کریں'}
          </button>
        </div>
      </header>

      <main className="flex-1 p-8">
        {/* Filters */}
        <div className="bg-white dark:bg-slate-800 p-6 rounded-2xl shadow-sm border border-slate-200 dark:border-slate-700 mb-6 flex flex-wrap gap-6 items-center">
          <div>
            <label className="block text-slate-700 dark:text-slate-300 font-bold mb-2">تاریخ منتخب کریں</label>
            <input 
              type="date" 
              className="px-4 py-2.5 rounded-xl border border-slate-300 dark:border-slate-600 bg-slate-50 dark:bg-slate-700 text-slate-800 dark:text-slate-100 font-sans font-bold"
              value={date} 
              onChange={e => setDate(e.target.value)} 
            />
          </div>
          <div>
            <label className="block text-slate-700 dark:text-slate-300 font-bold mb-2">کلاس / درجہ</label>
            <select 
              className="px-4 py-2.5 rounded-xl border border-slate-300 dark:border-slate-600 bg-slate-50 dark:bg-slate-700 text-slate-800 dark:text-slate-100 font-bold"
              value={selectedClass} 
              onChange={e => setSelectedClass(e.target.value)}
            >
              <option value="حفظ کلاس A">حفظ کلاس A</option>
              <option value="ناظرہ قرآن کلاس">ناظرہ قرآن کلاس</option>
              <option value="نورانی قاعدہ">نورانی قاعدہ</option>
              <option value="درجہ اولیٰ">درجہ اولیٰ</option>
            </select>
          </div>
        </div>

        {message && (
          <div className="mb-6 p-4 bg-emerald-100 text-emerald-800 border border-emerald-300 rounded-xl font-bold text-center">
            {message}
          </div>
        )}

        {/* Student Evaluation Table */}
        <div className="bg-white dark:bg-slate-800 rounded-2xl shadow-sm border border-slate-200 dark:border-slate-700 overflow-hidden">
          <table className="w-full text-right border-collapse">
            <thead>
              <tr className="bg-slate-50 dark:bg-slate-700/50 border-b text-slate-600 dark:text-slate-300 font-bold">
                <th className="p-4 w-12 text-center">#</th>
                <th className="p-4 w-48">نام طالب علم</th>
                <th className="p-4">روزانہ سبق (Sabak)</th>
                <th className="p-4">سبقی (Sabqi)</th>
                <th className="p-4">منزل (Manzil)</th>
                <th className="p-4 w-28 text-center">غلطیاں (Mistakes)</th>
                <th className="p-4 w-36 text-center">کیفیت (Quality)</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-slate-100 dark:divide-slate-700">
              {students.map((s, idx) => (
                <tr key={s.id} className="hover:bg-slate-50 dark:hover:bg-slate-700/50">
                  <td className="p-4 text-center font-bold text-slate-500">{idx + 1}</td>
                  <td className="p-4">
                    <div className="font-bold text-slate-800 dark:text-slate-100">{s.name}</div>
                    <div className="text-xs text-slate-400 font-sans" dir="ltr">رول: {s.admission_number}</div>
                  </td>
                  <td className="p-4">
                    <input 
                      type="text" 
                      className="w-full px-3 py-1.5 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-700 text-sm font-bold"
                      value={s.sabak}
                      onChange={e => handleUpdate(s.id, 'sabak', e.target.value)}
                    />
                  </td>
                  <td className="p-4">
                    <input 
                      type="text" 
                      className="w-full px-3 py-1.5 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-700 text-sm"
                      value={s.sabqi}
                      onChange={e => handleUpdate(s.id, 'sabqi', e.target.value)}
                    />
                  </td>
                  <td className="p-4">
                    <input 
                      type="text" 
                      className="w-full px-3 py-1.5 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-700 text-sm"
                      value={s.manzil}
                      onChange={e => handleUpdate(s.id, 'manzil', e.target.value)}
                    />
                  </td>
                  <td className="p-4 text-center">
                    <input 
                      type="number" 
                      min="0"
                      className="w-16 px-2 py-1.5 text-center rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-700 font-sans font-bold"
                      value={s.mistakes}
                      onChange={e => handleUpdate(s.id, 'mistakes', parseInt(e.target.value) || 0)}
                    />
                  </td>
                  <td className="p-4 text-center">
                    <select 
                      className={`px-3 py-1.5 rounded-lg text-sm font-bold border ${
                        s.status === 'بہترین' ? 'bg-emerald-100 text-emerald-800 border-emerald-300' :
                        s.status === 'تسلی بخش' ? 'bg-blue-100 text-blue-800 border-blue-300' :
                        s.status === 'محنت طلب' ? 'bg-amber-100 text-amber-800 border-amber-300' :
                        'bg-rose-100 text-rose-800 border-rose-300'
                      }`}
                      value={s.status}
                      onChange={e => handleUpdate(s.id, 'status', e.target.value)}
                    >
                      <option value="بہترین">بہترین (Excellent)</option>
                      <option value="تسلی بخش">تسلی بخش (Good)</option>
                      <option value="محنت طلب">محنت طلب (Needs Work)</option>
                      <option value="ناغہ">ناغہ (Absent)</option>
                    </select>
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
