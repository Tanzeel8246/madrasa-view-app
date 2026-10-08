'use client';

import { useState } from 'react';
import Link from 'next/link';

export default function FinancialReportsPage() {
  const [selectedYear, setSelectedYear] = useState('2026');
  const [selectedMonth, setSelectedMonth] = useState('الکل');

  const incomeCategories = [
    { name: 'ماہانہ فیس وصولی', amount: 485000, percentage: 42 },
    { name: 'زکوۃ فنڈ برائے مساکین', amount: 320000, percentage: 28 },
    { name: 'عطیات و صدقات عامہ', amount: 215000, percentage: 19 },
    { name: 'داخلہ فیس', amount: 85000, percentage: 7 },
    { name: 'متفرق آمدن', amount: 45000, percentage: 4 },
  ];

  const expenseCategories = [
    { name: 'اساتذہ و عملہ تنخواہیں', amount: 560000, percentage: 58 },
    { name: 'مطبخ و طعام طلبہ (راشن)', amount: 220000, percentage: 23 },
    { name: 'بجلی، گیس و یوٹیلٹی بلز', amount: 95000, percentage: 10 },
    { name: 'عمارت مرمت و دیکھ بھال', amount: 55000, percentage: 6 },
    { name: 'سٹیشنری و امتحانات', amount: 30000, percentage: 3 },
  ];

  const totalIncome = incomeCategories.reduce((a, b) => a + b.amount, 0);
  const totalExpense = expenseCategories.reduce((a, b) => a + b.amount, 0);
  const netSavings = totalIncome - totalExpense;

  return (
    <div className="flex flex-col bg-slate-50 dark:bg-slate-900 font-urdu min-h-screen transition-colors" dir="rtl">
      <header className="h-20 bg-white/80 dark:bg-slate-800/80 backdrop-blur-md border-b border-slate-200 dark:border-slate-700 flex items-center justify-between px-8 sticky top-0 z-10 print:hidden transition-colors">
        <div className="flex items-center gap-4">
          <Link href="/finance" className="text-theme-primary dark:text-theme-light hover:text-theme-hover transition-colors bg-theme-primary/10 p-2 rounded-lg">
            <span className="text-xl">⬅️</span>
          </Link>
          <h2 className="text-2xl font-bold text-slate-800 dark:text-slate-100 flex items-center gap-2">
            <span>📊</span> سالانہ و ماہانہ مالیاتی رپورٹ (Financial Reports)
          </h2>
        </div>
        <div className="flex items-center gap-3">
          <select 
            value={selectedYear} 
            onChange={e => setSelectedYear(e.target.value)} 
            className="px-4 py-2 rounded-xl border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 font-sans font-bold"
          >
            <option value="2026">2026</option>
            <option value="2025">2025</option>
          </select>
          <button onClick={() => window.print()} className="px-5 py-2 bg-emerald-600 hover:bg-emerald-700 text-white rounded-xl font-bold flex items-center gap-2 shadow-md">
            <span>🖨️</span> رپورٹ پرنٹ کریں
          </button>
        </div>
      </header>

      <main className="flex-1 p-8 print:p-0">
        {/* Printable Header */}
        <div className="hidden print:block text-center border-b-2 border-black pb-4 mb-6">
          <h1 className="text-3xl font-black">جامعہ اشرف المدارس — فروکہ ضلع سرگودھا</h1>
          <h3 className="text-xl font-bold">سالانہ جامع مالیاتی گوشوارہ برائے سال {selectedYear}ء</h3>
        </div>

        {/* Summary Metric Cards */}
        <div className="grid grid-cols-1 md:grid-cols-3 gap-6 mb-8">
          <div className="bg-white dark:bg-slate-800 p-6 rounded-2xl shadow-sm border border-emerald-100 dark:border-emerald-900/30">
            <p className="text-sm font-bold text-emerald-600 mb-1">مجموعی سالانہ آمدن (Gross Income)</p>
            <h3 className="text-3xl font-extrabold text-emerald-600 font-sans">Rs. {totalIncome.toLocaleString()}</h3>
          </div>
          <div className="bg-white dark:bg-slate-800 p-6 rounded-2xl shadow-sm border border-rose-100 dark:border-rose-900/30">
            <p className="text-sm font-bold text-rose-600 mb-1">مجموعی سالانہ اخراجات (Total Expenses)</p>
            <h3 className="text-3xl font-extrabold text-rose-600 font-sans">Rs. {totalExpense.toLocaleString()}</h3>
          </div>
          <div className="bg-white dark:bg-slate-800 p-6 rounded-2xl shadow-sm border border-blue-100 dark:border-blue-900/30">
            <p className="text-sm font-bold text-blue-600 mb-1">خالص بچت / سرپلس (Net Surplus)</p>
            <h3 className="text-3xl font-extrabold text-emerald-700 font-sans">Rs. {netSavings.toLocaleString()}</h3>
          </div>
        </div>

        {/* Comparative Breakdown Grid */}
        <div className="grid grid-cols-1 lg:grid-cols-2 gap-8 mb-8">
          {/* Income Breakdown */}
          <div className="bg-white dark:bg-slate-800 p-6 rounded-2xl shadow-sm border border-slate-200 dark:border-slate-700">
            <h3 className="text-xl font-bold text-emerald-700 dark:text-emerald-400 mb-4 border-b pb-2 flex items-center gap-2">
              <span>📈</span> آمدن بلحاظ مدات (Income Breakdown)
            </h3>
            <div className="space-y-4">
              {incomeCategories.map((item, i) => (
                <div key={i}>
                  <div className="flex justify-between items-center mb-1">
                    <span className="font-bold text-slate-800 dark:text-slate-200">{item.name}</span>
                    <span className="font-sans font-bold text-emerald-600">Rs. {item.amount.toLocaleString()} ({item.percentage}%)</span>
                  </div>
                  <div className="w-full bg-slate-100 dark:bg-slate-700 h-3 rounded-full overflow-hidden">
                    <div className="bg-emerald-500 h-full rounded-full" style={{ width: `${item.percentage}%` }}></div>
                  </div>
                </div>
              ))}
            </div>
          </div>

          {/* Expense Breakdown */}
          <div className="bg-white dark:bg-slate-800 p-6 rounded-2xl shadow-sm border border-slate-200 dark:border-slate-700">
            <h3 className="text-xl font-bold text-rose-700 dark:text-rose-400 mb-4 border-b pb-2 flex items-center gap-2">
              <span>📉</span> اخراجات بلحاظ مدات (Expense Breakdown)
            </h3>
            <div className="space-y-4">
              {expenseCategories.map((item, i) => (
                <div key={i}>
                  <div className="flex justify-between items-center mb-1">
                    <span className="font-bold text-slate-800 dark:text-slate-200">{item.name}</span>
                    <span className="font-sans font-bold text-rose-600">Rs. {item.amount.toLocaleString()} ({item.percentage}%)</span>
                  </div>
                  <div className="w-full bg-slate-100 dark:bg-slate-700 h-3 rounded-full overflow-hidden">
                    <div className="bg-rose-500 h-full rounded-full" style={{ width: `${item.percentage}%` }}></div>
                  </div>
                </div>
              ))}
            </div>
          </div>
        </div>

        {/* Printable Signatures */}
        <div className="hidden print:flex justify-between items-end mt-16 pt-8 border-t-2">
          <div className="text-center">
            <div className="w-40 border-b border-black mb-2"></div>
            <span className="font-bold">دستخط ناظم مالیات / کیشئر</span>
          </div>
          <div className="text-center">
            <div className="w-40 border-b border-black mb-2"></div>
            <span className="font-bold">دستخط آڈیٹر</span>
          </div>
          <div className="text-center">
            <div className="w-40 border-b border-black mb-2"></div>
            <span className="font-bold">دستخط مہتمم اعلیٰ</span>
          </div>
        </div>
      </main>
    </div>
  );
}
