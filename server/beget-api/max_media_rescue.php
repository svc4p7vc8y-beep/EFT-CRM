<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/max_media.php';

crm_schema_ensure_v30();
$config = crm_integration_config('max');
$rows = crm_db()->query("SELECT id, external_key, attachments_json, is_ignored FROM crm_communications WHERE channel='max' AND direction='incoming' ORDER BY occurred_at DESC LIMIT 500")->fetchAll();
$counts = ['messages' => count($rows), 'recovered' => 0, 'remaining' => 0, 'errors' => 0, 'ignored_with_images' => 0, 'failed_hosts' => []];
foreach ($rows as $row) {
    try {
        $result = crm_max_recover_images_for_row($row, $config);
        $counts['recovered'] += $result['recovered'];
        $counts['remaining'] += $result['remaining'];
        foreach ($result['failedHosts'] ?? [] as $host) $counts['failed_hosts'][$host] = ($counts['failed_hosts'][$host] ?? 0) + 1;
        if ($row['is_ignored'] && ($result['recovered'] || $result['remaining'])) $counts['ignored_with_images']++;
    } catch (Throwable $error) {
        $counts['errors']++;
    }
}
echo json_encode($counts, JSON_UNESCAPED_SLASHES) . PHP_EOL;
