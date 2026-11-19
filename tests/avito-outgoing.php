<?php
declare(strict_types=1);
require __DIR__.'/../server/beget-api/avito.php';
function crm_text(mixed $text,int $limit): string { return mb_substr((string)$text,0,$limit); }
function check_avito(bool $ok,string $label): void { if (!$ok) throw new RuntimeException($label); }
$photo=['mime'=>'image/jpeg','name'=>'photo.jpg'];$pdf=['mime'=>'application/pdf','name'=>'Проект.pdf'];
$calls=[];
$send=static function(string $path,array $body) use (&$calls): array { $calls[]=[$path,$body];return ['id'=>'sent-'.count($calls)]; };
$sent=crm_avito_send_parts('123','chat','Текст',[$photo,$pdf],$send,static fn($file)=>'image-12',static fn($file)=>'https://crm.eftsip.ru/download');
check_avito($sent['error']==='' && count($sent['sentIds'])===3,'All parts must be confirmed');
check_avito($calls[1][0]==='/messenger/v1/accounts/123/chats/chat/messages/image' && $calls[1][1]===['image_id'=>'image-12'],'Photo must use native image endpoint');
check_avito(str_contains($calls[2][1]['message']['text'],'Проект.pdf') && str_contains($calls[2][1]['message']['text'],'https://crm.eftsip.ru/download'),'Document must include name and download link');
$calls=[];
$sent=crm_avito_send_parts('123','chat','',[$photo],$send,static fn($file)=>'image-12',static fn($file)=>'unused');
check_avito(count($calls)===1 && $sent['error']==='','Photo-only message must work');
$calls=[];
$sent=crm_avito_send_parts('123','chat','text',[$photo],$send,static function($file): string {throw new RuntimeException('upload failed');},static fn($file)=>'unused');
check_avito(!$calls && $sent['error']!=='' && !$sent['sentIds'],'Upload failure must not send text');
$calls=[];
$partial=crm_avito_send_parts('123','chat','text',[$photo],static function($path,$body) use (&$calls) {if($calls)throw new RuntimeException('provider failed');$calls[]=$path;return ['id'=>'accepted'];},static fn($file)=>'image',static fn($file)=>'unused');
check_avito(crm_avito_delivery($partial)['status']==='error' && $partial['sentIds']===['accepted'],'Partial failure must retain provider ids and show error');
$meta=crm_avito_chat_metadata(['users'=>[['id'=>123,'name'=>'Company','public_user_profile'=>['avatar'=>['default'=>'https://www.avito.st/company.png']]],['id'=>456,'name'=>'Клиент','public_user_profile'=>['avatar'=>['images'=>['256x256'=>'https://www.avito.st/client.png']]]]],'context'=>['value'=>['images'=>['main'=>['image'=>'https://www.avito.st/house.jpg']]]]],'123');
check_avito($meta['sender']==='Клиент' && $meta['avatar']==='https://www.avito.st/client.png','Use client avatar, never company or listing image');
foreach (['javascript:alert(1)','https://avito.st.evil.test/avatar','http://www.avito.st/a','https://u:p@www.avito.st/a','https://www.avito.st:8080/a','https://127.0.0.1/a'] as $url) check_avito(crm_avito_avatar_url($url)==='','Unsafe avatar rejected');
echo "Avito native photos, document links, photo-only send, partial failures and avatar selection passed\n";
