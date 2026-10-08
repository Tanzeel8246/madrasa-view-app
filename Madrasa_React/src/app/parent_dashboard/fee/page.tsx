'use client';

import { useState } from 'react';
import Link from 'next/link';

export default function ParentFeePage() {
  const [amount, setAmount] = useState('3000');
  const [bankReceipt, setBankReceipt] = useState<string | null>(null);
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
            <span>💸</span> آن لائن فیس ادائیگی و چالان
          </h2>
        </div>
      </header>

      <main className="flex-1 p-8 max-w-2xl mx-auto w-full">
        {submitted ? (
          <div className="bg-white dark:bg-slate-800 rounded-3xl p-8 shadow-sm border border-slate-200 dark:border-slate-700 text-center space-y-4">
            <div className="text-6xl text-emerald-600">✅</div>
            <h3 className="text-2xl font-bold text-slate-800 dark:text-white">فیس ادائیگی کی رسید موصول ہو گئی!</h3>
            <p className="text-slate-500">ادارہ جاتی اکاؤنٹس سے تصدیق کے بعد فیس کا سٹیٹس "ادا شدہ" ہو جائے گا۔ جزاک اللہ خیراً۔</p>
            <Link href="/parent_dashboard" className="inline-block px-6 py-2.5 bg-emerald-600 text-white font-bold rounded-xl shadow mt-4">
              واپس ہوم پر جائیں
            </Link>
          </div>
        ) : (
          <div className="bg-white dark:bg-slate-800 rounded-3xl p-8 shadow-sm border border-slate-200 dark:border-slate-700">
            {/* Account Details Box */}
            <div className="bg-emerald-50 dark:bg-emerald-950/20 border border-emerald-200 dark:border-emerald-800 p-6 rounded-2xl mb-6">
              <h3 className="font-bold text-lg text-emerald-800 dark:text-emerald-300 mb-2">مدرسہ کا آفیشل بینک اکاؤنٹ برائے فیس</h3>
              <div className="space-y-1 text-sm font-sans" dir="ltr">
                <div><b>Bank:</b> Meezan Bank Ltd</div>
                <div><b>Title:</b> Madrasa Ashraf-ul-Madaris</div>
                <div><b>Account / IBAN:</b> PK55 MEZN 0001 2345 6789 0101</div>
                <div><b>JazzCash / EasyPaisa:</b> 0300-1234567</div>
              </div>
            </div>

            <form onSubmit={handleSubmit} className="space-y-4">
              <div>
                <label className="block text-slate-700 dark:text-slate-300 font-bold mb-2">ماہانہ فیس کی رقم (PKR)</label>
                <input 
                  type="number" 
                  required 
                  className="w-full px-4 py-3 rounded-xl border border-slate-300 dark:border-slate-600 font-sans text-xl font-bold"
                  value={amount}
                  onChange={e => setAmount(e.target.value)}
                />
              </div>

              <div>
                <label className="block text-slate-700 dark:text-slate-300 font-bold mb-2">ادائیگی کی رسید / ٹرانزیکشن سکرین شاٹ اپلوڈ کریں *</label>
                <input 
                  type="file" 
                  accept="image/*"
                  required
                  className="w-full px-4 py-3 rounded-xl border border-slate-300 dark:border-slate-600"
                  onChange={e => setBankReceipt(e.target.files?.[0]?.name || null)}
                />
                <p className="text-xs text-slate-500 mt-1">جے پی جی یا پی این جی اسکرین شاٹ</p>
              </div>

              <button type="submit" className="w-full py-4 bg-emerald-600 hover:bg-emerald-700 text-white font-bold text-xl rounded-xl shadow-lg transition mt-4">
                رسید جمع کروائیں
              </button>
            </form>
          </div>
        )}
      </main>
    </div>
  );
}
