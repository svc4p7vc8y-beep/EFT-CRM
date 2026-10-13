import { useState } from 'react';
import { BRIEF_SCOPE, briefReadiness, normalizeBuildingBrief } from '../app/buildingBrief.js';
import './building-brief.css';

const select = (name, label, options) => ({ name, label, options });
const number = (name, label, step = '1') => ({ name, label, type: 'number', step });
const text = (name, label) => ({ name, label });
const check = (name, label) => ({ name, label, type: 'checkbox' });
const sections = [
  ['Постройка и размеры', [
    text('buildingType','Тип постройки'), select('goal','Что требуется',['Тёплый контур','Домокомплект','Под ключ','Реконструкция','Подобрать']), number('length','Длина, м','0.1'), number('width','Ширина, м','0.1'), number('approxArea','Примерная площадь, м²','0.1'), select('floors','Этажей',['1','2']), number('wallHeight','Высота стен, м','0.1'), select('houseShape','Форма',['Прямоугольная','Г-образная','П-образная','Сложная','Подобрать']), select('basement','Подвал',['Нет','Да','Подобрать']), select('attic','Мансарда',['Нет','Да','Подобрать']), number('bedrooms','Спальни'), number('bathrooms','Санузлы'), number('kitchens','Кухни'), number('utilityRooms','Подсобные помещения'), check('secondLight','Второй свет'), check('staircaseOpening','Лестничный проём'), text('roomsNotes','Пожелания по планировке'),
  ]],
  ['Участок и доступ', [
    text('region','Регион'), number('siteArea','Площадь участка, м²','0.1'), text('cadastralNumber','Кадастровый номер'), select('terrain','Рельеф',['Ровный','Уклон','Подобрать']), select('soil','Грунт',['Не знаю','Песок','Глина','Суглинок','Скальный','Подобрать']), select('access','Подъезд',['Свободный','Ограниченный','Нет подъезда','Подобрать']), select('electricityOnSite','Электричество на участке',['Есть','Нет','Не выбрано']), select('waterOnSite','Вода на участке',['Есть','Нет','Не выбрано']), select('sewerOnSite','Канализация на участке',['Есть','Нет','Не выбрано']), number('deliveryDistance','Расстояние доставки, км','0.1'),
  ]],
  ['СИП и конструктив', [
    select('panelFamily','Семейство панели',['PPS','Минвата','CSP PPS','Подобрать']), select('wallThickness','Стены 1 этажа',['124 мм','174 мм','224 мм','Подобрать']), select('floorThickness','Пол',['124 мм','174 мм','224 мм','Подобрать']), select('ceilingThickness','Потолок',['124 мм','174 мм','224 мм','Подобрать']), select('partitionType','Перегородки',['Каркасные','SIP-панели','Подобрать']), select('panelLayout','Шаг раскладки',['1250 мм','625 мм','Подобрать']), select('secondFloorThickness','Межэтажное перекрытие',['124 мм','174 мм','224 мм','Подобрать']), select('secondFloorWallThickness','Стены 2 этажа',['124 мм','174 мм','224 мм','Подобрать']), number('secondFloorWallHeight','Высота стен 2 этажа, м','0.1'), select('secondFloorPartitionType','Перегородки 2 этажа',['Каркасные','SIP-панели','Подобрать']),
  ]],
  ['Кровля', [
    select('roofShape','Форма кровли',['Двускатная','Вальмовая','Плоская','Односкатная','Подобрать']), select('roofType','Конструкция',['Холодная','Тёплая SIP','Утеплённая по стропилам','Подобрать']), select('roofCovering','Покрытие',['Металлочерепица','Профлист','Мягкая кровля','Подобрать']), select('ridgeAxis','Направление конька',['Вдоль длины дома','Вдоль ширины дома','Подобрать']), number('ridgeHeight','Высота конька, м','0.1'), number('eaveOverhang','Карнизный свес, м','0.1'), number('gableOverhang','Фронтонный свес, м','0.1'), select('gableType','Фронтоны',['По типу кровли','Каркасные','Из SIP-панелей','Не учитывать']), number('gableCount','Количество фронтонов'), select('flatRoofSlopeMode','Уклон плоской кровли',['Перепад высоты стен','Разуклонка по основанию','Без уклона']), select('flatRoofSlopeDirection','Направление уклона',['К фасаду','К задней стороне','Влево','Вправо']), number('flatRoofSlopePercent','Уклон, %','0.1'),
  ]],
  ['Проёмы и площадки', [
    number('windowsCount','Окон'), number('entranceDoorsCount','Входных дверей'), number('interiorDoorsCount','Межкомнатных дверей'), number('garageDoorsCount','Ворот'), number('windowWidth','Ширина окна, м','0.01'), number('windowHeight','Высота окна, м','0.01'), number('entranceDoorWidth','Ширина входной двери, м','0.01'), number('entranceDoorHeight','Высота входной двери, м','0.01'), number('garageDoorWidth','Ширина ворот, м','0.01'), number('garageDoorHeight','Высота ворот, м','0.01'), text('openingsNotes','Пожелания по проёмам'), check('terrace','Терраса'), number('terraceLength','Длина террасы, м','0.1'), number('terraceWidth','Ширина террасы, м','0.1'), select('terraceSide','Сторона террасы',['Фасад','Сзади','Слева','Справа','Подобрать']), select('terraceRoof','Кровля террасы',['Без кровли','Холодная','Тёплая SIP','Подобрать']), check('porch','Крыльцо'), number('porchLength','Длина крыльца, м','0.1'), number('porchWidth','Ширина крыльца, м','0.1'), select('porchSide','Сторона крыльца',['Фасад','Сзади','Слева','Справа','Подобрать']), select('porchRoof','Кровля крыльца',['Без кровли','Холодная','Тёплая SIP','Подобрать']),
  ]],
  ['Инженерия и отделка', [
    select('electricStage','Электрика',['Не нужна','Подготовка','Предчистовая','Полная','Подобрать']), select('waterStage','Водоснабжение',['Не нужно','Подготовка','Полная разводка','Подобрать']), select('sewerStage','Канализация',['Не нужна','Подготовка','Полная разводка','Подобрать']), select('heating','Отопление',['Не нужно','Электрическое','Газовое','Подобрать']), select('waterSource','Источник воды',['Не нужен','Скважина','Колодец','Центральное','Подобрать']), select('sewer','Стоки',['Не нужна','Септик','Центральная','Подобрать']), select('ventilation','Вентиляция',['Не нужна','Естественная','Принудительная','Подобрать']), check('gas','Газ'), check('fireplace','Камин'), select('exteriorFinish','Фасад',['Без отделки','Штукатурка','Сайдинг','Планкен','Подобрать']), select('interiorFinish','Внутренняя отделка',['Без отделки','Предчистовая','Чистовая','Подобрать']), text('floorFinish','Покрытие пола'), text('ceilingFinish','Отделка потолка'), check('plinth','Цоколь'), check('gutters','Водостоки'),
  ]],
  ['Сроки и пожелания', [
    number('budget','Ориентир бюджета, ₽'), { name:'desiredStart', label:'Желаемое начало', type:'date' }, select('decisionStatus','Этап решения',['Собираю информацию','Выбираю подрядчика','Готов к договору']), select('contactMethod','Связь',['Звонок','Почта','Мессенджер']), text('contactTime','Удобное время связи'), text('notes','Пожелания клиента'),
  ]],
];

