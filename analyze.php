<?php
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function fail_json(string $message, int $code = 400): never {
    http_response_code($code);
    echo json_encode(['error' => $message], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail_json('POST required.', 405);

$symbol = trim((string)($_POST['symbol'] ?? 'UNKNOWN'));
$timeframe = trim((string)($_POST['timeframe'] ?? 'UNKNOWN'));
$allowedTf = ['M1','M5','M15','M30','H1','H4','D1','UNKNOWN'];
if (!in_array($timeframe, $allowedTf, true)) $timeframe = 'UNKNOWN';

if (!isset($_FILES['chart']) || !is_array($_FILES['chart'])) fail_json('Please upload a candlestick chart screenshot.');
$file = $_FILES['chart'];
if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) fail_json('The image upload failed. Please try a smaller PNG, JPG or WEBP screenshot.');
if (($file['size'] ?? 0) > 8 * 1024 * 1024) fail_json('Image is too large. Maximum size is 8 MB.');

$tmp = $file['tmp_name'];
$mime = (new finfo(FILEINFO_MIME_TYPE))->file($tmp);
$allowed = ['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'];
if (!isset($allowed[$mime])) fail_json('Only JPG, PNG and WEBP chart images are supported.');

$bytes = file_get_contents($tmp);
if ($bytes === false) fail_json('Could not read the uploaded image.', 500);
$dataUrl = 'data:' . $mime . ';base64,' . base64_encode($bytes);

$apiKey = getenv('OPENAI_API_KEY');
if (!$apiKey) {
    fail_json('AI engine is not connected yet. Add OPENAI_API_KEY to the server environment, then try again.', 503);
}

$prompt = <<<PROMPT
You are Primonizer Forex AI, a careful market-analysis assistant. Analyze the supplied candlestick chart screenshot for $symbol on the $timeframe timeframe.

IMPORTANT: A screenshot is incomplete market data. Never claim certainty, guaranteed profit, institutional order flow, or that an order block is objectively "correct". Treat order blocks, liquidity and smart-money concepts as price-action hypotheses inferred from visible structure. State when the image is unclear or insufficient.

Produce a concise but deep report with these sections:
1. CHART READ — visible symbol/timeframe if inferable, candle quality, obvious trend/range context.
2. MARKET STRUCTURE — swing highs/lows, HH/HL/LH/LL, BOS and/or CHoCH candidates. Explain the candle sequence supporting each claim.
3. LIQUIDITY — equal highs/lows, obvious resting-liquidity areas, sweeps/grabs and whether a sweep appears confirmed or only possible.
4. ORDER BLOCKS — identify potential bullish and bearish OB zones using visible price action. For every candidate explain: origin, displacement, structure break, mitigation/retest status, freshness and invalidation. Do not invent exact prices if the axis is unreadable.
5. FVG / IMBALANCE — identify visible three-candle imbalance candidates and whether they remain open or appear filled.
6. SUPPORT / RESISTANCE — important visible zones and why they matter.
7. PREMIUM / DISCOUNT — if a clear dealing range can be inferred, describe the location; otherwise say it cannot be established reliably.
8. CONFLUENCE — combine structure + liquidity + OB/FVG + location. Separate strong visual evidence from weak inference.
9. SCENARIOS — describe bullish and bearish scenarios, their invalidation conditions, and what confirmation a trader could watch for. Do NOT present a guaranteed BUY/SELL signal.
10. RISK NOTES — mention spread, slippage, news, leverage and the limitation of screenshot-only analysis.

Use clear language suitable for a trader. Prefer "candidate", "possible", "visible evidence" and "invalidation" over certainty. If the screenshot does not show enough information, explicitly say what additional timeframe or chart information would improve the analysis.
PROMPT;

$payload = [
  'model' => 'gpt-5.6-luna',
  'input' => [[
    'role' => 'user',
    'content' => [
      ['type' => 'input_text', 'text' => $prompt],
      ['type' => 'input_image', 'image_url' => $dataUrl, 'detail' => 'high']
    ]
  ]]
];

$ch = curl_init('https://api.openai.com/v1/responses');
curl_setopt_array($ch, [
  CURLOPT_POST => true,
  CURLOPT_RETURNTRANSFER => true,
  CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $apiKey],
  CURLOPT_POSTFIELDS => json_encode($payload),
  CURLOPT_TIMEOUT => 90,
]);
$response = curl_exec($ch);
$curlError = curl_error($ch);
$status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($response === false || $curlError) fail_json('AI connection failed. Please try again.', 502);
$data = json_decode($response, true);
if (!is_array($data)) fail_json('AI returned an invalid response.', 502);
if ($status >= 400) {
    $msg = $data['error']['message'] ?? 'AI provider rejected the request.';
    fail_json($msg, 502);
}

$text = $data['output_text'] ?? '';
if (!$text && isset($data['output']) && is_array($data['output'])) {
    foreach ($data['output'] as $item) {
        foreach (($item['content'] ?? []) as $part) {
            if (isset($part['text'])) $text .= $part['text'];
        }
    }
}
if (!$text) fail_json('The AI returned no analysis.', 502);

echo json_encode([
  'ok' => true,
  'symbol' => $symbol,
  'timeframe' => $timeframe,
  'engine' => 'Primonizer Forex AI',
  'analysis' => trim($text)
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
