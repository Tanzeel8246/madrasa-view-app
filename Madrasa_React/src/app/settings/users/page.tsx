'use client';

import { useState } from 'react';
import Link from 'next/link';

interface SystemUser {
  id: number;
  username: string;
  name: string;
  role: string;
  phone: string;
  status: 'Active' | 'Blocked';
  permissions: string[];
}

export default function UsersManagementPage() {
  const [users, setUsers] = useState<SystemUser[]>([
    { id: 1, username: 'admin', name: 'مہتمم صاحب (سپر ایڈمن)', role: 'admin', phone: '03001234567', status: 'Active', permissions: ['manage_all'] },
    { id: 2, username: 'nazim_taleemat', name: 'مولانا عبدالحنان', role: 'nazim', phone: '03019876543', status: 'Active', permissions: ['classes', 'students', 'attendance', 'academics'] },
    { id: 3, username: 'accountant', name: 'محمد فاروق', role: 'accountant', phone: '03024567890', status: 'Active', permissions: ['finance', 'fees', 'payroll', 'reports'] },
    { id: 4, username: 'teacher_bilal', name: 'قاری محمد بلال', role: 'teacher', phone: '03031122334', status: 'Active', permissions: ['attendance', 'academics'] },
  ]);

  const [isModalOpen, setIsModalOpen] = useState(false);
  const [formData, setFormData] = useState({
    username: '',
    name: '',
    password: '',
    role: 'teacher',
    phone: '',
  });

  const handleAddUser = (e: React.FormEvent) => {
    e.preventDefault();
    const newUser: SystemUser = {
      id: Date.now(),
      username: formData.username,
      name: formData.name,
      role: formData.role,
      phone: formData.phone,
      status: 'Active',
      permissions: [formData.role],
    };
    setUsers([...users, newUser]);
    setIsModalOpen(false);
  };

  const toggleStatus = (id: number) => {
    setUsers(users.map(u => u.id === id ? { ...u, status: u.status === 'Active' ? 'Blocked' : 'Active' } : u));
  };

  return (
    <div className="flex flex-col bg-slate-50 dark:bg-slate-900 font-urdu min-h-screen transition-colors" dir="rtl">
      <header className="h-20 bg-white/80 dark:bg-slate-800/80 backdrop-blur-md border-b border-slate-200 dark:border-slate-700 flex items-center justify-between px-8 sticky top-0 z-10 transition-colors">
        <div className="flex items-center gap-4">
          <Link href="/" className="text-theme-primary dark:text-theme-light hover:text-theme-hover transition-colors bg-theme-primary/10 p-2 rounded-lg">
            <span className="text-xl">🏠</span>
          </Link>
          <h2 className="text-2xl font-bold text-slate-800 dark:text-slate-100 flex items-center gap-2">
            <span>👥</span> یوزرز اور اختیارات کا انتظام (Users & Permissions)
          </h2>
        </div>
        <button onClick={() => setIsModalOpen(true)} className="px-5 py-2.5 bg-theme-primary hover:bg-theme-hover text-white rounded-xl font-bold shadow-md">
          <span>➕</span> نیا یوزر شامل کریں
        </button>
      </header>

      <main className="flex-1 p-8">
        <div className="bg-white dark:bg-slate-800 rounded-2xl shadow-sm border border-slate-200 dark:border-slate-700 overflow-hidden">
          <table className="w-full text-right border-collapse">
            <thead>
              <tr className="bg-slate-50 dark:bg-slate-700/50 border-b text-slate-600 dark:text-slate-300 font-bold">
                <th className="p-4">#</th>
                <th className="p-4">یوزر نیم (Login ID)</th>
                <th className="p-4">مکمل نام</th>
                <th className="p-4">کردار (Role)</th>
                <th className="p-4">رابطہ نمبر</th>
                <th className="p-4 text-center">حالت (Status)</th>
                <th className="p-4 text-center">ایکشن</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-slate-100 dark:divide-slate-700">
              {users.map((u, i) => (
                <tr key={u.id} className="hover:bg-slate-50 dark:hover:bg-slate-700/50">
                  <td className="p-4 font-bold">{i + 1}</td>
                  <td className="p-4 font-sans font-bold text-theme-primary" dir="ltr">{u.username}</td>
                  <td className="p-4 font-bold text-slate-800 dark:text-white">{u.name}</td>
                  <td className="p-4">
                    <span className="px-3 py-1 bg-indigo-50 dark:bg-indigo-900/30 text-indigo-700 dark:text-indigo-400 rounded-full text-xs font-bold">
                      {u.role === 'admin' ? 'سپر ایڈمن' : u.role === 'nazim' ? 'ناظمِ تعلیمات' : u.role === 'accountant' ? 'اکاؤنٹنٹ / کیشئر' : 'استاذ / مدرس'}
                    </span>
                  </td>
                  <td className="p-4 font-sans" dir="ltr">{u.phone}</td>
                  <td className="p-4 text-center">
                    <span className={`px-3 py-1 rounded-full text-xs font-bold ${
                      u.status === 'Active' ? 'bg-emerald-100 text-emerald-800' : 'bg-rose-100 text-rose-800'
                    }`}>
                      {u.status === 'Active' ? 'فعال' : 'معطل'}
                    </span>
                  </td>
                  <td className="p-4 text-center">
                    {u.username !== 'admin' && (
                      <button 
                        onClick={() => toggleStatus(u.id)}
                        className={`px-3 py-1 rounded-lg text-xs font-bold transition ${
                          u.status === 'Active' ? 'bg-rose-100 text-rose-700 hover:bg-rose-200' : 'bg-emerald-100 text-emerald-700 hover:bg-emerald-200'
                        }`}
                      >
                        {u.status === 'Active' ? 'بلاک کریں' : 'بحال کریں'}
                      </button>
                    )}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </main>

      {/* Modal */}
      {isModalOpen && (
        <div className="fixed inset-0 bg-slate-900/50 backdrop-blur-sm flex items-center justify-center z-50 p-4">
          <div className="bg-white dark:bg-slate-800 rounded-3xl shadow-2xl w-full max-w-lg p-6 border border-slate-200 dark:border-slate-700">
            <h3 className="text-xl font-bold mb-4">نیا یوزر اکاؤنٹ بنائیں</h3>
            <form onSubmit={handleAddUser} className="space-y-4">
              <div>
                <label className="block text-sm font-bold mb-1">یوزر نیم (انگریزی میں) *</label>
                <input type="text" required className="w-full px-3 py-2 rounded-xl border font-sans" dir="ltr" value={formData.username} onChange={e => setFormData({...formData, username: e.target.value})} />
              </div>
              <div>
                <label className="block text-sm font-bold mb-1">مکمل نام *</label>
                <input type="text" required className="w-full px-3 py-2 rounded-xl border" value={formData.name} onChange={e => setFormData({...formData, name: e.target.value})} />
              </div>
              <div className="grid grid-cols-2 gap-4">
                <div>
                  <label className="block text-sm font-bold mb-1">کردار (Role) *</label>
                  <select className="w-full px-3 py-2 rounded-xl border" value={formData.role} onChange={e => setFormData({...formData, role: e.target.value})}>
                    <option value="teacher">استاذ / مدرس</option>
                    <option value="nazim">ناظمِ تعلیمات</option>
                    <option value="accountant">اکاؤنٹنٹ / کیشئر</option>
                    <option value="admin">ایڈمنسٹریٹر</option>
                  </select>
                </div>
                <div>
                  <label className="block text-sm font-bold mb-1">موبائل نمبر *</label>
                  <input type="text" required className="w-full px-3 py-2 rounded-xl border font-sans" dir="ltr" value={formData.phone} onChange={e => setFormData({...formData, phone: e.target.value})} />
                </div>
              </div>
              <div>
                <label className="block text-sm font-bold mb-1">پاسورڈ *</label>
                <input type="password" required className="w-full px-3 py-2 rounded-xl border font-sans" dir="ltr" value={formData.password} onChange={e => setFormData({...formData, password: e.target.value})} />
              </div>
              <div className="flex justify-end gap-3 pt-4 border-t">
                <button type="button" onClick={() => setIsModalOpen(false)} className="px-4 py-2 bg-slate-100 rounded-xl">منسوخ کریں</button>
                <button type="submit" className="px-6 py-2 bg-theme-primary text-white font-bold rounded-xl shadow">یوزر بنائیں</button>
              </div>
            </form>
          </div>
        </div>
      )}
    </div>
  );
}
