<?php
/**
 * FundingExpressAi — application intake
 * Saves lead + statement files, emails sales@expressfundingai.com with attachments
 */
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
  http_response_code(204);
  exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405);
  echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
  exit;
}

require_once __DIR__ . DIRECTORY_SEPARATOR . 'smtp-mailer.php';

$mailCfg = [
  'to' => 'sales@expressfundingai.com',
  'from' => 'sales@expressfundingai.com',
  'from_name' => 'FundingExpressAi',
  'smtp_host' => 'smtp.hostinger.com',
  'smtp_port' => 465,
  'smtp_secure' => 'ssl',
  'smtp_user' => 'sales@expressfundingai.com',
  'smtp_pass' => '',
  'smtp_timeout' => 30,
];
$configFile = __DIR__ . DIRECTORY_SEPARATOR . 'mail-config.php';
if (is_file($configFile)) {
  $loaded = include $configFile;
  if (is_array($loaded)) {
    $mailCfg = array_merge($mailCfg, $loaded);
  }
}

$to = $mailCfg['to'];
$from = $mailCfg['from'];

$contentType = $_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? '';
$data = null;
$uploadDebug = [];

if (stripos($contentType, 'multipart/form-data') !== false || !empty($_POST)) {
  $payload = isset($_POST['payload']) ? $_POST['payload'] : '';
  $data = json_decode($payload, true);
  if (!is_array($data)) {
    // Rare: some hosts leave payload only in raw body
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);
  }
} else {
  $raw = file_get_contents('php://input');
  $data = json_decode($raw, true);
}

if (!is_array($data)) {
  http_response_code(400);
  echo json_encode(['ok' => false, 'error' => 'Invalid application payload']);
  exit;
}

$biz = isset($data['business']) && is_array($data['business']) ? $data['business'] : [];
$owner = isset($data['owner']) && is_array($data['owner']) ? $data['owner'] : [];
$debts = isset($data['debts']) && is_array($data['debts']) ? $data['debts'] : [];
$revenue = isset($data['revenue']) && is_array($data['revenue']) ? $data['revenue'] : [];
$estimate = isset($data['estimate']) && is_array($data['estimate']) ? $data['estimate'] : [];

$bizName = !empty($biz['legalName']) ? $biz['legalName'] : 'New lead';
$leadId = date('Ymd-His') . '-' . substr(bin2hex(random_bytes(4)), 0, 8);

$baseDir = __DIR__ . DIRECTORY_SEPARATOR . 'uploads';
$leadDir = $baseDir . DIRECTORY_SEPARATOR . $leadId;
if (!is_dir($baseDir)) {
  @mkdir($baseDir, 0755, true);
}
if (!is_dir($leadDir)) {
  @mkdir($leadDir, 0755, true);
}

/**
 * Normalize $_FILES entry into a list of single-file arrays.
 */
function fe_normalize_files($fileInfo) {
  $out = [];
  if (!is_array($fileInfo) || !isset($fileInfo['name'])) return $out;
  if (is_array($fileInfo['name'])) {
    foreach ($fileInfo['name'] as $i => $name) {
      $out[] = [
        'name' => $name,
        'type' => $fileInfo['type'][$i] ?? 'application/octet-stream',
        'tmp_name' => $fileInfo['tmp_name'][$i] ?? '',
        'error' => $fileInfo['error'][$i] ?? UPLOAD_ERR_NO_FILE,
        'size' => $fileInfo['size'][$i] ?? 0,
      ];
    }
  } else {
    $out[] = $fileInfo;
  }
  return $out;
}

function fe_safe_filename($name, $fallback) {
  $orig = basename((string)$name);
  $orig = preg_replace('/[^A-Za-z0-9._-]/', '_', $orig);
  if ($orig === '' || $orig === '.' || $orig === '..') return $fallback;
  return $orig;
}

