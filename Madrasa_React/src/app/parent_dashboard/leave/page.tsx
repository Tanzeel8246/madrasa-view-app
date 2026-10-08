'use client';

import { useState } from 'react';
import Link from 'next/link';

export default function ParentLeavePage() {
  const [fromDate, setFromDate] = useState(new Date().toISOString().split('T')[0]);
  const [toDate, setToDate] = useState(new Date().toISOString().split('T')[0]);
  const [reason, setReason] = useState('');
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
            <span>✉️</span> رخصت و چھٹی کی درخواست
          </h2>
        </div>
      </header>

      <main className="flex-1 p-8 max-w-2xl mx-auto w-full">
        {submitted ? (
          <div className="bg-white dark:bg-slate-800 rounded-3xl p-8 shadow-sm border border-slate-200 dark:border-slate-700 text-center space-y-4">
            <div className="text-6xl text-emerald-600">✉️</div>
            <h3 className="text-2xl font-bold text-slate-800 dark:text-white">چھٹی کی درخواست کامیابی سے ارسال ہو گئی!</h3>
            <p className="text-slate-500">ناظمِ تعلیمات / استاد صاحب کے تصدیق کرنے پر آپ کو مطلع کر دیا جائے گا۔</p>
            <Link href="/parent_dashboard" className="inline-block px-6 py-2.5 bg-emerald-600 text-white font-bold rounded-xl shadow mt-4">
              واپس ہوم پر جائیں
            </Link>
          </div>
        ) : (
          <div className="bg-white dark:bg-slate-800 rounded-3xl p-8 shadow-sm border border-slate-200 dark:border-slate-700">
            <h3 className="text-xl font-bold mb-4">طالب علم: محمد عثمان (رول نمبر: 1001)</h3>
            <form onSubmit={handleSubmit} className="space-y-4">
              <div className="grid grid-cols-2 gap-4">
                <div>
                  <label className="block text-slate-700 dark:text-slate-300 font-bold mb-2">چھٹی شروع تاریخ</label>
                  <input type="date" required className="w-full px-4 py-3 rounded-xl border font-sans" value={fromDate} onChange={e => setFromDate(e.target.value)} />
                </div>
                <div>
                  <label className="block text-slate-700 dark:text-slate-300 font-bold mb-2">چھٹی اختتام تاریخ</label>
                  <input type="date" required className="w-full px-4 py-3 rounded-xl border font-sans" value={toDate} onChange={e => setToDate(e.target.value)} />
                </div>
              </div>

              <div>
                <label className="block text-slate-700 dark:text-slate-300 font-bold mb-2">چھٹی کی وجہ / تفصیل *</label>
                <textarea 
                  rows={4} 
                  required 
                  className="w-full px-4 py-3 rounded-xl border resize-none" 
                  placeholder="بیماری یا ضروری گھریلو کام کی تفصیل تحریر کریں..."
                  value={reason} 
                  onChange={e => setReason(e.target.value)} 
                />
              </div>

              <button type="submit" className="w-full py-4 bg-emerald-600 hover:bg-emerald-700 text-white font-bold text-xl rounded-xl shadow-lg transition">
                درخواست ارسال کریں
              </button>
            </form>
          </div>
        )}
      </main>
    </div>
  );
}
