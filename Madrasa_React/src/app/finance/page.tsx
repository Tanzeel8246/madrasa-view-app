'use client';

import { useState, useEffect } from 'react';
import Link from 'next/link';

interface Transaction {
  id: number;
  type: 'Income' | 'Expense';
  date: string;
  head_name: string;
  amount: number;
  receipt_no: string;
  spender_name?: string;
  payment_method: string;
  description: string;
}

export default function FinancePage() {
  const [activeTab, setActiveTab] = useState<'all' | 'income' | 'expense'>('all');
  const [transactions, setTransactions] = useState<Transaction[]>([]);
  const [loading, setLoading] = useState(true);
  const [search, setSearch] = useState('');
  const [dateFrom, setDateFrom] = useState('');
  const [dateTo, setDateTo] = useState('');
  
  // Modal states
  const [isModalOpen, setIsModalOpen] = useState(false);
  const [modalType, setModalType] = useState<'Income' | 'Expense'>('Income');
  const [saving, setSaving] = useState(false);
  const [printReceipt, setPrintReceipt] = useState<Transaction | null>(null);

  const [formData, setFormData] = useState({
    type: 'Income',
    date: new Date().toISOString().split('T')[0],
    head_name: '',
    amount: '',
    receipt_no: '',
    spender_name: '',
    payment_method: 'Cash',
    description: '',
  });

  const headsIncome = ['ماہانہ فیس', 'داخلہ فیس', 'عطیات و صدقات', 'زکوۃ فنڈ', 'امداد برائے تعمیرات', 'متفرق آمدن'];
  const headsExpense = ['اساتذہ تنخواہیں', 'بجلی / گیس بلز', 'طعام و راشن طلبہ', 'مرمت و عمارت', 'سٹیشنری و کتب', 'متفرق اخراجات'];

  // Mock initial demo data & fetch from API
  useEffect(() => {
    fetchTransactions();
  }, []);

  const fetchTransactions = async () => {
    setLoading(true);
    try {
      const res = await fetch('/api/finance');
      const data = await res.json();
      if (data.success && data.data) {
        setTransactions(data.data);
      } else {
        // Fallback demo data
        setTransactions([
          { id: 1, type: 'Income', date: '2026-10-01', head_name: 'عطیات و صدقات', amount: 50000, receipt_no: 'REC-101', spender_name: 'حاجی محمد رفیق', payment_method: 'Cash', description: 'برائے طلبہ دارالاقامہ' },
          { id: 2, type: 'Income', date: '2026-10-02', head_name: 'ماہانہ فیس', amount: 35000, receipt_no: 'REC-102', spender_name: 'کلاس حفظ طلبہ', payment_method: 'Online', description: 'اکتوبر فیس کلیکشن' },
          { id: 3, type: 'Expense', date: '2026-10-03', head_name: 'بجلی / گیس بلز', amount: 18500, receipt_no: 'EXP-501', spender_name: 'لیسکو دفتر', payment_method: 'Bank', description: 'بل ماہ ستمبر' },
          { id: 4, type: 'Expense', date: '2026-10-04', head_name: 'طعام و راشن طلبہ', amount: 42000, receipt_no: 'EXP-502', spender_name: 'غلہ منڈی', payment_method: 'Cash', description: 'گندم، چاول اور دالیں' },
          { id: 5, type: 'Income', date: '2026-10-05', head_name: 'زکوۃ فنڈ', amount: 100000, receipt_no: 'REC-103', spender_name: 'شیخ عبداللہ', payment_method: 'Bank', description: 'زکوۃ سالانہ برائے مساکین طلبہ' },
        ]);
      }
    } catch (e) {
      console.error(e);
    } finally {
      setLoading(false);
    }
  };

  const handleOpenModal = (type: 'Income' | 'Expense') => {
    setModalType(type);
    setFormData({
      type,
      date: new Date().toISOString().split('T')[0],
      head_name: type === 'Income' ? headsIncome[0] : headsExpense[0],
      amount: '',
      receipt_no: `${type === 'Income' ? 'REC' : 'EXP'}-${Math.floor(1000 + Math.random() * 9000)}`,
      spender_name: '',
      payment_method: 'Cash',
      description: '',
    });
    setIsModalOpen(true);
  };

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    setSaving(true);
    const newTx: Transaction = {
      id: Date.now(),
      type: modalType,
      date: formData.date,
      head_name: formData.head_name,
      amount: parseFloat(formData.amount) || 0,
      receipt_no: formData.receipt_no,
      spender_name: formData.spender_name,
      payment_method: formData.payment_method,
      description: formData.description,
    };

    try {
      await fetch('/api/finance', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(newTx),
      });
    } catch (_) {}

    setTransactions([newTx, ...transactions]);
    setSaving(false);
    setIsModalOpen(false);
  };

  const filtered = transactions.filter(t => {
    if (activeTab !== 'all' && t.type.toLowerCase() !== activeTab) return false;
    if (search && !t.head_name.includes(search) && !t.spender_name?.includes(search) && !t.receipt_no.includes(search)) return false;
    if (dateFrom && t.date < dateFrom) return false;
    if (dateTo && t.date > dateTo) return false;
    return true;
  });

  const totalIncome = transactions.filter(t => t.type === 'Income').reduce((acc, t) => acc + t.amount, 0);
  const totalExpense = transactions.filter(t => t.type === 'Expense').reduce((acc, t) => acc + t.amount, 0);
  const netBalance = totalIncome - totalExpense;

  return (
    <div className="flex flex-col bg-slate-50 dark:bg-slate-900 font-urdu min-h-screen transition-colors" dir="rtl">
      {/* Header */}
      <header className="h-20 bg-white/80 dark:bg-slate-800/80 backdrop-blur-md border-b border-slate-200 dark:border-slate-700 flex items-center justify-between px-8 sticky top-0 z-10 print:hidden transition-colors">
        <div className="flex items-center gap-4">
          <Link href="/" className="text-theme-primary dark:text-theme-light hover:text-theme-hover transition-colors bg-theme-primary/10 p-2 rounded-lg">
            <span className="text-xl">🏠</span>
          </Link>
          <h2 className="text-2xl font-bold text-slate-800 dark:text-slate-100 flex items-center gap-2">
            <span>💰</span> مالیات و کیش بک (Finance & Cashbook)
          </h2>
        </div>
        <div className="flex gap-3">
          <button 
            onClick={() => handleOpenModal('Income')}
            className="bg-emerald-600 hover:bg-emerald-700 text-white px-5 py-2.5 rounded-xl font-bold shadow-md shadow-emerald-600/20 transition-all flex items-center gap-2"
          >
            <span>➕</span> آمدن کا اندراج (Income)
          </button>
          <button 
            onClick={() => handleOpenModal('Expense')}
            className="bg-rose-600 hover:bg-rose-700 text-white px-5 py-2.5 rounded-xl font-bold shadow-md shadow-rose-600/20 transition-all flex items-center gap-2"
          >
            <span>➖</span> خرچ کا اندراج (Expense)
          </button>
        </div>
      </header>

      <main className="flex-1 p-8 print:p-0">
        {/* Metric Cards */}
        <div className="grid grid-cols-1 md:grid-cols-3 gap-6 mb-8 print:hidden">
          <div className="bg-white dark:bg-slate-800 p-6 rounded-2xl shadow-sm border border-emerald-100 dark:border-emerald-900/30 flex justify-between items-center">
            <div>
              <p className="text-sm font-bold text-emerald-600 dark:text-emerald-400 mb-1">کل آمدن (Total Income)</p>
              <h3 className="text-3xl font-extrabold text-slate-800 dark:text-white">Rs. {totalIncome.toLocaleString()}</h3>
            </div>
            <div className="w-14 h-14 bg-emerald-100 dark:bg-emerald-900/40 text-emerald-600 dark:text-emerald-400 rounded-2xl flex items-center justify-center text-3xl">
              📈
            </div>
          </div>

          <div className="bg-white dark:bg-slate-800 p-6 rounded-2xl shadow-sm border border-rose-100 dark:border-rose-900/30 flex justify-between items-center">
            <div>
              <p className="text-sm font-bold text-rose-600 dark:text-rose-400 mb-1">کل اخراجات (Total Expense)</p>
              <h3 className="text-3xl font-extrabold text-slate-800 dark:text-white">Rs. {totalExpense.toLocaleString()}</h3>
            </div>
            <div className="w-14 h-14 bg-rose-100 dark:bg-rose-900/40 text-rose-600 dark:text-rose-400 rounded-2xl flex items-center justify-center text-3xl">
              📉
            </div>
          </div>

          <div className="bg-white dark:bg-slate-800 p-6 rounded-2xl shadow-sm border border-blue-100 dark:border-blue-900/30 flex justify-between items-center">
            <div>
              <p className="text-sm font-bold text-blue-600 dark:text-blue-400 mb-1">خالص بیلنس (Net Balance)</p>
              <h3 className={`text-3xl font-extrabold ${netBalance >= 0 ? 'text-emerald-600 dark:text-emerald-400' : 'text-rose-600 dark:text-rose-400'}`}>
                Rs. {netBalance.toLocaleString()}
              </h3>
            </div>
            <div className="w-14 h-14 bg-blue-100 dark:bg-blue-900/40 text-blue-600 dark:text-blue-400 rounded-2xl flex items-center justify-center text-3xl">
              ⚖️
            </div>
          </div>
        </div>

        {/* Quick Links Submenu Bar */}
        <div className="flex flex-wrap gap-2 mb-6 print:hidden">
          <Link href="/finance" className="px-4 py-2 bg-theme-primary text-white rounded-xl font-bold text-sm shadow">روزنامچہ کیش بک</Link>
          <Link href="/finance/fees" className="px-4 py-2 bg-white dark:bg-slate-800 text-slate-700 dark:text-slate-300 hover:bg-slate-100 rounded-xl font-bold text-sm border border-slate-200 dark:border-slate-700">فیس چالان و بقایاجات</Link>
          <Link href="/finance/payroll" className="px-4 py-2 bg-white dark:bg-slate-800 text-slate-700 dark:text-slate-300 hover:bg-slate-100 rounded-xl font-bold text-sm border border-slate-200 dark:border-slate-700">اساتذہ تنخواہیں و وظائف</Link>
          <Link href="/finance/loans" className="px-4 py-2 bg-white dark:bg-slate-800 text-slate-700 dark:text-slate-300 hover:bg-slate-100 rounded-xl font-bold text-sm border border-slate-200 dark:border-slate-700">ایڈوانس و قرض مینیجمنٹ</Link>
          <Link href="/finance/assets" className="px-4 py-2 bg-white dark:bg-slate-800 text-slate-700 dark:text-slate-300 hover:bg-slate-100 rounded-xl font-bold text-sm border border-slate-200 dark:border-slate-700">اثاثہ جات و راشن عطیات</Link>
          <Link href="/finance/reports" className="px-4 py-2 bg-white dark:bg-slate-800 text-slate-700 dark:text-slate-300 hover:bg-slate-100 rounded-xl font-bold text-sm border border-slate-200 dark:border-slate-700">مالیاتی رپورٹس و لیجر</Link>
        </div>

        {/* Filter & Search Bar */}
        <div className="bg-white dark:bg-slate-800 p-4 rounded-2xl shadow-sm border border-slate-200 dark:border-slate-700 mb-6 flex flex-wrap gap-4 items-center justify-between print:hidden">
          {/* Tabs */}
          <div className="flex bg-slate-100 dark:bg-slate-700 p-1 rounded-xl">
            <button
              onClick={() => setActiveTab('all')}
              className={`px-4 py-2 rounded-lg font-bold text-sm transition-all ${activeTab === 'all' ? 'bg-white dark:bg-slate-800 text-slate-800 dark:text-white shadow-sm' : 'text-slate-600 dark:text-slate-400'}`}
            >
              تمام ٹرانزیکشنز ({transactions.length})
            </button>
            <button
              onClick={() => setActiveTab('income')}
              className={`px-4 py-2 rounded-lg font-bold text-sm transition-all ${activeTab === 'income' ? 'bg-emerald-600 text-white shadow-sm' : 'text-slate-600 dark:text-slate-400'}`}
            >
              صرف آمدن
            </button>
            <button
              onClick={() => setActiveTab('expense')}
              className={`px-4 py-2 rounded-lg font-bold text-sm transition-all ${activeTab === 'expense' ? 'bg-rose-600 text-white shadow-sm' : 'text-slate-600 dark:text-slate-400'}`}
            >
              صرف اخراجات
            </button>
          </div>

          <div className="flex flex-1 max-w-md gap-2">
            <input 
              type="text"
              placeholder="مد، نام یا رسید نمبر سے تلاش کریں..."
              className="flex-1 px-4 py-2 rounded-xl border border-slate-200 dark:border-slate-600 focus:outline-none focus:ring-2 focus:ring-theme-primary bg-slate-50 dark:bg-slate-700 text-slate-800 dark:text-slate-200 text-base"
              value={search}
              onChange={e => setSearch(e.target.value)}
            />
          </div>

          <div className="flex items-center gap-2">
            <span className="text-sm font-bold text-slate-500">تاریخ:</span>
            <input 
              type="date"
              className="px-3 py-2 rounded-xl border border-slate-200 dark:border-slate-600 bg-slate-50 dark:bg-slate-700 text-slate-800 dark:text-slate-200 text-sm font-sans"
              value={dateFrom}
              onChange={e => setDateFrom(e.target.value)}
            />
            <span className="text-sm text-slate-400">تا</span>
            <input 
              type="date"
              className="px-3 py-2 rounded-xl border border-slate-200 dark:border-slate-600 bg-slate-50 dark:bg-slate-700 text-slate-800 dark:text-slate-200 text-sm font-sans"
              value={dateTo}
              onChange={e => setDateTo(e.target.value)}
            />
          </div>
        </div>

        {/* Transactions Table */}
        <div className="bg-white dark:bg-slate-800 rounded-2xl shadow-sm border border-slate-200 dark:border-slate-700 overflow-hidden">
          <div className="overflow-x-auto">
            <table className="w-full text-right border-collapse">
              <thead>
                <tr className="bg-slate-50 dark:bg-slate-700/50 border-b border-slate-200 dark:border-slate-700 text-slate-600 dark:text-slate-300 font-bold text-base">
                  <th className="py-4 px-4 w-12 text-center">#</th>
                  <th className="py-4 px-4">رسید نمبر</th>
                  <th className="py-4 px-4">تاریخ</th>
                  <th className="py-4 px-4">مد (Category / Head)</th>
                  <th className="py-4 px-4">تفصیل / منجانب / بنام</th>
                  <th className="py-4 px-4">طریقہ ادائیگی</th>
                  <th className="py-4 px-4 text-left">رقم (روپے)</th>
                  <th className="py-4 px-4 text-center print:hidden">ایکشن</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-100 dark:divide-slate-700">
                {loading ? (
                  <tr><td colSpan={8} className="py-10 text-center text-slate-500 font-bold">لوڈ ہو رہا ہے...</td></tr>
                ) : filtered.length === 0 ? (
                  <tr><td colSpan={8} className="py-10 text-center text-slate-500 font-bold">کوئی اندراج موجود نہیں ہے</td></tr>
                ) : (
                  filtered.map((tx, idx) => (
                    <tr key={tx.id} className="hover:bg-slate-50 dark:hover:bg-slate-700/50 transition-colors">
                      <td className="py-3 px-4 font-bold text-slate-500 text-center">{idx + 1}</td>
                      <td className="py-3 px-4 font-sans font-bold text-slate-700 dark:text-slate-300 text-sm" dir="ltr">
                        {tx.receipt_no}
                      </td>
                      <td className="py-3 px-4 font-sans text-sm text-slate-600 dark:text-slate-400">{tx.date}</td>
                      <td className="py-3 px-4">
                        <span className={`inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-bold ${
                          tx.type === 'Income' ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-400' : 'bg-rose-100 text-rose-700 dark:bg-rose-900/30 dark:text-rose-400'
                        }`}>
                          {tx.type === 'Income' ? '🟢 آمدن' : '🔴 خرچ'}: {tx.head_name}
                        </span>
                      </td>
                      <td className="py-3 px-4">
                        <div className="font-bold text-slate-800 dark:text-slate-100">{tx.spender_name || '-'}</div>
                        <div className="text-xs text-slate-500 dark:text-slate-400">{tx.description}</div>
                      </td>
                      <td className="py-3 px-4 text-sm text-slate-600 dark:text-slate-300">{tx.payment_method}</td>
                      <td className={`py-3 px-4 text-left font-sans font-extrabold text-lg ${
                        tx.type === 'Income' ? 'text-emerald-600 dark:text-emerald-400' : 'text-rose-600 dark:text-rose-400'
                      }`}>
                        {tx.type === 'Income' ? '+' : '-'} {tx.amount.toLocaleString()}
                      </td>
                      <td className="py-3 px-4 text-center print:hidden">
                        <button 
                          onClick={() => setPrintReceipt(tx)} 
                          className="px-3 py-1 bg-slate-100 dark:bg-slate-700 hover:bg-slate-200 text-slate-700 dark:text-slate-300 rounded-lg text-sm font-bold transition flex items-center gap-1 mx-auto"
                          title="رسید پرنٹ کریں"
                        >
                          <span>🖨️</span> پرنٹ
                        </button>
                      </td>
                    </tr>
                  ))
                )}
              </tbody>
            </table>
          </div>
        </div>
      </main>

      {/* Modal for Add Transaction */}
      {isModalOpen && (
        <div className="fixed inset-0 bg-slate-900/50 backdrop-blur-sm flex items-center justify-center z-50 p-4 transition-colors">
          <div className="bg-white dark:bg-slate-800 rounded-3xl shadow-2xl w-full max-w-2xl overflow-hidden border border-slate-200 dark:border-slate-700 animate-in fade-in zoom-in duration-300">
            <div className={`px-8 py-5 border-b border-slate-200 dark:border-slate-700 flex justify-between items-center text-white ${
              modalType === 'Income' ? 'bg-gradient-to-r from-emerald-600 to-teal-600' : 'bg-gradient-to-r from-rose-600 to-red-600'
            }`}>
              <h3 className="text-2xl font-bold flex items-center gap-2">
                <span>{modalType === 'Income' ? '➕ آمدن کا اندراج (Add Income)' : '➖ خرچ کا اندراج (Add Expense)'}</span>
              </h3>
              <button onClick={() => setIsModalOpen(false)} className="text-white hover:opacity-80 text-3xl font-sans leading-none">&times;</button>
            </div>

            <form onSubmit={handleSubmit} className="p-8 space-y-6">
              <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div>
                  <label className="block text-slate-700 dark:text-slate-300 font-bold mb-2">تاریخ (Date) *</label>
                  <input 
                    type="date" 
                    required 
                    className="w-full px-4 py-3 rounded-xl border border-slate-200 dark:border-slate-600 bg-slate-50 dark:bg-slate-700 text-slate-800 dark:text-slate-100 font-sans text-lg"
                    value={formData.date}
                    onChange={e => setFormData({ ...formData, date: e.target.value })}
                  />
                </div>

                <div>
                  <label className="block text-slate-700 dark:text-slate-300 font-bold mb-2">رسید نمبر (Receipt No)</label>
                  <input 
                    type="text" 
                    className="w-full px-4 py-3 rounded-xl border border-slate-200 dark:border-slate-600 bg-slate-50 dark:bg-slate-700 text-slate-800 dark:text-slate-100 font-sans text-lg"
                    value={formData.receipt_no}
                    onChange={e => setFormData({ ...formData, receipt_no: e.target.value })}
                  />
                </div>

                <div>
                  <label className="block text-slate-700 dark:text-slate-300 font-bold mb-2">مد / کیٹگری (Head) *</label>
                  <select 
                    required 
                    className="w-full px-4 py-3 rounded-xl border border-slate-200 dark:border-slate-600 bg-slate-50 dark:bg-slate-700 text-slate-800 dark:text-slate-100 text-lg"
                    value={formData.head_name}
                    onChange={e => setFormData({ ...formData, head_name: e.target.value })}
                  >
                    {(modalType === 'Income' ? headsIncome : headsExpense).map((h, i) => (
                      <option key={i} value={h}>{h}</option>
                    ))}
                  </select>
                </div>

                <div>
                  <label className="block text-slate-700 dark:text-slate-300 font-bold mb-2">رقم (Amount in PKR) *</label>
                  <input 
                    type="number" 
                    required 
                    min="1"
                    className="w-full px-4 py-3 rounded-xl border border-slate-200 dark:border-slate-600 bg-slate-50 dark:bg-slate-700 text-slate-800 dark:text-slate-100 font-sans text-lg"
                    placeholder="مثال: 5000"
                    value={formData.amount}
                    onChange={e => setFormData({ ...formData, amount: e.target.value })}
                  />
                </div>

                <div>
                  <label className="block text-slate-700 dark:text-slate-300 font-bold mb-2">
                    {modalType === 'Income' ? 'دہندہ کا نام (Received From)' : 'وصول کنندہ / خرچ کنندہ (Paid To)'}
                  </label>
                  <input 
                    type="text" 
                    className="w-full px-4 py-3 rounded-xl border border-slate-200 dark:border-slate-600 bg-slate-50 dark:bg-slate-700 text-slate-800 dark:text-slate-100 text-lg"
                    placeholder="نام درج کریں..."
                    value={formData.spender_name}
                    onChange={e => setFormData({ ...formData, spender_name: e.target.value })}
                  />
                </div>

                <div>
                  <label className="block text-slate-700 dark:text-slate-300 font-bold mb-2">طریقہ ادائیگی (Payment Method)</label>
                  <select 
                    className="w-full px-4 py-3 rounded-xl border border-slate-200 dark:border-slate-600 bg-slate-50 dark:bg-slate-700 text-slate-800 dark:text-slate-100 text-lg"
                    value={formData.payment_method}
                    onChange={e => setFormData({ ...formData, payment_method: e.target.value })}
                  >
                    <option value="Cash">نقد (Cash)</option>
                    <option value="Bank">بینک اکاؤنٹ (Bank Transfer)</option>
                    <option value="Online">آن لائن (JazzCash / EasyPaisa)</option>
                    <option value="Cheque">چیک (Cheque)</option>
                  </select>
                </div>

                <div className="md:col-span-2">
                  <label className="block text-slate-700 dark:text-slate-300 font-bold mb-2">تفصیل / ریمارکس (Description)</label>
                  <textarea 
                    rows={3}
                    className="w-full px-4 py-3 rounded-xl border border-slate-200 dark:border-slate-600 bg-slate-50 dark:bg-slate-700 text-slate-800 dark:text-slate-100 text-lg resize-none"
                    placeholder="اضافی تفصیل یا نوٹ..."
                    value={formData.description}
                    onChange={e => setFormData({ ...formData, description: e.target.value })}
                  />
                </div>
              </div>

              <div className="flex justify-end gap-4 pt-4 border-t border-slate-100 dark:border-slate-700">
                <button type="button" onClick={() => setIsModalOpen(false)} className="px-6 py-2.5 rounded-xl bg-slate-100 dark:bg-slate-700 text-slate-700 dark:text-slate-300 font-bold hover:bg-slate-200 text-lg">منسوخ کریں</button>
                <button 
                  type="submit" 
                  disabled={saving}
                  className={`px-8 py-2.5 rounded-xl text-white font-bold text-lg shadow-lg transition-all ${
                    modalType === 'Income' ? 'bg-emerald-600 hover:bg-emerald-700' : 'bg-rose-600 hover:bg-rose-700'
                  }`}
                >
                  {saving ? 'محفوظ ہو رہا ہے...' : 'محفوظ کریں'}
                </button>
              </div>
            </form>
          </div>
        </div>
      )}

      {/* Receipt Print Modal */}
      {printReceipt && (
        <div className="fixed inset-0 bg-slate-900/60 backdrop-blur-sm flex items-center justify-center z-50 p-4">
          <div className="bg-white rounded-3xl shadow-2xl w-full max-w-lg overflow-hidden border border-slate-300">
            <div className="p-4 bg-slate-100 border-b flex justify-between items-center print:hidden">
              <span className="font-bold text-slate-700">رسید پیش نظارہ (Receipt Preview)</span>
              <div className="flex gap-2">
                <button onClick={() => window.print()} className="px-4 py-1.5 bg-emerald-600 text-white rounded-lg font-bold text-sm">پرنٹ کریں</button>
                <button onClick={() => setPrintReceipt(null)} className="px-3 py-1.5 bg-slate-200 text-slate-700 rounded-lg text-sm font-bold">بند کریں</button>
              </div>
            </div>

            <div className="p-8 text-slate-800 font-urdu border-4 border-double border-slate-800 m-4 rounded-xl">
              <div className="text-center border-b pb-4 mb-4">
                <h2 className="text-2xl font-black">جامعہ اشرف المدارس</h2>
                <p className="text-xs text-slate-500">رسیدِ برائے {printReceipt.type === 'Income' ? 'آمدن و وصولی' : 'اخراجات'}</p>
              </div>
              <div className="space-y-3 text-base">
                <div className="flex justify-between">
                  <span className="font-bold">رسید نمبر:</span>
                  <span className="font-sans font-bold" dir="ltr">{printReceipt.receipt_no}</span>
                </div>
                <div className="flex justify-between">
                  <span className="font-bold">تاریخ:</span>
                  <span className="font-sans">{printReceipt.date}</span>
                </div>
                <div className="flex justify-between">
                  <span className="font-bold">مد (Category):</span>
                  <span>{printReceipt.head_name}</span>
                </div>
                <div className="flex justify-between">
                  <span className="font-bold">منجانب / بنام:</span>
                  <span className="font-bold">{printReceipt.spender_name || '-'}</span>
                </div>
                <div className="flex justify-between">
                  <span className="font-bold">طریقہ ادائیگی:</span>
                  <span>{printReceipt.payment_method}</span>
                </div>
                <div className="flex justify-between border-t border-b py-2 text-xl font-bold">
                  <span>کل رقم:</span>
                  <span className="font-sans font-extrabold text-emerald-700">Rs. {printReceipt.amount.toLocaleString()}</span>
                </div>
                <p className="text-sm text-slate-600 mt-2">تفصیل: {printReceipt.description || 'کوئی تفصیل درج نہیں'}</p>
              </div>
              <div className="flex justify-between items-end mt-12 pt-4 border-t border-dashed">
                <div className="text-center">
                  <div className="w-24 border-b border-black mb-1"></div>
                  <span className="text-xs font-bold">دستخط وصول کنندہ</span>
                </div>
                <div className="text-center">
                  <div className="w-24 border-b border-black mb-1"></div>
                  <span className="text-xs font-bold">مہر ادارہ</span>
                </div>
              </div>
            </div>
          </div>
        </div>
      )}
    </div>
  );
}
