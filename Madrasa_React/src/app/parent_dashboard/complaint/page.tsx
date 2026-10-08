'use client';

import { useState } from 'react';
import Link from 'next/link';

export default function ParentComplaintPage() {
  const [category, setCategory] = useState('تعلیمی معاملہ');
  const [description, setDescription] = useState('');
  const [submitted, setSubmitted] = useState(false);

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault();
    setSubmitted(true);
  };

  return (
    <div className="flex flex-col bg-slate-50 dark:bg-slate-900 font-urdu min-h-screen transition-colors" dir="rtl">
      <header className="h-20 bg-white/80 dark:bg-slate-800/80 backdrop-blur-md border-b border-slate-200 dark:border-slate-700 flex items-center justify-between px-8 sticky top-0 z-10 transition-colors">
        <div className="flex items-center gap-4">
          <Link href="/parent_dashboard" className="text-theme-primary dark:text-theme-light hover:text-theme-hover transition-colors bg-theme-primary/10 p-2 rounded-lg">
            <span className="text-xl">⬅️</span>
          </Link>
          <h2 className="text-2xl font-bold text-slate-800 dark:text-slate-100 flex items-center gap-2">
            <span>⚠️</span> شکایت یا تجویز کا اندراج
          </h2>
        </div>
      </header>

      <main className="flex-1 p-8 max-w-2xl mx-auto w-full">
        {submitted ? (
          <div className="bg-white dark:bg-slate-800 rounded-3xl p-8 shadow-sm border border-slate-200 dark:border-slate-700 text-center space-y-4">
            <div className="text-6xl text-emerald-600">👍</div>
            <h3 className="text-2xl font-bold text-slate-800 dark:text-white">آپ کی شکایت / تجویز انتظامیہ کو موصول ہو گئی ہے!</h3>
            <p className="text-slate-500">مہتمم صاحب یا متعلقہ نگران اس پر جلد از جلد غور کر کے کارروائی عمل میں لائیں گے۔</p>
            <Link href="/parent_dashboard" className="inline-block px-6 py-2.5 bg-emerald-600 text-white font-bold rounded-xl shadow mt-4">
              واپس ہوم پر جائیں
            </Link>
          </div>
        ) : (
          <div className="bg-white dark:bg-slate-800 rounded-3xl p-8 shadow-sm border border-slate-200 dark:border-slate-700">
            <h3 className="text-xl font-bold mb-4">طالب علم: محمد عثمان (رول نمبر: 1001)</h3>
            <form onSubmit={handleSubmit} className="space-y-4">
              <div>
                <label className="block text-slate-700 dark:text-slate-300 font-bold mb-2">شکایت کی نوعیت / کیٹگری</label>
                <select className="w-full px-4 py-3 rounded-xl border text-lg" value={category} onChange={e => setCategory(e.target.value)}>
                  <option value="تعلیمی معاملہ">تعلیمی معاملہ (پڑھائی / سبق)</option>
                  <option value="دارالاقامہ / ہاسٹل">دارالاقامہ / ہاسٹل رہائش</option>
                  <option value="طعام و میس">طعام و میس کچن</option>
                  <option value="اخلاق و نظم و ضبط">نظم و ضبط و دیگر امور</option>
                </select>
              </div>

              <div>
                <label className="block text-slate-700 dark:text-slate-300 font-bold mb-2">تفصیل تحریر کریں *</label>
                <textarea 
                  rows={4} 
                  required 
                  className="w-full px-4 py-3 rounded-xl border resize-none" 
                  placeholder="شکایت یا تجویز کو واضح الفاظ میں درج کریں..."
                  value={description} 
                  onChange={e => setDescription(e.target.value)} 
                />
              </div>

              <button type="submit" className="w-full py-4 bg-rose-600 hover:bg-rose-700 text-white font-bold text-xl rounded-xl shadow-lg transition">
                شکایت جمع کروائیں
              </button>
            </form>
          </div>
        )}
      </main>
    </div>
  );
}
