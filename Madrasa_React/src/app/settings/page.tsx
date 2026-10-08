'use client';

import { useState } from 'react';
import Link from 'next/link';

export default function SettingsPage() {
  const [formData, setFormData] = useState({
    madrasa_name: 'مدرسہ اشرف المدارس',
    masjid_name: 'جامع مسجد حنفیہ',
    address: 'محلہ راؤ رشید والا/بالمقابل دفتر زراعت ساہیوال روڈ فروکہ ضلع سرگودھا',
    phone: '0300-1234567',
    email: 'info@madrasa.com',
    hijri_year: '1447ھ',
    principal_name: 'مولانا مفتی عبدالحنان صاحب دامت برکاتہم',
    receipt_footer: 'طالب علم کی غیر حاضری پر یومیہ 50 روپے جرمانہ عائد کیا جائے گا۔',
  });

  const [saved, setSaved] = useState(false);

  const handleSave = (e: React.FormEvent) => {
    e.preventDefault();
    setSaved(true);
    setTimeout(() => setSaved(false), 3000);
  };

  return (
    <div className="flex flex-col bg-slate-50 dark:bg-slate-900 font-urdu min-h-screen transition-colors" dir="rtl">
      <header className="h-20 bg-white/80 dark:bg-slate-800/80 backdrop-blur-md border-b border-slate-200 dark:border-slate-700 flex items-center justify-between px-8 sticky top-0 z-10 transition-colors">
        <div className="flex items-center gap-4">
          <Link href="/" className="text-theme-primary dark:text-theme-light hover:text-theme-hover transition-colors bg-theme-primary/10 p-2 rounded-lg">
            <span className="text-xl">🏠</span>
          </Link>
          <h2 className="text-2xl font-bold text-slate-800 dark:text-slate-100 flex items-center gap-2">
            <span>⚙️</span> سافٹ ویئر و ادارہ جاتی سیٹنگز (Software Settings)
          </h2>
        </div>
      </header>

      <main className="flex-1 p-8 max-w-4xl mx-auto w-full">
        {saved && (
          <div className="mb-6 p-4 bg-emerald-100 text-emerald-800 rounded-xl font-bold text-center border border-emerald-300">
            تمام سیٹنگز کامیابی سے محفوظ ہو گئیں!
          </div>
        )}

        <form onSubmit={handleSave} className="bg-white dark:bg-slate-800 rounded-3xl p-8 shadow-sm border border-slate-200 dark:border-slate-700 space-y-6">
          <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
            <div>
              <label className="block font-bold mb-2">مدرسہ کا نام (Madrasa Name)</label>
              <input type="text" className="w-full px-4 py-3 rounded-xl border text-lg" value={formData.madrasa_name} onChange={e => setFormData({...formData, madrasa_name: e.target.value})} />
            </div>

            <div>
              <label className="block font-bold mb-2">منسلک مسجد کا نام (Masjid Name)</label>
              <input type="text" className="w-full px-4 py-3 rounded-xl border text-lg" value={formData.masjid_name} onChange={e => setFormData({...formData, masjid_name: e.target.value})} />
            </div>

            <div className="md:col-span-2">
              <label className="block font-bold mb-2">ادارے کا مکمل پتہ (Address)</label>
              <input type="text" className="w-full px-4 py-3 rounded-xl border text-lg" value={formData.address} onChange={e => setFormData({...formData, address: e.target.value})} />
            </div>

            <div>
              <label className="block font-bold mb-2">رابطہ فون نمبر (Phone)</label>
              <input type="text" className="w-full px-4 py-3 rounded-xl border font-sans text-lg" dir="ltr" value={formData.phone} onChange={e => setFormData({...formData, phone: e.target.value})} />
            </div>

            <div>
              <label className="block font-bold mb-2">موجودہ تعلیمی / ہجری سال</label>
              <input type="text" className="w-full px-4 py-3 rounded-xl border text-lg" value={formData.hijri_year} onChange={e => setFormData({...formData, hijri_year: e.target.value})} />
            </div>

            <div className="md:col-span-2">
              <label className="block font-bold mb-2">مہتمم / ناظم اعلیٰ کا نام</label>
              <input type="text" className="w-full px-4 py-3 rounded-xl border text-lg" value={formData.principal_name} onChange={e => setFormData({...formData, principal_name: e.target.value})} />
            </div>

            <div className="md:col-span-2">
              <label className="block font-bold mb-2">رسید فوٹر نوٹ (رسید کے نیچے چھپنے والی ہدایت)</label>
              <textarea rows={3} className="w-full px-4 py-3 rounded-xl border resize-none" value={formData.receipt_footer} onChange={e => setFormData({...formData, receipt_footer: e.target.value})} />
            </div>
          </div>

          <div className="flex justify-end pt-4 border-t">
            <button type="submit" className="px-8 py-3 bg-theme-primary text-white font-bold text-lg rounded-xl shadow-lg hover:bg-theme-hover transition">
              سیٹنگز محفوظ کریں
            </button>
          </div>
        </form>
      </main>
    </div>
  );
}