function fe_mime_for($name, $fallback = 'application/octet-stream') {
  $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
  $map = [
    'pdf' => 'application/pdf',
    'png' => 'image/png',
    'jpg' => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'gif' => 'image/gif',
    'webp' => 'image/webp',
  ];
  return $map[$ext] ?? $fallback;
}

$savedFiles = [];
$seenNames = [];

// Path A: multipart binary uploads
$uploadDebug['files_keys'] = array_keys($_FILES);
foreach ($_FILES as $key => $info) {
  if (stripos($key, 'statement') === false) continue;
  foreach (fe_normalize_files($info) as $file) {
    $err = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($err !== UPLOAD_ERR_OK) {
      $uploadDebug['multipart_errors'][] = ($file['name'] ?? '?') . ' err=' . $err;
      continue;
    }
    $orig = fe_safe_filename($file['name'] ?? '', 'statement_' . (count($savedFiles) + 1) . '.bin');
    // Avoid overwrite
    if (isset($seenNames[$orig])) {
      $orig = (count($savedFiles) + 1) . '_' . $orig;
    }
    $dest = $leadDir . DIRECTORY_SEPARATOR . $orig;
    if (@move_uploaded_file($file['tmp_name'], $dest)) {
      $seenNames[$orig] = true;
      $savedFiles[] = [
        'name' => $orig,
        'path' => $dest,
        'size' => (int)($file['size'] ?? filesize($dest)),
        'mime' => fe_mime_for($orig, $file['type'] ?? 'application/octet-stream'),
        'source' => 'multipart',
      ];
    } else {
      $uploadDebug['multipart_errors'][] = $orig . ' move_failed';
    }
  }
}

// Path B: base64 embedded in JSON (survives hosts that strip multipart files)
if (!empty($data['statementUploads']) && is_array($data['statementUploads'])) {
  foreach ($data['statementUploads'] as $idx => $up) {
    if (!is_array($up) || empty($up['data'])) continue;
    $orig = fe_safe_filename($up['name'] ?? '', 'statement_' . ($idx + 1) . '.bin');
    if (isset($seenNames[$orig])) {
      // Already saved via multipart — skip duplicate
      continue;
    }
    $raw = base64_decode((string)$up['data'], true);
    if ($raw === false || $raw === '') {
      $uploadDebug['base64_errors'][] = $orig . ' decode_failed';
      continue;
    }
    // Cap each file at 8MB
    if (strlen($raw) > 8 * 1024 * 1024) {
      $uploadDebug['base64_errors'][] = $orig . ' too_large';
      continue;
    }
    $dest = $leadDir . DIRECTORY_SEPARATOR . $orig;
    if (@file_put_contents($dest, $raw) !== false) {
      $seenNames[$orig] = true;
      $savedFiles[] = [
        'name' => $orig,
        'path' => $dest,
        'size' => strlen($raw),
        'mime' => fe_mime_for($orig, $up['type'] ?? 'application/octet-stream'),
        'source' => 'base64',
      ];
    } else {
      $uploadDebug['base64_errors'][] = $orig . ' write_failed';
    }
  }
}

// Strip bulky base64 from backup JSON (keep names only)
unset($data['statementUploads']);
$data['leadId'] = $leadId;
$data['savedFiles'] = array_map(function ($f) {
  return $f['name'] . ' (' . $f['size'] . ' bytes, ' . $f['source'] . ')';
}, $savedFiles);
$data['uploadDebug'] = $uploadDebug;

@file_put_contents(
  $leadDir . DIRECTORY_SEPARATOR . 'application.json',
  json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
);

$subject = 'New FundingExpressAi application — ' . $bizName;

