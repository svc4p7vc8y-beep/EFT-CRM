import { useEffect, useRef, useState } from 'react';
import { ArrowLeft, ArrowRight, Check, Download, Eraser, FolderInput, PenLine, Send, Share2, X } from 'lucide-react';
import { crmApi } from '../app/api.js';
import './document-viewer.css';

export function openDocument(document) {
  window.dispatchEvent(new CustomEvent('eft:open-document', { detail: document }));
}

const isImage = (item) => item?.mime?.startsWith('image/') || /\.(png|jpe?g|webp|gif)$/i.test(item?.name || '');
const isPdf = (item) => item?.mime === 'application/pdf' || /\.pdf$/i.test(item?.name || '');

export function DocumentViewer({ sites = [], liveSession, canSend, canManageFiles, sendCommunication, notify }) {
  const [item, setItem] = useState(null);
  const [page, setPage] = useState(1);
  const [pages, setPages] = useState(1);
  const [draw, setDraw] = useState(false);
  const [dirty, setDirty] = useState(false);
  const [color, setColor] = useState('#ed3535');
  const [width, setWidth] = useState(4);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  const [action, setAction] = useState('');
  const [siteId, setSiteId] = useState('');
  const [channel, setChannel] = useState('email');
  const [caption, setCaption] = useState('');
  const [savedFile, setSavedFile] = useState(null);
  const [renderRevision, setRenderRevision] = useState(0);
  const canvasRef = useRef(null);
  const baseRef = useRef(null);
  const marksRef = useRef(null);
  const sourceRef = useRef(null);
  const workingPdfRef = useRef(null);
  const strokeRef = useRef(false);
  const openRef = useRef(0);

  useEffect(() => {
    const open = (event) => {
      const next = event.detail;
      if (!next?.url) return;
      openRef.current += 1;
      workingPdfRef.current = null;
      setItem(next); setPage(1); setPages(1); setDraw(false); setDirty(false);
      setError(''); setAction(''); setSiteId(next.siteId || ''); setCaption(''); setSavedFile(null);
    };
    window.addEventListener('eft:open-document', open);
    return () => window.removeEventListener('eft:open-document', open);
  }, []);
  useEffect(() => {
    if (!item) return undefined;
    const key = (event) => { if (event.key === 'Escape') { setItem(null); setAction(''); } };
    window.addEventListener('keydown', key);
    const previous = document.body.style.overflow;
    document.body.style.overflow = 'hidden';
    return () => { window.removeEventListener('keydown', key); document.body.style.overflow = previous; };
  }, [item]);
  useEffect(() => {
    if (!item || (!isImage(item) && !isPdf(item))) return undefined;
    const token = ++openRef.current;
    let cancelled = false;
    async function load() {
      setError(''); setDirty(false);
      try {
        let blob = isPdf(item) ? workingPdfRef.current : null;
        if (!blob) {
          const response = await fetch(item.url, { credentials: 'include' });
          if (!response.ok) throw new Error(`Файл не загрузился (${response.status}).`);
          blob = await response.blob();
        }
        if (cancelled || token !== openRef.current) return;
        sourceRef.current = blob;
        const canvas = canvasRef.current;
        if (!canvas) return;
        if (isPdf(item)) {
          const [pdfjs, worker] = await Promise.all([import('pdfjs-dist'), import('pdfjs-dist/build/pdf.worker.min.mjs?url')]);
          pdfjs.GlobalWorkerOptions.workerSrc = worker.default;
          const pdf = await pdfjs.getDocument({ data: await blob.arrayBuffer() }).promise;
          if (cancelled || token !== openRef.current) return;
          setPages(pdf.numPages);
          const pdfPage = await pdf.getPage(page);
          const viewport = pdfPage.getViewport({ scale: Math.min(2, 1800 / pdfPage.getViewport({ scale: 1 }).width) });
          canvas.width = Math.round(viewport.width); canvas.height = Math.round(viewport.height);
          await pdfPage.render({ canvasContext: canvas.getContext('2d'), viewport }).promise;
        } else {
          const bitmap = await createImageBitmap(blob);
          if (cancelled || token !== openRef.current) { bitmap.close(); return; }
          const scale = Math.min(1, 2000 / Math.max(bitmap.width, bitmap.height));
          canvas.width = Math.max(1, Math.round(bitmap.width * scale));
          canvas.height = Math.max(1, Math.round(bitmap.height * scale));
          const context = canvas.getContext('2d');
          context.drawImage(bitmap, 0, 0, canvas.width, canvas.height);
          bitmap.close();
        }
        baseRef.current = canvas.getContext('2d').getImageData(0, 0, canvas.width, canvas.height);
        if (isPdf(item)) {
          const marks = document.createElement('canvas');
          marks.width = canvas.width; marks.height = canvas.height;
          marksRef.current = marks;
        } else marksRef.current = null;
      } catch (failure) { if (!cancelled) setError(failure.message || 'Не удалось открыть файл.'); }
    }
    load();
    return () => { cancelled = true; };
  }, [item, page, renderRevision]);

  const close = () => { setItem(null); setAction(''); };
  const point = (event) => { const bounds = canvasRef.current.getBoundingClientRect(); return [(event.clientX - bounds.left) * canvasRef.current.width / bounds.width, (event.clientY - bounds.top) * canvasRef.current.height / bounds.height]; };
  const markContexts = () => [canvasRef.current?.getContext('2d'), marksRef.current?.getContext('2d')].filter(Boolean);
  const pointerDown = (event) => {
    if (!draw) return;
    event.currentTarget.setPointerCapture(event.pointerId);
    const [x, y] = point(event);
    for (const context of markContexts()) { context.beginPath(); context.moveTo(x, y); context.strokeStyle = color; context.lineWidth = width; context.lineCap = 'round'; context.lineJoin = 'round'; context.lineTo(x + 0.01, y + 0.01); context.stroke(); }
    strokeRef.current = true; setDirty(true); setSavedFile(null);
  };
  const pointerMove = (event) => { if (!strokeRef.current) return; const [x, y] = point(event); for (const context of markContexts()) { context.lineTo(x, y); context.stroke(); } setDirty(true); };
  const pointerUp = () => { strokeRef.current = false; };
  const editedFile = async () => {
    if (savedFile) return savedFile;
    if (!dirty) {
      if (isPdf(item) && workingPdfRef.current) return workingPdfRef.current;
      const response = await fetch(item.url, { credentials: 'include' });
      if (!response.ok) throw new Error('Не удалось прочитать исходный файл.');
      return new File([await response.blob()], item.name || 'document', { type: item.mime || response.headers.get('content-type') || 'application/octet-stream' });
    }
    if (isPdf(item)) {
      const [{ PDFDocument }, source] = await Promise.all([import('pdf-lib'), sourceRef.current?.arrayBuffer()]);
      if (!source || !marksRef.current) throw new Error('PDF ещё не загружен.');
      const pdf = await PDFDocument.load(source);
      const marksBlob = await new Promise((resolve) => marksRef.current.toBlob(resolve, 'image/png'));
      if (!marksBlob) throw new Error('Не удалось создать пометки PDF.');
      const marks = await pdf.embedPng(await marksBlob.arrayBuffer());
      const pdfPage = pdf.getPage(page - 1);
      pdfPage.drawImage(marks, { x: 0, y: 0, width: pdfPage.getWidth(), height: pdfPage.getHeight() });
      const bytes = await pdf.save();
      return new File([bytes], `${(item.name || 'document').replace(/\.pdf$/i, '')}-пометки.pdf`, { type: 'application/pdf' });
    }
    const blob = await new Promise((resolve) => canvasRef.current.toBlob(resolve, 'image/png'));
    if (!blob) throw new Error('Не удалось создать изображение с пометками.');
    const name = `${(item.name || 'document').replace(/\.[^.]+$/, '')}-пометки.png`;
    return new File([blob], name, { type: 'image/png' });
  };
  const saveEdited = async () => {
    setBusy(true); setError('');
    try {
      if (!liveSession) throw new Error('Сохранение доступно после входа в CRM.');
      const file = await editedFile();
      if (file.size > 8 * 1024 * 1024) throw new Error('Файл с пометками больше 8 МБ.');
      const result = await crmApi.filesUpload(file, siteId || item.siteId || '');
      if (isPdf(item)) { workingPdfRef.current = file; setRenderRevision((value) => value + 1); }
      setSavedFile(file); setDirty(false); notify?.('Файл с пометками сохранён на сервере');
      return result;
    } catch (failure) { setError(failure.message); return null; }
    finally { setBusy(false); }
  };
  const applyEdited = async () => {
    setBusy(true); setError('');
    try {
      const file = await editedFile();
      if (isPdf(item)) { workingPdfRef.current = file; setRenderRevision((value) => value + 1); }
      setSavedFile(file); setDirty(false);
    } catch (failure) { setError(failure.message || 'Не удалось применить пометки.'); }
    finally { setBusy(false); }
  };
  const downloadEdited = async () => {
    setBusy(true); setError('');
    try {
      const file = await editedFile();
      const url = URL.createObjectURL(file);
      const link = document.createElement('a'); link.href = url; link.download = file.name; link.click();
      window.setTimeout(() => URL.revokeObjectURL(url), 1000);
    } catch (failure) { setError(failure.message || 'Не удалось скачать файл с пометками.'); }
    finally { setBusy(false); }
  };
  const send = async () => {
    setBusy(true); setError('');
    try {
      if (!canSend || !siteId) throw new Error('Выберите клиента и объект для отправки.');
      const file = await editedFile();
      const result = await sendCommunication({ siteId, channel, subject: `Документ ЭФТ: ${file.name}`, text: caption.trim() || `Направляем файл ${file.name}`, files: [file] });
      if (result?.deliveryStatus === 'error') throw new Error(result.deliveryError || 'Канал не доставил файл.');
      notify?.(result?.deliveryStatus === 'sent' || result?.deliveryStatus === 'delivered' ? 'Файл отправлен и сохранён в переписке' : 'Файл сохранён в переписке; внешняя отправка пока не выполнена'); setAction('');
    } catch (failure) { setError(failure.message); }
    finally { setBusy(false); }
  };
  const move = async () => {
    setBusy(true); setError('');
    try {
      if (!canManageFiles) throw new Error('Нет прав на сохранение файлов.');
      if (item.sharedFileId) {
        await crmApi.filesMove(item.sharedFileId, siteId);
        setItem((current) => ({ ...current, siteId })); notify?.('Файл перемещён');
      } else {
        const file = await editedFile();
        const saved = await crmApi.filesUpload(file, siteId);
        setItem((current) => ({ ...current, siteId, sharedFileId: saved.id })); notify?.('Копия сохранена в разделе «Файлы»');
      }
      setAction('');
      window.dispatchEvent(new Event('eft:files-changed'));
    } catch (failure) { setError(failure.message); }
    finally { setBusy(false); }
  };
  const share = async () => {
    setAction('share'); setError('');
  };
  if (!item) return null;
  const drawable = isImage(item) || isPdf(item);
  return <div className="document-viewer" role="dialog" aria-modal="true" aria-label={`Просмотр файла: ${item.name || 'Документ'}`} onMouseDown={(event) => { if (event.target === event.currentTarget) close(); }}>
    <div className="document-viewer-shell">
      <header className="document-viewer-toolbar"><strong title={item.name}>{item.name || 'Документ'}</strong><div className="document-viewer-actions"><button type="button" onClick={share}><Share2 size={17}/>Поделиться</button><button type="button" onClick={() => { setAction('move'); setSiteId(item.siteId || ''); }} disabled={!canManageFiles}><FolderInput size={17}/>{item.sharedFileId ? 'Переместить' : 'В файлы'}</button><button type="button" onClick={() => { setDraw((value) => !value); setAction(''); }} disabled={!drawable}><PenLine size={17}/>{draw ? 'Завершить рисунок' : 'Рисовать'}</button><a href={item.downloadUrl || item.url} download={item.name}><Download size={17}/>Скачать</a><button type="button" className="document-viewer-close" onClick={close} aria-label="Закрыть документ"><X size={21}/>Закрыть</button></div></header>
      {draw ? <div className="document-viewer-draw"><label>Цвет <input type="color" value={color} onChange={(event) => setColor(event.target.value)}/></label><label>Толщина <input type="range" min="2" max="18" value={width} onChange={(event) => setWidth(Number(event.target.value))}/></label><button type="button" onClick={() => { if (baseRef.current && canvasRef.current) canvasRef.current.getContext('2d').putImageData(baseRef.current, 0, 0); marksRef.current?.getContext('2d').clearRect(0, 0, marksRef.current.width, marksRef.current.height); setDirty(false); if (!workingPdfRef.current) setSavedFile(null); }}><Eraser size={16}/>Очистить пометки</button>{isPdf(item) ? <button type="button" disabled={!dirty || busy} onClick={applyEdited}><Check size={16}/>Применить к PDF</button> : null}<button type="button" disabled={(!dirty && !savedFile) || busy || !canManageFiles} onClick={saveEdited}><Check size={16}/>{busy ? 'Сохраняем…' : 'Сохранить на сервере'}</button><button type="button" disabled={(!dirty && !savedFile) || busy} onClick={downloadEdited}><Download size={16}/>Скачать с пометками</button></div> : null}
      {error ? <p className="document-viewer-error" role="alert">{error}</p> : null}
      <main className="document-viewer-content">{drawable ? <canvas ref={canvasRef} className={draw ? 'is-drawing' : ''} onPointerDown={pointerDown} onPointerMove={pointerMove} onPointerUp={pointerUp} onPointerCancel={pointerUp} aria-label={isPdf(item) ? `Страница ${page} файла ${item.name}` : item.name}/> : <div className="document-viewer-unsupported"><p>Предпросмотр этого формата недоступен. Файл можно скачать или отправить клиенту.</p><a className="button" href={item.downloadUrl || item.url} download={item.name}><Download size={17}/>Скачать файл</a></div>}</main>
      {isPdf(item) && pages > 1 ? <div className="document-viewer-pages"><button type="button" disabled={page <= 1 || dirty} title={dirty ? 'Сначала сохраните или очистите пометки' : ''} onClick={() => setPage((value) => value - 1)}><ArrowLeft size={17}/></button><span>Страница {page} из {pages}{dirty ? ' · сохраните пометки перед переходом' : ''}</span><button type="button" disabled={page >= pages || dirty} title={dirty ? 'Сначала сохраните или очистите пометки' : ''} onClick={() => setPage((value) => value + 1)}><ArrowRight size={17}/></button></div> : null}
      {action ? <div className="document-viewer-panel"><h3>{action === 'share' ? 'Отправить клиенту' : item.sharedFileId ? 'Переместить в папку объекта' : 'Сохранить копию в файлах'}</h3><label>Клиент и объект<select value={siteId} onChange={(event) => setSiteId(event.target.value)}><option value="">{action === 'move' ? 'Общие файлы' : 'Выберите объект'}</option>{sites.map((site) => <option key={site.id} value={site.id}>{site.label}</option>)}</select></label>{action === 'share' ? <><label>Канал<select value={channel} onChange={(event) => setChannel(event.target.value)}><option value="email">Почта</option><option value="telegram">Telegram</option><option value="max">MAX</option></select></label><label>Сообщение<input value={caption} onChange={(event) => setCaption(event.target.value)} placeholder="Направляем документ с пометками"/></label><button type="button" className="button primary" onClick={send} disabled={busy || !canSend || !siteId}><Send size={17}/>{busy ? 'Отправляем…' : 'Отправить файл'}</button></> : <button type="button" className="button primary" onClick={move} disabled={busy}><FolderInput size={17}/>{busy ? 'Сохраняем…' : item.sharedFileId ? 'Переместить' : 'Сохранить в файлах'}</button>}<button type="button" className="button" onClick={() => setAction('')}>Отмена</button></div> : null}
    </div>
  </div>;
}
