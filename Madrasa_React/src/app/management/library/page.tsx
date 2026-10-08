'use client';

import { useState } from 'react';
import Link from 'next/link';

interface Book {
  id: number;
  title: string;
  author: string;
  category: string;
  rack_no: string;
  total_copies: number;
  available_copies: number;
}

export default function LibraryPage() {
  const [books, setBooks] = useState<Book[]>([
    { id: 1, title: 'صحیح بخاری مع فتح الباری', author: 'امام ابن حجر عسقلانیؒ', category: 'حدیث شریف', rack_no: 'الماری 2، خانہ 3', total_copies: 5, available_copies: 3 },
    { id: 2, title: 'تفسیر ابن کثیر (مکمل)', author: 'علامہ عماد الدین ابن کثیرؒ', category: 'تفسیر', rack_no: 'الماری 1، خانہ 1', total_copies: 4, available_copies: 4 },
    { id: 3, title: 'ہدایہ شریف مع حاشیہ', author: 'امام برہان الدین مرغینانیؒ', category: 'فقہ حنفی', rack_no: 'الماری 3، خانہ 2', total_copies: 8, available_copies: 6 },
    { id: 4, title: 'القاموس الوحید (عربی اردو لغت)', author: 'مولانا وحید الزماں کیرانویؒ', category: 'لغت و ادب', rack_no: 'الماری 4، خانہ 1', total_copies: 3, available_copies: 1 },
  ]);

  return (
    <div className="flex flex-col bg-slate-50 dark:bg-slate-900 font-urdu min-h-screen transition-colors" dir="rtl">
      <header className="h-20 bg-white/80 dark:bg-slate-800/80 backdrop-blur-md border-b border-slate-200 dark:border-slate-700 flex items-center justify-between px-8 sticky top-0 z-10 transition-colors">
        <div className="flex items-center gap-4">
          <Link href="/" className="text-theme-primary dark:text-theme-light hover:text-theme-hover transition-colors bg-theme-primary/10 p-2 rounded-lg">
            <span className="text-xl">🏠</span>
          </Link>
          <h2 className="text-2xl font-bold text-slate-800 dark:text-slate-100 flex items-center gap-2">
            <span>📚</span> کتب خانہ و لائبریری مینیجمنٹ (Library Management)
          </h2>
        </div>
      </header>

      <main className="flex-1 p-8">
        <div className="bg-white dark:bg-slate-800 rounded-2xl shadow-sm border border-slate-200 dark:border-slate-700 overflow-hidden">
          <div className="p-4 bg-slate-50 dark:bg-slate-700/50 flex justify-between items-center border-b">
            <h3 className="text-lg font-bold">کتب کی فہرست و کیٹلاگ</h3>
            <span className="text-sm font-bold text-slate-500">کل عناوین: {books.length}</span>
          </div>

          <table className="w-full text-right border-collapse">
            <thead>
              <tr className="bg-slate-50 dark:bg-slate-700/50 border-b text-slate-600 dark:text-slate-300 font-bold">
                <th className="p-4">#</th>
                <th className="p-4">کتاب کا نام</th>
                <th className="p-4">مصنف / مولف</th>
                <th className="p-4">موضوع / فن</th>
                <th className="p-4">الماری و خانہ</th>
                <th className="p-4 text-center">دستیاب / کل نسخے</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-slate-100 dark:divide-slate-700">
              {books.map((b, idx) => (
                <tr key={b.id} className="hover:bg-slate-50 dark:hover:bg-slate-700/50">
                  <td className="p-4 font-bold">{idx + 1}</td>
                  <td className="p-4 font-bold text-slate-800 dark:text-white">{b.title}</td>
                  <td className="p-4 text-slate-600 dark:text-slate-300">{b.author}</td>
                  <td className="p-4"><span className="px-3 py-1 bg-indigo-50 dark:bg-indigo-900/30 text-indigo-700 dark:text-indigo-400 rounded-full text-xs font-bold">{b.category}</span></td>
                  <td className="p-4 text-slate-500">{b.rack_no}</td>
                  <td className="p-4 text-center font-sans font-bold">
                    <span className="text-emerald-600">{b.available_copies}</span> / {b.total_copies}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </main>
    </div>
  );
}
