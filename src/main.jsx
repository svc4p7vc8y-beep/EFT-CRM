import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import { DesignReview } from './review/DesignReview.jsx';
import './review/review.css';

createRoot(document.getElementById('root')).render(<StrictMode><DesignReview /></StrictMode>);
