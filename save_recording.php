<?php
// save_recording.php — saves every upload with an auto-incrementing suffix if needed
header('Content-Type: application/json');

function fail($http, $msg, $extra = []) {
  http_response_code($http);
  error_log("[recorder] $msg :: " . json_encode($extra));
  echo json_encode(['ok' => false, 'error' => $msg] + $extra);
  exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail(405, 'Method not allowed', ['method'=>$_SERVER['REQUEST_METHOD']]);
if (!ini_get('file_uploads')) fail(500, 'PHP file_uploads=Off in php.ini');
if (!isset($_FILES['audio'])) fail(400, 'No "audio" file field received.', ['_FILES'=>array_keys($_FILES)]);

$err = $_FILES['audio']['error'] ?? 0;
$errMap = [0=>'OK',1=>'INI_SIZE',2=>'FORM_SIZE',3=>'PARTIAL',4=>'NO_FILE',6=>'NO_TMP_DIR',7=>'CANT_WRITE',8=>'EXTENSION'];
if ($err !== UPLOAD_ERR_OK) {
  fail(400, "Upload error ($err): " . ($errMap[$err] ?? 'UNKNOWN'), [
    'upload_max_filesize'=>ini_get('upload_max_filesize'),
    'post_max_size'=>ini_get('post_max_size'),
    'size_client'=>$_FILES['audio']['size'] ?? null
  ]);
}

$base = __DIR__ . DIRECTORY_SEPARATOR . 'recordings';
$day  = date('Ymd');
$dir  = $base . DIRECTORY_SEPARATOR . $day;
if (!is_dir($dir) && !mkdir($dir, 0775, true)) fail(500, 'Failed to create dir', ['dir'=>$dir]);

// Desired client filename (e.g., "audio-file.wav")
$clientName = $_POST['filename'] ?? $_FILES['audio']['name'] ?? ('recording_'.time().'.wav');
$clientName = preg_replace('/[^a-zA-Z0-9_.-]+/', '_', $clientName);
if ($clientName === '') $clientName = 'recording_'.time().'.wav';

// Split into name + extension
$dotPos = strrpos($clientName, '.');
if ($dotPos === false) {
  $name = $clientName; $ext = 'wav';
} else {
  $name = substr($clientName, 0, $dotPos);
  $ext  = substr($clientName, $dotPos + 1);
  if ($ext === '') $ext = 'wav';
}

// Generate a unique filename (no overwrite) within today's folder
function unique_path($dir, $name, $ext) {
  $path = $dir . DIRECTORY_SEPARATOR . $name . '.' . $ext;
  if (!file_exists($path)) return [$path, $name . '.' . $ext];
  // find next suffix _001, _002, ...
  $i = 1;
  while (true) {
    $candName = sprintf("%s_%03d.%s", $name, $i, $ext);
    $candPath = $dir . DIRECTORY_SEPARATOR . $candName;
    if (!file_exists($candPath)) return [$candPath, $candName];
    $i++;
    if ($i > 99999) break; // sanity
  }
  // fallback if somehow all are taken (very unlikely)
  $ts = date('His');
  $fallback = $name . '_' . $ts . '.' . $ext;
  return [$dir . DIRECTORY_SEPARATOR . $fallback, $fallback];
}

list($path, $finalName) = unique_path($dir, $name, $ext);

if (!is_uploaded_file($_FILES['audio']['tmp_name'])) {
  fail(400, 'Temp file is not an uploaded file.', ['tmp'=>$_FILES['audio']['tmp_name']]);
}
if (!move_uploaded_file($_FILES['audio']['tmp_name'], $path)) {
  $perm = @substr(sprintf('%o', fileperms($dir)), -4);
  fail(500, 'Failed to save file (move_uploaded_file false).', [
    'dir'=>$dir, 'perm'=>$perm, 'writable'=>is_writable($dir)
  ]);
}

// collect metadata (for Excel mapping)
$prompt_id   = $_POST['prompt_id']   ?? null;   // 1-based index from texts.json
$prompt_text = $_POST['prompt_text'] ?? null;   // the sentence text
$text_sha1   = $_POST['text_sha1']   ?? null;   // SHA-1 of the sentence (optional but recommended)

$meta = [
  'saved_at'     => date('c'),
  'client_ip'    => $_SERVER['REMOTE_ADDR'] ?? null,
  'user_agent'   => $_SERVER['HTTP_USER_AGENT'] ?? null,
  'prompt_id'    => $prompt_id,
  'prompt_text'  => $prompt_text,
  'text_sha1'    => $text_sha1,
  'size_bytes'   => filesize($path),
  'filename'     => $finalName,
  'relative_path'=> 'recordings/'.$day.'/'.$finalName
];

// write sidecar JSON
file_put_contents($path.'.json', json_encode($meta, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));

// append to CSV log (audit)
$csv = $base . DIRECTORY_SEPARATOR . 'record_log.csv';
$createHeader = !file_exists($csv);
$fp = fopen($csv, 'a');
if ($createHeader) {
  fputcsv($fp, ['saved_at','date_folder','filename','size_bytes','prompt_id','text_sha1','prompt_text']);
}
fputcsv($fp, [
  $meta['saved_at'],
  $day,
  $finalName,
  $meta['size_bytes'],
  $prompt_id,
  $text_sha1,
  $prompt_text
]);
fclose($fp);

echo json_encode(['ok'=>true, 'path'=>$meta['relative_path'], 'filename'=>$finalName]);
