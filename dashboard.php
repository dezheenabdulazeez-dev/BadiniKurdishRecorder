<?php
// dashboard.php — simple viewer for recordings + progress

// ---------- helpers ----------
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function fmt_bytes($b){
  $b = (float)$b;
  if ($b >= 1073741824) return number_format($b/1073741824,2).' GB';
  if ($b >= 1048576)   return number_format($b/1048576,2).' MB';
  if ($b >= 1024)      return number_format($b/1024,2).' KB';
  return $b.' B';
}
function load_texts_json($path='texts.json'){
  if (!file_exists($path)) return ["نمونەی دەقی گەورە کاتێک texts.json بەردەست نیە.","The quick brown fox jumps over the lazy dog."];
  $raw = file_get_contents($path);
  $arr = json_decode($raw, true);
  if (!is_array($arr) || !count($arr)) return [];
  // accept either ["str", ...] or [{"text":".."}, ...]
  if (is_string($arr[0])) return array_map('strval', $arr);
  return array_map(function($x){ return isset($x['text']) ? (string)$x['text'] : ''; }, $arr);
}
function load_csv_log($csv){
  if (!file_exists($csv)) return [];
  $fp = fopen($csv, 'r'); if(!$fp) return [];
  $rows=[]; $header=null;
  while(($r=fgetcsv($fp))!==false){
    if ($header===null){ $header=$r; continue; }
    $rows[] = array_combine($header, $r);
  }
  fclose($fp);
  return $rows;
}
function starts_with($s,$p){ return strncmp($s,$p,strlen($p))===0; }

// ---------- inputs ----------
$q   = isset($_GET['q'])   ? trim($_GET['q'])   : '';
$day = isset($_GET['day']) ? preg_replace('/[^0-9]/','', $_GET['day']) : '';
$baseDir = __DIR__ . DIRECTORY_SEPARATOR . 'recordings';
$csvPath = $baseDir . DIRECTORY_SEPARATOR . 'record_log.csv';

// ---------- data ----------
$texts = load_texts_json();
$totalTexts = count($texts);
$rows = load_csv_log($csvPath);

// filter by day
if ($day !== '') {
  $rows = array_values(array_filter($rows, fn($r) => ($r['date_folder'] ?? '') === $day));
}

// search (matches prompt_text, sha1, filename, prompt_id)
if ($q !== '') {
  $qLower = mb_strtolower($q, 'UTF-8');
  $rows = array_values(array_filter($rows, function($r) use ($qLower){
    foreach (['prompt_text','text_sha1','filename','prompt_id'] as $k){
      $v = mb_strtolower((string)($r[$k] ?? ''), 'UTF-8');
      if ($v !== '' && mb_strpos($v, $qLower) !== false) return true;
    }
    return false;
  }));
}

// newest first
usort($rows, function($a,$b){
  // saved_at is ISO8601
  return strcmp($b['saved_at'] ?? '', $a['saved_at'] ?? '');
});

// unique progress by prompt_id
$seen = [];
foreach ($rows as $r){
  $pid = (int)($r['prompt_id'] ?? 0);
  if ($pid > 0) $seen[$pid] = true;
}
$done = count($seen);
$percent = $totalTexts ? round($done*100/$totalTexts) : 0;

// collect days for filter
$days = [];
foreach ($rows as $r){
  $d = $r['date_folder'] ?? '';
  if ($d) $days[$d]=true;
}
$days = array_keys($days);
sort($days); // ascending
$days = array_reverse($days); // newest on top

