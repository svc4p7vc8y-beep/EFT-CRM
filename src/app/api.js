const API_URL = String(import.meta.env.VITE_CRM_API_URL || '').trim();

export const serverMode = API_URL !== '';

export class ApiError extends Error {
  constructor(message, { code = 'request_failed', status = 0 } = {}) {
    super(message);
    this.name = 'ApiError';
    this.code = code;
    this.status = status;
  }
}

let csrfToken = '';

function actionUrl(action) {
  const separator = API_URL.includes('?') ? '&' : '?';
  return `${API_URL}${separator}action=${encodeURIComponent(action)}`;
}

async function request(action, { method = 'GET', body, signal } = {}) {
  let response;
  try {
    response = await fetch(actionUrl(action), {
      method,
      credentials: 'same-origin',
      headers: {
        Accept: 'application/json',
        ...(body === undefined ? {} : { 'Content-Type': 'application/json' }),
        ...(csrfToken ? { 'X-CSRF-Token': csrfToken } : {}),
      },
      body: body === undefined ? undefined : JSON.stringify(body),
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
  clearSession: () => { csrfToken = ''; },
};
