const API_URL = String(import.meta.env.VITE_CRM_API_URL || '').trim();

export const serverMode = API_URL !== '';

const presetAvatar = (value = '') => /^employee-(0[1-9]|1[0-5])\.jpg$/.test(value);
const uploadedAvatar = (value = '') => /^e-[a-f0-9]{24}\.(jpg|png|webp)$/.test(value);
const avatarKey = (value = '') => presetAvatar(value) || uploadedAvatar(value) ? value : 'employee-01.jpg';
const avatarUrl = (key) => presetAvatar(key) ? `./avatars/${key}` : `/uploads/employees/${encodeURIComponent(key)}`;
export function employeeFromApi(employee) {
  const name = String(employee?.name || '');
  const key = avatarKey(employee?.avatarKey);
  return { ...employee, avatarKey: key, initials: name.split(/\s+/).filter(Boolean).slice(0, 2).map((part) => part[0]).join('').toUpperCase(), avatar: avatarUrl(key), payRate: Number(employee?.payRate || 0), advanceAmount: Number(employee?.advanceAmount || 0) };
}
export function employeeToApi(employee) {
  const { photoFile: _photoFile, ...fields } = employee;
  return { ...fields, avatarKey: avatarKey(employee?.avatarKey || String(employee?.avatar || '').split('/').pop()) };
}

export class ApiError extends Error {
  constructor(message, { code = 'request_failed', status = 0 } = {}) {
    super(message);
    this.name = 'ApiError';
    this.code = code;
    this.status = status;
  }
}

let csrfToken = '';

function actionUrl(action, query = {}) {
  const separator = API_URL.includes('?') ? '&' : '?';
  const parameters = new URLSearchParams({ action, ...Object.fromEntries(Object.entries(query).filter(([, value]) => value !== '' && value !== undefined)) });
  return `${API_URL}${separator}${parameters}`;
}

async function request(action, { method = 'GET', body, signal, query } = {}) {
  const formData = body instanceof FormData;
  let response;
  try {
    response = await fetch(actionUrl(action, query), {
      method,
      credentials: 'same-origin',
      headers: {
        Accept: 'application/json',
        ...(body === undefined || formData ? {} : { 'Content-Type': 'application/json' }),
        ...(csrfToken ? { 'X-CSRF-Token': csrfToken } : {}),
      },
      body: body === undefined ? undefined : formData ? body : JSON.stringify(body),
      signal,
    });
  } catch (error) {
    if (error?.name === 'AbortError') throw error;
    throw new ApiError('Не удалось связаться с сервером CRM. Проверьте соединение и повторите попытку.');
  }

  let payload = null;
  try {
    payload = await response.json();
  } catch {
    throw new ApiError('Сервер CRM вернул некорректный ответ.', { status: response.status });
  }

  if (!response.ok || payload?.ok === false) {
    throw new ApiError(payload?.message || 'Не удалось выполнить запрос.', {
      code: payload?.code,
      status: response.status,
    });
  }
  if (payload?.csrf) csrfToken = payload.csrf;
  return payload;
}

export const crmApi = {
  session: (signal) => request('session', { signal }),
  login: (username, password) => request('login', { method: 'POST', body: { username, password } }),
  logout: () => request('logout', { method: 'POST', body: {} }),
  bootstrap: (signal) => request('bootstrap', { signal }),
  saveEmployee: (employee) => request('employees.save', { method: employee.id ? 'PUT' : 'POST', body: employee }),
  uploadEmployeePhoto: (employeeId, file) => {
    const body = new FormData();
    body.append('employeeId', employeeId);
    body.append('photo', file);
    return request('employees.photo', { method: 'POST', body });
  },
  saveCrew: (crew) => request('crews.save', { method: crew.id ? 'PUT' : 'POST', body: crew }),
  attendance: (month, employeeId = '', signal) => request('attendance.list', { signal, query: { month, employeeId } }),
  saveAttendance: (entry) => request('attendance.save', { method: entry.id ? 'PUT' : 'POST', body: entry }),
  payroll: (month, signal) => request('payroll', { signal, query: { month } }),
  clearSession: () => { csrfToken = ''; },
};
