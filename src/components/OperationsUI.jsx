import { useState } from 'react';
import { createPortal } from 'react-dom';
import { Dialog } from './UI.jsx';
import { RELEASE } from '../app/operations.js';

export function EntryForm({ children, onSubmit, onClose, label = 'Сохранить' }) {
  const [error, setError] = useState(''); const [busy,setBusy]=useState(false);
  return <form onSubmit={async (e) => { e.preventDefault(); setError(''); setBusy(true); try { await onSubmit(Object.fromEntries(new FormData(e.currentTarget))); } catch (err) { setError(err.message); setBusy(false); } }}><div className="form-content"><div className="form-grid">{children}</div>{error ? <p className="form-error" role="alert">{error}</p> : null}</div><footer className="dialog-actions"><button type="button" className="button" onClick={onClose} disabled={busy}>Отмена</button><button className="button primary" disabled={busy}>{busy?'Сохраняем…':label}</button></footer></form>;
}
export function downloadCSV(title, headers, rows) {
  const cell = (value) => { let s = String(value ?? ''); if (/^[=+@\-\t\r]/.test(s)) s = "'" + s; return '"' + s.replaceAll('"', '""') + '"'; };
  const content = '\uFEFF' + [headers, ...rows].map((r) => r.map(cell).join(';')).join('\r\n');
  const url = URL.createObjectURL(new Blob([content], { type: 'text/csv;charset=utf-8' }));
  const a = document.createElement('a'); a.href = url; a.download = `${title}.csv`; a.click(); setTimeout(() => URL.revokeObjectURL(url), 1000);
}
export function Report({ report, onClose }) {
  return createPortal(<Dialog title="Печатная форма" onClose={onClose} wide><div className="report-sheet"><div className="report-brand"><img src="./eft-logo.jpg" alt="ЭФТ" /><span>ЭФТ · CRM · версия {RELEASE}</span></div><h2>{report.title}</h2><p>{report.subtitle}</p><div className="table-wrap"><table><thead><tr>{report.headers.map((h, i) => <th key={i}>{h}</th>)}</tr></thead><tbody>{report.rows.map((row, i) => <tr key={i}>{row.map((value, j) => <td key={j}>{value}</td>)}</tr>)}</tbody></table></div>{report.footer ? <p className="report-footer">{report.footer}</p> : null}<p className="report-signature">Составил ____________________　 Проверил ____________________</p></div><footer className="dialog-actions"><button className="button" onClick={() => downloadCSV(report.title, report.headers, report.rows)}>Скачать CSV</button><button className="button primary" onClick={() => window.print()}>Печать / PDF</button></footer></Dialog>, document.body);
}
export function SectionTabs({ items, value, onChange }) { return <div className="settings-tabs">{items.map(([id, title]) => <button key={id} className={value === id ? 'active' : ''} onClick={() => onChange(id)}>{title}</button>)}</div>; }
