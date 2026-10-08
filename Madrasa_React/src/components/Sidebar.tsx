'use client';
import Link from 'next/link';
import { usePathname } from 'next/navigation';
import { useState, useEffect } from 'react';

interface SubItem {
  name: string;
  href: string;
}

interface NavItem {
  name: string;
  icon: string;
  href: string;
  subId?: string;
  subItems?: SubItem[];
}

export default function Sidebar() {
  const pathname = usePathname();
  const [userRole, setUserRole] = useState<string>('admin');
  const [systemType, setSystemType] = useState<'Madrasa' | 'Masjid'>('Madrasa');
  const [openSubmenu, setOpenSubmenu] = useState<string | null>(null);

  useEffect(() => {
    const userStr = localStorage.getItem('user');
    if (userStr) {
      const user = JSON.parse(userStr);
      setUserRole(user.role || 'admin');
    }
    const savedSys = localStorage.getItem('system_type') as 'Madrasa' | 'Masjid';
    if (savedSys) {
      setSystemType(savedSys);
    }
  }, []);

  const toggleSystemType = (sys: 'Madrasa' | 'Masjid') => {
    setSystemType(sys);
    localStorage.setItem('system_type', sys);
  };

  const adminNavItems: NavItem[] = [
    { name: 'ڈیش بورڈ', icon: '⏱️', href: '/' },
    { name: 'اسمارٹ الرٹس و نوٹیفیکیشنز', icon: '🔔', href: '/smart_notifications' },
    { name: 'درخواستیں وشکایات', icon: '📝', href: '/complaints' },
    { name: 'نوٹس بورڈ (اعلانات)', icon: '📢', href: '/notices' },
    { name: 'کلاسز اور نصاب', icon: '📚', href: '/classes' },
    { name: 'داخلہ پائپ لائن و درخواستیں', icon: '🎓', href: '/admissions' },
    { name: 'تعلیمی کارکردگی (اندراج)', icon: '📖', href: '/academics/entry' },
    { name: 'تعلیمی کارکردگی (دیکھیں)', icon: '📈', href: '/academics/view' },
    { name: 'امتحانات و کشف الدرجات', icon: '📑', href: '/exams' },
    { name: 'درس نظامی و پروموشن', icon: '📜', href: '/darse_nizami' },
    { name: 'طلباء کا ریکارڈ', icon: '👨‍🎓', href: '/students' },
    { name: 'فارغ التحصیل طلباء (Alumni)', icon: '🏅', href: '/alumni' },
    { name: 'اساتذہ کا ریکارڈ', icon: '👨‍🏫', href: '/teachers' },
    { name: 'اساتذہ کی یومیہ حاضری', icon: '📝', href: '/teachers_attendance' },
    { name: 'اساتذہ حاضری رپورٹ', icon: '📊', href: '/teachers_attendance_report' },
    { name: 'پیریڈ وائز حاضری رجسٹر', icon: '📅', href: '/attendance' },
    { name: 'حاضری کی تفصیلی رپورٹ', icon: '📋', href: '/attendance_report' },
    
    // مالیات سب مینیو
    { 
      name: 'مالیات و کیش بک', 
      icon: '💰', 
      href: '/finance',
      subId: 'finance',
      subItems: [
        { name: 'روزنامچہ کیش بک', href: '/finance' },
        { name: 'فیس وصولی و بقایاجات', href: '/finance/fees' },
        { name: 'عملہ وظائف و پے رول', href: '/finance/payroll' },
        { name: 'ایڈوانس و قرض مینیجمنٹ', href: '/finance/loans' },
        { name: 'اثاثہ جات و راشن عطیات', href: '/finance/assets' },
        { name: 'مالیاتی رپورٹس و لیجر', href: '/finance/reports' },
        { name: 'چارٹ آف اکاؤنٹس', href: '/finance/erp/chart' },
        { name: 'ڈبل انٹری واؤچرز', href: '/finance/erp/vouchers' },
      ]
    },

    // انتظامات سب مینیو
    {
      name: 'انتظامات',
      icon: '💼',
      href: '/management/library',
      subId: 'management',
      subItems: [
        { name: 'کتب خانہ و لائبریری', href: '/management/library' },
        { name: 'شاپ و انوینٹری اسٹور', href: '/management/inventory' },
        { name: 'دارالاقامہ و ہاسٹل الاٹمنٹ', href: '/management/hostel' },
        { name: 'بھرتی و تقرری (ملازمت)', href: '/management/recruitment' },
      ]
    },

    { name: 'مسجد و مکتب ڈیش بورڈ', icon: '🕌', href: '/masjid_dashboard' },
    { name: 'یوزرز کا انتظام', icon: '👥', href: '/settings/users' },
    { name: 'سافٹ ویئر سیٹنگز', icon: '⚙️', href: '/settings' },
    { name: 'سبسکرپشن کیفیت', icon: '💎', href: '/settings/subscription' },
    { name: 'پاسورڈ و پروفائل', icon: '🔑', href: '/profile' },
    { name: 'لاگ آؤٹ (باہر نکلیں)', icon: '🚪', href: '#' },
  ];

  const parentNavItems: NavItem[] = [
    { name: 'ڈیش بورڈ (ہوم)', icon: '🏠', href: '/parent_dashboard' },
    { name: 'تعلیمی و حاضری رپورٹ', icon: '📊', href: '/parent_dashboard/report' },
    { name: 'فیس / آن لائن ادائیگی', icon: '💸', href: '/parent_dashboard/fee' },
    { name: 'چھٹی کی درخواست', icon: '✉️', href: '/parent_dashboard/leave' },
    { name: 'شکایت یا تجویز درج کریں', icon: '⚠️', href: '/parent_dashboard/complaint' },
    { name: 'لاگ آؤٹ (باہر نکلیں)', icon: '🚪', href: '#' },
  ];

  const navItems = userRole === 'parent' ? parentNavItems : adminNavItems;

  const handleLogout = (e: React.MouseEvent, isLogoutItem: boolean) => {
    if (isLogoutItem) {
      e.preventDefault();
      localStorage.removeItem('user');
      localStorage.removeItem('token');
      window.location.href = '/login';
    }
  };

  return (
    <aside className="w-72 bg-white dark:bg-slate-900 text-slate-700 dark:text-slate-300 flex flex-col shadow-2xl h-screen overflow-y-auto print:hidden font-urdu custom-scrollbar border-l border-slate-200 dark:border-slate-800 transition-colors" dir="rtl">
      
      {/* Header Profile Area */}
      <div className="p-4 border-b border-slate-200 dark:border-slate-800 bg-slate-50 dark:bg-slate-800/50 sticky top-0 z-10 text-center transition-colors">
        <h2 className="text-xl font-extrabold text-theme-primary">مدرسہ اشرف المدارس</h2>
        <p className="text-xs text-slate-500 dark:text-slate-400 mt-0.5">{userRole === 'parent' ? 'پیرنٹس پورٹل' : 'کلاؤڈ ایڈمن پورٹل'}</p>

        {/* System Switcher */}
        {userRole !== 'parent' && (
          <div className="flex bg-slate-200 dark:bg-slate-700 p-1 rounded-xl mt-3 text-xs font-bold">
            <button 
              onClick={() => toggleSystemType('Madrasa')}
              className={`flex-1 py-1.5 rounded-lg transition-all ${systemType === 'Madrasa' ? 'bg-white dark:bg-slate-800 text-theme-primary shadow-sm' : 'text-slate-500'}`}
            >
              🏛️ مدرسہ موڈ
            </button>
            <button 
              onClick={() => toggleSystemType('Masjid')}
              className={`flex-1 py-1.5 rounded-lg transition-all ${systemType === 'Masjid' ? 'bg-emerald-700 text-white shadow-sm' : 'text-slate-500'}`}
            >
              🕌 مسجد موڈ
            </button>
          </div>
        )}
      </div>

      <nav className="flex-1 py-4">
        {navItems.map((item, index) => {
          const isActive = pathname === item.href || (item.subItems && item.subItems.some((s: any) => pathname === s.href));
          const isLogout = item.name.includes('لاگ آؤٹ');
          const hasSub = !!item.subItems;
          const isSubOpen = openSubmenu === item.subId || isActive;

          if (hasSub) {
            return (
              <div key={index} className="mb-1">
                <button
                  onClick={() => setOpenSubmenu(openSubmenu === item.subId ? null : (item.subId || null))}
                  className={`w-full flex items-center justify-between px-6 py-2.5 transition-all text-base border-r-4 ${
                    isActive 
                      ? 'bg-theme-primary/10 border-theme-primary text-theme-primary font-bold' 
                      : 'border-transparent hover:bg-slate-100 dark:hover:bg-slate-800 text-slate-600 dark:text-slate-400'
                  }`}
                >
                  <div className="flex items-center gap-3">
                    <span className="text-xl w-6 text-center">{item.icon}</span>
                    <span>{item.name}</span>
                  </div>
                  <span className={`text-xs transition-transform ${isSubOpen ? 'rotate-180' : ''}`}>▼</span>
                </button>

                {isSubOpen && (
                  <div className="bg-slate-50 dark:bg-slate-800/40 py-1 pr-12 pl-4 space-y-1">
                    {item.subItems?.map((sub: any, sIdx: number) => {
                      const isSubActive = pathname === sub.href;
                      return (
                        <Link
                          key={sIdx}
                          href={sub.href}
                          className={`block py-1.5 px-3 rounded-lg text-sm transition-all ${
                            isSubActive 
                              ? 'bg-theme-primary text-white font-bold' 
                              : 'text-slate-600 dark:text-slate-400 hover:text-theme-primary hover:bg-slate-100 dark:hover:bg-slate-700/50'
                          }`}
                        >
                          • {sub.name}
                        </Link>
                      );
                    })}
                  </div>
                )}
              </div>
            );
          }

          return (
            <Link 
              key={index} 
              href={item.href}
              onClick={(e) => handleLogout(e, isLogout)}
              className={`flex items-center gap-3 px-6 py-2 transition-all text-base border-r-4 ${
                isActive 
                  ? 'bg-theme-primary/10 border-theme-primary text-theme-primary font-bold' 
                  : 'border-transparent hover:bg-slate-100 dark:hover:bg-slate-800 text-slate-600 dark:text-slate-400 hover:text-theme-primary dark:hover:text-theme-light'
              } ${isLogout ? 'text-red-500 hover:text-red-600 dark:text-red-400 dark:hover:text-red-300 mt-4 border-t border-slate-200 dark:border-slate-800 pt-4' : ''}`}
            >
              <span className="text-xl w-6 text-center">{item.icon}</span>
              <span className="flex-1">{item.name}</span>
            </Link>
          );
        })}
      </nav>
      
      {/* Scrollbar CSS */}
      <style dangerouslySetInnerHTML={{__html: `
        .custom-scrollbar::-webkit-scrollbar { width: 6px; }
        .custom-scrollbar::-webkit-scrollbar-track { background: transparent; }
        .custom-scrollbar::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 4px; }
        .custom-scrollbar::-webkit-scrollbar-thumb:hover { background: #94a3b8; }
        .dark .custom-scrollbar::-webkit-scrollbar-thumb { background: #334155; }
        .dark .custom-scrollbar::-webkit-scrollbar-thumb:hover { background: #475569; }
      `}} />
    </aside>
  );
}
