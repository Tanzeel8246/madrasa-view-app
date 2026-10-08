'use client';

import { useState, useEffect } from 'react';
import Link from 'next/link';

export default function ProfilePage() {
  const [user, setUser] = useState<any>({ name: 'ایڈمنسٹریٹر', username: 'admin' });
  const [oldPassword, setOldPassword] = useState('');
  const [newPassword, setNewPassword] = useState('');
  const [confirmPassword, setConfirmPassword] = useState('');
  const [msg, setMsg] = useState<{ type: 'success' | 'error'; text: string } | null>(null);

  useEffect(() => {
    const userStr = localStorage.getItem('user');
    if (userStr) {
      setUser(JSON.parse(userStr));
    }
  }, []);

  const handleChangePassword = (e: React.FormEvent) => {
    e.preventDefault();
    if (newPassword !== confirmPassword) {
      setMsg({ type: 'error', text: 'نیا پاسورڈ اور تصدیقی پاسورڈ آپس میں مماثل نہیں ہیں!' });
      return;
    }
    setMsg({ type: 'success', text: 'پاسورڈ کامیابی سے تبدیل ہو گیا!' });
    setOldPassword('');
    setNewPassword('');
    setConfirmPassword('');
  };

  return (
    <div className="flex flex-col bg-slate-50 dark:bg-slate-900 font-urdu min-h-screen transition-colors" dir="rtl">
      <header className="h-20 bg-white/80 dark:bg-slate-800/80 backdrop-blur-md border-b border-slate-200 dark:border-slate-700 flex items-center justify-between px-8 sticky top-0 z-10 transition-colors">
        <div className="flex items-center gap-4">
          <Link href="/" className="text-theme-primary dark:text-theme-light hover:text-theme-hover transition-colors bg-theme-primary/10 p-2 rounded-lg">
            <span className="text-xl">🏠</span>
          </Link>
          <h2 className="text-2xl font-bold text-slate-800 dark:text-slate-100 flex items-center gap-2">
            <span>🔑</span> صارف پروفائل و پاسورڈ تبدیلی (Profile & Security)
          </h2>
        </div>
      </header>

      <main className="flex-1 p-8 max-w-xl mx-auto w-full">
        {msg && (
          <div className={`mb-6 p-4 rounded-xl font-bold text-center border ${
            msg.type === 'success' ? 'bg-emerald-100 text-emerald-800 border-emerald-300' : 'bg-rose-100 text-rose-800 border-rose-300'
          }`}>
            {msg.text}
          </div>
        )}

        <div className="bg-white dark:bg-slate-800 rounded-3xl p-8 shadow-sm border border-slate-200 dark:border-slate-700">
          <div className="text-center mb-6 pb-6 border-b">
            <div className="w-20 h-20 bg-theme-primary/10 text-theme-primary rounded-full flex items-center justify-center text-4xl mx-auto mb-3 font-sans">
              👤
            </div>
            <h3 className="text-2xl font-bold text-slate-800 dark:text-white">{user.name}</h3>
            <p className="text-slate-500 font-sans mt-1" dir="ltr">@{user.username || 'admin'}</p>
          </div>

          <form onSubmit={handleChangePassword} className="space-y-4">
            <div>
              <label className="block text-slate-700 dark:text-slate-300 font-bold mb-2">پرانا پاسورڈ درج کریں *</label>
              <input 
                type="password" 
                required 
                className="w-full px-4 py-3 rounded-xl border border-slate-300 dark:border-slate-600 font-sans" 
                dir="ltr"
                value={oldPassword} 
                onChange={e => setOldPassword(e.target.value)} 
              />
            </div>

            <div>
              <label className="block text-slate-700 dark:text-slate-300 font-bold mb-2">نیا پاسورڈ *</label>
              <input 
                type="password" 
                required 
                className="w-full px-4 py-3 rounded-xl border border-slate-300 dark:border-slate-600 font-sans" 
                dir="ltr"
                value={newPassword} 
                onChange={e => setNewPassword(e.target.value)} 
              />
            </div>

            <div>
              <label className="block text-slate-700 dark:text-slate-300 font-bold mb-2">نئے پاسورڈ کی تصدیق کریں *</label>
              <input 
                type="password" 
                required 
                className="w-full px-4 py-3 rounded-xl border border-slate-300 dark:border-slate-600 font-sans" 
                dir="ltr"
                value={confirmPassword} 
                onChange={e => setConfirmPassword(e.target.value)} 
              />
            </div>

            <button type="submit" className="w-full py-4 bg-theme-primary hover:bg-theme-hover text-white font-bold text-lg rounded-xl shadow-lg transition mt-4">
              پاسورڈ تبدیل کریں
            </button>
          </form>
        </div>
      </main>
    </div>
  );
}
