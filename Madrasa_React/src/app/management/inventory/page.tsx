'use client';

import { useState } from 'react';
import Link from 'next/link';

interface InventoryItem {
  id: number;
  item_name: string;
  category: string;
  stock_qty: number;
  unit_price: number;
  min_alert_qty: number;
  status: 'In Stock' | 'Low Stock' | 'Out of Stock';
}

export default function InventoryPage() {
  const [items, setItems] = useState<InventoryItem[]>([
    { id: 1, item_name: 'سفید کرتا شلوار (یونیفارم سائز 32)', category: 'لباس / یونیفارم', stock_qty: 45, unit_price: 1800, min_alert_qty: 10, status: 'In Stock' },
    { id: 2, item_name: 'نمازی ٹوپی (کڑھائی والی)', category: 'لباس / یونیفارم', stock_qty: 6, unit_price: 250, min_alert_qty: 15, status: 'Low Stock' },
    { id: 3, item_name: 'قاعدہ یسرن القرآن', category: 'کتب و نصاب', stock_qty: 120, unit_price: 120, min_alert_qty: 20, status: 'In Stock' },
    { id: 4, item_name: 'رجسٹر خطاطی و امتحانات', category: 'سٹیشنری', stock_qty: 0, unit_price: 150, min_alert_qty: 25, status: 'Out of Stock' },
  ]);

  return (
    <div className="flex flex-col bg-slate-50 dark:bg-slate-900 font-urdu min-h-screen transition-colors" dir="rtl">
      <header className="h-20 bg-white/80 dark:bg-slate-800/80 backdrop-blur-md border-b border-slate-200 dark:border-slate-700 flex items-center justify-between px-8 sticky top-0 z-10 transition-colors">
        <div className="flex items-center gap-4">
          <Link href="/" className="text-theme-primary dark:text-theme-light hover:text-theme-hover transition-colors bg-theme-primary/10 p-2 rounded-lg">
            <span className="text-xl">🏠</span>
          </Link>
          <h2 className="text-2xl font-bold text-slate-800 dark:text-slate-100 flex items-center gap-2">
            <span>🛒</span> شاپ و انوینٹری اسٹور (Shop & Inventory)
          </h2>
        </div>
      </header>

      <main className="flex-1 p-8">
        <div className="bg-white dark:bg-slate-800 rounded-2xl shadow-sm border border-slate-200 dark:border-slate-700 overflow-hidden">
          <div className="p-4 bg-slate-50 dark:bg-slate-700/50 flex justify-between items-center border-b">
            <h3 className="text-lg font-bold">موجودہ اسٹاک آئٹمز</h3>
            <span className="text-sm font-bold text-slate-500">کل آئٹمز: {items.length}</span>
          </div>

          <table className="w-full text-right border-collapse">
            <thead>
              <tr className="bg-slate-50 dark:bg-slate-700/50 border-b text-slate-600 dark:text-slate-300 font-bold">
                <th className="p-4">#</th>
                <th className="p-4">نام آئٹم / پروڈکٹ</th>
                <th className="p-4">کیٹگری</th>
                <th className="p-4">موجودہ اسٹاک</th>
                <th className="p-4">قیمت فی عدد</th>
                <th className="p-4 text-center">کیفیت اسٹاک</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-slate-100 dark:divide-slate-700">
              {items.map((it, idx) => (
                <tr key={it.id} className="hover:bg-slate-50 dark:hover:bg-slate-700/50">
                  <td className="p-4 font-bold">{idx + 1}</td>
                  <td className="p-4 font-bold text-slate-800 dark:text-white">{it.item_name}</td>
                  <td className="p-4 text-slate-600 dark:text-slate-300">{it.category}</td>
                  <td className="p-4 font-sans font-bold text-lg">{it.stock_qty}</td>
                  <td className="p-4 font-sans font-bold text-emerald-600">Rs. {it.unit_price}</td>
                  <td className="p-4 text-center">
                    <span className={`px-3 py-1 rounded-full text-xs font-bold ${
                      it.status === 'In Stock' ? 'bg-emerald-100 text-emerald-700' : it.status === 'Low Stock' ? 'bg-amber-100 text-amber-700' : 'bg-rose-100 text-rose-700'
                    }`}>
                      {it.status === 'In Stock' ? 'دستیاب' : it.status === 'Low Stock' ? 'کم اسٹاک' : 'ختم'}
                    </span>
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
