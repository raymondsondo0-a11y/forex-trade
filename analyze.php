<?php
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
function fail_json(string $message, int $code = 400): never { http_response_code($code); echo json_encode(['error'=>$message], JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail_json('POST required.',405);
$symbol=trim((string)($_POST['symbol']??'UNKNOWN')); $timeframe=trim((string)($_POST['timeframe']??'UNKNOWN'));
$allowedTf=['M1','M5','M15','M30','H1','H4','D1','UNKNOWN']; if(!in_array($timeframe,$allowedTf,true))$timeframe='UNKNOWN';
if(!isset($_FILES['chart'])||!is_array($_FILES['chart']))fail_json('Please upload a candlestick chart screenshot.');
$file=$_FILES['chart']; if(($file['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK)fail_json('The image upload failed. Please try again.');
if(($file['size']??0)>12*1024*1024)fail_json('Image is too large. Maximum size is 12 MB.');
$tmp=$file['tmp_name']; $mime=(new finfo(FILEINFO_MIME_TYPE))->file($tmp); $allowed=['image/jpeg','image/png','image/webp']; if(!in_array($mime,$allowed,true))fail_json('Only JPG, PNG and WEBP chart images are supported.');
$bytes=file_get_contents($tmp); if($bytes===false)fail_json('Could not read the uploaded image.',500); $dataUrl='data:'.$mime.';base64,'.base64_encode($bytes);
$apiKey=getenv('OPENAI_API_KEY'); if(!$apiKey&&is_file(__DIR__.'/runtime_config.php')){ $v=require __DIR__.'/runtime_config.php'; if(is_string($v))$apiKey=trim($v); }
if(!$apiKey)fail_json('AI engine is not connected. Server runtime configuration is missing.',503);
$prompt=<<<PROMPT
You are Primonizer Forex AI, a visual trading tutor and rigorous chart-analysis engine.

Analyze the ENTIRE VISIBLE CHART AREA, not only the newest candles. Reconstruct the largest readable historical context from left to right, then focus on the current price area. The goal is to teach the trader what the market is doing and show the reasoning directly on the chart.

Create a visual chart map. Identify, when actually visible: major/minor swing highs and lows; HH, HL, LH, LL; displacement; BOS and CHoCH candidates; equal highs/lows and liquidity pools; liquidity sweeps; potential bullish/bearish order-block zones; three-candle FVG/imbalance candidates; support/resistance; broad dealing range; premium/discount when reliable; and current price.

IMPORTANT COORDINATES:
- Estimate annotation positions as normalized coordinates from 0 to 1 over the ORIGINAL IMAGE: x=0 is left edge, x=1 right edge; y=0 is top, y=1 bottom.
- For a zone, provide x1,y1,x2,y2.
- For a point, provide x,y.
- Coordinates must refer to visible chart locations, not page/UI locations.
- Only create annotations when the feature is reasonably visible. Do not fabricate coordinates.

DIRECTIONAL CONCLUSION:
Give a CURRENT directional bias based only on visible evidence: BULLISH, BEARISH, or NEUTRAL.
Also give a short next-move scenario: UP, DOWN, or WAIT.
This is a scenario, NOT certainty and NOT a guaranteed prediction. Explain the evidence and the exact invalidation condition.
If the evidence is mixed, use NEUTRAL/WAIT rather than forcing a direction.

Return ONLY valid JSON. No markdown fences. Use exactly this structure:
{
  "direction": "BULLISH|BEARISH|NEUTRAL",
  "next_move": "UP|DOWN|WAIT",
  "confidence": 0,
  "headline": "short human-readable conclusion",
  "current_state": "2-3 short sentences",
  "market_story": ["Step 1...","Step 2...","Step 3...","Step 4..."],
  "bullish_case": "short scenario and confirmation condition",
  "bearish_case": "short scenario and confirmation condition",
  "invalidation": "single clear invalidation condition for the primary direction",
  "watch_zone": "short description of the most important area to watch",
  "annotations": [
    {"type":"HH|HL|LH|LL|BOS|CHoCH|LIQUIDITY|SWEEP|OB_BULL|OB_BEAR|FVG|SUPPORT|RESISTANCE|PREMIUM|DISCOUNT|CURRENT","label":"short label","note":"very short reason","x":0.0,"y":0.0,"x2":null,"y2":null},
    {"type":"...","label":"...","note":"...","x":0.0,"y":0.0,"x2":null,"y2":null}
  ],
  "path": [
    {"direction":"UP|DOWN","x":0.0,"y":0.0,"label":"START|TARGET|INVALIDATION"}
  ],
  "key_levels": ["short level description","short level description"],
  "tutorial": [
    {"title":"Structure","text":"short explanation"},
    {"title":"Liquidity","text":"short explanation"},
    {"title":"Trigger","text":"short explanation"},
    {"title":"What to watch","text":"short explanation"}
  ],
  "risk_note": "short risk note"
}

Rules:
- confidence is an integer 0-100 representing confidence in the evidence visible in this screenshot, NOT probability of profit.
- Keep every text field concise and easy to scan.
- Never invent exact prices if the price axis is unreadable.
- Never invent candles, levels, order flow or data not visible.
- Treat order blocks, liquidity and SMC concepts as hypotheses inferred from price action.
- Give structural context before calling BOS/CHoCH.
- If image quality prevents reliable detection, say so and use fewer annotations.
- Do not claim certainty or guaranteed profit.
- The diagram path should show the primary scenario from CURRENT toward TARGET and, if useful, an INVALIDATION branch.
- Do not force a bullish or bearish answer when evidence is mixed.

Selected symbol: $symbol
Selected timeframe: $timeframe
PROMPT;
$payload=['model'=>'gpt-5.6-luna','input'=>[['role'=>'user','content'=>[['type'=>'input_text','text'=>$prompt],['type'=>'input_image','image_url'=>$dataUrl,'detail'=>'high']]]]];
$ch=curl_init('https://api.openai.com/v1/responses'); curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_RETURNTRANSFER=>true,CURLOPT_HTTPHEADER=>['Content-Type: application/json','Authorization: Bearer '.$apiKey],CURLOPT_POSTFIELDS=>json_encode($payload),CURLOPT_TIMEOUT=>120]);
$response=curl_exec($ch); $curlError=curl_error($ch); $status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE); curl_close($ch);
if($response===false||$curlError)fail_json('AI connection failed. Please try again.',502); $data=json_decode($response,true); if(!is_array($data))fail_json('AI returned an invalid response.',502);
if($status>=400)fail_json($data['error']['message']??'AI provider rejected the request.',502);
$text=$data['output_text']??''; if(!$text&&isset($data['output'])&&is_array($data['output']))foreach($data['output'] as $item)foreach(($item['content']??[]) as $part)if(isset($part['text']))$text.=$part['text'];
if(!$text)fail_json('The AI returned no analysis.',502);
$text=trim($text); if(str_starts_with($text,'```'))$text=preg_replace('/^```(?:json)?\s*|\s*```$/','',$text);
$analysis=json_decode($text,true); if(!is_array($analysis))fail_json('The AI returned an unreadable visual analysis. Please try a clearer chart screenshot.',502);
$analysis['direction']=in_array(($analysis['direction']??''),['BULLISH','BEARISH','NEUTRAL'],true)?$analysis['direction']:'NEUTRAL';
$analysis['next_move']=in_array(($analysis['next_move']??''),['UP','DOWN','WAIT'],true)?$analysis['next_move']:'WAIT';
$analysis['confidence']=max(0,min(100,(int)($analysis['confidence']??0)));
$analysis['annotations']=is_array($analysis['annotations']??null)?$analysis['annotations']:[];
$analysis['path']=is_array($analysis['path']??null)?$analysis['path']:[];
$analysis['market_story']=is_array($analysis['market_story']??null)?$analysis['market_story']:[];
$analysis['tutorial']=is_array($analysis['tutorial']??null)?$analysis['tutorial']:[];
echo json_encode(['ok'=>true,'symbol'=>$symbol,'timeframe'=>$timeframe,'engine'=>'Primonizer Forex AI — Visual Full Chart Tutor','analysis'=>$analysis],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
