'use client';

import { useState } from 'react';
import Link from 'next/link';

interface ItikafRecord {
  id: number;
  name: string;
  father_name: string;
  cnic: string;
  phone: string;
  tent_no: string;
  year: string;
}

export default function MasjidDashboardPage() {
  const [activeTab, setActiveTab] = useState<'dashboard' | 'itikaf'>('dashboard');

  const [itikafList, setItikafList] = useState<ItikafRecord[]>([
    { id: 1, name: 'حاجی محمد رفیق', father_name: 'عبدالشکور', cnic: '38403-1234567-1', phone: '03001234567', tent_no: 'خیمہ نمبر 01', year: 'رمضان 1447ھ' },
    { id: 2, name: 'طارق محمود', father_name: 'محمد بشیر', cnic: '38403-7654321-3', phone: '03019876543', tent_no: 'خیمہ نمبر 02', year: 'رمضان 1447ھ' },
    { id: 3, name: 'سلمان فاروق', father_name: 'فاروق احمد', cnic: '38403-5566778-5', phone: '03024567890', tent_no: 'خیمہ نمبر 03', year: 'رمضان 1447ھ' },
  ]);

  return (
    <div className="flex flex-col bg-slate-50 dark:bg-slate-900 font-urdu min-h-screen transition-colors" dir="rtl">
      <header className="h-20 bg-white/80 dark:bg-slate-800/80 backdrop-blur-md border-b border-slate-200 dark:border-slate-700 flex items-center justify-between px-8 sticky top-0 z-10 transition-colors">
        <div className="flex items-center gap-4">
          <Link href="/" className="text-emerald-700 dark:text-emerald-400 bg-emerald-50 dark:bg-emerald-900/30 p-2 rounded-lg">
            <span className="text-xl">🕌</span>
          </Link>
          <h2 className="text-2xl font-bold text-slate-800 dark:text-slate-100 flex items-center gap-2">
            <span>🕌</span> جامع مسجد حنفیہ ڈیش بورڈ (Masjid Dashboard)
          </h2>
        </div>
        <div className="flex gap-2">
          <button onClick={() => setActiveTab('dashboard')} className={`px-4 py-2 rounded-xl font-bold ${activeTab === 'dashboard' ? 'bg-emerald-700 text-white' : 'bg-slate-100 text-slate-700'}`}>
            مسجد جائزہ و چندہ بکس
          </button>
          <button onClick={() => setActiveTab('itikaf')} className={`px-4 py-2 rounded-xl font-bold ${activeTab === 'itikaf' ? 'bg-emerald-700 text-white' : 'bg-slate-100 text-slate-700'}`}>
            اعتکاف ریکارڈ
          </button>
        </div>
      </header>

      <main className="flex-1 p-8">
        {activeTab === 'dashboard' ? (
          <div className="space-y-8">
            <div className="grid grid-cols-1 md:grid-cols-4 gap-6">
              <div className="bg-white dark:bg-slate-800 p-6 rounded-2xl shadow-sm border border-emerald-100">
                <p className="text-sm font-bold text-slate-500 mb-1">جمعہ چندہ بکس آمدن</p>
                <h3 className="text-3xl font-extrabold text-emerald-600 font-sans">Rs. 85,400</h3>
              </div>
              <div className="bg-white dark:bg-slate-800 p-6 rounded-2xl shadow-sm border border-emerald-100">
                <p className="text-sm font-bold text-slate-500 mb-1">امام و مؤذن ماہانہ وظائف</p>
                <h3 className="text-3xl font-extrabold text-slate-800 dark:text-white font-sans">Rs. 45,000</h3>
              </div>
              <div className="bg-white dark:bg-slate-800 p-6 rounded-2xl shadow-sm border border-emerald-100">
                <p className="text-sm font-bold text-slate-500 mb-1">مسجد بجلی و یوٹیلٹی بلز</p>
                <h3 className="text-3xl font-extrabold text-rose-600 font-sans">Rs. 22,000</h3>
              </div>
              <div className="bg-white dark:bg-slate-800 p-6 rounded-2xl shadow-sm border border-emerald-100">
                <p className="text-sm font-bold text-slate-500 mb-1">معتکفین تعداد</p>
                <h3 className="text-3xl font-extrabold text-indigo-600 font-sans">18 افراد</h3>
              </div>
            </div>

            <div className="bg-white dark:bg-slate-800 p-6 rounded-2xl shadow-sm border border-slate-200 dark:border-slate-700">
              <h3 className="text-xl font-bold mb-4">نمازوں کے اوقات کار</h3>
              <div className="grid grid-cols-5 gap-4 text-center">
                <div className="bg-slate-50 dark:bg-slate-700 p-4 rounded-xl">
                  <span className="text-xs font-bold text-slate-500 block">فجر</span>
                  <span className="text-2xl font-bold font-sans text-emerald-700">05:15 AM</span>
                </div>
                <div className="bg-slate-50 dark:bg-slate-700 p-4 rounded-xl">
                  <span className="text-xs font-bold text-slate-500 block">ظہر</span>
                  <span className="text-2xl font-bold font-sans text-emerald-700">01:30 PM</span>
                </div>
                <div className="bg-slate-50 dark:bg-slate-700 p-4 rounded-xl">
                  <span className="text-xs font-bold text-slate-500 block">عصر</span>
                  <span className="text-2xl font-bold font-sans text-emerald-700">04:45 PM</span>
                </div>
                <div className="bg-slate-50 dark:bg-slate-700 p-4 rounded-xl">
                  <span className="text-xs font-bold text-slate-500 block">مغرب</span>
                  <span className="text-2xl font-bold font-sans text-emerald-700">06:05 PM</span>
                </div>
                <div className="bg-slate-50 dark:bg-slate-700 p-4 rounded-xl">
                  <span className="text-xs font-bold text-slate-500 block">عشاء</span>
                  <span className="text-2xl font-bold font-sans text-emerald-700">08:00 PM</span>
                </div>
              </div>
            </div>
          </div>
        ) : (
          <div className="bg-white dark:bg-slate-800 rounded-2xl shadow-sm border border-slate-200 dark:border-slate-700 overflow-hidden">
            <div className="p-4 bg-slate-50 dark:bg-slate-700/50 flex justify-between items-center border-b">
              <h3 className="text-lg font-bold">معتکفین کا باضابطہ ریکارڈ</h3>
              <span className="text-sm font-bold text-slate-500">کل معتکفین: {itikafList.length}</span>
            </div>

            <table className="w-full text-right border-collapse">
              <thead>
                <tr className="bg-slate-50 dark:bg-slate-700/50 border-b text-slate-600 dark:text-slate-300 font-bold">
                  <th className="p-4">#</th>
                  <th className="p-4">نام معتکف</th>
                  <th className="p-4">ولدیت</th>
                  <th className="p-4">شناختی کارڈ نمبر</th>
                  <th className="p-4">موبائل نمبر</th>
                  <th className="p-4">خیمہ / جگہ الاٹمنٹ</th>
                  <th className="p-4">سال</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-100 dark:divide-slate-700">
                {itikafList.map((it, idx) => (
                  <tr key={it.id} className="hover:bg-slate-50 dark:hover:bg-slate-700/50">
                    <td className="p-4 font-bold">{idx + 1}</td>
                    <td className="p-4 font-bold text-slate-800 dark:text-white">{it.name}</td>
                    <td className="p-4 text-slate-500">{it.father_name}</td>
                    <td className="p-4 font-sans" dir="ltr">{it.cnic}</td>
                    <td className="p-4 font-sans" dir="ltr">{it.phone}</td>
                    <td className="p-4 font-bold text-emerald-700">{it.tent_no}</td>
                    <td className="p-4 font-sans">{it.year}</td>
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
