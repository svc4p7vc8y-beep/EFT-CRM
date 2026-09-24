import { useEffect, useState } from 'react';
import { Eye, EyeOff, FileLock2, HardHat, ImagePlus, KeyRound, Plus, ShieldCheck, UserCog, UserRound, UsersRound } from 'lucide-react';
import { Avatar, Dialog, Field, PageHeading } from '../components/UI.jsx';
import { EMPLOYEE_AVATARS, STAFF_ROLES, employee } from '../app/model.js';
import { CrewPlanning } from './CrewPlanning.jsx';
import './staff-settings.css';

function SettingsForm({ children, onSubmit, onClose }) {
  const [error, setError] = useState(''); const [busy,setBusy]=useState(false);
  return <form onSubmit={async (event) => { event.preventDefault(); setError(''); setBusy(true); try { await onSubmit(new FormData(event.currentTarget)); } catch (e) { setError(e.message); setBusy(false); } }}>
    <div className="form-content">{children}{error ? <p className="form-error" role="alert">{error}</p> : null}</div>
    <footer className="dialog-actions"><button className="button" type="button" onClick={onClose} disabled={busy}>Отмена</button><button className="button primary" type="submit" disabled={busy}>{busy?'Сохраняем…':'Сохранить'}</button></footer>
  </form>;
}

