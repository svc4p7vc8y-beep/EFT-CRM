import { useState } from 'react';

const screens = [
  { id: 'office', label: '01 · Заявки и клиенты', file: 'office-v1.png', title: 'Рабочее место менеджера', description: 'Список заявок, следующий контакт и история общения в одной рабочей области.', points: ['Навигация слева объединяет офис, цех и стройку.', 'В центре — заявки и ближайшие действия.', 'Справа — детали выбранного клиента и история общения.'] },
  { id: 'factory', label: '02 · Задачи цеха', file: 'factory-v1.png', title: 'Рабочее место начальника цеха', description: 'Очередь работ, исполнители и препятствия, которые влияют на готовность заказа.', points: ['Задания сгруппированы по состоянию работы.', 'В каждой задаче видны заказ, исполнитель и срок.', 'Блокировки и загрузка сотрудников помогают распределить работу.'] },
];

export function DesignReview() {
  const [selected, setSelected] = useState('office');
  const [expanded, setExpanded] = useState(false);
  const screen = screens.find((item) => item.id === selected);
  const imageUrl = `${import.meta.env.BASE_URL}concepts/${screen.file}`;
  function selectScreen(id) { setSelected(id); setExpanded(false); }

  return <div className="review-page">
    <header className="review-header">
      <a className="wordmark" href={import.meta.env.BASE_URL} aria-label="ЭФТ — страница согласования">ЭФТ</a>
      <span>Система управления строительством</span>
      <a className="repository-link" href="https://github.com/svc4p7vc8y-beep/EFT-CRM" target="_blank" rel="noreferrer">Репозиторий ↗</a>
    </header>
    <main>
      <div className="review-intro"><div><h1>Выбираем интерфейс</h1><p>Первая концепция · заявки и производство</p></div><span className="review-status">На согласовании</span></div>
      <p className="explanation">Ниже — изображения будущих экранов. Кнопки внутри макетов пока не работают. Все имена, суммы и контакты на изображениях вымышлены.</p>
      <nav className="screen-switcher" aria-label="Макеты экранов">{screens.map((item) => <button key={item.id} aria-pressed={selected === item.id} onClick={() => selectScreen(item.id)}>{item.label}</button>)}</nav>
      <section className="concept-section" aria-labelledby="concept-title">
        <div className="concept-toolbar"><div><h2 id="concept-title">{screen.title}</h2><p>{screen.description}</p></div><button className="zoom-button" onClick={() => setExpanded((value) => !value)} aria-pressed={expanded}>{expanded ? 'Вместить в экран' : 'Увеличить макет'}</button></div>
        <div className={`concept-viewport${expanded ? ' is-expanded' : ''}`} tabIndex={expanded ? 0 : undefined} aria-label={expanded ? 'Увеличенный макет, доступна прокрутка' : undefined}><img key={screen.id} src={imageUrl} width="1536" height="1024" alt={`Концепция: ${screen.title}. Статичное изображение интерфейса.`} fetchPriority="high" /></div>
        <div className="concept-notes"><h3>Что обсудить</h3><ul>{screen.points.map((point) => <li key={point}>{point}</li>)}</ul><p>Напишите в чате, подходит ли направление: цвета, размер текста, плотность информации и расположение разделов. После согласования начнётся реализация рабочих экранов на React.</p></div>
      </section>
    </main>
    <footer><span>ЭФТ · Концепция 01</span><span>Версия страницы: {__BUILD_REVISION__}</span></footer>
  </div>;
}
