'use client';

import { useState } from 'react';
import Link from 'next/link';

interface Candidate {
  id: number;
  name: string;
  father_name: string;
  phone: string;
  target_class: string;
  stage: 'موصول' | 'انٹرویو' | 'منظور شدہ' | 'داخلہ مکمل';
  date: string;
}

export default function AdmissionsPage() {
  const [candidates, setCandidates] = useState<Candidate[]>([
    { id: 1, name: 'عبداللہ بن سعود', father_name: 'سعود احمد', phone: '03001122334', target_class: 'حفظ القرآن', stage: 'موصول', date: '2026-10-05' },
    { id: 2, name: 'حمزہ طارق', father_name: 'محمد طارق', phone: '03015544332', target_class: 'ناظرہ', stage: 'انٹرویو', date: '2026-10-04' },
    { id: 3, name: 'عمار یاسر', father_name: 'یاسر محمود', phone: '03029988776', target_class: 'درجہ اولیٰ', stage: 'منظور شدہ', date: '2026-10-02' },
    { id: 4, name: 'سلمان خان', father_name: 'خان محمد', phone: '03034455667', target_class: 'حفظ القرآن', stage: 'داخلہ مکمل', date: '2026-10-01' },
  ]);

  const stages: Candidate['stage'][] = ['موصول', 'انٹرویو', 'منظور شدہ', 'داخلہ مکمل'];

  const moveStage = (id: number, nextStage: Candidate['stage']) => {
    setCandidates(candidates.map(c => c.id === id ? { ...c, stage: nextStage } : c));
  };

  return (
    <div className="flex flex-col bg-slate-50 dark:bg-slate-900 font-urdu min-h-screen transition-colors" dir="rtl">
      <header className="h-20 bg-white/80 dark:bg-slate-800/80 backdrop-blur-md border-b border-slate-200 dark:border-slate-700 flex items-center justify-between px-8 sticky top-0 z-10 transition-colors">
        <div className="flex items-center gap-4">
          <Link href="/" className="text-theme-primary dark:text-theme-light hover:text-theme-hover transition-colors bg-theme-primary/10 p-2 rounded-lg">
            <span className="text-xl">🏠</span>
          </Link>
          <h2 className="text-2xl font-bold text-slate-800 dark:text-slate-100 flex items-center gap-2">
            <span>🎓</span> داخلہ پائپ لائن و درخواستیں (Admissions Pipeline)
          </h2>
        </div>
      </header>

      <main className="flex-1 p-8">
        {/* Kanban Board for Admission Pipeline */}
        <div className="grid grid-cols-1 md:grid-cols-4 gap-6">
          {stages.map(stage => {
            const list = candidates.filter(c => c.stage === stage);
            return (
              <div key={stage} className="bg-slate-100 dark:bg-slate-800/60 p-4 rounded-2xl border border-slate-200 dark:border-slate-700 flex flex-col h-full">
                <div className="flex justify-between items-center mb-4">
                  <h3 className="font-bold text-lg text-slate-800 dark:text-white flex items-center gap-2">
                    <span className="w-3 h-3 rounded-full bg-theme-primary inline-block"></span>
                    {stage}
                  </h3>
                  <span className="px-2 py-0.5 bg-white dark:bg-slate-700 rounded-full font-sans font-bold text-xs">
                    {list.length}
                  </span>
                </div>

                <div className="space-y-3 flex-1 overflow-y-auto">
                  {list.map(c => (
                    <div key={c.id} className="bg-white dark:bg-slate-800 p-4 rounded-xl shadow-sm border border-slate-200 dark:border-slate-700">
                      <h4 className="font-bold text-slate-800 dark:text-white text-base">{c.name}</h4>
                      <p className="text-xs text-slate-500 mb-2">ولد {c.father_name} | رابطہ: <span className="font-sans" dir="ltr">{c.phone}</span></p>
                      <div className="flex justify-between items-center pt-2 border-t text-xs">
                        <span className="bg-indigo-50 dark:bg-indigo-900/30 text-indigo-700 dark:text-indigo-400 px-2 py-0.5 rounded font-bold">
                          {c.target_class}
                        </span>
                        
                        {stage !== 'داخلہ مکمل' && (
                          <button 
                            onClick={() => {
                              const currIdx = stages.indexOf(stage);
                              moveStage(c.id, stages[currIdx + 1]);
                            }}
                            className="text-emerald-600 hover:text-emerald-700 font-bold"
                          >
                            اگلے مرحلے پر منتقل کریں ➔
                          </button>
                        )}
                      </div>
                    </div>
                  ))}
                  {list.length === 0 && (
                    <p className="text-center py-8 text-slate-400 text-sm">اس مرحلے میں کوئی درخواست نہیں</p>
                  )}
                </div>
              </div>
            );
          })}
        </div>
      </main>
    </div>
  );
}
