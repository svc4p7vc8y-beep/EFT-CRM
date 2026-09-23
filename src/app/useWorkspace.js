import { useEffect, useState } from 'react';
import { applyCommand, createDemoState, STORAGE_KEY, validateState } from './model.js';

function readWorkspace() {
  try { const raw = localStorage.getItem(STORAGE_KEY); return { state: raw ? validateState(JSON.parse(raw)) : createDemoState(), error: '' }; }
  catch { return { state: createDemoState(), error: 'Сохранённые данные недоступны. Показаны примеры; исходное сохранение не перезаписано.' }; }
}
export function useWorkspace() {
  const [initial] = useState(readWorkspace);
  const [state, setState] = useState(initial.state);
  const [storageError, setStorageError] = useState(initial.error);
  useEffect(() => {
    const sync = (event) => { if (event.key === STORAGE_KEY && event.newValue) { try { setState(validateState(JSON.parse(event.newValue))); setStorageError(''); } catch { setStorageError('Данные из другой вкладки не удалось прочитать.'); } } };
    window.addEventListener('storage', sync); return () => window.removeEventListener('storage', sync);
  }, []);
  function save(next) {
    try { localStorage.setItem(STORAGE_KEY, JSON.stringify(next)); } catch { throw new Error('Не удалось сохранить: проверьте доступ к хранилищу браузера или экспортируйте данные.'); }
    setState(next); setStorageError(''); return next;
  }
  function command(action, payload) {
    if (storageError) throw new Error('Сначала сохраните исходные данные через «Данные» и восстановите рабочую копию.');
    const raw = localStorage.getItem(STORAGE_KEY);
    const latest = raw ? validateState(JSON.parse(raw)) : state;
    return save(applyCommand(latest, action, payload));
  }
  return { state, command, storageError, replace: (next) => save(validateState(next)) };
}
