#!/usr/bin/php
<?php
define('DEBUG', true);
define('TRANSLATION_DIR', './');
define('ORIGINAL_DIR', '../topodroid/assets/man/');
define('MAN_PAGE_EXTENSION', 'htm');

enum TranslationStatus: string
{
  case OK = 'OK';
  case OUTDATED = 'OUTDATED';
  case MISSING_FILE_IN_DIRECTORY = 'MISSING_FILE_IN_DIRECTORY';
  case MISSING_FILE_IN_GIT = 'MISSING_FILE_IN_GIT';
  case UNTRANSLATED = 'UNTRANSLATED';
  case ERROR = 'ERROR';
  case NONEXISTENT_COMMIT = 'NONEXISTENT_COMMIT';
}

function getFilesWithExtension($dir, $extension) {
  if (!is_dir($dir)) {
    return "Error: Directory '$dir' does not exist.\n";
  }

  $files = [];
  if ($handle = opendir($dir)) {
    while (false !== ($file = readdir($handle))) {
      if (pathinfo($file, PATHINFO_EXTENSION) === $extension) {
        $files[] = $file;
      }
    }
    closedir($handle);
  } else {
    return "Error: Unable to open directory '$dir'.\n";
  }
  natcasesort($files);
  $files = array_fill_keys($files, true);
  return $files;
}

function lerCommit($dom) {
  $commitElement = $dom->getElementById('last-update-commit');
  if ($commitElement) {
    $lastCommit = $commitElement->nodeValue;
    return $lastCommit;
  } else {
    return false;
  }
}

function carregarHTML($arquivo) {
  $html = file_get_contents($arquivo);
  $dom = new DOMDocument();
  libxml_use_internal_errors(true);
  $dom->loadHTML($html);
  libxml_clear_errors();
  return $dom;
}

function getTerminalWidth() {
  $output = [];
  exec('stty size 2>&1', $output);

  if (count($output) > 0 && preg_match('/^\d+\s+(\d+)$/', $output[0], $matches)) {
    return (int)$matches[1];
  }

  return 80;
}

function showProgressBar($done, $total, $availableWidth = 40) {
  if ($total === 0) return;

  $size = $availableWidth - 20;

  $progress = ($done / $total);
  $bar = floor($progress * $size);

  $status_bar = "\r[";
  $status_bar .= str_repeat("=", $bar);
  if ($bar < $size) {
    $status_bar .= ">";
    $status_bar .= str_repeat(" ", $size - $bar);
  } else {
    $status_bar .= "=";
  }

  $percent = number_format($progress * 100, 0);

  $status_bar .= "] $percent%  $done/$total";

  echo "$status_bar  ";

  if ($done === $total) {
    echo "\n";
  }

  flush();
}

function verificarTraducaoAtualizada($arquivo, $commitId) {
  $currentDir = getcwd();
  chdir(ORIGINAL_DIR);
  exec("git cat-file -t $commitId 2>&1", $output, $return_var);
  if ($return_var !== 0) {
    chdir($currentDir);
    return TranslationStatus::NONEXISTENT_COMMIT;
  }

  exec("git log --pretty=format:'%H' -- $arquivo", $output, $return_var);
  if ($return_var !== 0) {
    chdir($currentDir);
    return TranslationStatus::MISSING_FILE_IN_GIT;
  }

  $fileChanged = false;
  $first = true;
  $tamCommitId = strlen($commitId);
  // print "Arquivo: '$arquivo'\n";
  foreach ($output as $commit) {
    if ($commit == 'commit') {
      continue;
    }
    $commitAbreviado = substr($commit, 0, $tamCommitId);
    // print "commit registrado: '$commitId' - commit abreviado: '$commitAbreviado' - commit completo: '$commit'\n";
    if ($first) {
      $originalLastCommit = $commitAbreviado;
    }
    if ($commitAbreviado === $commitId) {
      if ($first) {
        break;
      }
      else {
        $fileChanged = true;
        break;
      }
    }
    $first = false;
  }

  chdir($currentDir);
  if ($fileChanged) {
    return [
      'status' => TranslationStatus::OUTDATED,
      'originalLastCommit' => $originalLastCommit,
    ];
  } else {
    return [
      'status' => TranslationStatus::OK,
    ];
  }
}

if ($argc !== 1) {
  echo "Usage: traducao_atualizada.php\n";
  exit(1);
}

$translationDir =  realpath(TRANSLATION_DIR);
if (DEBUG) {
  echo "Translation directory: '$translationDir'\n";
}
$translatedFiles = getFilesWithExtension($translationDir, MAN_PAGE_EXTENSION);
// var_dump($translatedFiles);
 if (is_string($translatedFiles)) {
  echo "When reading translation dir: $translatedFiles";
  exit(1);
}

$originalDir = realpath(ORIGINAL_DIR);
if (DEBUG) {
  echo "Original directory: '$originalDir'\n";
}
$originalFiles = getFilesWithExtension($originalDir, MAN_PAGE_EXTENSION);
// var_dump($originalFiles);
if (is_string($originalFiles)) {
  echo "When reading original dir: $originalFiles";
  exit(1);
}

$missingTranslationFiles = array_diff_key($originalFiles, $translatedFiles);
$missingOriginalFiles = array_diff_key($translatedFiles, $originalFiles);

if (!empty($missingTranslationFiles)) {
  $missingTranslationFiles = array_keys($missingTranslationFiles);
  natcasesort($missingTranslationFiles);
  echo "Files whose translated does not exist:\n";
  foreach ($missingTranslationFiles as $file) {
    echo "  $file\n";
    $translatedFiles[$file]['status'] = TranslationStatus::MISSING_FILE_IN_DIRECTORY;
  }
}

if (!empty($missingOriginalFiles)) {
  $missingOriginalFiles = array_keys($missingOriginalFiles);
  natcasesort($missingOriginalFiles);
  echo "Translated files without original file:\n";
  foreach ($missingOriginalFiles as $file => $value) {
    echo "  $file\n";
  }
}

$countTranslatedFiles = count($translatedFiles);
if ($countTranslatedFiles === 0) {
  echo "No files to check.\n";
  exit(0);
}

$countNeedsTranslation = 0;
$processedFiles = 0;
$terminalWidth = getTerminalWidth();
foreach ($translatedFiles as $key => $value) {
  showProgressBar($processedFiles++, $countTranslatedFiles, $terminalWidth);
  if ($value !== true) {
    continue;
  }

  $dom = carregarHTML($key);
  $commit = lerCommit($dom);

  $translatedFiles[$key] = [];
  if ($commit === false) {
    $translatedFiles[$key]['status'] = TranslationStatus::UNTRANSLATED;
  }
  else {
    $translatedFiles[$key]['lastTranslatedCommit'] = $commit;
    $translatedFiles[$key] += verificarTraducaoAtualizada($key, $commit);
  }
}
showProgressBar($processedFiles, $countTranslatedFiles, $terminalWidth);

foreach ($translatedFiles as $key => $value) {
  if (is_array($value) && key_exists('status', $value) && ($value['status'] === TranslationStatus::OK)) {
    continue;
  }
  $countNeedsTranslation++;
  echo "File: $key - Status:\n";
  print_r($value);
  if ($value['status'] === TranslationStatus::OUTDATED) {
    echo "Git command for changes:\ngit diff " . $value['lastTranslatedCommit'] . ".. $key\n";
  }
  else if ($value['status'] === TranslationStatus::UNTRANSLATED) {
    echo "cp command :\ncp -v $originalDir/$key $translationDir\n";
  }
  echo "\n";
}

echo "Files that need translation: $countNeedsTranslation\n";
