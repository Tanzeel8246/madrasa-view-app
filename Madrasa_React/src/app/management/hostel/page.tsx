'use client';

import { useState } from 'react';
import Link from 'next/link';

interface HostelRoom {
  id: number;
  room_no: string;
  floor: string;
  total_beds: number;
  occupied_beds: number;
  supervisor: string;
}

export default function HostelPage() {
  const [rooms, setRooms] = useState<HostelRoom[]>([
    { id: 1, room_no: 'کمرہ 101', floor: 'گراؤنڈ فلور', total_beds: 12, occupied_beds: 12, supervisor: 'قاری بلال' },
    { id: 2, room_no: 'کمرہ 102', floor: 'گراؤنڈ فلور', total_beds: 12, occupied_beds: 10, supervisor: 'مولانا عثمان' },
    { id: 3, room_no: 'کمرہ 201', floor: 'پہلی منزل', total_beds: 16, occupied_beds: 14, supervisor: 'حافظ سلیمان' },
    { id: 4, room_no: 'کمرہ 202', floor: 'پہلی منزل', total_beds: 16, occupied_beds: 16, supervisor: 'قاری احمد' },
  ]);

  const totalBeds = rooms.reduce((a, b) => a + b.total_beds, 0);
  const totalOccupied = rooms.reduce((a, b) => a + b.occupied_beds, 0);
  const totalFree = totalBeds - totalOccupied;

  return (
    <div className="flex flex-col bg-slate-50 dark:bg-slate-900 font-urdu min-h-screen transition-colors" dir="rtl">
      <header className="h-20 bg-white/80 dark:bg-slate-800/80 backdrop-blur-md border-b border-slate-200 dark:border-slate-700 flex items-center justify-between px-8 sticky top-0 z-10 transition-colors">
        <div className="flex items-center gap-4">
          <Link href="/" className="text-theme-primary dark:text-theme-light hover:text-theme-hover transition-colors bg-theme-primary/10 p-2 rounded-lg">
            <span className="text-xl">🏠</span>
          </Link>
          <h2 className="text-2xl font-bold text-slate-800 dark:text-slate-100 flex items-center gap-2">
            <span>🛏️</span> دارالاقامہ و ہاسٹل الاٹمنٹ (Hostel Management)
          </h2>
        </div>
      </header>

      <main className="flex-1 p-8">
        <div className="grid grid-cols-1 md:grid-cols-3 gap-6 mb-8">
          <div className="bg-white dark:bg-slate-800 p-6 rounded-2xl shadow-sm border">
            <p className="text-sm font-bold text-slate-500 mb-1">کل رہائشی گنجائش (Total Beds)</p>
            <h3 className="text-3xl font-extrabold font-sans text-slate-800 dark:text-white">{totalBeds}</h3>
          </div>
          <div className="bg-white dark:bg-slate-800 p-6 rounded-2xl shadow-sm border border-emerald-100">
            <p className="text-sm font-bold text-emerald-600 mb-1">مقیم طلبہ (Occupied)</p>
            <h3 className="text-3xl font-extrabold font-sans text-emerald-600">{totalOccupied}</h3>
          </div>
          <div className="bg-white dark:bg-slate-800 p-6 rounded-2xl shadow-sm border border-indigo-100">
            <p className="text-sm font-bold text-indigo-600 mb-1">خالی بیڈز (Available)</p>
            <h3 className="text-3xl font-extrabold font-sans text-indigo-600">{totalFree}</h3>
          </div>
        </div>

        <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
          {rooms.map(r => (
            <div key={r.id} className="bg-white dark:bg-slate-800 rounded-2xl shadow-sm border border-slate-200 dark:border-slate-700 p-6">
              <div className="flex justify-between items-center mb-3">
                <h3 className="text-xl font-bold text-slate-800 dark:text-white">{r.room_no}</h3>
                <span className="text-xs bg-slate-100 dark:bg-slate-700 px-2 py-1 rounded font-bold">{r.floor}</span>
              </div>
              <p className="text-sm text-slate-500 mb-4">نگران کمرہ: <span className="font-bold text-slate-700 dark:text-slate-300">{r.supervisor}</span></p>

              <div className="space-y-2">
                <div className="flex justify-between text-xs font-bold">
                  <span>بیڈز الاٹمنٹ:</span>
                  <span className="font-sans">{r.occupied_beds} / {r.total_beds}</span>
                </div>
                <div className="w-full bg-slate-100 dark:bg-slate-700 h-2.5 rounded-full overflow-hidden">
                  <div 
                    className={`h-full rounded-full ${r.occupied_beds === r.total_beds ? 'bg-rose-500' : 'bg-emerald-500'}`}
                    style={{ width: `${(r.occupied_beds / r.total_beds) * 100}%` }}
                  ></div>
                </div>
              </div>

              <div className="mt-4 pt-3 border-t text-center">
                <span className={`text-xs font-bold ${r.occupied_beds === r.total_beds ? 'text-rose-600' : 'text-emerald-600'}`}>
                  {r.occupied_beds === r.total_beds ? 'کمرہ فل ہے' : `${r.total_beds - r.occupied_beds} بیڈز خالی ہیں`}
                </span>
              </div>
            </div>
          ))}
        </div>
      </main>
    </div>
  );
}
