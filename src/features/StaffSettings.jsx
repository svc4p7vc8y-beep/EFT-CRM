import { useState } from 'react';
import { FileLock2, HardHat, Plus, UserRound, UsersRound } from 'lucide-react';
import { Avatar, Field, PageHeading } from '../components/UI.jsx';
import { EMPLOYEE_AVATARS, STAFF_ROLES, employee } from '../app/model.js';
import './staff-settings.css';

function SettingsForm({ children, onSubmit, onClose }) {
  const [error, setError] = useState(''); const [busy,setBusy]=useState(false);
  return <form onSubmit={async (event) => { event.preventDefault(); setError(''); setBusy(true); try { await onSubmit(new FormData(event.currentTarget)); } catch (e) { setError(e.message); setBusy(false); } }}>
    <div className="form-content">{children}{error ? <p className="form-error" role="alert">{error}</p> : null}</div>
    <footer className="dialog-actions"><button className="button" type="button" onClick={onClose} disabled={busy}>Отмена</button><button className="button primary" type="submit" disabled={busy}>{busy?'Сохраняем…':'Сохранить'}</button></footer>
  </form>;
}

export function EmployeeForm({ person, onSubmit, onClose, serverMode=false }) {
  return <SettingsForm onClose={onClose} onSubmit={(data) => onSubmit({ name: data.get('name'), role: data.get('role'), department: data.get('department'), phone: data.get('phone'), email: data.get('email'), notes: data.get('notes'), avatar: data.get('avatar'), attendanceMode: data.get('attendanceMode'), active: data.has('active') })}>
    <div className="form-grid">
      <fieldset className="avatar-picker field-wide"><legend>Фотография сотрудника</legend><div>{EMPLOYEE_AVATARS.map((avatar, index) => <label key={avatar}><input type="radio" name="avatar" value={avatar} defaultChecked={(person?.avatar || EMPLOYEE_AVATARS[0]) === avatar} /><img src={avatar} alt={`Рисованный аватар ${index + 1}`} /></label>)}</div><small>15 вымышленных рисованных портретов: 12 мужских и 3 женских.</small></fieldset>
      <Field label="Имя / ФИО *" wide><input name="name" required maxLength={200} defaultValue={person?.name || ''} autoFocus /></Field>
      <Field label="Должность *"><select name="role" defaultValue={person?.role || 'Менеджер'}>{STAFF_ROLES.map((role) => <option key={role}>{role}</option>)}</select></Field>
      <Field label="Подразделение"><input name="department" maxLength={100} defaultValue={person?.department || ''} placeholder="Офис, цех, монтаж" /></Field>
      <Field label="Рабочий телефон"><input name="phone" type="tel" maxLength={60} defaultValue={person?.phone || ''} /></Field>
      <Field label="Рабочая почта"><input name="email" type="email" maxLength={200} defaultValue={person?.email || ''} /></Field>
      <Field label="Учёт в табеле"><select name="attendanceMode" defaultValue={person?.attendanceMode || 'hours'}><option value="hours">Считать рабочие часы</option><option value="days">Ставить «+» за рабочий день</option></select></Field>
      <Field label="Примечание" wide><textarea name="notes" maxLength={2000} rows={3} defaultValue={person?.notes || ''} placeholder="Специализация, зона ответственности" /></Field>
      <label className="check-row field-wide"><input type="checkbox" name="active" defaultChecked={person?.active !== false} />Активный сотрудник</label>
      <p className="form-hint field-wide">{serverMode?'Запись будет сохранена в закрытой базе CRM. Копии документов добавляются только через отдельный защищённый раздел.':'Ознакомительная версия хранит записи в этом браузере. Здесь не следует вводить паспортные данные и загружать документы.'}</p>
    </div>
  </SettingsForm>;
}

export function CrewForm({ state, crew, onSubmit, onClose }) {
  const [members, setMembers] = useState(crew?.memberIds || []);
  return <SettingsForm onClose={onClose} onSubmit={(data) => onSubmit({ name: data.get('name'), specialty: data.get('specialty'), phone: data.get('phone'), notes: data.get('notes'), leadId: data.get('leadId'), memberIds: data.getAll('memberIds'), active: data.has('active') })}>
    <div className="form-grid">
      <Field label="Название бригады *" wide><input name="name" required maxLength={200} defaultValue={crew?.name || ''} autoFocus placeholder="Например, Монтажная бригада №1" /></Field>
      <Field label="Специализация"><input name="specialty" maxLength={100} defaultValue={crew?.specialty || ''} placeholder="СИП-монтаж, электрика…" /></Field>
      <Field label="Рабочий телефон"><input name="phone" type="tel" maxLength={60} defaultValue={crew?.phone || ''} /></Field>
      <Field label="Бригадир" wide><select name="leadId" defaultValue={crew?.leadId || ''}><option value="">Не назначен</option>{state.employees.filter((person) => members.includes(person.id)).map((person) => <option key={person.id} value={person.id}>{person.name}</option>)}</select></Field>
      <fieldset className="field-wide member-fieldset"><legend>Состав бригады</legend><div className="member-grid">{state.employees.filter((person) => person.active || members.includes(person.id)).map((person) => <label key={person.id} className="check-row"><input type="checkbox" name="memberIds" value={person.id} checked={members.includes(person.id)} onChange={(event) => setMembers((current) => event.target.checked ? [...current, person.id] : current.filter((id) => id !== person.id))} />{person.name}<small>{person.role}{person.active ? '' : ' · неактивен'}</small></label>)}</div></fieldset>
      <Field label="Примечание" wide><textarea name="notes" maxLength={2000} rows={3} defaultValue={crew?.notes || ''} /></Field>
      <label className="check-row field-wide"><input type="checkbox" name="active" defaultChecked={crew?.active !== false} />Действующая бригада</label>
    </div>
  </SettingsForm>;
}

