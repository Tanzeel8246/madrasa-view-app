'use client';

import { useState } from 'react';
import Link from 'next/link';

interface Account {
  id: number;
  code: string;
  name: string;
  type: 'Asset' | 'Liability' | 'Equity' | 'Revenue' | 'Expense';
  balance: number;
}

export default function ChartOfAccountsPage() {
  const [filterType, setFilterType] = useState('all');
  const [accounts, setAccounts] = useState<Account[]>([
    { id: 1, code: '1001', name: 'کیش ان ہینڈ (مین کیشئر)', type: 'Asset', balance: 185000 },
    { id: 2, code: '1002', name: 'میزان بینک اکاونٹ', type: 'Asset', balance: 840000 },
    { id: 3, code: '1003', name: 'ایڈوانس برائے خریداری راشن', type: 'Asset', balance: 25000 },
    { id: 4, code: '2001', name: 'واجب الادا ادویات و میڈیکل بل', type: 'Liability', balance: 12000 },
    { id: 5, code: '3001', name: 'عمومی فنڈ (General Equity)', type: 'Equity', balance: 950000 },
    { id: 6, code: '4001', name: 'ماہانہ طلبہ فیس آمدن', type: 'Revenue', balance: 485000 },
    { id: 7, code: '4002', name: 'زکوۃ و صدقات فنڈ', type: 'Revenue', balance: 320000 },
    { id: 8, code: '5001', name: 'اساتذہ و عملہ تنخواہیں خرچ', type: 'Expense', balance: 560000 },
    { id: 9, code: '5002', name: 'طعام و راشن مطبخ خرچ', type: 'Expense', balance: 220000 },
    { id: 10, code: '5003', name: 'بجلی و یوٹیلٹی بلز', type: 'Expense', balance: 95000 },
  ]);

  const filtered = accounts.filter(a => filterType === 'all' || a.type === filterType);

  return (
    <div className="flex flex-col bg-slate-50 dark:bg-slate-900 font-urdu min-h-screen transition-colors" dir="rtl">
      <header className="h-20 bg-white/80 dark:bg-slate-800/80 backdrop-blur-md border-b border-slate-200 dark:border-slate-700 flex items-center justify-between px-8 sticky top-0 z-10 transition-colors">
        <div className="flex items-center gap-4">
          <Link href="/finance" className="text-theme-primary dark:text-theme-light hover:text-theme-hover transition-colors bg-theme-primary/10 p-2 rounded-lg">
            <span className="text-xl">⬅️</span>
          </Link>
          <h2 className="text-2xl font-bold text-slate-800 dark:text-slate-100 flex items-center gap-2">
            <span>📑</span> چارٹ آف اکاؤنٹس (ERP Chart of Accounts)
          </h2>
        </div>
      </header>

      <main className="flex-1 p-8">
        <div className="flex gap-2 mb-6">
          {['all', 'Asset', 'Liability', 'Equity', 'Revenue', 'Expense'].map(t => (
            <button
              key={t}
              onClick={() => setFilterType(t)}
              className={`px-4 py-2 rounded-xl font-bold text-sm transition ${
                filterType === t ? 'bg-theme-primary text-white shadow' : 'bg-white dark:bg-slate-800 border text-slate-700 dark:text-slate-300'
              }`}
            >
              {t === 'all' ? 'تمام کھاتے' : t === 'Asset' ? 'اثاثہ جات (Assets)' : t === 'Liability' ? 'واجبات (Liabilities)' : t === 'Equity' ? 'سرمایہ (Equity)' : t === 'Revenue' ? 'آمدن (Revenue)' : 'اخراجات (Expense)'}
            </button>
          ))}
        </div>

        <div className="bg-white dark:bg-slate-800 rounded-2xl shadow-sm border border-slate-200 dark:border-slate-700 overflow-hidden">
          <table className="w-full text-right border-collapse">
            <thead>
              <tr className="bg-slate-50 dark:bg-slate-700/50 border-b text-slate-600 dark:text-slate-300 font-bold">
                <th className="p-4 w-24">اکاؤنٹ کوڈ</th>
                <th className="p-4">کھاتے کا نام (Account Title)</th>
                <th className="p-4">نوعیت (Account Type)</th>
                <th className="p-4 text-left">موجودہ بیلنس (Balance)</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-slate-100 dark:divide-slate-700">
              {filtered.map(a => (
                <tr key={a.id} className="hover:bg-slate-50 dark:hover:bg-slate-700/50">
                  <td className="p-4 font-sans font-bold text-theme-primary">{a.code}</td>
                  <td className="p-4 font-bold text-slate-800 dark:text-slate-100">{a.name}</td>
                  <td className="p-4">
                    <span className="px-3 py-1 bg-slate-100 dark:bg-slate-700 rounded-full text-xs font-bold font-sans">
                      {a.type}
                    </span>
                  </td>
                  <td className="p-4 text-left font-sans font-bold text-lg">
                    Rs. {a.balance.toLocaleString()}
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
