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
You are Primonizer Forex AI, a rigorous chart-analysis engine.

Analyze the ENTIRE VISIBLE CHART AREA. Do not focus only on the newest candles. Preserve the largest readable historical context in the screenshot, then give extra weight to the most recent confirmed structure when describing the current state.

First create an internal structured chart map from the image. Look across the full visible chart for: major/minor swing highs and lows; HH, HL, LH, LL sequences; displacement; BOS and CHoCH candidates; repeated/equal highs and lows; liquidity pools and sweeps; potential bullish/bearish order-block zones; three-candle FVG/imbalance candidates; support/resistance; the broad visible dealing range; premium/discount location where reliable; and current price location relative to broader structure.

Accuracy rules:
- Never intentionally crop the chart to only the recent area.
- Separate visible evidence from interpretation.
- Never invent exact prices if the axis is unreadable.
- Never invent candles, levels, order flow or data that is not visible.
- Treat order blocks, liquidity and SMC concepts as hypotheses inferred from price action.
- Give structural context before calling a BOS or CHoCH.
- For every important zone explain the evidence and invalidation.
- If image quality prevents reliable detection, say so instead of guessing.

Write a readable report with:
1. EXECUTIVE CHART READ
2. FULL-VIEW MARKET STRUCTURE
3. LIQUIDITY MAP
4. ORDER-BLOCK MAP
5. FVG / IMBALANCE MAP
6. SUPPORT / RESISTANCE
7. PREMIUM / DISCOUNT
8. MULTI-FACTOR CONFLUENCE
9. BULLISH SCENARIO AND INVALIDATION
10. BEARISH SCENARIO AND INVALIDATION
11. WHAT THE CHART DOES NOT PROVE
12. RISK NOTES

Do not claim certainty or guaranteed profit. Use candidate, possible, visible evidence, confirmation and invalidation. Explain what extra timeframe/data would improve the analysis. Selected symbol: $symbol. Selected timeframe: $timeframe.
PROMPT;
$payload=['model'=>'gpt-5.6-luna','input'=>[['role'=>'user','content'=>[['type'=>'input_text','text'=>$prompt],['type'=>'input_image','image_url'=>$dataUrl,'detail'=>'high']]]]];
$ch=curl_init('https://api.openai.com/v1/responses'); curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_RETURNTRANSFER=>true,CURLOPT_HTTPHEADER=>['Content-Type: application/json','Authorization: Bearer '.$apiKey],CURLOPT_POSTFIELDS=>json_encode($payload),CURLOPT_TIMEOUT=>120]);
$response=curl_exec($ch); $curlError=curl_error($ch); $status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE); curl_close($ch);
if($response===false||$curlError)fail_json('AI connection failed. Please try again.',502); $data=json_decode($response,true); if(!is_array($data))fail_json('AI returned an invalid response.',502);
if($status>=400)fail_json($data['error']['message']??'AI provider rejected the request.',502);
$text=$data['output_text']??''; if(!$text&&isset($data['output'])&&is_array($data['output']))foreach($data['output'] as $item)foreach(($item['content']??[]) as $part)if(isset($part['text']))$text.=$part['text'];
if(!$text)fail_json('The AI returned no analysis.',502);
echo json_encode(['ok'=>true,'symbol'=>$symbol,'timeframe'=>$timeframe,'engine'=>'Primonizer Forex AI — Full Chart Analysis','analysis'=>trim($text)],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
