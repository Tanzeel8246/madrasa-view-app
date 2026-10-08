'use client';

import { useState, useEffect } from 'react';
import Link from 'next/link';

interface StudentFee {
  id: number;
  student_name: string;
  father_name: string;
  admission_number: string;
  class_name: string;
  monthly_fee: number;
  paid_amount: number;
  balance: number;
  month: string;
  status: 'Paid' | 'Pending' | 'Partial';
  last_payment_date?: string;
}

export default function FeesManagementPage() {
  const [fees, setFees] = useState<StudentFee[]>([]);
  const [loading, setLoading] = useState(true);
  const [search, setSearch] = useState('');
  const [statusFilter, setStatusFilter] = useState('all');
  const [selectedMonth, setSelectedMonth] = useState('اکتوبر 2026');
  
  // Payment Modal
  const [selectedFee, setSelectedFee] = useState<StudentFee | null>(null);
  const [paymentAmount, setPaymentAmount] = useState('');
  const [isPayModalOpen, setIsPayModalOpen] = useState(false);
  const [saving, setSaving] = useState(false);

  useEffect(() => {
    // Demo data for fee records
    setFees([
      { id: 1, student_name: 'محمد عثمان', father_name: 'عبدالرشید', admission_number: '1001', class_name: 'حفظ', monthly_fee: 3000, paid_amount: 3000, balance: 0, month: 'اکتوبر 2026', status: 'Paid', last_payment_date: '2026-10-02' },
      { id: 2, student_name: 'علی حسن', father_name: 'محمد اکرم', admission_number: '1002', class_name: 'ناظرہ', monthly_fee: 2500, paid_amount: 1500, balance: 1000, month: 'اکتوبر 2026', status: 'Partial', last_payment_date: '2026-10-04' },
      { id: 3, student_name: 'ابوبکر صدیق', father_name: 'طارق محمود', admission_number: '1003', class_name: 'قاعدہ', monthly_fee: 2000, paid_amount: 0, balance: 2000, month: 'اکتوبر 2026', status: 'Pending' },
      { id: 4, student_name: 'عبدالرحمن', father_name: 'فاروق احمد', admission_number: '1004', class_name: 'اولیٰ', monthly_fee: 3500, paid_amount: 3500, balance: 0, month: 'اکتوبر 2026', status: 'Paid', last_payment_date: '2026-10-01' },
      { id: 5, student_name: 'حسن رضا', father_name: 'غلام مرتضیٰ', admission_number: '1005', class_name: 'حفظ', monthly_fee: 3000, paid_amount: 0, balance: 3000, month: 'اکتوبر 2026', status: 'Pending' },
    ]);
    setLoading(false);
  }, []);

  const handleOpenPay = (fee: StudentFee) => {
    setSelectedFee(fee);
    setPaymentAmount(fee.balance.toString());
    setIsPayModalOpen(true);
  };

  const handleSavePayment = (e: React.FormEvent) => {
    e.preventDefault();
    if (!selectedFee) return;
    setSaving(true);
    const amt = parseFloat(paymentAmount) || 0;
    
    setFees(fees.map(f => {
      if (f.id === selectedFee.id) {
        const newPaid = f.paid_amount + amt;
        const newBal = Math.max(0, f.monthly_fee - newPaid);
        return {
          ...f,
          paid_amount: newPaid,
          balance: newBal,
          status: newBal === 0 ? 'Paid' : 'Partial',
          last_payment_date: new Date().toISOString().split('T')[0]
        };
      }
      return f;
    }));

    setSaving(false);
    setIsPayModalOpen(false);
  };

  const filtered = fees.filter(f => {
    if (statusFilter !== 'all' && f.status !== statusFilter) return false;
    if (search && !f.student_name.includes(search) && !f.admission_number.includes(search) && !f.class_name.includes(search)) return false;
    return true;
  });

  const totalDemand = fees.reduce((acc, f) => acc + f.monthly_fee, 0);
  const totalCollected = fees.reduce((acc, f) => acc + f.paid_amount, 0);
  const totalPending = fees.reduce((acc, f) => acc + f.balance, 0);

  return (
    <div className="flex flex-col bg-slate-50 dark:bg-slate-900 font-urdu min-h-screen transition-colors" dir="rtl">
      {/* Header */}
      <header className="h-20 bg-white/80 dark:bg-slate-800/80 backdrop-blur-md border-b border-slate-200 dark:border-slate-700 flex items-center justify-between px-8 sticky top-0 z-10 transition-colors">
        <div className="flex items-center gap-4">
          <Link href="/finance" className="text-theme-primary dark:text-theme-light hover:text-theme-hover transition-colors bg-theme-primary/10 p-2 rounded-lg">
            <span className="text-xl">⬅️</span>
          </Link>
          <h2 className="text-2xl font-bold text-slate-800 dark:text-slate-100 flex items-center gap-2">
            <span>📋</span> فیس وصولی، چالان و نادہندگان (Fee Management)
          </h2>
        </div>
        <div className="flex gap-3">
          <button onClick={() => window.print()} className="bg-slate-800 hover:bg-slate-900 text-white px-5 py-2.5 rounded-xl font-bold shadow-md transition-all flex items-center gap-2">
            <span>🖨️</span> فیس لسٹ پرنٹ کریں
          </button>
        </div>
      </header>

      <main className="flex-1 p-8">
        {/* Metric Cards */}
        <div className="grid grid-cols-1 md:grid-cols-3 gap-6 mb-8">
          <div className="bg-white dark:bg-slate-800 p-6 rounded-2xl shadow-sm border border-slate-100 dark:border-slate-700 flex justify-between items-center">
            <div>
              <p className="text-sm font-bold text-slate-500 mb-1">کل متوقع فیس (Total Demand)</p>
              <h3 className="text-3xl font-extrabold text-slate-800 dark:text-white">Rs. {totalDemand.toLocaleString()}</h3>
            </div>
            <div className="w-14 h-14 bg-indigo-100 text-indigo-600 rounded-2xl flex items-center justify-center text-3xl">
              📊
            </div>
          </div>

          <div className="bg-white dark:bg-slate-800 p-6 rounded-2xl shadow-sm border border-emerald-100 dark:border-emerald-900/30 flex justify-between items-center">
            <div>
              <p className="text-sm font-bold text-emerald-600 mb-1">کل وصول شدہ (Collected)</p>
              <h3 className="text-3xl font-extrabold text-emerald-600">Rs. {totalCollected.toLocaleString()}</h3>
            </div>
            <div className="w-14 h-14 bg-emerald-100 text-emerald-600 rounded-2xl flex items-center justify-center text-3xl">
              ✅
            </div>
          </div>

          <div className="bg-white dark:bg-slate-800 p-6 rounded-2xl shadow-sm border border-rose-100 dark:border-rose-900/30 flex justify-between items-center">
            <div>
              <p className="text-sm font-bold text-rose-600 mb-1">بقایا جات / نادہندگان (Pending Balance)</p>
              <h3 className="text-3xl font-extrabold text-rose-600">Rs. {totalPending.toLocaleString()}</h3>
            </div>
            <div className="w-14 h-14 bg-rose-100 text-rose-600 rounded-2xl flex items-center justify-center text-3xl">
              ⚠️
            </div>
          </div>
        </div>

        {/* Filter Bar */}
        <div className="bg-white dark:bg-slate-800 p-4 rounded-2xl shadow-sm border border-slate-200 dark:border-slate-700 mb-6 flex flex-wrap gap-4 items-center justify-between">
          <div className="flex bg-slate-100 dark:bg-slate-700 p-1 rounded-xl">
            <button onClick={() => setStatusFilter('all')} className={`px-4 py-2 rounded-lg font-bold text-sm ${statusFilter === 'all' ? 'bg-white dark:bg-slate-800 text-slate-800 dark:text-white shadow-sm' : 'text-slate-600'}`}>
              تمام طلبہ ({fees.length})
            </button>
            <button onClick={() => setStatusFilter('Paid')} className={`px-4 py-2 rounded-lg font-bold text-sm ${statusFilter === 'Paid' ? 'bg-emerald-600 text-white shadow-sm' : 'text-slate-600'}`}>
              ادا شدہ (Paid)
            </button>
            <button onClick={() => setStatusFilter('Partial')} className={`px-4 py-2 rounded-lg font-bold text-sm ${statusFilter === 'Partial' ? 'bg-amber-600 text-white shadow-sm' : 'text-slate-600'}`}>
              جزوی (Partial)
            </button>
            <button onClick={() => setStatusFilter('Pending')} className={`px-4 py-2 rounded-lg font-bold text-sm ${statusFilter === 'Pending' ? 'bg-rose-600 text-white shadow-sm' : 'text-slate-600'}`}>
              نادہندگان (Pending)
            </button>
          </div>

          <div className="flex flex-1 max-w-md gap-2">
            <input 
              type="text"
              placeholder="طالب علم، رول نمبر یا کلاس سے تلاش کریں..."
              className="flex-1 px-4 py-2 rounded-xl border border-slate-200 dark:border-slate-600 focus:outline-none focus:ring-2 focus:ring-theme-primary bg-slate-50 dark:bg-slate-700 text-slate-800 dark:text-slate-200"
              value={search}
              onChange={e => setSearch(e.target.value)}
            />
          </div>
        </div>

        {/* Table */}
        <div className="bg-white dark:bg-slate-800 rounded-2xl shadow-sm border border-slate-200 dark:border-slate-700 overflow-hidden">
          <div className="overflow-x-auto">
            <table className="w-full text-right border-collapse">
              <thead>
                <tr className="bg-slate-50 dark:bg-slate-700/50 border-b border-slate-200 dark:border-slate-700 text-slate-600 dark:text-slate-300 font-bold text-base">
                  <th className="py-4 px-4 w-12 text-center">#</th>
                  <th className="py-4 px-4">رول نمبر</th>
                  <th className="py-4 px-4">نام طالب علم و ولدیت</th>
                  <th className="py-4 px-4">کلاس</th>
                  <th className="py-4 px-4">ماہانہ فیس</th>
                  <th className="py-4 px-4">ادا شدہ</th>
                  <th className="py-4 px-4">بقایا (Due)</th>
                  <th className="py-4 px-4 text-center">کیفیت (Status)</th>
                  <th className="py-4 px-4 text-center">ایکشن</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-100 dark:divide-slate-700">
                {filtered.map((f, idx) => (
                  <tr key={f.id} className="hover:bg-slate-50 dark:hover:bg-slate-700/50 transition-colors">
                    <td className="py-3 px-4 font-bold text-slate-500 text-center">{idx + 1}</td>
                    <td className="py-3 px-4 font-sans font-bold text-slate-700 dark:text-slate-300 text-sm" dir="ltr">{f.admission_number}</td>
                    <td className="py-3 px-4">
                      <div className="font-bold text-slate-800 dark:text-slate-100">{f.student_name}</div>
                      <div className="text-xs text-slate-500 dark:text-slate-400">ولد {f.father_name}</div>
                    </td>
                    <td className="py-3 px-4 font-bold text-slate-700 dark:text-slate-300">{f.class_name}</td>
                    <td className="py-3 px-4 font-sans font-bold">Rs. {f.monthly_fee.toLocaleString()}</td>
                    <td className="py-3 px-4 font-sans font-bold text-emerald-600">Rs. {f.paid_amount.toLocaleString()}</td>
                    <td className="py-3 px-4 font-sans font-bold text-rose-600">Rs. {f.balance.toLocaleString()}</td>
                    <td className="py-3 px-4 text-center">
                      <span className={`inline-block px-3 py-1 rounded-full text-xs font-bold ${
                        f.status === 'Paid' ? 'bg-emerald-100 text-emerald-700' : f.status === 'Partial' ? 'bg-amber-100 text-amber-700' : 'bg-rose-100 text-rose-700'
                      }`}>
                        {f.status === 'Paid' ? 'ادا شدہ' : f.status === 'Partial' ? 'جزوی ادا' : 'بقایا (غیر ادا شدہ)'}
                      </span>
                    </td>
                    <td className="py-3 px-4 text-center">
                      {f.balance > 0 ? (
                        <button 
                          onClick={() => handleOpenPay(f)}
                          className="px-4 py-1.5 bg-emerald-600 hover:bg-emerald-700 text-white rounded-lg text-sm font-bold shadow-sm transition"
                        >
                          وصولی درج کریں
                        </button>
                      ) : (
                        <span className="text-xs text-emerald-600 font-bold">مکمل ادا شدہ</span>
                      )}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </div>
      </main>

      {/* Pay Modal */}
      {isPayModalOpen && selectedFee && (
        <div className="fixed inset-0 bg-slate-900/50 backdrop-blur-sm flex items-center justify-center z-50 p-4">
          <div className="bg-white dark:bg-slate-800 rounded-3xl shadow-2xl w-full max-w-md overflow-hidden border border-slate-200 dark:border-slate-700 animate-in fade-in zoom-in">
            <div className="bg-emerald-600 text-white px-6 py-4 flex justify-between items-center">
              <h3 className="text-xl font-bold">فیس وصولی: {selectedFee.student_name}</h3>
              <button onClick={() => setIsPayModalOpen(false)} className="text-white text-2xl leading-none">&times;</button>
            </div>
            <form onSubmit={handleSavePayment} className="p-6 space-y-4">
              <div>
                <p className="text-sm text-slate-500 mb-1">کلاس: <b>{selectedFee.class_name}</b> | رول نمبر: <b>{selectedFee.admission_number}</b></p>
                <p className="text-sm text-slate-500">کل بقایا رقم: <b className="text-rose-600">Rs. {selectedFee.balance.toLocaleString()}</b></p>
              </div>
              <div>
                <label className="block text-slate-700 dark:text-slate-300 font-bold mb-2">وصول شدہ رقم (PKR) *</label>
                <input 
                  type="number"
                  required
                  max={selectedFee.balance}
                  min="1"
                  className="w-full px-4 py-3 rounded-xl border border-slate-300 dark:border-slate-600 bg-slate-50 dark:bg-slate-700 text-slate-800 dark:text-slate-100 font-sans text-xl font-bold"
                  value={paymentAmount}
                  onChange={e => setPaymentAmount(e.target.value)}
                />
              </div>
              <div className="flex justify-end gap-3 pt-4 border-t">
                <button type="button" onClick={() => setIsPayModalOpen(false)} className="px-5 py-2 rounded-xl bg-slate-100 text-slate-700 font-bold">منسوخ کریں</button>
                <button type="submit" disabled={saving} className="px-6 py-2 rounded-xl bg-emerald-600 text-white font-bold hover:bg-emerald-700 shadow-md">
                  {saving ? 'محفوظ ہو رہا ہے...' : 'وصولی محفوظ کریں'}
                </button>
              </div>
            </form>
          </div>
        </div>
      )}
    </div>
  );
}
