import { Toaster } from "@/components/ui/toaster";
import { Toaster as Sonner } from "@/components/ui/sonner";
import { TooltipProvider } from "@/components/ui/tooltip";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { BrowserRouter, Routes, Route } from "react-router-dom";
import { LanguageProvider } from "./contexts/LanguageContext";
import { AuthProvider } from "./contexts/AuthContext";
import { useAuth } from "./contexts/AuthContext";
import ProtectedRoute from "./components/ProtectedRoute";
import MadrasahSetupDialog from "./components/MadrasahSetupDialog";
import Layout from "./components/Layout";
import Dashboard from "./pages/Dashboard";
import Students from "./pages/Students";
import Teachers from "./pages/Teachers";
import Classes from "./pages/Classes";
import Attendance from "./pages/Attendance";
import Fees from "./pages/Fees";
import Income from "./pages/Income";
import Expense from "./pages/Expense";
import Salaries from "./pages/Salaries";
import Loans from "./pages/Loans";
import Reports from "./pages/Reports";
import Settings from "./pages/Settings";
import Auth from "./pages/Auth";
import NotFound from "./pages/NotFound";
import { useState, useEffect } from "react";

const queryClient = new QueryClient();

const AppContent = () => {
  const { user, madrasahId, loading } = useAuth();
  const [showSetup, setShowSetup] = useState(false);

  useEffect(() => {
    if (!loading && user && !madrasahId) {
      setShowSetup(true);
    }
  }, [user, madrasahId, loading]);

  return (
    <>
      <Routes>
        <Route path="/auth" element={<Auth />} />
        <Route path="/" element={<ProtectedRoute><Layout><Dashboard /></Layout></ProtectedRoute>} />
        <Route path="/students" element={<ProtectedRoute><Layout><Students /></Layout></ProtectedRoute>} />
        <Route path="/teachers" element={<ProtectedRoute><Layout><Teachers /></Layout></ProtectedRoute>} />
        <Route path="/classes" element={<ProtectedRoute><Layout><Classes /></Layout></ProtectedRoute>} />
        <Route path="/attendance" element={<ProtectedRoute><Layout><Attendance /></Layout></ProtectedRoute>} />
        <Route path="/fees" element={<ProtectedRoute><Layout><Fees /></Layout></ProtectedRoute>} />
        <Route path="/income" element={<ProtectedRoute><Layout><Income /></Layout></ProtectedRoute>} />
        <Route path="/expense" element={<ProtectedRoute><Layout><Expense /></Layout></ProtectedRoute>} />
        <Route path="/salaries" element={<ProtectedRoute><Layout><Salaries /></Layout></ProtectedRoute>} />
        <Route path="/loans" element={<ProtectedRoute><Layout><Loans /></Layout></ProtectedRoute>} />
        <Route path="/reports" element={<ProtectedRoute><Layout><Reports /></Layout></ProtectedRoute>} />
        <Route path="/settings" element={<ProtectedRoute><Layout><Settings /></Layout></ProtectedRoute>} />
        <Route path="*" element={<NotFound />} />
      </Routes>
      <MadrasahSetupDialog 
        open={showSetup} 
        onComplete={() => {
          setShowSetup(false);
          window.location.reload();
        }} 
      />
    </>
  );
};

const App = () => (
  <QueryClientProvider client={queryClient}>
    <BrowserRouter>
      <LanguageProvider>
        <AuthProvider>
          <TooltipProvider>
            <Toaster />
            <Sonner />
            <AppContent />
          </TooltipProvider>
        </AuthProvider>
      </LanguageProvider>
    </BrowserRouter>
  </QueryClientProvider>
);

export default App;
