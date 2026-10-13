export const CALCULATOR_URL = 'https://calc.eftsip.ru/';

export const BRIEF_SCOPE = ['Сваи и обвязка', 'SIP-пол', 'SIP-стены', 'Перегородки', 'SIP-потолок', 'Кровля', 'Окна и двери', 'Терраса или крыльцо', 'Инженерия', 'Внутренняя отделка', 'Внешняя отделка', 'Доставка'];

export const BRIEF_FIELDS = [
  'buildingType','goal','region','length','width','approxArea','floors','wallHeight','houseShape','basement','attic','roomsNotes','bedrooms','bathrooms','kitchens','utilityRooms','secondLight','staircaseOpening',
  'siteArea','cadastralNumber','terrain','soil','access','electricityOnSite','waterOnSite','sewerOnSite','deliveryDistance',
  'wallThickness','floorThickness','ceilingThickness','secondFloorThickness','secondFloorWallThickness','secondFloorWallHeight','partitionType','secondFloorPartitionType','panelFamily','panelLayout',
  'roofShape','roofType','roofCovering','ridgeAxis','ridgeHeight','eaveOverhang','gableOverhang','gableType','gableCount','flatRoofSlopeDirection','flatRoofSlopePercent','flatRoofSlopeMode',
  'windowsCount','entranceDoorsCount','interiorDoorsCount','garageDoorsCount','windowWidth','windowHeight','entranceDoorWidth','entranceDoorHeight','garageDoorWidth','garageDoorHeight','openingsNotes',
  'terrace','terraceLength','terraceWidth','terraceSide','terraceRoof','porch','porchLength','porchWidth','porchSide','porchRoof',
  'electricStage','waterStage','sewerStage','heating','waterSource','sewer','ventilation','gas','fireplace','exteriorFinish','interiorFinish','floorFinish','ceilingFinish','plinth','gutters',
  'budget','desiredStart','decisionStatus','contactMethod','contactTime','notes',
];
const BOOLEAN_FIELDS = new Set(['secondLight','staircaseOpening','terrace','porch','gas','fireplace','plinth','gutters']);
const numberOrNull = (value) => { const n = Number(String(value ?? '').replace(',', '.')); return Number.isFinite(n) && n > 0 ? n : null; };

export function normalizeBuildingBrief(raw) {
  if (!raw || typeof raw !== 'object' || Array.isArray(raw)) raw = {};
  const result = {};
  for (const field of BRIEF_FIELDS) {
    if (BOOLEAN_FIELDS.has(field)) result[field] = raw[field] === true || raw[field] === 'on';
    else result[field] = String(raw[field] ?? '').trim().slice(0, field === 'notes' || field === 'roomsNotes' || field === 'openingsNotes' ? 2000 : 250);
  }
  result.scope = BRIEF_SCOPE.filter((item) => Array.isArray(raw.scope) && raw.scope.includes(item));
  return result;
}

export function briefFromForm(values) {
  const raw = {};
  for (const field of BRIEF_FIELDS) raw[field] = values[`brief.${field}`] ?? '';
  raw.scope = BRIEF_SCOPE.filter((_, index) => values[`brief.scope.${index}`] === 'on');
  return normalizeBuildingBrief(raw);
}

export function briefReadiness(brief) {
  const missing = [];
  if (!brief?.buildingType) missing.push('тип постройки');
  if (!numberOrNull(brief?.length) || !numberOrNull(brief?.width)) missing.push('размеры');
  if (![1, 2].includes(Number(brief?.floors))) missing.push('этажность');
  if (!numberOrNull(brief?.wallHeight)) missing.push('высоту стен');
  if (!brief?.scope?.length) missing.push('состав расчёта');
  if (brief?.scope?.includes('SIP-стены') && !/^(124|174|224) мм$/.test(brief.wallThickness || '')) missing.push('толщину СИП-стен');
  if (brief?.scope?.includes('SIP-пол') && !/^(124|174|224) мм$/.test(brief.floorThickness || '')) missing.push('толщину СИП-пола');
  if (brief?.scope?.includes('SIP-потолок') && !/^(124|174|224) мм$/.test(brief.ceilingThickness || '')) missing.push('толщину СИП-потолка');
  if (brief?.scope?.includes('Кровля') && (!brief.roofShape || !brief.roofType || !brief.roofCovering || [brief.roofShape,brief.roofType,brief.roofCovering].includes('Подобрать'))) missing.push('конструкцию и покрытие кровли');
  return missing;
}

