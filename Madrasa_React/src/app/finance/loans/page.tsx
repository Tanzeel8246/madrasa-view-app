'use client';

import { useState, useEffect } from 'react';
import Link from 'next/link';

interface StaffLoan {
  id: number;
  staff_name: string;
  role: string;
  total_amount: number;
  monthly_deduction: number;
  recovered_amount: number;
  remaining_amount: number;
  start_date: string;
  status: 'Active' | 'Completed';
  reason: string;
}

export default function LoansPage() {
  const [loans, setLoans] = useState<StaffLoan[]>([]);
  const [search, setSearch] = useState('');
  const [isModalOpen, setIsModalOpen] = useState(false);
  const [saving, setSaving] = useState(false);

  const [formData, setFormData] = useState({
    staff_name: '',
    role: 'استاذ',
    total_amount: '',
    monthly_deduction: '',
    start_date: new Date().toISOString().split('T')[0],
    reason: '',
  });

  useEffect(() => {
    setLoans([
      { id: 1, staff_name: 'مولانا عبدالحنان', role: 'استاذ', total_amount: 50000, monthly_deduction: 5000, recovered_amount: 25000, remaining_amount: 25000, start_date: '2026-05-10', status: 'Active', reason: 'گھریلو اخراجات' },
      { id: 2, staff_name: 'قاری محمد بلال', role: 'قاری / ناظم', total_amount: 30000, monthly_deduction: 3000, recovered_amount: 30000, remaining_amount: 0, start_date: '2025-11-01', status: 'Completed', reason: 'علاج معالجہ' },
      { id: 3, staff_name: 'محمد اختر', role: 'خادم / باورچی', total_amount: 20000, monthly_deduction: 2000, recovered_amount: 6000, remaining_amount: 14000, start_date: '2026-07-15', status: 'Active', reason: 'بچے کی شادی' },
    ]);
  }, []);

  const handleAddLoan = (e: React.FormEvent) => {
    e.preventDefault();
    setSaving(true);
    const tot = parseFloat(formData.total_amount) || 0;
    const ded = parseFloat(formData.monthly_deduction) || 0;
    const newL: StaffLoan = {
      id: Date.now(),
      staff_name: formData.staff_name,
      role: formData.role,
      total_amount: tot,
      monthly_deduction: ded,
      recovered_amount: 0,
      remaining_amount: tot,
      start_date: formData.start_date,
      status: 'Active',
      reason: formData.reason,
    };
    setLoans([newL, ...loans]);
    setSaving(false);
    setIsModalOpen(false);
  };

  const handleDeductInstallment = (id: number) => {
    setLoans(loans.map(l => {
      if (l.id === id && l.remaining_amount > 0) {
        const newRec = l.recovered_amount + l.monthly_deduction;
        const newRem = Math.max(0, l.total_amount - newRec);
        return {
          ...l,
          recovered_amount: newRec,
          remaining_amount: newRem,
          status: newRem === 0 ? 'Completed' : 'Active'
        };
      }
      return l;
    }));
  };

  const totalOutstanding = loans.filter(l => l.status === 'Active').reduce((acc, l) => acc + l.remaining_amount, 0);

  return (
    <div className="flex flex-col bg-slate-50 dark:bg-slate-900 font-urdu min-h-screen transition-colors" dir="rtl">
      <header className="h-20 bg-white/80 dark:bg-slate-800/80 backdrop-blur-md border-b border-slate-200 dark:border-slate-700 flex items-center justify-between px-8 sticky top-0 z-10 transition-colors">
        <div className="flex items-center gap-4">
          <Link href="/finance" className="text-theme-primary dark:text-theme-light hover:text-theme-hover transition-colors bg-theme-primary/10 p-2 rounded-lg">
            <span className="text-xl">⬅️</span>
          </Link>
          <h2 className="text-2xl font-bold text-slate-800 dark:text-slate-100 flex items-center gap-2">
            <span>🤝</span> عملہ ایڈوانس و قرض مینیجمنٹ (Loans & Advances)
          </h2>
        </div>
        <button onClick={() => setIsModalOpen(true)} className="bg-theme-primary hover:bg-theme-hover text-white px-5 py-2.5 rounded-xl font-bold flex items-center gap-2 shadow-md">
          <span>➕</span> نیا قرض / ایڈوانس جاری کریں
        </button>
      </header>

      <main className="flex-1 p-8">
        <div className="grid grid-cols-1 md:grid-cols-3 gap-6 mb-8">
          <div className="bg-white dark:bg-slate-800 p-6 rounded-2xl shadow-sm border border-slate-200 dark:border-slate-700">
            <p className="text-sm font-bold text-slate-500 mb-1">کل بقایا قرضہ جات (Outstanding)</p>
            <h3 className="text-3xl font-extrabold text-rose-600">Rs. {totalOutstanding.toLocaleString()}</h3>
          </div>
          <div className="bg-white dark:bg-slate-800 p-6 rounded-2xl shadow-sm border border-slate-200 dark:border-slate-700">
            <p className="text-sm font-bold text-slate-500 mb-1">کل فعال کیسز (Active Cases)</p>
            <h3 className="text-3xl font-extrabold text-slate-800 dark:text-white">
              {loans.filter(l => l.status === 'Active').length}
            </h3>
          </div>
          <div className="bg-white dark:bg-slate-800 p-6 rounded-2xl shadow-sm border border-slate-200 dark:border-slate-700">
            <p className="text-sm font-bold text-slate-500 mb-1">مکمل ادا شدہ (Cleared)</p>
            <h3 className="text-3xl font-extrabold text-emerald-600">
              {loans.filter(l => l.status === 'Completed').length}
            </h3>
          </div>
        </div>

        <div className="bg-white dark:bg-slate-800 rounded-2xl shadow-sm border border-slate-200 dark:border-slate-700 overflow-hidden">
          <div className="overflow-x-auto">
            <table className="w-full text-right border-collapse">
              <thead>
                <tr className="bg-slate-50 dark:bg-slate-700/50 border-b border-slate-200 dark:border-slate-700 text-slate-600 dark:text-slate-300 font-bold text-base">
                  <th className="py-4 px-4 w-12 text-center">#</th>
                  <th className="py-4 px-4">نام ملازم و عہدہ</th>
                  <th className="py-4 px-4">کل قرض رقم</th>
                  <th className="py-4 px-4">ماہانہ قسط</th>
                  <th className="py-4 px-4">وصول شدہ رقم</th>
                  <th className="py-4 px-4">بقایا رقم (Remaining)</th>
                  <th className="py-4 px-4 text-center">کیفیت</th>
                  <th className="py-4 px-4 text-center">ایکشن</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-100 dark:divide-slate-700">
                {loans.map((l, idx) => (
                  <tr key={l.id} className="hover:bg-slate-50 dark:hover:bg-slate-700/50 transition-colors">
                    <td className="py-3 px-4 font-bold text-slate-500 text-center">{idx + 1}</td>
                    <td className="py-3 px-4">
                      <div className="font-bold text-slate-800 dark:text-slate-100">{l.staff_name}</div>
                      <div className="text-xs text-slate-500">{l.role} - {l.reason}</div>
                    </td>
                    <td className="py-3 px-4 font-sans font-bold">Rs. {l.total_amount.toLocaleString()}</td>
                    <td className="py-3 px-4 font-sans font-bold text-indigo-600">Rs. {l.monthly_deduction.toLocaleString()}</td>
                    <td className="py-3 px-4 font-sans font-bold text-emerald-600">Rs. {l.recovered_amount.toLocaleString()}</td>
                    <td className="py-3 px-4 font-sans font-bold text-rose-600">Rs. {l.remaining_amount.toLocaleString()}</td>
                    <td className="py-3 px-4 text-center">
                      <span className={`px-3 py-1 rounded-full text-xs font-bold ${
                        l.status === 'Active' ? 'bg-amber-100 text-amber-700' : 'bg-emerald-100 text-emerald-700'
                      }`}>
                        {l.status === 'Active' ? 'جاری (Active)' : 'مکمل ادا (Cleared)'}
                      </span>
                    </td>
                    <td className="py-3 px-4 text-center">
                      {l.status === 'Active' ? (
                        <button onClick={() => handleDeductInstallment(l.id)} className="px-3 py-1.5 bg-emerald-600 text-white rounded-lg text-xs font-bold hover:bg-emerald-700">
                          قسط وصولی درج کریں
                        </button>
                      ) : (
                        <span className="text-xs text-slate-400">کوئی بقایا نہیں</span>
                      )}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </div>
      </main>

      {/* Modal */}
      {isModalOpen && (
        <div className="fixed inset-0 bg-slate-900/50 backdrop-blur-sm flex items-center justify-center z-50 p-4">
          <div className="bg-white dark:bg-slate-800 rounded-3xl shadow-2xl w-full max-w-lg overflow-hidden border border-slate-200 dark:border-slate-700 p-6">
            <h3 className="text-xl font-bold mb-4">نیا قرض یا ایڈوانس فارم</h3>
            <form onSubmit={handleAddLoan} className="space-y-4">
              <div>
                <label className="block text-sm font-bold mb-1">نام ملازم *</label>
                <input type="text" required className="w-full px-3 py-2 rounded-xl border" value={formData.staff_name} onChange={e => setFormData({...formData, staff_name: e.target.value})} />
              </div>
              <div className="grid grid-cols-2 gap-4">
                <div>
                  <label className="block text-sm font-bold mb-1">کل رقم (PKR) *</label>
                  <input type="number" required className="w-full px-3 py-2 rounded-xl border font-sans" value={formData.total_amount} onChange={e => setFormData({...formData, total_amount: e.target.value})} />
                </div>
                <div>
                  <label className="block text-sm font-bold mb-1">ماہانہ کٹوتی قسط *</label>
                  <input type="number" required className="w-full px-3 py-2 rounded-xl border font-sans" value={formData.monthly_deduction} onChange={e => setFormData({...formData, monthly_deduction: e.target.value})} />
                </div>
              </div>
              <div>
                <label className="block text-sm font-bold mb-1">وجہ / مد</label>
                <input type="text" className="w-full px-3 py-2 rounded-xl border" value={formData.reason} onChange={e => setFormData({...formData, reason: e.target.value})} />
              </div>
              <div className="flex justify-end gap-3 pt-4 border-t">
                <button type="button" onClick={() => setIsModalOpen(false)} className="px-4 py-2 bg-slate-100 rounded-xl">منسوخ کریں</button>
                <button type="submit" disabled={saving} className="px-6 py-2 bg-theme-primary text-white font-bold rounded-xl">محفوظ کریں</button>
              </div>
            </form>
          </div>
        </div>
      )}
    </div>
  );
}
