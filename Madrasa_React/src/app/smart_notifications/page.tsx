'use client';

import { useState } from 'react';
import Link from 'next/link';

interface SmartAlert {
  id: number;
  type: 'absent' | 'fee' | 'request' | 'leave';
  title: string;
  description: string;
  time: string;
  is_read: boolean;
  action_url: string;
}

export default function SmartNotificationsPage() {
  const [alerts, setAlerts] = useState<SmartAlert[]>([
    { id: 1, type: 'absent', title: 'آج کی غیر حاضری الرٹ', description: 'آج 4 طلباء بغیر اطلاع کے غیر حاضر ہیں (کلاس حفظ: 2، ناظرہ: 2)۔', time: 'آج صبح 08:30', is_read: false, action_url: '/attendance' },
    { id: 2, type: 'request', title: 'والدین کی نئی درخواست', description: 'طالب علم حمزہ طارق کے والد کی طرف سے 2 دن کی رخصت کی درخواست موصول ہوئی ہے۔', time: '2 گھنٹے قبل', is_read: false, action_url: '/complaints' },
    { id: 3, type: 'fee', title: 'فیس یاددہانی الرٹ', description: 'ماہ اکتوبر کی فیس کے 3 نادہندگان طلباء کے بقایاجات موجود ہیں۔', time: 'کل شام', is_read: true, action_url: '/finance/fees' },
    { id: 4, type: 'leave', title: 'استاد رخصت درخواست', description: 'قاری محمد بلال نے کل کے لیے ہنگامی رخصت کی درخواست بھیجی ہے۔', time: 'کل دوپہر', is_read: true, action_url: '/teachers' },
  ]);

  const markAllRead = () => {
    setAlerts(alerts.map(a => ({ ...a, is_read: true })));
  };

  return (
    <div className="flex flex-col bg-slate-50 dark:bg-slate-900 font-urdu min-h-screen transition-colors" dir="rtl">
      <header className="h-20 bg-white/80 dark:bg-slate-800/80 backdrop-blur-md border-b border-slate-200 dark:border-slate-700 flex items-center justify-between px-8 sticky top-0 z-10 transition-colors">
        <div className="flex items-center gap-4">
          <Link href="/" className="text-theme-primary dark:text-theme-light hover:text-theme-hover transition-colors bg-theme-primary/10 p-2 rounded-lg">
            <span className="text-xl">🏠</span>
          </Link>
          <h2 className="text-2xl font-bold text-slate-800 dark:text-slate-100 flex items-center gap-2">
            <span>🔔</span> اسمارٹ الرٹس و نوٹیفیکیشنز (Smart Alerts)
          </h2>
        </div>
        <button onClick={markAllRead} className="px-4 py-2 bg-slate-100 dark:bg-slate-700 hover:bg-slate-200 text-slate-700 dark:text-slate-300 font-bold text-sm rounded-xl">
          سب کو پڑھا ہوا نشان زد کریں
        </button>
      </header>

      <main className="flex-1 p-8 max-w-4xl mx-auto w-full">
        <div className="space-y-4">
          {alerts.map(a => (
            <div 
              key={a.id} 
              className={`p-6 rounded-2xl border transition-all flex items-start justify-between gap-4 ${
                !a.is_read 
                  ? 'bg-white dark:bg-slate-800 border-indigo-200 dark:border-indigo-800 shadow-md ring-1 ring-indigo-500/20' 
                  : 'bg-white/70 dark:bg-slate-800/60 border-slate-200 dark:border-slate-700'
              }`}
            >
              <div className="flex items-start gap-4">
                <div className={`w-12 h-12 rounded-xl flex items-center justify-center text-2xl shrink-0 ${
                  a.type === 'absent' ? 'bg-rose-100 text-rose-600' :
                  a.type === 'fee' ? 'bg-amber-100 text-amber-600' :
                  a.type === 'request' ? 'bg-blue-100 text-blue-600' : 'bg-emerald-100 text-emerald-600'
                }`}>
                  {a.type === 'absent' ? '⚠️' : a.type === 'fee' ? '💸' : a.type === 'request' ? '✉️' : '👨‍🏫'}
                </div>
                <div>
                  <div className="flex items-center gap-2 mb-1">
                    <h3 className="font-bold text-lg text-slate-800 dark:text-white">{a.title}</h3>
                    {!a.is_read && (
                      <span className="w-2.5 h-2.5 rounded-full bg-rose-500 inline-block animate-ping"></span>
                    )}
                  </div>
                  <p className="text-slate-600 dark:text-slate-300 text-sm mb-2">{a.description}</p>
                  <span className="text-xs text-slate-400 font-sans">{a.time}</span>
                </div>
              </div>

              <Link 
                href={a.action_url}
                className="px-4 py-2 bg-theme-primary/10 hover:bg-theme-primary text-theme-primary hover:text-white rounded-xl text-xs font-bold transition shrink-0"
              >
                جائزہ لیں ➔
              </Link>
            </div>
          ))}
        </div>
      </main>
    </div>
  );
}