$lines = [];
$lines[] = 'NEW APPLICATION — FundingExpressAi';
$lines[] = 'Lead ID: ' . $leadId;
$lines[] = 'Submitted: ' . (isset($data['submittedAt']) ? $data['submittedAt'] : date('c'));
$lines[] = 'Product: ' . (isset($data['product']) ? $data['product'] : 'N/A');
$lines[] = '';
$lines[] = '=== BUSINESS ===';
$lines[] = 'Legal name: ' . ($biz['legalName'] ?? '');
$lines[] = 'DBA: ' . ($biz['dba'] ?? '');
$lines[] = 'State: ' . ($biz['state'] ?? '');
$lines[] = 'Address: ' . ($biz['address'] ?? '');
$lines[] = 'Email: ' . ($biz['email'] ?? '');
$lines[] = 'Phone: ' . ($biz['phone'] ?? '');
$lines[] = 'EIN: ' . ($biz['ein'] ?? '');
$lines[] = 'Start date: ' . ($biz['startDate'] ?? '');
$lines[] = '';
$lines[] = '=== OWNER ===';
$lines[] = 'Name: ' . ($owner['name'] ?? '');
$lines[] = 'Title: ' . ($owner['title'] ?? '');
$lines[] = 'Ownership %: ' . ($owner['ownership'] ?? '');
$lines[] = 'DOB: ' . ($owner['dob'] ?? '');
$lines[] = 'Home address: ' . ($owner['homeAddress'] ?? '');
$lines[] = 'SSN last 4: ' . ($owner['ssnLast4'] ?? '');
$lines[] = 'Credit band: ' . ($owner['credit'] ?? '');
$lines[] = 'Cell: ' . ($owner['phone'] ?? '');
$lines[] = 'Second owner: ' . ($owner['secondOwner'] ?? '');
$lines[] = '';
$lines[] = '=== DEBTS / PROFILE ===';
$lines[] = 'Existing MCA/loan: ' . ($debts['hasExisting'] ?? '');
$lines[] = 'Takes cards: ' . ($debts['takesCards'] ?? '');
$lines[] = 'Owns home: ' . ($debts['ownsHome'] ?? '');
$lines[] = 'Credit enhance interest: ' . ($debts['creditEnhance'] ?? '');
if (!empty($debts['lenders']) && is_array($debts['lenders'])) {
  foreach ($debts['lenders'] as $i => $l) {
    $n = is_array($l) ? ($l['name'] ?? '') : '';
    $b = is_array($l) ? ($l['balance'] ?? '') : '';
    $lines[] = 'Lender ' . ($i + 1) . ': ' . $n . ' | Balance: ' . $b;
  }
}
$lines[] = '';
$lines[] = '=== REVENUE ===';
if (!empty($revenue['months']) && is_array($revenue['months'])) {
  foreach ($revenue['months'] as $month => $amt) {
    $lines[] = $month . ': $' . number_format((float)$amt, 0);
  }
}
$lines[] = 'Average monthly: $' . number_format((float)($revenue['average'] ?? 0), 0);
$lines[] = 'Statement consent: ' . (!empty($revenue['statementConsent']) ? 'YES' : 'NO');
$lines[] = '';
$lines[] = '=== BANK STATEMENTS ===';
if (count($savedFiles)) {
  foreach ($savedFiles as $f) {
    $lines[] = ' - ' . $f['name'] . ' (' . number_format($f['size'] / 1024, 1) . ' KB) via ' . $f['source'] . ' — ATTACHED';
  }
  $lines[] = 'Also saved on server: uploads/' . $leadId . '/';
} else {
  $lines[] = 'No statement files could be saved.';
  if (!empty($revenue['statementFiles']) && is_array($revenue['statementFiles'])) {
    $lines[] = 'Client selected in browser:';
    foreach ($revenue['statementFiles'] as $f) {
      $lines[] = ' - ' . $f;
    }
  }
  if (!empty($uploadDebug)) {
    $lines[] = 'Upload debug: ' . json_encode($uploadDebug);
  }
}
$lines[] = '';
$lines[] = '=== AI ESTIMATE ===';
$lines[] = 'Tier: ' . ($estimate['tier'] ?? '');
$lines[] = 'Range: ' . ($estimate['display'] ?? '');
$lines[] = 'Avg used: $' . number_format((float)($estimate['averageRevenue'] ?? 0), 0);
$lines[] = '';
$lines[] = 'Reply to the applicant business email to continue this lead.';

