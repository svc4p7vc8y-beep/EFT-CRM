import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

const read = (path) => readFileSync(new URL(`../${path}`, import.meta.url), 'utf8');

test('сервер CRM использует отдельные таблицы и закрывает финансовые поля правами', () => {
  const schema = read('server/beget-api/schema.sql');
  const api = read('server/beget-api/api.php');
  const bootstrap = read('server/beget-api/bootstrap.php');
  assert.match(schema, /CREATE TABLE IF NOT EXISTS crm_users/);
  assert.match(schema, /pay_rate_cents/);
  assert.match(schema, /avatar_key/);
  assert.doesNotMatch(schema, /CREATE TABLE IF NOT EXISTS eft_/);
  assert.match(api, /crm_require_capability\('finance\.view'\)/);
  assert.match(api, /finance\.unlock/);
  assert.match(api, /crm_require_finance_unlocked/);
  assert.match(api, /users\.save/);
  assert.match(schema, /CREATE TABLE IF NOT EXISTS crm_settings/);
  assert.match(schema, /CREATE TABLE IF NOT EXISTS crm_orders/);
  assert.match(schema, /checklist_json/);
  assert.match(api, /workspace\.save/);
  assert.match(bootstrap, /workspace_conflict/);
  assert.match(bootstrap, /function crm_public_workspace/);
  assert.match(bootstrap, /function crm_workspace_for_user/);
  assert.match(bootstrap, /function crm_workspace_save/);
  assert.doesNotMatch(api, /password.*911/);
  assert.match(api, /Можно заполнять только свой табель/);
  assert.match(bootstrap, /if \(\$includeFinance\)/);
  assert.match(api, /employees\.save/);
  assert.match(api, /attendance\.list/);
  assert.match(api, /crews\.save/);
  assert.match(api, /employees\.photo/);
  assert.match(api, /is_uploaded_file/);
  assert.match(api, /5 \* 1024 \* 1024/);
  assert.match(api, /getimagesize/);
  assert.match(api, /move_uploaded_file/);
  assert.match(bootstrap, /function crm_avatar_key/);
  assert.match(bootstrap, /function crm_public_crews/);
  assert.match(schema, /CREATE TABLE IF NOT EXISTS crm_materials/);
  assert.match(schema, /CREATE TABLE IF NOT EXISTS crm_supply_needs/);
  assert.match(schema, /CREATE TABLE IF NOT EXISTS crm_stock_documents/);
  assert.match(schema, /CREATE TABLE IF NOT EXISTS crm_tools/);
  assert.match(schema, /construction_stage_id CHAR\(36\)/);
  assert.match(schema, /CREATE TABLE IF NOT EXISTS crm_logistics/);
  assert.match(api, /inventory\.save/);
  assert.match(api, /crm_require_capability\('procurement\.manage'\)/);
  assert.match(bootstrap, /function crm_public_inventory/);
  assert.match(bootstrap, /function crm_inventory_save/);
  assert.match(bootstrap, /'constructionStageId' => \$row\['construction_stage_id'\]/);
  assert.match(bootstrap, /'logistics' => \$logistics/);
  assert.match(bootstrap, /inventory_conflict/);
  assert.match(bootstrap, /httponly' => true/);
  assert.match(bootstrap, /samesite' => 'Lax'/);
  assert.match(api, /communications\.send/);
  assert.match(api, /connectors\.status/);
  assert.match(api, /25\*1024\*1024/);
  assert.match(bootstrap, /function crm_connector_statuses/);
  assert.match(bootstrap, /function crm_dispatch_message/);
  assert.match(schema, /delivery_status/);
});

test('автопубликация Beget не передаёт секретную конфигурацию', () => {
  const workflow = read('.github/workflows/beget.yml');
  const ignore = read('.gitignore');
  const entrypoint = read('server/beget-api/index.php');
  assert.match(workflow, /workflow_dispatch/);
  assert.match(workflow, /VITE_CRM_API_URL: \/api\//);
  assert.match(workflow, /beget-api\/index\.php/);
  assert.match(workflow, /--exclude='api\/config\.local\.php'/);
  assert.match(workflow, /uploads\/communications/);
  assert.match(workflow, /test -f '.+api\/config\.local\.php'/);
  assert.match(ignore, /server\/\*\*\/config\.local\.php/);
  assert.match(entrypoint, /require __DIR__ \. '\/api\.php'/);
});

test('одноразовый установщик закрывается после создания конфигурации', () => {
  const installer = read('server/beget-api/install.php');
  assert.match(installer, /is_file\(\$configPath\)/);
  assert.match(installer, /hash_equals\(\(string\)\$_SESSION\['install_csrf'\]/);
  assert.match(installer, /password_hash\(\$adminPassword, PASSWORD_DEFAULT\)/);
  assert.match(installer, /rename\(\$temporaryPath, \$configPath\)/);
  assert.doesNotMatch(installer, /echo\s+\$dbPassword/);
});

test('закупки подтверждают серверное сохранение до закрытия формы', () => {
  const app = read('src/app/App.jsx');
  const inventory = read('src/features/Inventory.jsx');
  assert.match(app, /const persistence = inventorySaveQueue\.current/);
  assert.match(app, /return persistence\.then\(\(\) => next\)/);
  assert.match(inventory, /async function commit\(action,payload\) \{ await command\(action,payload\); close\(\)/);
  assert.match(inventory, /Сохранено в базе данных/);
});

test('серверные закупки не зависят от локальной копии браузера', () => {
  const app = read('src/app/App.jsx');
  assert.match(app, /if \(!coreServerActions\.has\(action\) && !inventoryServerActions\.has\(action\)\) replace\(next\)/);
  assert.doesNotMatch(app, /applyCommand\(state, action, payload\); replace\(next\)/);
});
