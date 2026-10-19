import { useState } from 'react';
import { Dialog } from './UI.jsx';

const labels = {clients:'клиента',sites:'объект',leads:'заявку',orders:'заказ',tasks:'задачу',supplyNeeds:'потребность',purchases:'закупку',stockDocuments:'движение',materials:'материал',suppliers:'поставщика',tools:'инструмент'};
const related = {clients:'клиенты',sites:'объекты',leads:'заявки',orders:'заказы',tasks:'задачи',activities:'записи истории',constructionStages:'этапы строительства',logistics:'рейсы',supplyNeeds:'потребности',purchases:'закупки',stockDocuments:'движения',materials:'материалы',suppliers:'поставщики',tools:'инструменты',toolEvents:'события инструмента'};

export function DeletionDialog({target,counts,onClose,onConfirm}) {
  const [password,setPassword]=useState(''); const [busy,setBusy]=useState(false); const [error,setError]=useState('');
  async function submit(event){event.preventDefault();setError('');setBusy(true);try{await onConfirm(password);}catch(failure){setError(failure.message);setBusy(false);}}
  return <Dialog title={`Удалить ${labels[target.type]||'запись'}?`} onClose={busy?()=>{}:onClose}><form onSubmit={submit}><div className="form-content"><p>«{target.label}» будет удалено без возможности восстановления.</p><p>Связанные записи: {Object.entries(counts).map(([key,count])=>`${related[key]||key}: ${count}`).join(', ')}.</p><label>Пароль удаления<input type="password" autoComplete="off" value={password} onChange={(event)=>setPassword(event.target.value)} required autoFocus/></label>{error?<p className="form-error" role="alert">{error}</p>:null}</div><footer className="dialog-actions"><button className="button" type="button" disabled={busy} onClick={onClose}>Отмена</button><button className="button primary" disabled={busy||!password}>{busy?'Удаляем…':'Удалить'}</button></footer></form></Dialog>;
}