// URL helpers
function self_url($params){
  $base = strtok($_SERVER['REQUEST_URI'], '?');
  return $base . (count($params) ? ('?' . http_build_query($params)) : '');
}
?>
<!doctype html>
<html lang="en" dir="auto">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Recordings Dashboard</title>
<style>
  :root{
    --bg:#f6f7f9; --text:#111827; --muted:#6b7280; --border:#e5e7eb; --card:#ffffff;
    --cyan:#11b5e5; --cyan-dark:#0e9ec9; --olive:#8a7d36;
  }
  *{box-sizing:border-box}
  body{margin:0;background:var(--bg);color:var(--text);font-family:system-ui,-apple-system,"Segoe UI",Roboto,Arial,sans-serif}
  .wrap{max-width:1100px;margin:0 auto;padding:18px}
  .top{display:flex;flex-wrap:wrap;gap:10px;align-items:center;justify-content:space-between;margin-bottom:10px}
  .card{background:var(--card);border:1px solid var(--border);border-radius:14px;padding:14px}
  .stat{display:flex;gap:16px;align-items:center;flex-wrap:wrap}
  .badge{font-weight:800;color:#fff;background:var(--cyan);padding:8px 12px;border-radius:999px}
  .muted{color:var(--muted)}
  form.filters{display:flex;gap:10px;align-items:center;flex-wrap:wrap}
  input[type="text"], select{padding:10px;border:1px solid var(--border);border-radius:10px}
  button{padding:10px 14px;border:0;border-radius:10px;background:var(--cyan);color:#fff;font-weight:800;cursor:pointer}
  button:hover{background:var(--cyan-dark)}
  table{width:100%;border-collapse:separate;border-spacing:0 8px;margin-top:12px}
  th,td{padding:10px 12px;text-align:left;background:#fff;border-top:1px solid var(--border);border-bottom:1px solid var(--border)}
  th:first-child, td:first-child{border-left:1px solid var(--border);border-radius:8px 0 0 8px}
  th:last-child, td:last-child{border-right:1px solid var(--border);border-radius:0 8px 8px 0}
  .small{font-size:12px;color:var(--muted)}
  .nowrap{white-space:nowrap}
  .mono{font-family:ui-monospace, SFMono-Regular, Menlo, Consolas, monospace}
  .link{color:#0b6fb3;text-decoration:none}
  .pill{display:inline-block;padding:4px 8px;border-radius:999px;background:#eef6ff;color:#185fa1;font-weight:800;font-size:12px}
</style>
</head>
<body>
<div class="wrap">
  <div class="top">
    <div class="stat card">
      <div class="badge">Progress</div>
      <div><b><?=h($done)?> / <?=h($totalTexts)?></b> recorded <span class="muted">(<?=h($percent)?>%)</span></div>
    </div>
    <form class="filters card" method="get" action="">
      <label>Search:</label>
      <input type="text" name="q" value="<?=h($q)?>" placeholder="text, SHA1, filename, ID">
      <label>Day:</label>
      <select name="day">
        <option value="">All</option>
        <?php foreach($days as $d): ?>
          <option value="<?=h($d)?>" <?= $day===$d ? 'selected':'' ?>><?=h($d)?></option>
        <?php endforeach; ?>
      </select>
      <button>Apply</button>
      <a class="link" style="margin-left:6px" href="<?=h(self_url([]))?>">Reset</a>
      <a class="link" style="margin-left:16px" href="./index.php">← Back to recorder</a>
    </form>
  </div>

  <div class="card">
    <div class="small">Log file: <span class="mono">recordings/record_log.csv</span></div>
    <table>
      <thead>
        <tr>
          <th class="nowrap">Saved at</th>
          <th>Day</th>
          <th>File</th>
          <th class="nowrap">Size</th>
          <th>ID</th>
          <th>SHA1</th>
          <th>Text (snippet)</th>
          <th>Links</th>
        </tr>
      </thead>
      <tbody>
        <?php if(!count($rows)): ?>
          <tr><td colspan="8" class="small">No entries.</td></tr>
        <?php else: ?>
          <?php foreach($rows as $r):
            $d = $r['date_folder'] ?? '';
            $fname = $r['filename'] ?? '';
            $pathRel = 'recordings/'.$d.'/'.$fname;
            $size = $r['size_bytes'] ?? '';
            $pid  = $r['prompt_id'] ?? '';
            $sha1 = $r['text_sha1'] ?? '';
            $text = $r['prompt_text'] ?? '';
            $snippet = mb_substr($text, 0, 120, 'UTF-8') . (mb_strlen($text,'UTF-8')>120 ? '…':'');
            $jsonRel = $pathRel.'.json';
          ?>
          <tr>
            <td class="small mono nowrap"><?=h($r['saved_at'] ?? '')?></td>
            <td class="mono"><?=h($d)?></td>
            <td class="mono"><?=h($fname)?></td>
            <td class="small"><?=h(fmt_bytes($size))?></td>
            <td class="mono"><?=h($pid)?></td>
            <td class="mono small"><?=h($sha1)?></td>
            <td><?=h($snippet)?></td>
            <td class="small">
              <?php if ($d && $fname && file_exists(__DIR__ . DIRECTORY_SEPARATOR . $pathRel)): ?>
                <a class="pill" href="<?=h($pathRel)?>" target="_blank">WAV</a>
              <?php else: ?>
                <span class="pill" style="background:#ffecec;color:#a11">missing</span>
              <?php endif; ?>
              <?php if ($d && file_exists(__DIR__ . DIRECTORY_SEPARATOR . $jsonRel)): ?>
                <a class="pill" href="<?=h($jsonRel)?>" target="_blank" style="margin-left:6px">JSON</a>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>

  <div class="card" style="margin-top:12px">
    <h3 style="margin:0 0 6px 0">How this verifies Excel rows</h3>
    <ol class="small">
      <li><b>ID (prompt_id)</b> is the 1-based index into <span class="mono">texts.json</span> which came from your Excel (row order preserved).</li>
      <li><b>SHA1</b> is a hash of the exact sentence; compute SHA-1 on the Excel text to confirm a perfect match.</li>
      <li>Each upload also writes a sidecar file: <span class="mono">recordings/&lt;YYYYMMDD&gt;/audio-file.wav.json</span> with the same fields.</li>
    </ol>
  </div>
</div>
</body>
</html>
