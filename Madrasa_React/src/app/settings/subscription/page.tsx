'use client';

import { useState } from 'react';
import Link from 'next/link';

export default function SubscriptionPage() {
  const currentPlan = {
    name: 'معیاری پیکج (Standard Plan)',
    price: 'Rs. 2,500 / ماہ',
    status: 'Active',
    expiry: '15 نومبر 2026ء',
    days_left: 38,
    max_students: 100,
    used_students: 48,
    max_classes: 6,
    used_classes: 4,
    has_mobile_app: true,
    has_desktop_app: true,
  };

  return (
    <div className="flex flex-col bg-slate-50 dark:bg-slate-900 font-urdu min-h-screen transition-colors" dir="rtl">
      <header className="h-20 bg-white/80 dark:bg-slate-800/80 backdrop-blur-md border-b border-slate-200 dark:border-slate-700 flex items-center justify-between px-8 sticky top-0 z-10 transition-colors">
        <div className="flex items-center gap-4">
          <Link href="/" className="text-theme-primary dark:text-theme-light hover:text-theme-hover transition-colors bg-theme-primary/10 p-2 rounded-lg">
            <span className="text-xl">🏠</span>
          </Link>
          <h2 className="text-2xl font-bold text-slate-800 dark:text-slate-100 flex items-center gap-2">
            <span>💎</span> سبسکرپشن اور فیس کیفیت (Subscription Status)
          </h2>
        </div>
      </header>

      <main className="flex-1 p-8 max-w-4xl mx-auto w-full">
        {/* Active Plan Card */}
        <div className="bg-gradient-to-r from-emerald-700 to-teal-800 text-white rounded-3xl p-8 shadow-xl mb-8">
          <div className="flex justify-between items-start mb-4">
            <div>
              <span className="px-3 py-1 bg-white/20 rounded-full text-xs font-bold font-sans">ACTIVE PLAN</span>
              <h2 className="text-3xl font-extrabold mt-2">{currentPlan.name}</h2>
              <p className="text-emerald-100 text-sm mt-1">تینوں پلیٹ فارمز فعال: ویب ایڈمن پورٹل + اینڈرائڈ ایپ + ونڈوز سافٹ ویئر</p>
            </div>
            <div className="text-left font-sans">
              <span className="text-2xl font-extrabold">{currentPlan.price}</span>
              <span className="text-xs text-emerald-200 block mt-1">باقی ایام: {currentPlan.days_left} دن ({currentPlan.expiry})</span>
            </div>
          </div>

          <div className="grid grid-cols-2 gap-6 pt-6 border-t border-white/20">
            <div>
              <div className="flex justify-between text-xs font-bold mb-1">
                <span>طلبہ گنجائش استعمال:</span>
                <span className="font-sans">{currentPlan.used_students} / {currentPlan.max_students}</span>
              </div>
              <div className="w-full bg-white/20 h-2.5 rounded-full overflow-hidden">
                <div className="bg-white h-full rounded-full" style={{ width: `${(currentPlan.used_students / currentPlan.max_students) * 100}%` }}></div>
              </div>
            </div>

            <div>
              <div className="flex justify-between text-xs font-bold mb-1">
                <span>کلاسز حد:</span>
                <span className="font-sans">{currentPlan.used_classes} / {currentPlan.max_classes}</span>
              </div>
              <div className="w-full bg-white/20 h-2.5 rounded-full overflow-hidden">
                <div className="bg-white h-full rounded-full" style={{ width: `${(currentPlan.used_classes / currentPlan.max_classes) * 100}%` }}></div>
              </div>
            </div>
          </div>
        </div>

        {/* Upgrade Support Info */}
        <div className="bg-white dark:bg-slate-800 rounded-3xl p-8 border border-slate-200 dark:border-slate-700 text-center space-y-4">
          <h3 className="text-xl font-bold">پیکج اپ گریڈ یا فیس تجدید کے لیے رابطہ</h3>
          <p className="text-slate-500 max-w-lg mx-auto">اگر آپ کے ادارے کے طلبہ کی تعداد 100 سے تجاوز کر گئی ہے تو آپ ایڈوانس یا ان لمیٹڈ پیکج پر باآسانی منتقل ہو سکتے ہیں۔</p>
          <div className="pt-2">
            <a href="https://wa.me/923001234567" target="_blank" rel="noopener noreferrer" className="inline-flex items-center gap-2 px-8 py-3 bg-[#25d366] text-white font-bold rounded-2xl shadow-lg hover:opacity-90">
              <span>💬</span> واٹس ایپ سپورٹ پر رابطہ کریں
            </a>
          </div>
        </div>
      </main>
    </div>
  );
}
