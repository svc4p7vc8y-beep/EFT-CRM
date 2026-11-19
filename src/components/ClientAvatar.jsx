import { useState } from 'react';
import './client-avatar.css';

export function ClientAvatar({ client, className = '' }) {
  const url = client?.avatar || client?.avitoAvatar || '';
  const [failedUrl, setFailedUrl] = useState('');
  return <span className={`client-avatar ${className}`}>{url && failedUrl !== url
    ? <img src={url} alt={`Фото ${client?.name || 'клиента'}`} loading="lazy" referrerPolicy="no-referrer" onError={() => setFailedUrl(url)}/>
    : client?.name?.split(' ').filter(Boolean).map(part => part[0]).slice(0, 2).join('') || 'К'}</span>;
}