function BriefField({ field, value }) {
  const name = `brief.${field.name}`;
  if (field.type === 'checkbox') return <label className="brief-check" key={name}><input type="checkbox" name={name} defaultChecked={Boolean(value)} />{field.label}</label>;
  return <label className="brief-field" key={name}><span>{field.label}</span>{field.options ? <select name={name} defaultValue={value || ''}><option value="">Не указано</option>{field.options.map((item) => <option key={item} value={item}>{item}</option>)}</select> : field.name === 'buildingType' ? <><input name={name} list="brief-building-types" defaultValue={value || ''} maxLength={120} placeholder="Например, жилой дом" /><datalist id="brief-building-types">{['Жилой дом','Дачный дом','Баня','Гараж','Хозблок','Коммерческая постройка'].map((item) => <option key={item} value={item} />)}</datalist></> : <input name={name} type={field.type || 'text'} step={field.step} min={field.type === 'number' ? '0' : undefined} defaultValue={value || ''} maxLength={field.type ? undefined : 2000} />}</label>;
}

export function BuildingBriefFields({ brief }) {
  const [values, setValues] = useState(() => normalizeBuildingBrief(brief));
  const missing = briefReadiness(values);
  function update(event) {
    const name = event.target.name;
    if (!name?.startsWith('brief.')) return;
    const key = name.slice(6);
    if (key.startsWith('scope.')) {
      const item = BRIEF_SCOPE[Number(key.slice(6))];
      if (item) setValues((current) => ({ ...current, scope: event.target.checked ? [...current.scope, item] : current.scope.filter((entry) => entry !== item) }));
    } else setValues((current) => ({ ...current, [key]: event.target.type === 'checkbox' ? event.target.checked : event.target.value }));
  }
  return <div className="brief-form-section" onChange={update}><div className="brief-form-heading"><h3>Параметры постройки для калькулятора</h3><p>Можно заполнить постепенно. Данные сохраняются в объекте заявки и выгружаются в формат анкеты EFT.</p>{!missing.length ? <strong>Основные параметры заполнены</strong> : <small>Для черновика расчёта уточните: {missing.join(', ')}.</small>}</div>
    <details open className="brief-group"><summary>Состав расчёта</summary><div className="brief-scope-grid">{BRIEF_SCOPE.map((item, index) => <label key={item}><input type="checkbox" name={`brief.scope.${index}`} defaultChecked={values.scope.includes(item)} />{item}</label>)}</div></details>
    {sections.map(([title, fields], index) => <details className="brief-group" key={title} open={index === 0}><summary>{title}</summary><div className="brief-field-grid">{fields.map((field) => <BriefField key={field.name} field={field} value={values[field.name]} />)}</div></details>)}
    <p className="brief-form-note">План помещений, положение окон и точную геометрию нужно проверить в калькуляторе перед расчётом.</p>
  </div>;
}
