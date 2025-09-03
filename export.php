<?php
// export.php — zips today's recordings folder if it exists
$base = __DIR__ . DIRECTORY_SEPARATOR . 'recordings';
$day  = date('Ymd');
$dir  = $base . DIRECTORY_SEPARATOR . $day;

if (!is_dir($dir)) {
  header('Content-Type: text/plain; charset=utf-8');
  echo "No recordings for today ($day).";
  exit;
}

$zipName = "recordings_$day.zip";
$zipPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . $zipName;

if (class_exists('ZipArchive')) {
  $zip = new ZipArchive();
  if ($zip->open($zipPath, ZipArchive::CREATE|ZipArchive::OVERWRITE) !== true) {
    header('Content-Type: text/plain; charset=utf-8');
    echo "Failed to create ZIP.";
    exit;
  }
  $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
  foreach ($files as $file) {
    $local = substr($file->getPathname(), strlen($dir)+1);
    $zip->addFile($file->getPathname(), $local);
  }
  $zip->close();
  header('Content-Type: application/zip');
  header('Content-Disposition: attachment; filename="'.$zipName.'"');
  header('Content-Length: '.filesize($zipPath));
  readfile($zipPath);
  @unlink($zipPath);
} else {
  // Fallback: stream as .tar if ZipArchive not available
  $tar = "recordings_$day.tar";
  header('Content-Type: application/x-tar');
  header('Content-Disposition: attachment; filename="'.$tar.'"');
  chdir($dir);
  $ph = popen('tar -cf - .', 'r');
  fpassthru($ph);
  pclose($ph);
}
