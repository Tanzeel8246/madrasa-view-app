'use client';

import { useState } from 'react';
import Link from 'next/link';

interface CourseYear {
  id: number;
  name: string;
  level: string;
  total_students: number;
  books: string[];
}

export default function DarseNizamiPage() {
  const [courses, setCourses] = useState<CourseYear[]>([
    { id: 1, name: 'درجہ اولیٰ (سال اول)', level: 'ابتدائی ثانوی', total_students: 24, books: ['مفتاح العربیہ', 'نحو میر', 'صرف بہائی', 'مالابد منہ'] },
    { id: 2, name: 'درجہ ثانیہ (سال دوم)', level: 'ثانوی عامہ', total_students: 20, books: ['ہدایۃ النحو', 'علم الصیغہ', 'نور الایضاح', 'قدوری'] },
    { id: 3, name: 'درجہ ثالثہ (سال سوم)', level: 'ثانوی خاصہ', total_students: 18, books: ['کافیہ', 'شرح تہذیب', 'کنز الدقائق'] },
    { id: 4, name: 'درجہ رابعہ (سال چہارم)', level: 'عالیہ اول', total_students: 15, books: ['شرح جامی', 'مختصر المعانی', 'اصول الشاشی'] },
    { id: 5, name: 'دورہ حدیث شریف (سال آخر)', level: 'عالمیہ', total_students: 28, books: ['صحیح بخاری', 'صحیح مسلم', 'جامع ترمذی', 'سنن ابی داؤد'] },
  ]);

  const [activeTab, setActiveTab] = useState<'courses' | 'promotion'>('courses');
  const [promoteFrom, setPromoteFrom] = useState('درجہ اولیٰ (سال اول)');
  const [promoteTo, setPromoteTo] = useState('درجہ ثانیہ (سال دوم)');
  const [promoted, setPromoted] = useState(false);

  const handlePromoteAll = () => {
    setPromoted(true);
    setTimeout(() => setPromoted(false), 3000);
  };

  return (
    <div className="flex flex-col bg-slate-50 dark:bg-slate-900 font-urdu min-h-screen transition-colors" dir="rtl">
      <header className="h-20 bg-white/80 dark:bg-slate-800/80 backdrop-blur-md border-b border-slate-200 dark:border-slate-700 flex items-center justify-between px-8 sticky top-0 z-10 transition-colors">
        <div className="flex items-center gap-4">
          <Link href="/" className="text-theme-primary dark:text-theme-light hover:text-theme-hover transition-colors bg-theme-primary/10 p-2 rounded-lg">
            <span className="text-xl">🏠</span>
          </Link>
          <h2 className="text-2xl font-bold text-slate-800 dark:text-slate-100 flex items-center gap-2">
            <span>🎓</span> درس نظامی و عصری علوم (Darse Nizami & Promotion)
          </h2>
        </div>
        <div className="flex gap-2">
          <button onClick={() => setActiveTab('courses')} className={`px-4 py-2 rounded-xl font-bold ${activeTab === 'courses' ? 'bg-theme-primary text-white' : 'bg-slate-100 dark:bg-slate-700 text-slate-700 dark:text-slate-300'}`}>
            کورس و کتب سیٹ اپ
          </button>
          <button onClick={() => setActiveTab('promotion')} className={`px-4 py-2 rounded-xl font-bold ${activeTab === 'promotion' ? 'bg-theme-primary text-white' : 'bg-slate-100 dark:bg-slate-700 text-slate-700 dark:text-slate-300'}`}>
            سالانہ ترقی (Promotion)
          </button>
        </div>
      </header>

      <main className="flex-1 p-8">
        {activeTab === 'courses' ? (
          <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
            {courses.map(c => (
              <div key={c.id} className="bg-white dark:bg-slate-800 rounded-2xl shadow-sm border border-slate-200 dark:border-slate-700 p-6 flex flex-col justify-between">
                <div>
                  <div className="flex justify-between items-start mb-2">
                    <h3 className="text-xl font-bold text-slate-800 dark:text-white">{c.name}</h3>
                    <span className="px-2.5 py-1 bg-emerald-100 text-emerald-800 rounded-md text-xs font-bold font-sans">{c.level}</span>
                  </div>
                  <p className="text-slate-500 text-sm mb-4">کل زیرِ تعلیم طلبہ: <span className="font-bold text-indigo-600 font-sans">{c.total_students}</span></p>
                  
                  <div className="border-t pt-3">
                    <span className="text-xs font-bold text-slate-400 block mb-2">نصاب کی کتب:</span>
                    <div className="flex flex-wrap gap-1.5">
                      {c.books.map((b, i) => (
                        <span key={i} className="px-2.5 py-1 bg-slate-100 dark:bg-slate-700 text-slate-700 dark:text-slate-300 rounded-lg text-xs font-bold">
                          {b}
                        </span>
                      ))}
                    </div>
                  </div>
                </div>
              </div>
            ))}
          </div>
        ) : (
          <div className="bg-white dark:bg-slate-800 rounded-3xl shadow-sm border border-slate-200 dark:border-slate-700 p-8 max-w-2xl mx-auto">
            <h3 className="text-2xl font-bold mb-4 flex items-center gap-2">
              <span>⤴️</span> سالانہ طلبہ پروموشن وزرڈ (Annual Student Promotion)
            </h3>
            <p className="text-slate-500 mb-6">امتحانات کے بعد ایک جماعت کے تمام کامیاب طلبہ کو خودکار طریقے سے اگلی جماعت میں منتقل کریں۔</p>

            {promoted && (
              <div className="mb-6 p-4 bg-emerald-100 text-emerald-800 rounded-xl font-bold text-center">
                کامیاب طلبہ کو نئی کلاس میں کامیابی سے پروموٹ کر دیا گیا!
              </div>
            )}

            <div className="space-y-6">
              <div>
                <label className="block text-slate-700 dark:text-slate-300 font-bold mb-2">موجودہ درجہ (From Class)</label>
                <select className="w-full px-4 py-3 rounded-xl border text-lg" value={promoteFrom} onChange={e => setPromoteFrom(e.target.value)}>
                  {courses.map(c => <option key={c.id} value={c.name}>{c.name}</option>)}
                </select>
              </div>

              <div>
                <label className="block text-slate-700 dark:text-slate-300 font-bold mb-2">ترقی یافتہ درجہ (To Class)</label>
                <select className="w-full px-4 py-3 rounded-xl border text-lg" value={promoteTo} onChange={e => setPromoteTo(e.target.value)}>
                  {courses.map(c => <option key={c.id} value={c.name}>{c.name}</option>)}
                </select>
              </div>

              <button onClick={handlePromoteAll} className="w-full py-4 bg-emerald-600 hover:bg-emerald-700 text-white font-bold text-xl rounded-xl shadow-lg transition">
                تمام کامیاب طلبہ کو پروموٹ کریں
              </button>
            </div>
          </div>
        )}
      </main>
    </div>
  );
}
