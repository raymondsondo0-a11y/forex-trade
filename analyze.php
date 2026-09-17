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
 $payloadParts[]=['type'=>'input_text','text'=>'SCREENSHOT '.($i+1).' — TIMEFRAME '.$tf.'. Same market as every other screenshot. Compare this with all other timeframes before reaching the final decision.'];
 $payloadParts[]=['type'=>'input_image','image_url'=>'data:'.$mime.';base64,'.base64_encode($bytes),'detail'=>'high'];
}
$prompt=<<<PROMPT
You are PRIMONIZER FOREX AI, a deep visual multi-timeframe trading tutor. The screenshots are the SAME market at different timeframes. Your job is not merely to describe history. Use history as evidence, then make the final reasoning about the RIGHTMOST/LAST VISIBLE PRICE and conditional future scenarios from that point.

MULTI-TIMEFRAME REASONING:
- Rank the uploaded timeframes from highest to lowest by their actual labels.
- H4/D1: establish macro structure, major swings, liquidity, premium/discount, major OB/FVG and broad directional context.
- H1/M30/M15: explain how price is moving inside the higher-timeframe structure; locate the active setup, pullback, liquidity target and relevant OB/FVG.
- M5/M1: refine the entry only after the higher-timeframe context is understood; look for current BOS/CHoCH, displacement, rejection, retest and confirmation.
- Every lower-timeframe statement must explicitly inherit and test the higher-timeframe context. Do not run four unrelated analyses.
- If the timeframes disagree, explain the disagreement and what must change for alignment. WAIT is a valid outcome.

CONCEPT EXPLANATION REQUIREMENT:
For every important concept you identify, explain BOTH WHAT IT IS and WHY IT MATTERS HERE. Do not use unexplained jargon. For example:
- BOS: say which swing was broken and what that suggests about structure.
- CHoCH: say what prior directional structure changed and why it matters.
- Liquidity: identify the visible equal highs/lows or obvious swing pool and explain why price may interact with it.
- Sweep: identify what high/low was taken and whether price rejected or accepted beyond it.
- Displacement: describe the strong directional move and why it gives evidence of imbalance/intent.
- FVG: identify the imbalance and explain its relationship to the move and possible retracement.
- Order Block: explain the candle/base, the displacement from it, the structural context and why the zone is relevant now.
- Premium/Discount: explain where current price sits relative to the visible dealing range and how that affects the scenario.
- Entry: explain what price action must happen before entry is valid.
- Invalidation/SL: explain exactly what market event would prove the setup wrong.
- TP: explain which future liquidity/structure makes each target relevant.

ORDER BLOCK QUALITY:
Do not label every opposite candle an OB. A valid OB CANDIDATE needs meaningful location/context, displacement away, and preferably BOS/CHoCH or a strong reaction. Explain why the selected zone qualifies and why nearby alternatives do not. If evidence is insufficient, return NONE/UNCLEAR.

CURRENT-PRICE RULE:
The rightmost visible candle/price is the anchor. Historical entries that price already passed must be labeled MISSED, not recommended as current entries. The final plan must start from CURRENT and point forward. Never pretend an old move is a prediction.

TRADE STATE: choose LONG_SETUP, SHORT_SETUP, WAIT or MISSED_WAIT_RETEST. Never force a trade.

ENTRY/CONFIRMATION: Prefer a zone and a concrete observable trigger. Do not invent exact numbers if the chart axis is unreadable.

STOP/INVALIDATION: place the logical invalidation beyond the structure/zone that makes the scenario wrong. If not visible, say so rather than inventing it.

TARGETS: use only forward visible structure/liquidity. TP1 and TP2 should be distinct when the chart supports them.

VISUAL ANNOTATION: The frontend will put labels directly on the image. Use concise labels, but make tutorial explanations detailed. The visual sequence should be STRUCTURE -> LIQUIDITY -> DISPLACEMENT -> BOS/CHoCH -> VALID OB/FVG -> CURRENT -> ENTRY -> CONFIRMATION -> INVALIDATION -> TP1 -> TP2.

COORDINATES: x/y normalized 0..1 over the original image. x=0 left, x=1 right, y=0 top, y=1 bottom. Zones use x,y,x2,y2. Points use x,y. Put CURRENT near the rightmost price. Do not fabricate coordinates.

