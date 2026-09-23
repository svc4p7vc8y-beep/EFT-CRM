import { useRef } from 'react';
import { Check, Moon, Palette, Sun } from 'lucide-react';

const themes = [
  { id: 'light', label: 'Светлая', description: 'Светлый рабочий интерфейс', icon: Sun },
  { id: 'dark', label: 'Тёмная', description: 'Для работы при слабом освещении', icon: Moon },
  { id: 'brand', label: 'Фирменная ЭФТ', description: 'Графит и зелёный цвет логотипа', icon: Palette },
];

export function ThemeSwitcher({ value, onChange }) {
  const detailsRef = useRef(null);
  const current = themes.find((theme) => theme.id === value) || themes[0];
  const CurrentIcon = current.icon;

  function choose(theme) {
    onChange(theme.id);
    detailsRef.current?.removeAttribute('open');
  }

  return <details className="theme-switcher" ref={detailsRef}>
    <summary aria-label={`Оформление: ${current.label}`} title={`Оформление: ${current.label}`}><CurrentIcon size={18} /><span>{current.label}</span></summary>
    <div className="theme-menu" role="menu" aria-label="Выбор оформления">
      <strong>Оформление</strong>
      {themes.map((theme) => {
        const Icon = theme.icon;
        return <button type="button" role="menuitemradio" aria-checked={value === theme.id} className={value === theme.id ? 'active' : ''} key={theme.id} onClick={() => choose(theme)}>
          <span className={`theme-swatch ${theme.id}`}><Icon size={17} /></span>
          <span><b>{theme.label}</b><small>{theme.description}</small></span>
          {value === theme.id ? <Check size={17} /> : null}
        </button>;
      })}
    </div>
  </details>;
}