export function calculatorBrief({ client, site, lead }) {
  const a = normalizeBuildingBrief(site.buildingBrief);
  const floors = [1, 2].includes(Number(a.floors)) ? Number(a.floors) : null;
  const count = (field) => numberOrNull(a[field]) || 0;
  const area = (length, width) => numberOrNull(a[length]) && numberOrNull(a[width]) ? numberOrNull(a[length]) * numberOrNull(a[width]) : null;
  const terrace = { enabled: a.terrace, length: numberOrNull(a.terraceLength), width: numberOrNull(a.terraceWidth), area: area('terraceLength','terraceWidth'), side: a.terraceSide, roof: a.terraceRoof };
  const porch = { enabled: a.porch, length: numberOrNull(a.porchLength), width: numberOrNull(a.porchWidth), area: area('porchLength','porchWidth'), side: a.porchSide, roof: a.porchRoof };
  const platform = a.terrace ? terrace : porch;
  return {
    format: 'eft-client-brief', schemaVersion: 1, questionnaireVersion: 5, createdAt: new Date().toISOString(),
    crm: { siteId: site.id, leadId: lead.id },
    customer: { name: client.name, phone: client.phone || '', email: client.email || '', preferredContact: a.contactMethod, preferredTime: a.contactTime, consent: false },
    project: { goal: a.goal, buildingType: a.buildingType, address: site.address || a.region, region: a.region, floors, length: numberOrNull(a.length), width: numberOrNull(a.width), wallHeight: numberOrNull(a.wallHeight), approxArea: numberOrNull(a.approxArea), shape: a.houseShape, basement: a.basement, attic: a.attic, rooms: a.roomsNotes, roomCounts: { bedrooms: count('bedrooms'), bathrooms: count('bathrooms'), kitchens: count('kitchens'), utilityRooms: count('utilityRooms') }, features: [a.secondLight && 'Второй свет', a.staircaseOpening && 'Лестничный проём'].filter(Boolean) },
    site: { area: numberOrNull(a.siteArea), cadastralNumber: a.cadastralNumber, terrain: a.terrain, soil: a.soil, access: a.access, utilities: { electricity: a.electricityOnSite, water: a.waterOnSite, sewer: a.sewerOnSite } },
    scope: a.scope,
    sip: { wallThickness: a.wallThickness, floorThickness: a.floorThickness, secondFloorThickness: a.secondFloorThickness, ceilingThickness: a.ceilingThickness, partitionType: a.partitionType, panelFamily: a.panelFamily, panelLayout: a.panelLayout, secondFloor: floors === 2 ? { wallThickness: a.secondFloorWallThickness, wallHeight: numberOrNull(a.secondFloorWallHeight), partitionType: a.secondFloorPartitionType } : null },
    roof: { shape: a.roofShape, type: a.roofType, covering: a.roofCovering, ridgeAxis: a.ridgeAxis, ridgeHeight: numberOrNull(a.ridgeHeight), eaveOverhang: numberOrNull(a.eaveOverhang), gableOverhang: numberOrNull(a.gableOverhang), slopeDirection: a.roofShape === 'Плоская' ? a.flatRoofSlopeDirection : null, slopePercent: a.roofShape === 'Плоская' ? numberOrNull(a.flatRoofSlopePercent) : null, slopeMode: a.roofShape === 'Плоская' ? a.flatRoofSlopeMode : null, gableType: a.gableType, gableCount: Number(a.gableCount) || 0 },
    openings: { windows: `${count('windowsCount')} окон`, doors: `${count('entranceDoorsCount')} входных, ${count('interiorDoorsCount')} межкомнатных, ${count('garageDoorsCount')} ворот`, counts: { windows: count('windowsCount'), entranceDoors: count('entranceDoorsCount'), interiorDoors: count('interiorDoorsCount'), garageDoors: count('garageDoorsCount') }, standardSizes: { window: { width: numberOrNull(a.windowWidth), height: numberOrNull(a.windowHeight) }, entranceDoor: { width: numberOrNull(a.entranceDoorWidth), height: numberOrNull(a.entranceDoorHeight) }, garageDoor: { width: numberOrNull(a.garageDoorWidth), height: numberOrNull(a.garageDoorHeight) } }, notes: a.openingsNotes },
    extras: { items: [a.terrace && 'Терраса', a.porch && 'Крыльцо'].filter(Boolean), terrace, porch, platformSize: [platform.length, platform.width].filter(Boolean).join(' × '), platform },
    engineering: { requested: a.scope.includes('Инженерия'), electricStage: a.electricStage, waterStage: a.waterStage, sewerStage: a.sewerStage, heating: a.heating, waterSource: a.waterSource, sewer: a.sewer, ventilation: a.ventilation, gas: a.gas, fireplace: a.fireplace },
    finish: { exterior: a.exteriorFinish, interior: a.interiorFinish, floor: a.floorFinish, ceiling: a.ceilingFinish, plinth: a.plinth, gutters: a.gutters },
    delivery: { access: a.access, distance: numberOrNull(a.deliveryDistance) },
    budget: { amount: numberOrNull(a.budget), desiredStart: a.desiredStart, decisionStatus: a.decisionStatus },
    notes: a.notes, attachments: [],
    automation: { status: briefReadiness(a).length ? 'incomplete' : 'draft-ready', confirmed: [], manualReview: ['Проверить геометрию помещений, проёмы и приложенный план'], canCreateDraft: !briefReadiness(a).length, canCalculateAutomatically: false },
  };
}

export function downloadCalculatorBrief(context) {
  const brief = calculatorBrief(context);
  const blob = new Blob([JSON.stringify(brief, null, 2)], { type: 'application/json' });
  const url = URL.createObjectURL(blob);
  const link = document.createElement('a'); link.href = url; link.download = `Заявка_ЭФТ_${context.site.id.slice(0, 8)}.eft-brief.json`;
  document.body.append(link); link.click(); link.remove();
  setTimeout(() => URL.revokeObjectURL(url), 3000);
}
