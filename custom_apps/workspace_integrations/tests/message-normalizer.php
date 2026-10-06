<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);
require __DIR__.'/../lib/Service/ServiceException.php';
require __DIR__.'/../lib/Service/MessageNormalizer.php';
use OCA\WorkspaceIntegrations\Service\MessageNormalizer;
use OCA\WorkspaceIntegrations\Service\ServiceException;
$n=new MessageNormalizer();$checks=0;
function check(bool $v,string $why):void {global $checks;if(!$v){throw new RuntimeException($why);}++$checks;}
function reject(array $p,string $why):void {global $n;try{$n->normalize($p);}catch(ServiceException){check(true,$why);return;}throw new RuntimeException('Accepted '.$why);}
$plain=$n->normalize(['text'=>'An audit event','eventId'=>'event-1']);
check($plain['card']===null&&$plain['fingerprint']===hash('sha256','An audit event'),'legacy hash');
check($n->normalize(['message'=>'An audit event'])===$n->normalize(['text'=>'An audit event']),'message alias');
$slack=['text'=>'Audit summary','blocks'=>[
 ['type'=>'section','text'=>['type'=>'mrkdwn','text'=>':moneybag: *Audit result* — `example-user` _(preview only)_']],
 ['type'=>'section','fields'=>[['type'=>'mrkdwn','text'=>"*Member:*\nexample-user"],['type'=>'mrkdwn','text'=>"*Amount:*\n10.00 EUR"]]],
 ['type'=>'context','elements'=>[['type'=>'mrkdwn','text'=>'predicates: `{"eligible":true,"segmentIds":[1,2]}`']]],
]];
$s=$n->normalize($slack);check(count($s['card']['blocks'])===3,'Slack example');
check(str_contains($s['text'],'Audit summary') && str_contains($s['text'],'10.00 EUR') && str_contains($s['text'],'predicates'),'complete Slack notification fallback');
$slack['blocks'][1]['fields'][1]['text']='Changed';check($n->normalize($slack)['fingerprint']!==$s['fingerprint'],'layout covered by hash');
$custom=$n->normalize(['title'=>'Audit result','status'=>'preview','fields'=>[['label'=>'Member','value'=>'example-user']], 'context'=>['predicates'=>['eligible'=>true]],'eventId'=>'event-2']);
check($custom['card']['blocks'][3]['elements'][0]['type']==='rich_text_preformatted','custom JSON');
check(str_contains($custom['text'],'eligible'),'custom fallback');
$rich=['type'=>'rich_text','elements'=>[
 ['type'=>'rich_text_section','elements'=>[['type'=>'text','text'=>'Hello','style'=>['bold'=>true]],['type'=>'link','url'=>'https://example.com/a','text'=>'Details'],['type'=>'emoji','name'=>'moneybag']]],
 ['type'=>'rich_text_list','style'=>'ordered','offset'=>2,'indent'=>1,'elements'=>[['type'=>'rich_text_section','elements'=>[['type'=>'text','text'=>'Item']]]]],
 ['type'=>'rich_text_quote','elements'=>[['type'=>'text','text'=>'Quote']]],
 ['type'=>'rich_text_preformatted','elements'=>[['type'=>'text','text'=>'{"ok":true}']]],
]];
$table=['type'=>'table','rows'=>[[['type'=>'raw_text','text'=>'Label'],['type'=>'raw_text','text'=>'Value']],[['type'=>'raw_text','text'=>'Result'],$rich]],'column_settings'=>[['align'=>'left','is_wrapped'=>true],['align'=>'right']]];
$all=$n->normalize(['blocks'=>[['type'=>'header','text'=>['type'=>'plain_text','text'=>'Audit']],['type'=>'divider'],['type'=>'image','image_url'=>'https://example.com/image.png','alt_text'=>'Evidence'],$rich,$table]]);
check(count($all['card']['blocks'])===5,'all major blocks');
$attachment=$n->normalize(['attachments'=>[['fallback'=>'Audit','color'=>'good','title'=>'Result','title_link'=>'https://example.com/result','text'=>'*Ready*','mrkdwn_in'=>['text'],'fields'=>[['title'=>'Amount','value'=>'10.00','short'=>true],['title'=>'Description','value'=>'Preview','short'=>false]],'footer'=>'Audit worker','ts'=>1234567890]]]);
check($attachment['card']['blocks'][0]['color']==='good','attachment color');
check(str_contains($attachment['text'],'1234567890'),'attachment timestamp');
$reordered=['status'=>'preview','title'=>'Audit'];check($n->normalize($reordered)['fingerprint']===$n->normalize(array_reverse($reordered,true))['fingerprint'],'stable property order');
foreach(['actions','input','video','file','unknown'] as $type){reject(['blocks'=>[['type'=>$type]]],$type);}
reject(['text'=>'ok','blocks'=>[['type'=>'section','text'=>['type'=>'mrkdwn','text'=>'ok'],'accessory'=>['type'=>'button']]]],'accessory');
reject(['blocks'=>[['type'=>'context','elements'=>[['type'=>'image','image_url'=>'https://example.com/a','alt_text'=>'a']]]]],'unsupported context image');
foreach(['javascript:alert(1)','data:text/html,evil','https://user:secret@example.com/a','https://files.slack.com/a','https://files-pri.slack.com/a','https://example.com/space here'] as $url){reject(['blocks'=>[['type'=>'image','image_url'=>$url,'alt_text'=>'bad']]],'bad url');}
reject(['blocks'=>[['type'=>'section','text'=>['type'=>'mrkdwn','text'=>'<javascript:alert(1)|bad>']]]],'bad markdown URL');
reject(['blocks'=>[['type'=>'section','text'=>['type'=>'mrkdwn','text'=>'<@U123>']]]],'unresolved Slack mention');
reject(['title'=>'x','blocks'=>[['type'=>'divider']]],'mixed format');
reject(['text'=>'a','message'=>'b'],'ambiguous aliases');
reject(['text'=>str_repeat('a',16001)],'text bound');
reject(['blocks'=>array_fill(0,51,['type'=>'divider'])],'block bound');
reject(['text'=>"bad\x00text"],'control');
reject(['text'=>"\xff"],'invalid UTF-8');
reject(['blocks'=>[['type'=>'table','rows'=>[[['type'=>'raw_text','text'=>'a']],[['type'=>'raw_text','text'=>'a'],['type'=>'raw_text','text'=>'b']]]]]],'ragged table');
reject(['blocks'=>[['type'=>'rich_text','elements'=>[['type'=>'rich_text_section','elements'=>[['type'=>'user','user_id'=>'U123']]]]]]],'Slack user IDs');
reject(['attachments'=>[['color'=>'url(evil)','text'=>'Hello']]],'CSS injection');
reject(['text'=>'ok','attachments'=>[['text'=>'x','callback_id'=>'action']]],'interactive attachment');
reject(['text'=>'ok','unexpected'=>true],'unknown root key');
reject(['blocks'=>[]],'empty blocks');
reject(['context'=>['x'=>str_repeat('a',65000)],'title'=>'x'],'payload limit');
$spaces=$n->normalize(['blocks'=>[['type'=>'rich_text','elements'=>[['type'=>'rich_text_section','elements'=>[['type'=>'text','text'=>'A'],['type'=>'text','text'=>' '],['type'=>'text','text'=>'B']]]]]]]);
check($spaces['text']==='A B','inline whitespace preserved');
$linkFallback=$n->normalize(['blocks'=>[['type'=>'rich_text','elements'=>[['type'=>'rich_text_section','elements'=>[['type'=>'link','text'=>'Details','url'=>'https://example.com/a']]]]]]]);
check(str_contains($linkFallback['text'],'https://example.com/a'),'links preserved in fallback');
reject(['text'=>'x','blocks'=>null],'null layout');
reject(['blocks'=>[['type'=>[]]]],'invalid block type');
reject(['blocks'=>[['type'=>'rich_text','elements'=>[['type'=>'rich_text_section','elements'=>[['type'=>[]]]]]]]],'invalid inline type');
reject(['blocks'=>[['type'=>'table','rows'=>[['not a cell']]]]],'invalid cell type');
reject(['blocks'=>[['type'=>'rich_text','elements'=>['not an element']]]],'invalid rich type');
reject(['text'=>'short','blocks'=>array_fill(0,6,['type'=>'section','text'=>['type'=>'plain_text','text'=>str_repeat('a',3000)]])],'complete fallback bound');
$originalShape=$n->normalize(['text'=>':moneybag: *Transfer bonus 15% (531)* — `sample-user` (wallet id 100) _(shadow — not granted)_','blocks'=>[
 ['type'=>'section','text'=>['type'=>'mrkdwn','text'=>':moneybag: *Transfer bonus 15% (531)* — `sample-user` (wallet id 100) _(shadow — not granted)_']],
 ['type'=>'section','fields'=>array_map(fn($label)=>['type'=>'mrkdwn','text'=>'*'.$label.':*'."\n".'Example'],['Member','Decision','Provider','Amount','Brand','Event ts'])],
 ['type'=>'context','elements'=>[['type'=>'mrkdwn','text'=>'predicates: `{"memberFound":true,"bonusActive":true,"memberSegmentIds":[1,2,3]}`']]],
]]);
check(count($originalShape['card']['blocks'][1]['fields'])===6&&str_contains($originalShape['text'],'memberSegmentIds'),'sanitized six-field worker shape');
$many=['blocks'=>array_fill(0,20,['type'=>'rich_text','elements'=>array_fill(0,30,['type'=>'rich_text_section','elements'=>[['type'=>'text','text'=>'a'],['type'=>'text','text'=>'b']]])])];
try{$n->normalize($many);throw new RuntimeException('Accepted excessive nodes');}catch(ServiceException $e){check(str_contains($e->getMessage(),'nodes'),'node budget');}
echo "Message normalizer: $checks checks passed\n";
