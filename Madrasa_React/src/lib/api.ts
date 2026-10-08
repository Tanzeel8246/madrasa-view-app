/**
 * Pure PHP REST API Bridge for React Static Export
 * This utility routes all frontend data calls to the PHP API backend
 */

export const API_BASE_URL = 
  process.env.NEXT_PUBLIC_PHP_API_URL || 
  (typeof window !== 'undefined' && window.location.origin.includes('localhost')
    ? 'http://localhost/Madrasa%20web%20app/api'
    : '/api');

export async function apiRequest(endpoint: string, options: RequestInit = {}) {
  const url = `${API_BASE_URL}${endpoint.startsWith('/') ? endpoint : `/${endpoint}`}`;
  
  const token = typeof window !== 'undefined' ? localStorage.getItem('token') : null;
  const tenantId = typeof window !== 'undefined' ? localStorage.getItem('tenant_id') : null;

  const headers: Record<string, string> = {
    'Content-Type': 'application/json',
    ...(token ? { 'Authorization': `Bearer ${token}` } : {}),
    ...(tenantId ? { 'X-Tenant-ID': tenantId } : {}),
    ...((options.headers as Record<string, string>) || {}),
  };

  try {
    const res = await fetch(url, { ...options, headers });
    return await res.json();
  } catch (error) {
    console.error(`API Error on [${url}]:`, error);
    return { success: false, message: 'سرور سے رابطہ قائم نہیں ہو سکا۔' };
  }
}