export function StaffSettings({ state, focus, search, onEmployee, onCrew, runtime={mode:'demo'} }) {
  const serverMode=runtime.mode==='server'; const canManageEmployees=!serverMode||runtime.capabilities?.includes('*')||runtime.capabilities?.includes('employees.manage'); const canManageCrews=!serverMode||runtime.capabilities?.includes('*')||runtime.capabilities?.includes('crews.manage');
  const [section, setSection] = useState(focus);
  const query = search.toLocaleLowerCase('ru-RU');
  const employees = state.employees.filter((person) => `${person.name} ${person.role} ${person.department}`.toLocaleLowerCase('ru-RU').includes(query));
  const crews = state.crews.filter((crew) => `${crew.name} ${crew.specialty} ${crew.memberIds.map((id) => employee(id, state)?.name).join(' ')}`.toLocaleLowerCase('ru-RU').includes(query));
  return <section className="page staff-settings"><PageHeading title={focus === 'crews' ? 'Бригады' : 'Настройки'} description="Сотрудники, должности и состав рабочих бригад" />
    <div className="settings-tabs"><button className={section === 'employees' ? 'active' : ''} onClick={() => setSection('employees')}><UserRound size={18} />Сотрудники <span>{state.employees.length}</span></button><button className={section === 'crews' ? 'active' : ''} onClick={() => setSection('crews')}><HardHat size={18} />Бригады <span>{state.crews.length}</span></button><button className={section === 'documents' ? 'active' : ''} onClick={() => setSection('documents')}><FileLock2 size={18} />Документы</button></div>
    {section === 'employees' ? <><div className="settings-section-head"><div><h2>Сотрудники</h2><p>Менеджеры, цех и другой персонал</p></div>{canManageEmployees?<button className="button primary" onClick={() => onEmployee()}><Plus size={18} />Добавить сотрудника</button>:null}</div><div className="table-wrap"><table><thead><tr><th>Сотрудник</th><th>Должность</th><th>Подразделение</th><th>Контакт</th><th>Статус</th><th>Действие</th></tr></thead><tbody>{employees.map((person) => <tr key={person.id}><td><span className="staff-person"><Avatar id={person.id} state={state} /><strong>{person.name}</strong></span></td><td>{person.role}</td><td>{person.department || '—'}</td><td>{person.phone || person.email || '—'}</td><td>{person.active ? 'Работает' : 'Неактивен'}</td><td>{canManageEmployees?<button className="text-link" onClick={() => onEmployee(person.id)}>Редактировать</button>:<span className="muted">Просмотр</span>}</td></tr>)}</tbody></table>{!employees.length ? <p className="settings-empty">Сотрудники не найдены.</p> : null}</div></> : null}
    {section === 'crews' ? <><div className="settings-section-head"><div><h2>Рабочие бригады</h2><p>Состав, специализация и ответственный</p></div>{canManageCrews?<button className="button primary" onClick={() => onCrew()}><Plus size={18} />Добавить бригаду</button>:null}</div><div className="crew-grid">{crews.map((crew) => <article className="crew-card" key={crew.id}><div className="crew-card-top"><span className="crew-icon"><UsersRound size={20} /></span><span>{crew.active ? 'Действует' : 'Неактивна'}</span></div><h3>{crew.name}</h3><p>{crew.specialty || 'Специализация не указана'}</p><dl><dt>Бригадир</dt><dd>{employee(crew.leadId, state)?.name || 'Не назначен'}</dd><dt>Состав</dt><dd>{crew.memberIds.length} чел.</dd></dl>{canManageCrews?<button className="text-link" onClick={() => onCrew(crew.id)}>Редактировать бригаду</button>:null}</article>)}{!crews.length ? <div className="settings-empty">Бригад пока нет. Добавьте первую бригаду и выберите сотрудников.</div> : null}</div></> : null}
    {section === 'documents' ? <div className="document-gate"><FileLock2 size={32} /><h2>Документы сотрудников</h2><p>Для копий документов нужен закрытый сервер, вход пользователей, отдельные права кадрового доступа и журнал просмотров. Публичная демонстрационная страница не принимает и не хранит сканы.</p><span>Раздел будет подключён вместе с серверной базой CRM.</span></div> : null}
  </section>;
}
