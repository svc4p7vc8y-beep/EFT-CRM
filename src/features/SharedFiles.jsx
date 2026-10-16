import { useCallback, useEffect, useRef, useState } from 'react';
import { Download, FileText, FolderOpen, RefreshCw, Upload } from 'lucide-react';
import { crmApi } from '../app/api.js';
import './shared-files.css';

const CALC_API = 'https://eftsip.ru/api/api.php';
const sizeLabel = (size) => Number(size) < 1024 ? `${size} Б` : Number(size) < 1048576 ? `${Math.ceil(size / 1024)} КБ` : `${(size / 1048576).toFixed(1)} МБ`;
const dateLabel = (date) => date ? new Date(String(date).replace(' ', 'T')).toLocaleDateString('ru-RU') : '';

async function calculatorFiles() {
  const session = await fetch(`${CALC_API}?action=session`, { credentials: 'include' }).then((response) => response.json());
  if (!session.user) return { connected: false, files: [] };
  const folders = [{ id: '', path: '' }, { id: 'questionnaires', path: 'Анкеты' }];
  const files = [];
  const visited = new Set();
  while (folders.length && visited.size < 100) {
    const folder = folders.shift();
    if (visited.has(folder.id)) continue;
    visited.add(folder.id);
    const query = new URLSearchParams({ action: 'files', ...(folder.id ? { folder: folder.id } : {}) });
    const response = await fetch(`${CALC_API}?${query}`, { credentials: 'include' });
    if (!response.ok) throw new Error('Не удалось получить файлы калькулятора');
    const data = await response.json();
    for (const file of data.files || []) files.push({ id: `calc:${file.id}`, name: file.original_name, size: file.size_bytes, createdAt: file.created_at, source: 'Калькулятор', detail: folder.path || file.customer_name || 'Общая папка', href: `${CALC_API}?action=${file.downloadAction || 'file-download'}&id=${encodeURIComponent(file.id)}` });
    for (const child of data.folders || []) folders.push({ id: child.id, path: [folder.path, child.name].filter(Boolean).join(' / ') });
  }
  return { connected: true, files };
}

export function SharedFiles({ liveSession, localAttachments = [] }) {
  const input = useRef(null);
  const [items, setItems] = useState([]);
  const [connected, setConnected] = useState(false);
  const [loading, setLoading] = useState(false);
  const [uploading, setUploading] = useState(false);
  const [error, setError] = useState('');
  const [query, setQuery] = useState('');
  const [migrated, setMigrated] = useState(() => { try { return JSON.parse(localStorage.getItem('eft-crm-files-migrated-v1') || '[]'); } catch { return []; } });
  const oldFiles = localAttachments.filter((file) => !migrated.includes(file.id));
  const load = useCallback(async () => {
    setLoading(true); setError('');
    try {
      const [crm, calc] = await Promise.allSettled([liveSession ? crmApi.filesList() : Promise.resolve({ files: [] }), calculatorFiles()]);
      const crmFiles = crm.status === 'fulfilled' ? crm.value.files.map((file) => ({ id: `crm:${file.id}`, name: file.name, size: file.size, createdAt: file.createdAt, source: 'CRM', detail: file.siteName || 'Общие файлы', href: crmApi.filesDownloadUrl(file.id) })) : [];
      const calcFiles = calc.status === 'fulfilled' ? calc.value.files : [];
      setConnected(calc.status === 'fulfilled' && calc.value.connected);
      setItems([...crmFiles, ...calcFiles, ...oldFiles.map((file) => ({ id: `local:${file.id}`, name: file.name, size: file.size, createdAt: file.createdAt, source: 'CRM · этот браузер', detail: '', href: file.dataUrl, download: file.name }))].sort((a, b) => new Date(b.createdAt) - new Date(a.createdAt)));
      if (crm.status === 'rejected') setError(crm.reason.message || 'Не удалось получить файлы CRM');
      else if (calc.status === 'rejected') setError(calc.reason.message || 'Не удалось получить файлы калькулятора');
    } finally { setLoading(false); }
  }, [liveSession, localAttachments, migrated]);
  useEffect(() => { load(); }, [load]);
  const upload = async (event) => {
    const files = [...(event.target.files || [])]; event.target.value = '';
    if (!files.length) return;
    setUploading(true); setError('');
    try { for (const file of files) await crmApi.filesUpload(file); await load(); }
    catch (failure) { setError(failure.message); }
    finally { setUploading(false); }
  };
  const migrateOldFiles = async () => {
    setUploading(true); setError('');
    try {
      const done = [...migrated];
      for (const item of oldFiles) {
        const blob = await fetch(item.dataUrl).then((response) => response.blob());
        await crmApi.filesUpload(new File([blob], item.name, { type: item.type || blob.type }));
        done.push(item.id);
        localStorage.setItem('eft-crm-files-migrated-v1', JSON.stringify(done));
      }
      setMigrated(done);
      await load();
    } catch (failure) { setError(failure.message); }
    finally { setUploading(false); }
  };
  const visible = items.filter((file) => `${file.name} ${file.detail} ${file.source}`.toLocaleLowerCase('ru').includes(query.toLocaleLowerCase('ru')));
  return <section className="page shared-files-page">
    <header className="shared-files-header"><div><p className="eyebrow">Общее хранилище</p><h1>Файлы</h1><p>Документы CRM и калькулятора в одном списке.</p></div><div className="shared-files-actions"><button className="button" onClick={() => load()} disabled={loading}><RefreshCw size={16}/>Обновить</button>{liveSession ? <button className="button primary" onClick={() => input.current?.click()} disabled={uploading}><Upload size={16}/>{uploading ? 'Загрузка…' : 'Загрузить'}</button> : null}<input ref={input} className="sr-only" type="file" multiple onChange={upload} /></div></header>
    {!connected ? <div className="shared-files-info"><FolderOpen size={20}/><span>Чтобы видеть файлы калькулятора, войдите там под своей учётной записью и обновите этот список.</span><a className="button" href="https://calc.eftsip.ru/" target="_blank" rel="noreferrer">Открыть калькулятор</a></div> : null}
    {!liveSession ? <p className="shared-files-info">Демо-файлы доступны только в этом браузере. Для общего серверного списка войдите в CRM.</p> : null}
    {liveSession && oldFiles.length ? <div className="shared-files-info"><FolderOpen size={20}/><span>{oldFiles.length} файлов сохранено только в этом браузере. Перенесите их на сервер, чтобы видеть в калькуляторе.</span><button className="button" disabled={uploading} onClick={migrateOldFiles}>Перенести</button></div> : null}
    {error ? <p className="shared-files-error" role="alert">{error}</p> : null}
    <input className="shared-files-search" type="search" value={query} onChange={(event) => setQuery(event.target.value)} placeholder="Найти файл, объект или папку" aria-label="Поиск файлов" />
    <div className="shared-files-list">{visible.map((file) => <a key={file.id} href={file.href} download={file.download} target="_blank" rel="noreferrer" className="shared-file"><FileText size={20}/><span><strong>{file.name}</strong><small>{file.source} · {file.detail} · {sizeLabel(file.size)} · {dateLabel(file.createdAt)}</small></span><Download size={17}/></a>)}{!loading && !visible.length ? <p>Файлов пока нет.</p> : null}{loading ? <p>Загружаем файлы…</p> : null}</div>
  </section>;
}
