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
