import { useEffect, useState } from 'react';
import { ArrowRight, Eye, EyeOff, LockKeyhole, ShieldCheck } from 'lucide-react';
import { App } from './App.jsx';
import { crmApi, employeeToApi, serverMode } from './api.js';
import { RELEASE } from './operations.js';
import './auth.css';

function LoginScreen({ onLogin, initialError = '' }) {
  const [username, setUsername] = useState('');
  const [password, setPassword] = useState('');
  const [showPassword, setShowPassword] = useState(false);
  const [error, setError] = useState(initialError);
  const [busy, setBusy] = useState(false);

  async function submit(event) {
    event.preventDefault();
    setBusy(true);
    setError('');
    try {
      await onLogin(username.trim(), password);
    } catch (nextError) {
      setError(nextError.message);
      setBusy(false);
    }
  }

  return <main className="auth-page">
    <section className="auth-intro">
      <div className="auth-brand"><img src="./eft-logo.jpg" alt="ЭФТ" /><span>Управление строительством</span></div>
      <div className="auth-intro-copy"><span className="auth-kicker">Единое рабочее пространство</span><h1>От первого обращения<br />до сдачи дома</h1><p>Клиенты, производство, закупки, сотрудники, бригады и строительство в одной системе.</p></div>
      <div className="auth-protection"><ShieldCheck size={22} /><span><strong>Закрытая система ЭФТ</strong>Доступ выдаётся администратором</span></div>
    </section>
    <section className="auth-panel">
      <form className="login-card" onSubmit={submit}>
        <span className="login-icon"><LockKeyhole size={24} /></span>
        <p className="login-eyebrow">CRM ЭФТ · версия {RELEASE}</p>
        <h2>Вход в систему</h2>
        <p className="login-caption">Введите логин и пароль рабочего аккаунта.</p>
        <label><span>Логин</span><input name="username" autoComplete="username" autoFocus value={username} onChange={(event) => setUsername(event.target.value)} required /></label>
        <label><span>Пароль</span><span className="password-field"><input name="password" type={showPassword ? 'text' : 'password'} autoComplete="current-password" value={password} onChange={(event) => setPassword(event.target.value)} required /><button type="button" aria-label={showPassword ? 'Скрыть пароль' : 'Показать пароль'} onClick={() => setShowPassword((value) => !value)}>{showPassword ? <EyeOff size={18} /> : <Eye size={18} />}</button></span></label>
        {error ? <p className="login-error" role="alert">{error}</p> : null}
        <button className="login-submit" disabled={busy}>{busy ? 'Входим…' : <>Войти <ArrowRight size={18} /></>}</button>
        <small>Соединение защищено HTTPS. Не передавайте свой пароль другим сотрудникам.</small>
      </form>
    </section>
  </main>;
}

function LoadingScreen() {
  return <main className="auth-loading"><img src="./eft-logo.jpg" alt="ЭФТ" /><span className="auth-spinner" /><p>Подключаем рабочее пространство…</p></main>;
}

