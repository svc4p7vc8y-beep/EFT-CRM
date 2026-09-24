import test from 'node:test';
import assert from 'node:assert/strict';
import { applyCommand, createDemoState, validateState } from '../src/app/model.js';
import { stockFor } from '../src/app/operations.js';
const prepare=()=>{let s=createDemoState();const m=s.materials[0];return applyCommand(s,'material.save',{...m,tracked:true});};
const post=(s,kind,qty,price=0,extra={})=>applyCommand(s,'stock.post',{kind,date:'2026-09-23',target:'Учебный документ',updatePrice:true,lines:[{itemId:s.materials[0].id,quantity:qty,price}],...extra});
test('приход обновляет прайс, расход списывается по средней цене, история неизменна',()=>{
  let s=prepare();s=post(s,'receipt',10,100);s=post(s,'receipt',10,200);
  assert.equal(s.materials[0].price,200);assert.equal(s.stockDocuments[0].lines[0].price,100);
  s=post(s,'issue',5,999);assert.equal(s.stockDocuments.at(-1).lines[0].price,150);
  assert.deepEqual(stockFor(s,s.materials[0].id),{quantity:15,value:2250,average:150});
  assert.throws(()=>post(s,'issue',16),/Недостаточно/);
  assert.throws(()=>applyCommand(s,'material.save',{...s.materials[0],tracked:false}),/остаток/);
  assert.throws(()=>applyCommand(s,'material.save',{...s.materials[0],unit:'кг'}),/Единицу/);
});
test('покупка на объект обновляет цену, но не образует складской запас',()=>{
  const s=post(createDemoState(),'direct',4,250);assert.equal(stockFor(s,s.materials[0].id).quantity,0);assert.equal(s.materials[0].price,250);
  assert.throws(()=>post(createDemoState(),'receipt',4,250),/складской/);
});
test('закупка принимается частями, повторный приход сверх заказа запрещён',()=>{
  let s=prepare();s=applyCommand(s,'supplier.save',{name:'Учебный поставщик'});
  s=applyCommand(s,'purchase.create',{supplierId:s.suppliers[0].id,date:'2026-09-23',lines:[{itemId:s.materials[0].id,quantity:10,price:100}]});
  const extra={purchaseId:s.purchases[0].id,supplierId:s.suppliers[0].id};
  s=post(s,'receipt',4,100,extra);s=post(s,'receipt',6,100,extra);
  assert.equal(stockFor(s,s.materials[0].id).quantity,10);assert.throws(()=>post(s,'receipt',1,100,extra),/превышает/);
});
test('выдача инструмента исключает двойную выдачу и сохраняет историю возврата',()=>{
  let s=applyCommand(createDemoState(),'tool.save',{name:'Шуруповёрт',serial:'T-001',home:'field',price:10000});const id=s.tools[0].id;
  s=applyCommand(s,'tool.transfer',{id,kind:'issue',date:'2026-09-23',holderType:'employee',holderId:'worker-1'});
  assert.throws(()=>applyCommand(s,'tool.transfer',{id,kind:'issue',date:'2026-09-23',holderType:'employee',holderId:'worker-2'}),/уже выдан/);
  s=applyCommand(s,'tool.transfer',{id,kind:'return',date:'2026-09-24'});assert.equal(s.tools[0].holderId,'');assert.equal(s.toolEvents.length,2);assert.equal(s.toolEvents[1].holder,'Сотрудник 01');
});
test('табель обновляет отметку дня без двойного учёта часов',()=>{
  let s=applyCommand(createDemoState(),'attendance.save',{employeeId:'worker-1',date:'2026-09-23',kind:'work',hours:8});
  s=applyCommand(s,'attendance.save',{employeeId:'worker-1',date:'2026-09-23',kind:'work',hours:6});assert.equal(s.attendance.length,1);assert.equal(s.attendance[0].hours,6);
  assert.throws(()=>applyCommand(s,'attendance.save',{employeeId:'worker-1',date:'2026-09-23',kind:'work',hours:25}),/количество/);
  assert.throws(()=>applyCommand(s,'attendance.save',{employeeId:'worker-1',date:'2026-02-30',kind:'work',hours:8}));
});
test('ставка, фиксированный аванс и режим по дням сохраняются',()=>{
  let s=createDemoState();const rows=s.employees.map((p)=>({id:p.id,attendanceMode:p.id==='worker-1'?'days':'hours',payRate:p.id==='worker-1'?3500:500,advanceAmount:p.id==='worker-1'?12000:0}));
  s=applyCommand(s,'employee.rates.save',{rows});
  s=applyCommand(s,'attendance.save',{employeeId:'worker-1',date:'2026-09-23',kind:'work',hours:18});
  assert.equal(s.employees.find((p)=>p.id==='worker-1').payRate,3500);
  assert.equal(s.employees.find((p)=>p.id==='worker-1').advanceAmount,12000);
  assert.equal(s.attendance[0].hours,1);validateState(s);
});
test('общая задача по шаблону доступна всем сотрудникам, чек-лист хранит снимок',()=>{
  let s=createDemoState();const t=s.taskTemplates[0];
  s=applyCommand(s,'task.create',{templateId:t.id,title:t.title,orderId:'',assigneeId:'manager-1',dueAt:'2026-09-24T10:00',quantity:1,completedQty:0,unit:'задача',priority:'normal'});
  assert.equal(s.tasks[0].checklist.length,2);
  s=applyCommand(s,'template.save',{...t,title:'Новое название',checklist:'Другая проверка'});
  assert.equal(s.tasks[0].title,t.title);assert.equal(s.tasks[0].checklist.length,2);
  s=applyCommand(s,'activity.create',{siteId:'',taskId:s.tasks[0].id,type:'note',text:'Комментарий'});assert.equal(s.activities[0].text,'Комментарий');validateState(s);
});
test('миграция добавляет каталог без замены существующих записей',()=>{
  const s=createDemoState();delete s.materials;delete s.attendance;delete s.taskTemplates;
  const loaded=validateState(s);assert.equal(loaded.materials.length,299);assert.equal(loaded.tasks.length,6);assert.equal(loaded.materials.filter((m)=>m.tracked).length,0);
});
test('карточка клиента хранит настраиваемые действия и вложения объекта',()=>{
  let s=createDemoState();
  s=applyCommand(s,'client-action.save',{title:'Запросить фото участка',active:true});
  assert.equal(s.clientActions.at(-1).title,'Запросить фото участка');
  s=applyCommand(s,'attachment.add',{siteId:'site-1',name:'участок.jpg',type:'image/jpeg',size:4,dataUrl:'data:image/jpeg;base64,AAAA'});
  assert.equal(s.attachments.length,1);assert.equal(s.attachments[0].siteId,'site-1');validateState(s);
  assert.throws(()=>applyCommand(s,'attachment.add',{siteId:'missing',name:'x.txt',type:'text/plain',size:3,dataUrl:'data:text/plain;base64,QQ=='}),/Объект/);
});
test('потребность снабжения хранит несколько безопасных ссылок и меняет статус',()=>{
  let s=applyCommand(createDemoState(),'need.save',{name:'Диски для циркулярной пилы',kind:'consumable',destination:'production',quantity:5,unit:'шт',priority:'urgent',status:'requested',neededBy:'2026-09-30',requestedBy:'Сотрудник 01',links:['https://example.com/disk-1','https://shop.example/disk-2'],note:'Для участка раскроя'});
  const need=s.supplyNeeds[0];assert.equal(need.links.length,2);assert.equal(need.priority,'urgent');
  s=applyCommand(s,'need.save',{...need,status:'ordered'});assert.equal(s.supplyNeeds[0].status,'ordered');validateState(s);
  s=applyCommand(s,'need.save',{...s.supplyNeeds[0],status:'rejected'});assert.equal(s.supplyNeeds[0].status,'rejected');validateState(s);
  assert.throws(()=>applyCommand(s,'need.save',{...need,id:'',links:['javascript:alert(1)']}),/http/);
});
test('логистика хранит рейс и проверяет связь заказа с объектом',()=>{
  const source=createDemoState();
  const state=applyCommand(source,'logistics.save',{siteId:'site-1',orderId:'order-1',plannedAt:'2026-10-15T09:00',deliveryWindow:'09:00–12:00',vehicle:'ГАЗель А123ВС',driverId:'worker-1',status:'in_transit',carrier:'ЭФТ',loadingAddress:'Производство',unloadingAddress:'Истра',note:'Домокомплект'});
  assert.equal(state.logistics[0].status,'in_transit');assert.equal(state.logistics[0].siteId,'site-1');
  assert.throws(()=>applyCommand(state,'logistics.save',{siteId:'site-2',orderId:'order-1',plannedAt:'2026-10-15T09:00',vehicle:'ГАЗель',status:'planned'}),/другому объекту/);
});
test('центр общения хранит канал, направление и отметку прочтения',()=>{
  let state=createDemoState();
  state=applyCommand(state,'communication.send',{siteId:'site-1',channel:'telegram',text:'Подтверждаем встречу',authorId:'manager-1'});
  assert.equal(state.activities[0].channel,'telegram');assert.equal(state.activities[0].direction,'outgoing');assert.equal(state.activities[0].read,true);
  const incoming=state.activities.find((item)=>item.siteId==='site-1'&&item.direction==='incoming');assert.equal(incoming.read,false);
  state=applyCommand(state,'communication.read',{siteId:'site-1',channel:'telegram'});assert.equal(state.activities.filter((item)=>item.siteId==='site-1'&&item.channel==='telegram').every((item)=>item.read),true);validateState(state);
  assert.throws(()=>applyCommand(state,'communication.send',{siteId:'missing',channel:'telegram',text:'Тест'}),/объект/);
});
