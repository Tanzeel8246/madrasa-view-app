'use client';

import { useState } from 'react';
import Link from 'next/link';

interface Voucher {
  id: number;
  voucher_no: string;
  type: 'CPV' | 'CRV' | 'BPV' | 'BRV' | 'JV';
  date: string;
  debit_account: string;
  credit_account: string;
  amount: number;
  narration: string;
  status: 'Posted' | 'Draft';
}

export default function VouchersPage() {
  const [vouchers, setVouchers] = useState<Voucher[]>([
    { id: 1, voucher_no: 'CRV-2026-001', type: 'CRV', date: '2026-10-01', debit_account: 'کیش ان ہینڈ', credit_account: 'عطیات و صدقات فنڈ', amount: 50000, narration: 'حاجی محمد رفیق سے نقد عطیہ وصول ہوا', status: 'Posted' },
    { id: 2, voucher_no: 'CPV-2026-001', type: 'CPV', date: '2026-10-02', debit_account: 'طعام و راشن مطبخ خرچ', credit_account: 'کیش ان ہینڈ', amount: 42000, narration: 'غلہ منڈی سے گندم و چاول کی خریداری', status: 'Posted' },
    { id: 3, voucher_no: 'BPV-2026-001', type: 'BPV', date: '2026-10-03', debit_account: 'بجلی و یوٹیلٹی بلز', credit_account: 'میزان بینک اکاونٹ', amount: 18500, narration: 'بجلی کا بل چیک نمبر 45892 کے ذریعے ادا کیا', status: 'Posted' },
    { id: 4, voucher_no: 'JV-2026-001', type: 'JV', date: '2026-10-04', debit_account: 'اساتذہ تنخواہیں خرچ', credit_account: 'ایڈوانس کٹوتی ملازمین', amount: 5000, narration: 'ماہانہ تنخواہ سے ایڈوانس قسط کی ایڈجسٹمنٹ', status: 'Posted' },
  ]);

  return (
    <div className="flex flex-col bg-slate-50 dark:bg-slate-900 font-urdu min-h-screen transition-colors" dir="rtl">
      <header className="h-20 bg-white/80 dark:bg-slate-800/80 backdrop-blur-md border-b border-slate-200 dark:border-slate-700 flex items-center justify-between px-8 sticky top-0 z-10 transition-colors">
        <div className="flex items-center gap-4">
          <Link href="/finance" className="text-theme-primary dark:text-theme-light hover:text-theme-hover transition-colors bg-theme-primary/10 p-2 rounded-lg">
            <span className="text-xl">⬅️</span>
          </Link>
          <h2 className="text-2xl font-bold text-slate-800 dark:text-slate-100 flex items-center gap-2">
            <span>🧾</span> ڈبل انٹری واؤچرز و جرنل (Double-Entry Vouchers)
          </h2>
        </div>
      </header>

      <main className="flex-1 p-8">
        <div className="bg-white dark:bg-slate-800 rounded-2xl shadow-sm border border-slate-200 dark:border-slate-700 overflow-hidden">
          <div className="p-4 bg-slate-50 dark:bg-slate-700/50 flex justify-between items-center border-b">
            <h3 className="text-lg font-bold">جرنل واؤچرز لاگ</h3>
            <span className="text-sm font-bold text-slate-500">کل واؤچرز: {vouchers.length}</span>
          </div>

          <table className="w-full text-right border-collapse">
            <thead>
              <tr className="bg-slate-50 dark:bg-slate-700/50 border-b text-slate-600 dark:text-slate-300 font-bold">
                <th className="p-4">واؤچر نمبر</th>
                <th className="p-4">قسم (Type)</th>
                <th className="p-4">تاریخ</th>
                <th className="p-4">نام کھاتہ ڈیبٹ (Debit Account)</th>
                <th className="p-4">نام کھاتہ کریڈٹ (Credit Account)</th>
                <th className="p-4 text-left">رقم (PKR)</th>
                <th className="p-4">تفصیل (Narration)</th>
                <th className="p-4 text-center">کیفیت</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-slate-100 dark:divide-slate-700">
              {vouchers.map(v => (
                <tr key={v.id} className="hover:bg-slate-50 dark:hover:bg-slate-700/50">
                  <td className="p-4 font-sans font-bold text-theme-primary" dir="ltr">{v.voucher_no}</td>
                  <td className="p-4">
                    <span className="px-2.5 py-1 bg-indigo-50 dark:bg-indigo-900/30 text-indigo-700 dark:text-indigo-400 rounded-md text-xs font-bold font-sans">
                      {v.type}
                    </span>
                  </td>
                  <td className="p-4 font-sans text-sm">{v.date}</td>
                  <td className="p-4 font-bold text-emerald-600">{v.debit_account}</td>
                  <td className="p-4 font-bold text-rose-600">{v.credit_account}</td>
                  <td className="p-4 text-left font-sans font-bold text-base">Rs. {v.amount.toLocaleString()}</td>
                  <td className="p-4 text-sm text-slate-600 dark:text-slate-400">{v.narration}</td>
                  <td className="p-4 text-center">
                    <span className="px-3 py-1 bg-emerald-100 text-emerald-700 rounded-full text-xs font-bold">
                      {v.status === 'Posted' ? 'پوسٹڈ' : 'ڈرافٹ'}
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
