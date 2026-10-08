'use client';

import { useState, useEffect } from 'react';
import Link from 'next/link';

interface Asset {
  id: number;
  name: string;
  category: string;
  quantity: number;
  estimated_value: number;
  location: string;
  condition: 'بہترین' | 'مرمت طلب' | 'ناکارہ';
}

interface InKindDonation {
  id: number;
  donor_name: string;
  item_name: string;
  quantity_str: string;
  estimated_value: number;
  date: string;
  notes: string;
}

export default function AssetsPage() {
  const [tab, setTab] = useState<'assets' | 'donations'>('assets');
  const [assets, setAssets] = useState<Asset[]>([]);
  const [donations, setDonations] = useState<InKindDonation[]>([]);
  const [isModalOpen, setIsModalOpen] = useState(false);

  useEffect(() => {
    setAssets([
      { id: 1, name: 'لکڑی کے ڈیسک اور بینچ', category: 'فرنیچر', quantity: 60, estimated_value: 180000, location: 'درجات درس نظامی', condition: 'بہترین' },
      { id: 2, name: 'واٹر کولر و فلٹر پلانٹ', category: 'الیکٹرانکس', quantity: 2, estimated_value: 85000, location: 'صحن مدرسہ', condition: 'بہترین' },
      { id: 3, name: 'دیگیں و باورچی خانہ برتن', category: 'میس و کچن', quantity: 12, estimated_value: 120000, location: 'باورچی خانہ', condition: 'بہترین' },
      { id: 4, name: 'یو پی ایس و بیٹریاں', category: 'الیکٹرانکس', quantity: 4, estimated_value: 220000, location: 'مرکزی ہال', condition: 'مرمت طلب' },
    ]);

    setDonations([
      { id: 1, donor_name: 'حاجی بشیر احمد', item_name: 'گندم کی بوریاں', quantity_str: '20 بوری (1000 کلو)', estimated_value: 95000, date: '2026-10-02', notes: 'برائے مطبخ طلبہ' },
      { id: 2, donor_name: 'ملک ارشد', item_name: 'کوکنگ آئل کارٹن', quantity_str: '5 پیٹیاں', estimated_value: 38000, date: '2026-10-05', notes: 'راشن عطیہ' },
      { id: 3, donor_name: 'سیٹھ فاروق', item_name: 'سردیوں کے کمبل', quantity_str: '50 عدد', estimated_value: 75000, date: '2026-10-07', notes: 'دارالاقامہ طلبہ' },
    ]);
  }, []);

  return (
    <div className="flex flex-col bg-slate-50 dark:bg-slate-900 font-urdu min-h-screen transition-colors" dir="rtl">
      <header className="h-20 bg-white/80 dark:bg-slate-800/80 backdrop-blur-md border-b border-slate-200 dark:border-slate-700 flex items-center justify-between px-8 sticky top-0 z-10 transition-colors">
        <div className="flex items-center gap-4">
          <Link href="/finance" className="text-theme-primary dark:text-theme-light hover:text-theme-hover transition-colors bg-theme-primary/10 p-2 rounded-lg">
            <span className="text-xl">⬅️</span>
          </Link>
          <h2 className="text-2xl font-bold text-slate-800 dark:text-slate-100 flex items-center gap-2">
            <span>📦</span> اثاثہ جات و جنسی عطیات (Assets & In-Kind Donations)
          </h2>
        </div>
        <div className="flex gap-2">
          <button onClick={() => setTab('assets')} className={`px-5 py-2 rounded-xl font-bold transition ${tab === 'assets' ? 'bg-theme-primary text-white' : 'bg-slate-100 text-slate-700'}`}>
            ادارہ جاتی اثاثہ جات
          </button>
          <button onClick={() => setTab('donations')} className={`px-5 py-2 rounded-xl font-bold transition ${tab === 'donations' ? 'bg-theme-primary text-white' : 'bg-slate-100 text-slate-700'}`}>
            جنسی عطیات (راشن/سامان)
          </button>
        </div>
      </header>

      <main className="flex-1 p-8">
        {tab === 'assets' ? (
          <div className="bg-white dark:bg-slate-800 rounded-2xl shadow-sm border border-slate-200 dark:border-slate-700 overflow-hidden">
            <div className="p-4 bg-slate-50 dark:bg-slate-700/50 flex justify-between items-center border-b">
              <h3 className="text-lg font-bold">موجودہ اثاثہ جات کا رجسٹر</h3>
              <span className="text-sm font-bold text-slate-500">کل مالیت: Rs. {assets.reduce((a, x) => a + x.estimated_value, 0).toLocaleString()}</span>
            </div>
            <table className="w-full text-right border-collapse">
              <thead>
                <tr className="bg-slate-50 dark:bg-slate-700/50 border-b text-slate-600 dark:text-slate-300 font-bold">
                  <th className="p-4">#</th>
                  <th className="p-4">نام اثاثہ</th>
                  <th className="p-4">کیٹگری</th>
                  <th className="p-4">تعداد</th>
                  <th className="p-4">تخمینہ مالیت</th>
                  <th className="p-4">مقام (Location)</th>
                  <th className="p-4 text-center">حالت (Condition)</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-100 dark:divide-slate-700">
                {assets.map((a, i) => (
                  <tr key={a.id} className="hover:bg-slate-50 dark:hover:bg-slate-700/50">
                    <td className="p-4 font-bold">{i + 1}</td>
                    <td className="p-4 font-bold">{a.name}</td>
                    <td className="p-4">{a.category}</td>
                    <td className="p-4 font-sans font-bold">{a.quantity}</td>
                    <td className="p-4 font-sans font-bold text-emerald-600">Rs. {a.estimated_value.toLocaleString()}</td>
                    <td className="p-4">{a.location}</td>
                    <td className="p-4 text-center">
                      <span className={`px-3 py-1 rounded-full text-xs font-bold ${
                        a.condition === 'بہترین' ? 'bg-emerald-100 text-emerald-700' : 'bg-amber-100 text-amber-700'
                      }`}>
                        {a.condition}
                      </span>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        ) : (
          <div className="bg-white dark:bg-slate-800 rounded-2xl shadow-sm border border-slate-200 dark:border-slate-700 overflow-hidden">
            <div className="p-4 bg-slate-50 dark:bg-slate-700/50 flex justify-between items-center border-b">
              <h3 className="text-lg font-bold">جنسی عطیات و راشن کا اندراج</h3>
              <span className="text-sm font-bold text-slate-500">کل تخمینہ مالیت: Rs. {donations.reduce((a, x) => a + x.estimated_value, 0).toLocaleString()}</span>
            </div>
            <table className="w-full text-right border-collapse">
              <thead>
                <tr className="bg-slate-50 dark:bg-slate-700/50 border-b text-slate-600 dark:text-slate-300 font-bold">
                  <th className="p-4">#</th>
                  <th className="p-4">تاریخ</th>
                  <th className="p-4">نام عطیہ دہندہ</th>
                  <th className="p-4">شے / جنس</th>
                  <th className="p-4">مقدار / تعداد</th>
                  <th className="p-4">تخمینہ مالیت</th>
                  <th className="p-4">تفصیل / مصرف</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-100 dark:divide-slate-700">
                {donations.map((d, i) => (
                  <tr key={d.id} className="hover:bg-slate-50 dark:hover:bg-slate-700/50">
                    <td className="p-4 font-bold">{i + 1}</td>
                    <td className="p-4 font-sans">{d.date}</td>
                    <td className="p-4 font-bold">{d.donor_name}</td>
                    <td className="p-4 font-bold text-indigo-600">{d.item_name}</td>
                    <td className="p-4">{d.quantity_str}</td>
                    <td className="p-4 font-sans font-bold text-emerald-600">Rs. {d.estimated_value.toLocaleString()}</td>
                    <td className="p-4 text-sm text-slate-500">{d.notes}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </main>
    </div>
  );
}
