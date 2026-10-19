import test from 'node:test';
import assert from 'node:assert/strict';
import { createDemoState } from '../src/app/model.js';
import { prepareDeletion } from '../src/app/deletion.js';

test('удаление клиента убирает связанные записи, сохраняя остальных клиентов', () => {
  const state=createDemoState(); const client=state.clients[0];
  const siteIds=new Set(state.sites.filter((site)=>site.clientId===client.id).map((site)=>site.id));
  const result=prepareDeletion(state,'clients',client.id);
  assert.equal(result.domain,'workspace');
  assert.equal(result.snapshot.clients.some((item)=>item.id===client.id),false);
  assert.equal(result.snapshot.sites.some((item)=>siteIds.has(item.id)),false);
  assert.equal(result.snapshot.leads.some((item)=>siteIds.has(item.siteId)),false);
  assert.equal(state.clients.some((item)=>item.id===client.id),true);
  assert.equal(result.snapshot.clients.length,state.clients.length-1);
});

test('материал с историей нельзя удалить отдельно от документов', () => {
  const state=createDemoState();
  const item=state.materials[0];
  state.stockDocuments.push({id:'history-test',lines:[{itemId:item.id}]});
  assert.throws(()=>prepareDeletion(state,'materials',item.id),/документы|движениях/);
});
