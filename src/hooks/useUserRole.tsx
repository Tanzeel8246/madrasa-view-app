import { useAuth } from "@/contexts/AuthContext";

export const useUserRole = () => {
  const { userRole } = useAuth();

  const hasPermission = (allowedRoles: string[]): boolean => {
    if (!userRole) return false;
    return allowedRoles.includes(userRole);
  };

  const isAdmin = (): boolean => {
    return userRole === "admin";
  };

  const isTeacher = (): boolean => {
    return userRole === "teacher";
  };

  const isManager = (): boolean => {
    return userRole === "manager";
  };

  const isParent = (): boolean => {
    return userRole === "parent";
  };

  const canAddStudents = (): boolean => {
    return hasPermission(["admin", "teacher", "manager"]);
  };

  const canAddTeachers = (): boolean => {
    return hasPermission(["admin", "manager"]);
  };

  const canAddLearningReports = (): boolean => {
    return hasPermission(["admin", "teacher"]);
  };

  const canManageFinances = (): boolean => {
    return hasPermission(["admin", "manager"]);
  };

  const canPayFees = (): boolean => {
    return hasPermission(["admin", "manager", "parent"]);
  };

  const canEditStudents = (): boolean => {
    return hasPermission(["admin", "teacher", "manager"]);
  };

  const canDeleteData = (): boolean => {
    return isAdmin();
  };

  return {
    userRole,
    hasPermission,
    isAdmin,
    isTeacher,
    isManager,
    isParent,
    canAddStudents,
    canAddTeachers,
    canAddLearningReports,
    canManageFinances,
    canPayFees,
    canEditStudents,
    canDeleteData,
  };
};
