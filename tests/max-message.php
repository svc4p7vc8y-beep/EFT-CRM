<?php
declare(strict_types=1);
require __DIR__ . '/../server/beget-api/max_media.php';
function verify(bool $condition): void { if (!$condition) throw new RuntimeException('MAX message normalization failed'); }
$file = ['type'=>'file','filename'=>'project.pdf','payload'=>['url'=>'https://files.max.ru/project']];
$result = crm_max_message_body(['body'=>['mid'=>'outer','text'=>'Проект'], 'link'=>['type'=>'forward','message'=>['mid'=>'original','text'=>'Дом','attachments'=>[$file]]]]);
verify($result['mid']==='outer' && $result['text']==="Проект\nДом" && $result['attachments']===[$file]);
$reply = crm_max_message_body(['body'=>['mid'=>'reply','text'=>'Ответ'], 'link'=>['type'=>'reply','message'=>['text'=>'Старый файл','attachments'=>[$file]]]]);
verify($reply['text']==='Ответ' && $reply['attachments']===[]);
$share = crm_max_message_body(['body'=>['mid'=>'share','attachments'=>[['type'=>'share','payload'=>['url'=>'https://example.com/project']],['type'=>'share','payload'=>['url'=>'javascript:alert(1)']]]]]);
verify($share['text']==='https://example.com/project');
echo "MAX forward, reply and shared-link checks passed\n";