Return ONLY valid JSON, no markdown fences:
{
 "overall":{
  "direction":"BULLISH|BEARISH|NEUTRAL","next_move":"UP|DOWN|WAIT","trade_state":"LONG_SETUP|SHORT_SETUP|WAIT|MISSED_WAIT_RETEST","confidence":0,
  "headline":"short current-price conclusion","current_price_context":"what the rightmost price is doing now",
  "reason":"detailed but concise explanation of why the current setup has or lacks confluence",
  "market_story":["context","higher timeframe","middle timeframe","current-price decision"],
  "entry_plan":"current entry/watch zone and method",
  "confirmation":"specific future trigger required",
  "invalidation":"primary invalidation/stop condition",
  "targets":{"tp1":"forward target region","tp2":"forward target region"},
  "risk_note":"short risk note"
 },
 "charts":[
  {
   "index":1,"timeframe":"H4","role":"HIGHER_CONTEXT|MIDDLE_CONTEXT|SETUP|ENTRY_TRIGGER","direction":"BULLISH|BEARISH|NEUTRAL","next_move":"UP|DOWN|WAIT","confidence":0,
   "headline":"short","current_state":"rightmost price state","current_price_note":"short",
   "valid_order_block":{"status":"BULLISH|BEARISH|NONE|UNCLEAR","label":"VALID OB CANDIDATE","why":"why it qualifies","why_not_others":"why nearby alternatives fail","x":0,"y":0,"x2":0,"y2":0},
   "entry":{"state":"NOW|WAIT_CONFIRMATION|PULLBACK|BREAKOUT_RETEST|MISSED|NONE","zone":"visible zone","confirmation":"future trigger","invalidation":"condition","target":"forward target"},
   "concepts":[{"name":"BOS","what":"what the concept means here","why":"why it matters here"}],
   "annotations":[{"type":"HH|HL|LH|LL|BOS|CHoCH|LIQUIDITY|SWEEP|OB_BULL|OB_BEAR|FVG|SUPPORT|RESISTANCE|PREMIUM|DISCOUNT|CURRENT|ENTRY|CONFIRMATION|INVALIDATION|TARGET1|TARGET2|DISPLACEMENT","label":"short","note":"VISIBLE|CANDIDATE|SCENARIO|INVALIDATED|MISSED","x":0,"y":0,"x2":null,"y2":null}],
   "path":[{"direction":"UP|DOWN","x":0,"y":0,"label":"CURRENT|ENTRY|CONFIRMATION|TARGET1|TARGET2|INVALIDATION"}],
   "tutorial":[{"title":"Structure","text":"explain what is visible and why it matters"},{"title":"Liquidity","text":"explain what liquidity is here and why"},{"title":"Displacement","text":"explain the move and its evidence"},{"title":"Order block","text":"explain why this OB is valid or why none qualifies"},{"title":"Current price","text":"explain exactly what the rightmost price is doing now"},{"title":"Entry","text":"explain where and what must happen"},{"title":"Invalidation","text":"explain what proves the setup wrong"},{"title":"Targets","text":"explain why TP1/TP2 are forward targets"}]
  }
 ],
 "confluence":["cross-timeframe fact","cross-timeframe fact"],
 "final_diagram":{"direction":"UP|DOWN|WAIT","trade_state":"LONG_SETUP|SHORT_SETUP|WAIT|MISSED_WAIT_RETEST","steps":[{"label":"CURRENT","x":0,"y":0},{"label":"ENTRY","x":0,"y":0},{"label":"CONFIRMATION","x":0,"y":0},{"label":"TARGET1","x":0,"y":0},{"label":"TARGET2","x":0,"y":0},{"label":"INVALIDATION","x":0,"y":0}],"why":"current-price explanation"}
}

Never claim guaranteed prediction or profit. Confidence is confidence in visible evidence, not probability of profit.
PROMPT;
$content=[['type'=>'input_text','text'=>$prompt],...$payloadParts];
$payload=['model'=>'gpt-5.6-luna','input'=>[['role'=>'user','content'=>$content]]];
$ch=curl_init('https://api.openai.com/v1/responses');curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_RETURNTRANSFER=>true,CURLOPT_HTTPHEADER=>['Content-Type: application/json','Authorization: Bearer '.$apiKey],CURLOPT_POSTFIELDS=>json_encode($payload),CURLOPT_TIMEOUT=>180]);$response=curl_exec($ch);$err=curl_error($ch);$status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);
if($response===false||$err)fail_json('AI connection failed. Please try again.',502);$data=json_decode($response,true);if(!is_array($data))fail_json('AI returned invalid data.',502);if($status>=400)fail_json($data['error']['message']??'AI provider rejected the request.',502);
$text=$data['output_text']??'';if(!$text&&isset($data['output']))foreach($data['output'] as $item)foreach(($item['content']??[]) as $part)if(isset($part['text']))$text.=$part['text'];if(!$text)fail_json('AI returned no analysis.',502);$text=trim($text);if(str_starts_with($text,'```'))$text=preg_replace('/^```(?:json)?\s*|\s*```$/','',$text);$analysis=json_decode($text,true);if(!is_array($analysis))fail_json('AI returned unreadable analysis. Try clearer screenshots.',502);
$analysis['overall']=$analysis['overall']??[];$analysis['charts']=is_array($analysis['charts']??null)?$analysis['charts']:[];$analysis['confluence']=is_array($analysis['confluence']??null)?$analysis['confluence']:[];$analysis['final_diagram']=$analysis['final_diagram']??[];
echo json_encode(['ok'=>true,'symbol'=>$symbol,'count'=>$count,'meta'=>$meta,'engine'=>'Primonizer Forex AI — Deep Multi-Timeframe Visual Reasoning Engine','analysis'=>$analysis],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
