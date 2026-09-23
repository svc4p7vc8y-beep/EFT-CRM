import { StrictMode, Suspense, lazy } from 'react';
import { createRoot } from 'react-dom/client';
const Screen = new URLSearchParams(window.location.search).get('view') === 'design'
  ? lazy(() => import('./review/DesignReview.jsx').then((module) => ({ default: module.DesignReview })))
  : lazy(() => import('./app/App.jsx').then((module) => ({ default: module.App })));

createRoot(document.getElementById('root')).render(<StrictMode><Suspense fallback={<p style={{ padding: 24, fontFamily: 'sans-serif' }}>Загрузка ЭФТ…</p>}><Screen /></Suspense></StrictMode>);