export function EmployeeForm({ person, onSubmit, onClose, serverMode=false }) {
  const [photoFile, setPhotoFile] = useState(null);
  const [photoError, setPhotoError] = useState('');
  const [preview, setPreview] = useState(person?.avatar || EMPLOYEE_AVATARS[0]);
  const [avatar, setAvatar] = useState(EMPLOYEE_AVATARS.includes(person?.avatar) ? person.avatar : '');
  useEffect(() => {
    if (!photoFile) return undefined;
    const url = URL.createObjectURL(photoFile);
    setPreview(url);
    return () => URL.revokeObjectURL(url);
  }, [photoFile]);
  function chooseFile(event) {
    const file = event.target.files?.[0] || null;
    setPhotoError('');
    if (file && !['image/jpeg', 'image/png', 'image/webp'].includes(file.type)) { event.target.value = ''; setPhotoError('Выберите изображение JPG, PNG или WebP.'); return; }
    if (file && file.size > 5 * 1024 * 1024) { event.target.value = ''; setPhotoError('Фотография должна быть не больше 5 МБ.'); return; }
    setPhotoFile(file);
  }
  return <SettingsForm onClose={onClose} onSubmit={(data) => onSubmit({ name: data.get('name'), role: data.get('role'), department: data.get('department'), phone: data.get('phone'), email: data.get('email'), notes: data.get('notes'), avatar: avatar || person?.avatar || EMPLOYEE_AVATARS[0], avatarKey: avatar ? avatar.split('/').pop() : person?.avatarKey, photoFile, attendanceMode: data.get('attendanceMode'), active: data.has('active') })}>
    <div className="form-grid">
      <fieldset className="avatar-picker field-wide"><legend>Фотография сотрудника</legend>
        {serverMode ? <><div className="photo-upload"><img src={preview} alt="Предпросмотр фотографии сотрудника" /><span><strong>{photoFile ? photoFile.name : person?.avatarKey?.startsWith('e-') ? 'Загруженная фотография' : 'Загрузить свою фотографию'}</strong><small>JPG, PNG или WebP, до 5 МБ</small><label className="button"><ImagePlus size={17} />Выбрать файл<input type="file" accept="image/jpeg,image/png,image/webp" onChange={chooseFile} /></label></span></div>{photoError ? <p className="photo-error" role="alert">{photoError}</p> : null}</> : null}
        <p className="avatar-picker-label">Или выберите готовый аватар</p><div className="avatar-options">{EMPLOYEE_AVATARS.map((option, index) => <label key={option}><input type="radio" name="avatar" value={option} checked={avatar === option} onChange={() => { setAvatar(option); setPhotoFile(null); setPhotoError(''); setPreview(option); }} /><img src={option} alt={`Рисованный аватар ${index + 1}`} /></label>)}</div><small>15 вымышленных рисованных портретов: 12 мужских и 3 женских.</small></fieldset>
      <Field label="Имя / ФИО *" wide><input name="name" required maxLength={200} defaultValue={person?.name || ''} autoFocus /></Field>
      <Field label="Должность *"><input name="role" list="staff-role-options" required maxLength={120} defaultValue={person?.role || 'Менеджер'} placeholder="Выберите или введите новую"/><datalist id="staff-role-options">{STAFF_ROLES.map((role) => <option key={role} value={role}/>)}</datalist></Field>
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

const ACCESS_ROLES = [
  ['owner','Владелец','Полный доступ ко всем разделам и настройкам'],
  ['admin','Администратор','Сотрудники, бригады, клиенты, задачи, табель и интеграции'],
  ['finance','Финансы','Табель и финансовая часть после ввода отдельного PIN'],
  ['manager','Менеджер','Клиенты, заявки, задачи и собственный табель'],
  ['production','Производство','Задачи производства, сотрудники, бригады и общий табель'],
  ['procurement','Закупки','Закупки, просмотр клиентов и задач'],
  ['foreman','Бригадир','Своя бригада, её задачи и собственный табель'],
  ['employee','Сотрудник','Собственные задачи и собственный табель'],
  ['viewer','Просмотр','Просмотр клиентов и задач без редактирования'],
];

function PasswordInput({name,value,onChange,placeholder,required=false,minLength,pattern,autoComplete='new-password'}) {
  const [visible,setVisible]=useState(false);
  return <span className="password-field"><input name={name} type={visible?'text':'password'} value={value} onChange={onChange} placeholder={placeholder} required={required} minLength={minLength} pattern={pattern} autoComplete={autoComplete}/><button type="button" aria-label={visible?'Скрыть пароль':'Показать пароль'} onClick={()=>setVisible((current)=>!current)}>{visible?<EyeOff size={18}/>:<Eye size={18}/>}</button></span>;
}

function AccountForm({account,employees,onClose,onSubmit}) {
  const [password,setPassword]=useState('');
  return <SettingsForm onClose={onClose} onSubmit={(data)=>onSubmit({id:account?.id,username:data.get('username'),displayName:data.get('displayName'),role:data.get('role'),employeeId:data.get('employeeId'),active:data.has('active'),password})}><div className="form-grid">
    <Field label="Имя пользователя *" wide><input name="displayName" required maxLength={180} defaultValue={account?.displayName||''} autoFocus/></Field>
    <Field label="Логин *"><input name="username" required minLength={3} maxLength={96} pattern="[a-z0-9._-]+" defaultValue={account?.username||''} placeholder="ivan.petrov"/></Field>
    <Field label="Роль доступа"><select name="role" defaultValue={account?.role||'employee'}>{ACCESS_ROLES.map(([id,label])=><option key={id} value={id}>{label}</option>)}</select></Field>
    <Field label="Связанный сотрудник" wide><select name="employeeId" defaultValue={account?.employeeId||''}><option value="">Не связан</option>{employees.map((person)=><option key={person.id} value={person.id}>{person.name} · {person.role}</option>)}</select></Field>
    <Field label={account?'Новый пароль':'Пароль *'} wide><PasswordInput name="password" value={password} onChange={(event)=>setPassword(event.target.value)} required={!account} minLength={password||!account?8:undefined} placeholder={account?'Оставьте пустым, чтобы не менять':'Не меньше 8 символов'}/></Field>
    <label className="check-row field-wide"><input type="checkbox" name="active" defaultChecked={account?.active!==false}/>Доступ разрешён</label>
  </div></SettingsForm>;
}

function AccessSettings({state,runtime}) {
  const canUsers=runtime.capabilities?.includes('*')||runtime.capabilities?.includes('users.manage');
  const canFinance=runtime.capabilities?.includes('*')||runtime.capabilities?.includes('finance.manage');
  const [editing,setEditing]=useState(null); const [pin,setPin]=useState({current:'',next:'',confirm:''}); const [message,setMessage]=useState(''); const [error,setError]=useState(''); const [busy,setBusy]=useState(false);
  async function changePin(event){event.preventDefault();setError('');setMessage('');if(pin.next!==pin.confirm){setError('Новые пароли не совпадают.');return;}setBusy(true);try{await runtime.updateFinancePin(pin.current,pin.next);setPin({current:'',next:'',confirm:''});setMessage('Пароль финансовой части изменён.');}catch(nextError){setError(nextError.message);}finally{setBusy(false);}}
  return <div className="access-settings">
    {canUsers?<><div className="settings-section-head"><div><h2>Учётные записи сотрудников</h2><p>Логины, пароли и права входа в CRM</p></div><button className="button primary" onClick={()=>setEditing({})}><Plus size={18}/>Добавить учётную запись</button></div><div className="table-wrap"><table><thead><tr><th>Пользователь</th><th>Логин</th><th>Роль</th><th>Сотрудник</th><th>Статус</th><th></th></tr></thead><tbody>{(runtime.serverData?.users||[]).map((account)=>{const role=ACCESS_ROLES.find(([id])=>id===account.role);return <tr key={account.id}><td><strong>{account.displayName}</strong></td><td>{account.username}</td><td>{role?.[1]||account.role}</td><td>{state.employees.find((person)=>person.id===account.employeeId)?.name||'—'}</td><td>{account.active?'Разрешён':'Отключён'}</td><td><button className="text-link" onClick={()=>setEditing(account)}>Редактировать</button></td></tr>;})}</tbody></table></div></>:null}
    {canFinance?<form className="finance-pin-card" onSubmit={changePin}><div><KeyRound size={24}/><span><h2>Пароль финансовой части табеля</h2><p>Текущий первоначальный пароль: 911. После смены он хранится только как защищённый хеш.</p></span></div><div className="form-grid"><Field label="Текущий пароль"><PasswordInput value={pin.current} onChange={(event)=>setPin({...pin,current:event.target.value})} required minLength={3} pattern="[0-9 ]+" placeholder="Текущий PIN"/></Field><Field label="Новый пароль"><PasswordInput value={pin.next} onChange={(event)=>setPin({...pin,next:event.target.value})} required minLength={3} pattern="[0-9 ]+" placeholder="3–12 цифр"/></Field><Field label="Повторите новый пароль"><PasswordInput value={pin.confirm} onChange={(event)=>setPin({...pin,confirm:event.target.value})} required minLength={3} pattern="[0-9 ]+" placeholder="Повторите PIN"/></Field></div>{error?<p className="form-error" role="alert">{error}</p>:null}{message?<p className="settings-success">{message}</p>:null}<button className="button primary" disabled={busy}>{busy?'Сохраняем…':'Изменить пароль'}</button></form>:null}
    <div className="rights-card"><div><ShieldCheck size={24}/><span><h2>Права ролей</h2><p>Текущая матрица доступа</p></span></div><div className="rights-grid">{ACCESS_ROLES.map(([id,label,description])=><article key={id}><strong>{label}</strong><span>{description}</span></article>)}</div></div>
    {editing?<Dialog title={editing.id?'Редактировать учётную запись':'Новая учётная запись'} onClose={()=>setEditing(null)}><AccountForm account={editing.id?editing:null} employees={state.employees} onClose={()=>setEditing(null)} onSubmit={async(payload)=>{await runtime.saveUser(payload);setEditing(null);}}/></Dialog>:null}
  </div>;
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

export function StaffSettings({ state, focus, search, onEmployee, onCrew, command, runtime={mode:'demo'} }) {
  const serverMode=runtime.mode==='server'; const canManageEmployees=!serverMode||runtime.capabilities?.includes('*')||runtime.capabilities?.includes('employees.manage'); const canManageCrews=!serverMode||runtime.capabilities?.includes('*')||runtime.capabilities?.includes('crews.manage');
  const [section, setSection] = useState(focus);
  const query = search.toLocaleLowerCase('ru-RU');
  const employees = state.employees.filter((person) => `${person.name} ${person.role} ${person.department}`.toLocaleLowerCase('ru-RU').includes(query));
  const crews = state.crews.filter((crew) => `${crew.name} ${crew.specialty} ${crew.memberIds.map((id) => employee(id, state)?.name).join(' ')}`.toLocaleLowerCase('ru-RU').includes(query));
  const canAccess=serverMode&&(runtime.capabilities?.includes('*')||runtime.capabilities?.includes('users.manage')||runtime.capabilities?.includes('finance.manage'));
  return <section className="page staff-settings"><PageHeading title={focus === 'crews' ? 'Бригады' : 'Настройки'} description="Сотрудники, должности, бригады и права доступа" />
    <div className="settings-tabs"><button className={section === 'employees' ? 'active' : ''} onClick={() => setSection('employees')}><UserRound size={18} />Сотрудники <span>{state.employees.length}</span></button><button className={section === 'crews' ? 'active' : ''} onClick={() => setSection('crews')}><HardHat size={18} />Бригады <span>{state.crews.length}</span></button>{canAccess?<button className={section === 'access' ? 'active' : ''} onClick={() => setSection('access')}><UserCog size={18}/>Доступ</button>:null}<button className={section === 'documents' ? 'active' : ''} onClick={() => setSection('documents')}><FileLock2 size={18} />Документы</button></div>
    {section === 'employees' ? <><div className="settings-section-head"><div><h2>Сотрудники</h2><p>Менеджеры, цех и другой персонал</p></div>{canManageEmployees?<button className="button primary" onClick={() => onEmployee()}><Plus size={18} />Добавить сотрудника</button>:null}</div><div className="table-wrap"><table><thead><tr><th>Сотрудник</th><th>Должность</th><th>Подразделение</th><th>Контакт</th><th>Статус</th><th>Действие</th></tr></thead><tbody>{employees.map((person) => <tr key={person.id}><td><span className="staff-person"><Avatar id={person.id} state={state} /><strong>{person.name}</strong></span></td><td>{person.role}</td><td>{person.department || '—'}</td><td>{person.phone || person.email || '—'}</td><td>{person.active ? 'Работает' : 'Неактивен'}</td><td>{canManageEmployees?<button className="text-link" onClick={() => onEmployee(person.id)}>Редактировать</button>:<span className="muted">Просмотр</span>}</td></tr>)}</tbody></table>{!employees.length ? <p className="settings-empty">Сотрудники не найдены.</p> : null}</div></> : null}
    {section === 'crews' ? <CrewPlanning state={state} search={search} onCrew={onCrew} command={command} canManage={canManageCrews} /> : null}
    {section === 'documents' ? <div className="document-gate"><FileLock2 size={32} /><h2>Документы сотрудников</h2><p>Для копий документов нужен закрытый сервер, вход пользователей, отдельные права кадрового доступа и журнал просмотров. Публичная демонстрационная страница не принимает и не хранит сканы.</p><span>Раздел будет подключён вместе с серверной базой CRM.</span></div> : null}
    {section === 'access'&&canAccess?<AccessSettings state={state} runtime={runtime}/>:null}
  </section>;
}