export function AuthRoot() {
  const [auth, setAuth] = useState(() => serverMode ? { status: 'loading' } : { status: 'demo' });

  useEffect(() => {
    if (!serverMode) return undefined;
    const controller = new AbortController();
    crmApi.session(controller.signal)
      .then(async (session) => {
        if (!session.authenticated) return setAuth({ status: 'guest' });
        const workspace = await crmApi.bootstrap(controller.signal);
        setAuth({ status: 'ready', session, workspace });
      })
      .catch((error) => {
        if (error.name !== 'AbortError') setAuth({ status: 'guest', error: error.message });
      });
    return () => controller.abort();
  }, []);

  if (auth.status === 'demo') return <App runtime={{ mode: 'demo' }} />;
  if (auth.status === 'loading') return <LoadingScreen />;
  if (auth.status === 'guest') return <LoginScreen initialError={auth.error} onLogin={async (username, password) => {
    const session = await crmApi.login(username, password);
    const workspace = await crmApi.bootstrap();
    setAuth({ status: 'ready', session: { ...session, authenticated: true }, workspace });
  }} />;

  const updateWorkspace = (patch) => setAuth((current) => current.status === 'ready' ? { ...current, workspace: { ...current.workspace, ...patch } } : current);
  const replaceEmployee = (employee) => setAuth((current) => {
    if (current.status !== 'ready') return current;
    const employees = [...(current.workspace.employees || [])]; const index = employees.findIndex((item) => item.id === employee.id);
    if (index >= 0) employees[index] = employee; else employees.push(employee);
    return { ...current, workspace: { ...current.workspace, employees } };
  });
  const replaceCrew = (crew) => setAuth((current) => {
    if (current.status !== 'ready') return current;
    const crews = [...(current.workspace.crews || [])]; const index = crews.findIndex((item) => item.id === crew.id);
    if (index >= 0) crews[index] = crew; else crews.push(crew);
    return { ...current, workspace: { ...current.workspace, crews } };
  });
  const refreshWorkspace = async () => {
    const workspace = await crmApi.bootstrap();
    setAuth((current) => current.status === 'ready' ? { ...current, session: { ...current.session, financeUnlocked: workspace.financeUnlocked }, workspace } : current);
    return workspace;
  };
  const replaceUser = (user) => setAuth((current) => {
    if (current.status !== 'ready') return current;
    const users = [...(current.workspace.users || [])]; const index = users.findIndex((item) => item.id === user.id);
    if (index >= 0) users[index] = user; else users.push(user);
    return { ...current, workspace: { ...current.workspace, users } };
  });

  return <App runtime={{ mode: 'server', user: auth.session.user, capabilities: auth.session.capabilities, financeUnlocked: Boolean(auth.workspace.financeUnlocked), serverData: auth.workspace,
    saveWorkspace: async (workspace, baseRevision, initialize = false) => {
      const result = await crmApi.saveWorkspace(workspace, baseRevision, initialize);
      updateWorkspace(result);
      return initialize ? refreshWorkspace() : result;
    },
    uploadConstructionPhoto: async (stageId, file) => { await crmApi.uploadConstructionPhoto(stageId, file); return refreshWorkspace(); },
    saveInventory: async (inventory, baseRevision) => {
      const result = await crmApi.saveInventory(inventory, baseRevision);
      updateWorkspace(result);
      return result;
    },
    refreshWorkspace,
    saveEmployee: async (employee) => {
      const result = await crmApi.saveEmployee(employeeToApi(employee));
      let saved = result.employee;
      replaceEmployee(saved);
      if (employee.photoFile) {
        const photo = await crmApi.uploadEmployeePhoto(saved.id, employee.photoFile);
        saved = photo.employee;
        replaceEmployee(saved);
      }
      return saved;
    },
    saveCrew: async (crew) => { const result = await crmApi.saveCrew(crew); replaceCrew(result.crew); return result.crew; },
    saveUser: async (user) => { const result = await crmApi.saveUser(user); replaceUser(result.user); return result.user; },
    unlockFinance: async (pin) => { await crmApi.unlockFinance(pin); await refreshWorkspace(); },
    lockFinance: async () => { await crmApi.lockFinance(); await refreshWorkspace(); },
    updateFinancePin: async (currentPin, newPin) => { await crmApi.updateFinancePin(currentPin, newPin); await refreshWorkspace(); },
    loadAttendance: async (month) => { const result = await crmApi.attendance(month); updateWorkspace({ attendance: result.attendance, attendanceMonth: month }); return result.attendance; },
    saveAttendance: async (entry) => { const result = await crmApi.saveAttendance(entry); const attendance = [...(auth.workspace.attendance || [])]; const index = attendance.findIndex((item) => item.employeeId === result.attendance.employeeId && item.date === result.attendance.date); if (index >= 0) attendance[index] = result.attendance; else attendance.push(result.attendance); updateWorkspace({ attendance }); return result.attendance; },
    saveRates: async (rows) => { for (const row of rows) { const employee = auth.workspace.employees.find((item) => item.id === row.id); if (employee) { const result = await crmApi.saveEmployee(employeeToApi({ ...employee, ...row })); replaceEmployee(result.employee); } } },
    onLogout: async () => {
    try { await crmApi.logout(); } finally { crmApi.clearSession(); setAuth({ status: 'guest' }); }
  } }} />;
}
