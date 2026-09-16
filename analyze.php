<?php
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
function fail_json(string $m,int $c=400):never{http_response_code($c);echo json_encode(['error'=>$m],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);exit;}
if($_SERVER['REQUEST_METHOD']!=='POST')fail_json('POST required.',405);
$symbol=trim((string)($_POST['symbol']??'UNKNOWN'));
$allowed=['M1','M5','M15','M30','H1','H4','D1','UNKNOWN'];
$files=$_FILES['charts']??null;
if(!$files||!isset($files['tmp_name'])||!is_array($files['tmp_name']))fail_json('Upload up to 4 chart screenshots.');
$count=count($files['tmp_name']);if($count<1||$count>4)fail_json('Please upload between 1 and 4 screenshots.');
$apiKey=getenv('OPENAI_API_KEY');if(!$apiKey&&is_file(__DIR__.'/runtime_config.php')){$v=require __DIR__.'/runtime_config.php';if(is_string($v))$apiKey=trim($v);}if(!$apiKey)fail_json('AI engine is not connected.',503);
$payloadParts=[];$meta=[];
for($i=0;$i<$count;$i++){
 if(($files['error'][$i]??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK)fail_json('One of the uploaded images failed.');
 if(($files['size'][$i]??0)>12*1024*1024)fail_json('Each image must be 12 MB or smaller.');
 $tmp=$files['tmp_name'][$i];$mime=(new finfo(FILEINFO_MIME_TYPE))->file($tmp);if(!in_array($mime,['image/jpeg','image/png','image/webp'],true))fail_json('Only JPG, PNG and WEBP are supported.');
 $bytes=file_get_contents($tmp);if($bytes===false)fail_json('Could not read one uploaded image.',500);
 $tf=trim((string)($_POST['timeframes'][$i]??'UNKNOWN'));if(!in_array($tf,$allowed,true))$tf='UNKNOWN';
 $meta[]=['index'=>$i+1,'timeframe'=>$tf];
 $payloadParts[]=['type'=>'input_text','text'=>'CHART '.$i.' — TIMEFRAME '.$tf.'. Analyze this chart independently, then relate it to the other charts.'];
 $payloadParts[]=['type'=>'input_image','image_url'=>'data:'.$mime.';base64,'.base64_encode($bytes),'detail'=>'high'];
}
$prompt=<<<PROMPT
You are PRIMONIZER FOREX AI, a multi-timeframe visual trading tutor.

There are up to four screenshots of the SAME market, normally different timeframes. Analyze EACH screenshot independently across its ENTIRE visible chart, left-to-right, not just the newest candles. Then combine them into one top-down market story.

For every chart identify only visible evidence: HH/HL/LH/LL, BOS/CHoCH, displacement, liquidity/equal highs-lows/sweeps, bullish and bearish order-block CANDIDATES, FVG/imbalances, support/resistance, premium/discount, current price and the best visible entry/watch area.

ORDER BLOCK QUALITY IS IMPORTANT. Do NOT call every last opposite candle an order block. A valid OB CANDIDATE should have contextual evidence such as a meaningful swing/location, displacement away from it and preferably a structural break. Explain WHY it qualifies and WHY another nearby candle does not. Mark its exact visible area with normalized coordinates.

ENTRY: For each timeframe, give an entry/watch zone, confirmation trigger, invalidation and target area when these can be read from the screenshot. Never invent exact prices if the axis is unreadable. Prefer an entry ZONE rather than a fake exact number.

MULTI-TIMEFRAME CONCLUSION: Use the higher timeframe for broad structure/context and lower timeframe for refinement/trigger. Do not force agreement. If timeframes conflict, explicitly say so and explain what must happen before a lower-timeframe entry aligns with higher-timeframe structure.

COORDINATES: x/y are normalized 0..1 over the ORIGINAL image. x=0 left, x=1 right, y=0 top, y=1 bottom. Zones use x1,y1,x2,y2. Points use x,y. Do not fabricate coordinates.

Return ONLY valid JSON, no markdown fences, in this shape:
{
 "overall":{"direction":"BULLISH|BEARISH|NEUTRAL","next_move":"UP|DOWN|WAIT","confidence":0,"headline":"short conclusion","market_story":["...","...","...","..."],"entry_plan":"short multi-timeframe entry plan","invalidation":"short primary invalidation","risk_note":"short risk note"},
 "charts":[{"index":1,"timeframe":"H4","direction":"BULLISH|BEARISH|NEUTRAL","next_move":"UP|DOWN|WAIT","confidence":0,"headline":"short","current_state":"short","valid_order_block":{"status":"BULLISH|BEARISH|NONE|UNCLEAR","label":"VALID OB CANDIDATE","why":"short evidence","why_not_others":"short explanation","x":0,"y":0,"x2":0,"y2":0},"entry":{"zone":"short visible zone","confirmation":"short trigger","invalidation":"short condition","target":"short target"},"annotations":[{"type":"HH|HL|LH|LL|BOS|CHoCH|LIQUIDITY|SWEEP|OB_BULL|OB_BEAR|FVG|SUPPORT|RESISTANCE|PREMIUM|DISCOUNT|CURRENT|ENTRY|INVALIDATION|TARGET","label":"short","note":"short","x":0,"y":0,"x2":null,"y2":null}],"path":[{"direction":"UP|DOWN","x":0,"y":0,"label":"START|ENTRY|TARGET|INVALIDATION"}],"tutorial":[{"title":"Structure","text":"short"},{"title":"Liquidity","text":"short"},{"title":"Order block","text":"short"},{"title":"Entry","text":"short"}] }],
 "confluence":["short cross-timeframe fact","short cross-timeframe fact"],
 "final_diagram":{"direction":"UP|DOWN|WAIT","steps":[{"label":"CURRENT","x":0,"y":0},{"label":"ENTRY","x":0,"y":0},{"label":"TARGET","x":0,"y":0}],"why":"short explanation"}
}

Evidence labels: VISIBLE means directly readable; CANDIDATE means a reasonable chart interpretation; SCENARIO means conditional future path; INVALIDATED means its condition has occurred. Never claim guaranteed prediction or profit. Confidence is confidence in visible evidence, not probability of profit.
PROMPT;
$content=[['type'=>'input_text','text'=>$prompt],...$payloadParts];
$payload=['model'=>'gpt-5.6-luna','input'=>[['role'=>'user','content'=>$content]]];
$ch=curl_init('https://api.openai.com/v1/responses');curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_RETURNTRANSFER=>true,CURLOPT_HTTPHEADER=>['Content-Type: application/json','Authorization: Bearer '.$apiKey],CURLOPT_POSTFIELDS=>json_encode($payload),CURLOPT_TIMEOUT=>180]);$response=curl_exec($ch);$err=curl_error($ch);$status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);
if($response===false||$err)fail_json('AI connection failed. Please try again.',502);$data=json_decode($response,true);if(!is_array($data))fail_json('AI returned invalid data.',502);if($status>=400)fail_json($data['error']['message']??'AI provider rejected the request.',502);
$text=$data['output_text']??'';if(!$text&&isset($data['output']))foreach($data['output'] as $item)foreach(($item['content']??[]) as $part)if(isset($part['text']))$text.=$part['text'];if(!$text)fail_json('AI returned no analysis.',502);$text=trim($text);if(str_starts_with($text,'```'))$text=preg_replace('/^```(?:json)?\s*|\s*```$/','',$text);$analysis=json_decode($text,true);if(!is_array($analysis))fail_json('AI returned unreadable analysis. Try clearer screenshots.',502);
$analysis['overall']=$analysis['overall']??[];$analysis['charts']=is_array($analysis['charts']??null)?$analysis['charts']:[];$analysis['confluence']=is_array($analysis['confluence']??null)?$analysis['confluence']:[];$analysis['final_diagram']=$analysis['final_diagram']??[];
echo json_encode(['ok'=>true,'symbol'=>$symbol,'count'=>$count,'meta'=>$meta,'engine'=>'Primonizer Forex AI — Multi-Timeframe Visual Tutor','analysis'=>$analysis],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
