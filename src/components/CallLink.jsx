import { Phone } from 'lucide-react';

export function phoneDialHref(phone) {
  const match = String(phone || '').match(/\+?\d[\d\s().-]{5,}\d/);
  if (!match) return '';
  const digits = match[0].replace(/\D/g, '');
  if (digits.length < 7 || digits.length > 15) return '';
  if (digits.length === 11 && digits.startsWith('8')) return `tel:+7${digits.slice(1)}`;
  return `tel:${match[0].trim().startsWith('+') ? '+' : ''}${digits}`;
}

export function CallLink({ phone, label, className = 'call-link' }) {
  const href = phoneDialHref(phone);
  if (!href) return phone || 'Не указан';
  return <a className={className} href={href} aria-label={`Позвонить: ${phone}`}><Phone size={15} aria-hidden="true" />{label || phone}</a>;
}
