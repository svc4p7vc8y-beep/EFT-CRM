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
  assert.match(bootstrap, /httponly' => true/);
  assert.match(bootstrap, /samesite' => 'Lax'/);
});

test('автопубликация Beget не передаёт секретную конфигурацию', () => {
  const workflow = read('.github/workflows/beget.yml');
  const ignore = read('.gitignore');
  const entrypoint = read('server/beget-api/index.php');
  assert.match(workflow, /workflow_dispatch/);
  assert.match(workflow, /VITE_CRM_API_URL: \/api\//);
  assert.match(workflow, /beget-api\/index\.php/);
  assert.match(workflow, /--exclude='api\/config\.local\.php'/);
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
