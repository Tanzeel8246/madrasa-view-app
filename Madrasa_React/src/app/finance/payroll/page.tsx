'use client';

import { useState, useEffect } from 'react';
import Link from 'next/link';

interface TeacherSalary {
  id: number;
  name: string;
  father_name: string;
  phone: string;
  basic_salary: number;
  house_rent: number;
  medical: number;
  travel: number;
  deduction: number;
  absent_days: number;
  net_salary: number;
  status: 'Paid' | 'Unpaid';
  payment_date?: string;
}

export default function PayrollPage() {
  const [salaries, setSalaries] = useState<TeacherSalary[]>([]);
  const [loading, setLoading] = useState(true);
  const [month, setMonth] = useState('اکتوبر 2026');
  const [selectedSlip, setSelectedSlip] = useState<TeacherSalary | null>(null);

  useEffect(() => {
    // Initial mock data
    setSalaries([
      { id: 1, name: 'مولانا عبدالحنان', father_name: 'نور محمد', phone: '03001234567', basic_salary: 35000, house_rent: 5000, medical: 2000, travel: 3000, absent_days: 0, deduction: 0, net_salary: 45000, status: 'Paid', payment_date: '2026-10-01' },
      { id: 2, name: 'قاری محمد بلال', father_name: 'عبدالشکور', phone: '03017654321', basic_salary: 28000, house_rent: 4000, medical: 1500, travel: 2000, absent_days: 2, deduction: 1800, net_salary: 33700, status: 'Unpaid' },
      { id: 3, name: 'مفتی سلیمان', father_name: 'عبدالقدوس', phone: '03029876543', basic_salary: 40000, house_rent: 6000, medical: 2500, travel: 3500, absent_days: 1, deduction: 1200, net_salary: 50800, status: 'Paid', payment_date: '2026-10-02' },
      { id: 4, name: 'حافظ احمد رضا', father_name: 'مشتاق احمد', phone: '03031122334', basic_salary: 25000, house_rent: 3000, medical: 1000, travel: 2000, absent_days: 0, deduction: 0, net_salary: 31000, status: 'Unpaid' },
    ]);
    setLoading(false);
  }, []);

  const handlePay = (id: number) => {
    setSalaries(salaries.map(s => s.id === id ? { ...s, status: 'Paid', payment_date: new Date().toISOString().split('T')[0] } : s));
  };

  const totalPayroll = salaries.reduce((acc, s) => acc + s.net_salary, 0);
  const totalPaid = salaries.filter(s => s.status === 'Paid').reduce((acc, s) => acc + s.net_salary, 0);
  const totalUnpaid = salaries.filter(s => s.status === 'Unpaid').reduce((acc, s) => acc + s.net_salary, 0);

  return (
    <div className="flex flex-col bg-slate-50 dark:bg-slate-900 font-urdu min-h-screen transition-colors" dir="rtl">
      {/* Header */}
      <header className="h-20 bg-white/80 dark:bg-slate-800/80 backdrop-blur-md border-b border-slate-200 dark:border-slate-700 flex items-center justify-between px-8 sticky top-0 z-10 print:hidden transition-colors">
        <div className="flex items-center gap-4">
          <Link href="/finance" className="text-theme-primary dark:text-theme-light hover:text-theme-hover transition-colors bg-theme-primary/10 p-2 rounded-lg">
            <span className="text-xl">⬅️</span>
          </Link>
          <h2 className="text-2xl font-bold text-slate-800 dark:text-slate-100 flex items-center gap-2">
            <span>👨‍🏫</span> اساتذہ و عملہ وظائف و پے رول (Staff Payroll)
          </h2>
        </div>
        <div className="flex items-center gap-4">
          <span className="text-base font-bold text-slate-500">مہینہ: {month}</span>
          <button onClick={() => window.print()} className="bg-slate-800 text-white px-5 py-2 rounded-xl font-bold">
            🖨️ پرنٹ شیٹ
          </button>
        </div>
      </header>

      <main className="flex-1 p-8 print:p-0">
        {/* Metric Cards */}
        <div className="grid grid-cols-1 md:grid-cols-3 gap-6 mb-8 print:hidden">
          <div className="bg-white dark:bg-slate-800 p-6 rounded-2xl shadow-sm border border-slate-100 dark:border-slate-700 flex justify-between items-center">
            <div>
              <p className="text-sm font-bold text-slate-500 mb-1">کل پے رول لاگت</p>
              <h3 className="text-3xl font-extrabold text-slate-800 dark:text-white">Rs. {totalPayroll.toLocaleString()}</h3>
            </div>
            <div className="w-14 h-14 bg-indigo-100 text-indigo-600 rounded-2xl flex items-center justify-center text-3xl">
              💰
            </div>
          </div>

          <div className="bg-white dark:bg-slate-800 p-6 rounded-2xl shadow-sm border border-emerald-100 dark:border-emerald-900/30 flex justify-between items-center">
            <div>
              <p className="text-sm font-bold text-emerald-600 mb-1">ادا شدہ وظائف (Paid)</p>
              <h3 className="text-3xl font-extrabold text-emerald-600">Rs. {totalPaid.toLocaleString()}</h3>
            </div>
            <div className="w-14 h-14 bg-emerald-100 text-emerald-600 rounded-2xl flex items-center justify-center text-3xl">
              ✅
            </div>
          </div>

          <div className="bg-white dark:bg-slate-800 p-6 rounded-2xl shadow-sm border border-amber-100 dark:border-amber-900/30 flex justify-between items-center">
            <div>
              <p className="text-sm font-bold text-amber-600 mb-1">غیر ادا شدہ (Unpaid)</p>
              <h3 className="text-3xl font-extrabold text-amber-600">Rs. {totalUnpaid.toLocaleString()}</h3>
            </div>
            <div className="w-14 h-14 bg-amber-100 text-amber-600 rounded-2xl flex items-center justify-center text-3xl">
              ⏳
            </div>
          </div>
        </div>

        {/* Payroll Table */}
        <div className="bg-white dark:bg-slate-800 rounded-2xl shadow-sm border border-slate-200 dark:border-slate-700 overflow-hidden">
          <div className="overflow-x-auto">
            <table className="w-full text-right border-collapse">
              <thead>
                <tr className="bg-slate-50 dark:bg-slate-700/50 border-b border-slate-200 dark:border-slate-700 text-slate-600 dark:text-slate-300 font-bold text-base">
                  <th className="py-4 px-4 w-12 text-center">#</th>
                  <th className="py-4 px-4">استاد کا نام</th>
                  <th className="py-4 px-4">بنیادی تنخواہ</th>
                  <th className="py-4 px-4">الاؤنسز (Rent/Med/Travel)</th>
                  <th className="py-4 px-4">غیر حاضری / کٹوتی</th>
                  <th className="py-4 px-4 text-left">خالص تنخواہ (Net Pay)</th>
                  <th className="py-4 px-4 text-center">سٹیٹس</th>
                  <th className="py-4 px-4 text-center print:hidden">ایکشن</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-100 dark:divide-slate-700">
                {salaries.map((s, idx) => (
                  <tr key={s.id} className="hover:bg-slate-50 dark:hover:bg-slate-700/50 transition-colors">
                    <td className="py-3 px-4 font-bold text-slate-500 text-center">{idx + 1}</td>
                    <td className="py-3 px-4">
                      <div className="font-bold text-slate-800 dark:text-slate-100">{s.name}</div>
                      <div className="text-xs text-slate-500">ولد {s.father_name}</div>
                    </td>
                    <td className="py-3 px-4 font-sans font-bold">Rs. {s.basic_salary.toLocaleString()}</td>
                    <td className="py-3 px-4 font-sans text-xs text-slate-600 dark:text-slate-400">
                      <div>رینٹ: Rs. {s.house_rent}</div>
                      <div>میڈیکل+سفر: Rs. {s.medical + s.travel}</div>
                    </td>
                    <td className="py-3 px-4 font-sans text-sm text-rose-600 font-bold">
                      {s.deduction > 0 ? `- Rs. ${s.deduction} (${s.absent_days} چھٹی)` : 'صفر'}
                    </td>
                    <td className="py-3 px-4 font-sans font-extrabold text-lg text-emerald-600 text-left">
                      Rs. {s.net_salary.toLocaleString()}
                    </td>
                    <td className="py-3 px-4 text-center">
                      <span className={`px-3 py-1 rounded-full text-xs font-bold ${
                        s.status === 'Paid' ? 'bg-emerald-100 text-emerald-700' : 'bg-amber-100 text-amber-700'
                      }`}>
                        {s.status === 'Paid' ? 'ادا شدہ' : 'غیر ادا شدہ'}
                      </span>
                    </td>
                    <td className="py-3 px-4 text-center print:hidden">
                      <div className="flex gap-2 justify-center">
                        {s.status === 'Unpaid' && (
                          <button onClick={() => handlePay(s.id)} className="px-3 py-1 bg-emerald-600 text-white rounded-lg text-xs font-bold hover:bg-emerald-700">
                            ادائیگی درج کریں
                          </button>
                        )}
                        <button onClick={() => setSelectedSlip(s)} className="px-3 py-1 bg-slate-100 dark:bg-slate-700 text-slate-700 dark:text-slate-300 rounded-lg text-xs font-bold hover:bg-slate-200">
                          سلپ پرنٹ
                        </button>
                      </div>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </div>
      </main>

      {/* Salary Slip Modal */}
      {selectedSlip && (
        <div className="fixed inset-0 bg-slate-900/60 backdrop-blur-sm flex items-center justify-center z-50 p-4">
          <div className="bg-white rounded-3xl shadow-2xl w-full max-w-md overflow-hidden border border-slate-300">
            <div className="p-4 bg-slate-100 border-b flex justify-between items-center print:hidden">
              <span className="font-bold text-slate-700">تنخواہ سلپ (Salary Slip)</span>
              <div className="flex gap-2">
                <button onClick={() => window.print()} className="px-4 py-1 bg-emerald-600 text-white rounded-lg font-bold text-sm">پرنٹ کریں</button>
                <button onClick={() => setSelectedSlip(null)} className="px-3 py-1 bg-slate-200 text-slate-700 rounded-lg text-sm font-bold">بند کریں</button>
              </div>
            </div>

            <div className="p-6 text-slate-800 font-urdu border-2 border-slate-800 m-4 rounded-xl">
              <div className="text-center border-b pb-3 mb-3">
                <h3 className="text-xl font-black">جامعہ اشرف المدارس</h3>
                <p className="text-xs text-slate-500">تنخواہ و وظیفہ سلپ — {month}</p>
              </div>
              <div className="space-y-2 text-sm">
                <div className="flex justify-between">
                  <span className="font-bold">نام استاد:</span>
                  <span>{selectedSlip.name}</span>
                </div>
                <div className="flex justify-between">
                  <span className="font-bold">بنیادی وظیفہ:</span>
                  <span className="font-sans">Rs. {selectedSlip.basic_salary.toLocaleString()}</span>
                </div>
                <div className="flex justify-between">
                  <span className="font-bold">ہاؤس رینٹ:</span>
                  <span className="font-sans">Rs. {selectedSlip.house_rent.toLocaleString()}</span>
                </div>
                <div className="flex justify-between">
                  <span className="font-bold">میڈیکل و سفری الاؤنس:</span>
                  <span className="font-sans">Rs. {(selectedSlip.medical + selectedSlip.travel).toLocaleString()}</span>
                </div>
                <div className="flex justify-between text-rose-600">
                  <span className="font-bold">کٹوتی (غیر حاضری):</span>
                  <span className="font-sans">- Rs. {selectedSlip.deduction.toLocaleString()}</span>
                </div>
                <div className="flex justify-between border-t border-b py-2 text-lg font-bold text-emerald-700">
                  <span>خالص واجب الادا:</span>
                  <span className="font-sans font-extrabold">Rs. {selectedSlip.net_salary.toLocaleString()}</span>
                </div>
              </div>
              <div className="flex justify-between items-end mt-8 pt-4 border-t border-dashed">
                <div className="text-center">
                  <div className="w-20 border-b border-black mb-1"></div>
                  <span className="text-xs font-bold">دستخط مہتمم</span>
                </div>
                <div className="text-center">
                  <div className="w-20 border-b border-black mb-1"></div>
                  <span className="text-xs font-bold">دستخط استاد</span>
                </div>
              </div>
            </div>
          </div>
        </div>
      )}
    </div>
  );
}
