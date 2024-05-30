#!/usr/bin/php
<?php
define('DEBUG', true);
define('TRANSLATION_DIR', './');
define('ORIGINAL_DIR', '../topodroid/assets/man/');
define('MAN_PAGE_EXTENSION', 'htm');
define('SECONDS_PER_DAY', 24 * 60 * 60);

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

enum RevisionStatus: string
{
  case OK = 'OK';
  case OUTDATED = 'OUTDATED';
  case MISSING_REVISION = 'MISSING_REVISION';
  case WAITING_TRANSLATION = 'WAITING_TRANSLATION';
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

function lerRevisao($dom) {
  $revisionElement = $dom->getElementById('revision');
  if ($revisionElement) {
    return $revisionElement->nodeValue;
  } else {
    return false;
  }
}

function lerDataUltimaAtualizacao($dom) {
  $lastUpdateElement = $dom->getElementById('last-update-date');
  if ($lastUpdateElement) {
    return $lastUpdateElement->nodeValue;
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
  static $identifiedCommits = [];

  $currentDir = getcwd();
  chdir(ORIGINAL_DIR);

  if (!key_exists($commitId, $identifiedCommits)) {
    exec("git cat-file -t $commitId 2>&1", $gitCatFileOutput, $return_var);
    if ($return_var !== 0) {
      chdir($currentDir);
      return TranslationStatus::NONEXISTENT_COMMIT;
    }
    $identifiedCommits[$commitId] = true;
  }

  exec("git log --pretty=format:'%H' $commitId.. -- $arquivo", $gitLogOutput, $return_var);
  if ($return_var !== 0) {

    chdir($currentDir);
    return TranslationStatus::MISSING_FILE_IN_GIT;
  }

  chdir($currentDir);
  if (count($gitLogOutput) > 0) {
    return [
      'translationStatus' => TranslationStatus::OUTDATED,
    ];
  } else {
    return [
      'translationStatus' => TranslationStatus::OK,
    ];
  }
}

function readTranslationRevisionData($arquivo) {
  global $translatedFiles;

  $dom = carregarHTML($arquivo);
  $commit = lerCommit($dom);

  $translatedFiles[$arquivo] = [];
  $translatedFiles[$arquivo]['revision'] = lerRevisao($dom);
  $translatedFiles[$arquivo]['dateLastUpdate'] = lerDataUltimaAtualizacao($dom);
  $translatedFiles[$arquivo]['lastTranslatedCommit'] = $commit;

  if ($commit === false) {
    $translatedFiles[$arquivo]['translationStatus'] = TranslationStatus::ERROR;
    return;
  }
}

function verificarRevisaoAtualizada($arquivo) {
  global $translatedFiles;

  if ($translatedFiles[$arquivo]['translationStatus'] !== TranslationStatus::OK) {
    $translatedFiles[$arquivo]['revisionStatus'] = RevisionStatus::WAITING_TRANSLATION;
    return;
  }

  if ($translatedFiles[$arquivo]['revision'] === false) {
    $translatedFiles[$arquivo]['revisionStatus'] = RevisionStatus::MISSING_REVISION;
    return;
  }

  $timestampLastUpdate = strtotime($translatedFiles[$arquivo]['dateLastUpdate'])
    + SECONDS_PER_DAY;
  $timestampLastCommit = getCommitTimestamp($translatedFiles[$arquivo]['lastTranslatedCommit']);
  if ($timestampLastUpdate >= $timestampLastCommit) {
    $translatedFiles[$arquivo]['revisionStatus'] = RevisionStatus::OK;
  } else {
    $translatedFiles[$arquivo]['revisionStatus'] = RevisionStatus::OUTDATED;
    $translatedFiles[$arquivo]['firstCommitForRevision'] =
      getCommitAfterDate($timestampLastUpdate);
    $translatedFiles[$arquivo]['relevantCommitsForRevision'] = getRelevantCommitsForRevision(
      $translatedFiles[$arquivo]['firstCommitForRevision'],
      $arquivo
    );
  }
}

function getRelevantCommitsForRevision($firstCommit, $file) {
  exec("git log $firstCommit..HEAD --format='%h' -- $file", $gitLogOutput, $return_var);

  $commits = [];

  foreach($gitLogOutput as $commit) {
    $commits[] = $commit;
  }

  return $commits;
}

function getCommitAfterDate($timestamp) {
  exec(
    "git rev-list --all --reverse --after=$timestamp | head -n1 | xargs git rev-parse --short",
    $gitLogOutput,
    $return_var
  );

  if ($return_var !== 0) {
    return false;
  }

  return $gitLogOutput[0];
}

function getCommitTimestamp($commitId) {
  static $commitTimestamps = [];

  if (key_exists($commitId, $commitTimestamps)) {
    return $commitTimestamps[$commitId];
  }

  $currentDir = getcwd();
  chdir(ORIGINAL_DIR);
  exec("git show -s --format=%ct $commitId", $gitShowOutput, $return_var);
  chdir($currentDir);

  if ($return_var !== 0) {
    return false;
  }

  $commitTimestamps[$commitId] = (int)$gitShowOutput[0];

  return $commitTimestamps[$commitId];
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
    $translatedFiles[$file]['translationStatus'] = TranslationStatus::MISSING_FILE_IN_DIRECTORY;
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

$processedFiles = 0;
$terminalWidth = getTerminalWidth();
foreach ($translatedFiles as $key => $value) {
  showProgressBar($processedFiles++, $countTranslatedFiles, $terminalWidth);

  readTranslationRevisionData($key);
  if ($value !== true) {
    continue;
  }

  $commit = $translatedFiles[$key]['lastTranslatedCommit'];

  if ($commit === false) {
    $translatedFiles[$key]['translationStatus'] = TranslationStatus::UNTRANSLATED;
  }
  else {
    $translatedFiles[$key]['lastTranslatedCommit'] = $commit;
    $translatedFiles[$key] += verificarTraducaoAtualizada($key, $commit);
  }

  verificarRevisaoAtualizada($key);
}
showProgressBar($processedFiles, $countTranslatedFiles, $terminalWidth);

$countNeedsRevision = 0;
echo "\n\n----------------------------------------------\n";
echo "Arquivos que precisam de revisão:\n\n";
foreach ($translatedFiles as $key => $value) {
  if (is_array($value)
    && key_exists('revisionStatus', $value)
    && ($value['revisionStatus'] === RevisionStatus::OK)) {
    continue;
  }
  $countNeedsRevision++;

  if ($value['revisionStatus'] === RevisionStatus::OUTDATED) {
    echo "------------------------\n";
  }

  echo "File: $key - Status: {$value['revisionStatus']->value}\n";

  if ($value['revisionStatus'] === RevisionStatus::OUTDATED) {
    echo "\nGit command for relevant commits:\ngit log {$value['firstCommitForRevision']}..HEAD --oneline -- $key\n";
    echo "\nGit commands for changes in each relevant commit:\n";
    foreach ($value['relevantCommitsForRevision'] as $commit) {
      echo "git diff $commit^ $commit $key\n";
    }
    echo "------------------------\n";
  }
  echo "\n";
}

echo "Files that need revision: $countNeedsRevision\n";
echo "----------------------------------------------\n\n";

$countNeedsTranslation = 0;
echo "\n\n----------------------------------------------\n";
echo "Arquivos que precisam de tradução:\n\n";
foreach ($translatedFiles as $key => $value) {
  if (is_array($value)
    && key_exists('translationStatus', $value)
    && ($value['translationStatus'] === TranslationStatus::OK)) {
    continue;
  }
  $countNeedsTranslation++;
  echo "File: $key - Status: {$value['translationStatus']->value}\n";

  if ($value['translationStatus'] === TranslationStatus::OUTDATED) {
    echo "Git command for changes:\ngit diff " . $value['lastTranslatedCommit'] . ".. $key\n";
  }
  else if ($value['translationStatus'] === TranslationStatus::UNTRANSLATED) {
    echo "cp command:\ncp -v $originalDir/$key $translationDir\n";
  }
  echo "\n";
}

echo "Files that need translation: $countNeedsTranslation\n";
echo "----------------------------------------------\n\n";