$bodyText = implode("\r\n", $lines);
$replyTo = !empty($biz['email']) ? $biz['email'] : $from;

$attachPayload = [];
foreach ($savedFiles as $f) {
  if (!is_file($f['path'])) continue;
  $attachPayload[] = [
    'path' => $f['path'],
    'name' => $f['name'],
    'mime' => $f['mime'] ?: 'application/octet-stream',
  ];
}

$mailOk = false;
$mailError = '';
$mailVia = '';

// Preferred: authenticated SMTP (Hostinger requires this for reliable delivery)
if (!empty($mailCfg['smtp_pass']) && $mailCfg['smtp_pass'] !== 'PUT_SALES_MAILBOX_PASSWORD_HERE') {
  $smtpResult = fe_smtp_send($mailCfg, [
    'to' => $to,
    'from' => $from,
    'from_name' => $mailCfg['from_name'] ?? 'FundingExpressAi',
    'reply_to' => $replyTo,
    'subject' => $subject,
    'body' => $bodyText,
    'attachments' => $attachPayload,
  ]);
  $mailOk = !empty($smtpResult['ok']);
  $mailError = $smtpResult['error'] ?? '';
  $mailVia = $mailOk ? ('smtp:' . ($smtpResult['host'] ?? 'ok')) : 'smtp-failed';
}

// Fallback: PHP mail() plain text (often unreliable on Hostinger; no attachments)
if (!$mailOk) {
  $plainHeaders = 'From: FundingExpressAi <' . $from . '>' . "\r\n"
    . 'Reply-To: ' . $replyTo . "\r\n"
    . 'Content-Type: text/plain; charset=UTF-8' . "\r\n"
    . 'X-Mailer: FundingExpressAi';
  $encodedSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
  $fallbackBody = $bodyText . "\r\n\r\n[Note: attachments are saved on the server under uploads/" . $leadId . "/]";
  $mailOk = @mail($to, $encodedSubject, $fallbackBody, $plainHeaders, '-f' . $from);
  if (!$mailOk) {
    $mailOk = @mail($to, $encodedSubject, $fallbackBody, $plainHeaders);
  }
  if ($mailOk) {
    $mailVia = $mailVia === 'smtp-failed' ? 'mail-fallback-after-smtp' : 'mail';
  } elseif ($mailError === '') {
    $mailError = 'PHP mail() returned false. Configure SMTP via setup-mail.php';
  }
}

@file_put_contents(
  $baseDir . DIRECTORY_SEPARATOR . 'mail-log.txt',
  date('c') . ' lead=' . $leadId . ' to=' . $to
    . ' mailOk=' . ($mailOk ? '1' : '0')
    . ' via=' . $mailVia
    . ' files=' . count($savedFiles)
    . ($mailError !== '' ? ' err=' . $mailError : '')
    . "\n",
  FILE_APPEND
);

$savedOk = is_file($leadDir . DIRECTORY_SEPARATOR . 'application.json');

if ($mailOk || $savedOk) {
  echo json_encode([
    'ok' => true,
    'to' => $to,
    'mailOk' => (bool)$mailOk,
    'mailVia' => $mailVia,
    'mailError' => $mailOk ? '' : $mailError,
    'smtpConfigured' => !empty($mailCfg['smtp_pass']) && $mailCfg['smtp_pass'] !== 'PUT_SALES_MAILBOX_PASSWORD_HERE',
    'leadId' => $leadId,
    'filesSaved' => count($savedFiles),
    'filesAttached' => count($attachPayload),
  ]);
} else {
  http_response_code(500);
  echo json_encode(['ok' => false, 'error' => 'Could not save or email application', 'mailError' => $mailError]);
}
