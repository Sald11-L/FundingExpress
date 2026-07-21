<?php
/**
 * FundingExpressAi — application intake
 * Saves lead + statement files, emails sales@expressfundingai.com
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

$to = 'sales@expressfundingai.com';
$from = 'sales@expressfundingai.com';

// Support JSON or multipart (with files)
$contentType = $_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? '';
$data = null;

if (stripos($contentType, 'multipart/form-data') !== false) {
  $payload = isset($_POST['payload']) ? $_POST['payload'] : '';
  $data = json_decode($payload, true);
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

$baseDir = __DIR__ . '/uploads';
$leadDir = $baseDir . '/' . $leadId;
if (!is_dir($baseDir)) {
  @mkdir($baseDir, 0755, true);
}
if (!is_dir($leadDir)) {
  @mkdir($leadDir, 0755, true);
}

// Save uploaded statement files
$savedFiles = [];
if (!empty($_FILES['statements']) && is_array($_FILES['statements']['name'])) {
  $count = count($_FILES['statements']['name']);
  for ($i = 0; $i < $count; $i++) {
    if ($_FILES['statements']['error'][$i] !== UPLOAD_ERR_OK) continue;
    $orig = basename((string)$_FILES['statements']['name'][$i]);
    $orig = preg_replace('/[^A-Za-z0-9._-]/', '_', $orig);
    if ($orig === '' || $orig === '.' || $orig === '..') $orig = 'statement_' . ($i + 1) . '.bin';
    $dest = $leadDir . '/' . $orig;
    if (@move_uploaded_file($_FILES['statements']['tmp_name'][$i], $dest)) {
      $savedFiles[] = [
        'name' => $orig,
        'path' => $dest,
        'size' => (int)$_FILES['statements']['size'][$i],
      ];
    }
  }
}

$data['leadId'] = $leadId;
$data['savedFiles'] = array_map(function ($f) {
  return $f['name'] . ' (' . $f['size'] . ' bytes)';
}, $savedFiles);

// Backup JSON on server (File Manager > uploads/{leadId}/application.json)
@file_put_contents($leadDir . '/application.json', json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

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
    $lines[] = ' - ' . $f['name'] . ' (' . number_format($f['size'] / 1024, 1) . ' KB) [attached + saved on server]';
  }
  $lines[] = 'Server folder: uploads/' . $leadId . '/';
} else {
  $lines[] = 'No statement files uploaded with this application.';
  if (!empty($revenue['statementFiles']) && is_array($revenue['statementFiles'])) {
    $lines[] = 'Client listed:';
    foreach ($revenue['statementFiles'] as $f) {
      $lines[] = ' - ' . $f;
    }
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

// Build multipart email with attachments
$boundary = 'bnd_' . md5(uniqid((string)mt_rand(), true));
$headers = [];
$headers[] = 'From: FundingExpressAi <' . $from . '>';
$headers[] = 'Reply-To: ' . $replyTo;
$headers[] = 'MIME-Version: 1.0';
$headers[] = 'Content-Type: multipart/mixed; boundary="' . $boundary . '"';
$headers[] = 'X-Mailer: FundingExpressAi';

$message = '';
$message .= '--' . $boundary . "\r\n";
$message .= "Content-Type: text/plain; charset=UTF-8\r\n";
$message .= "Content-Transfer-Encoding: 8bit\r\n\r\n";
$message .= $bodyText . "\r\n";

foreach ($savedFiles as $f) {
  if (!is_file($f['path'])) continue;
  $content = file_get_contents($f['path']);
  if ($content === false) continue;
  // Skip very large attachments in email (>8MB) — still saved on server
  if (strlen($content) > 8 * 1024 * 1024) continue;
  $message .= '--' . $boundary . "\r\n";
  $message .= 'Content-Type: application/octet-stream; name="' . $f['name'] . "\"\r\n";
  $message .= "Content-Transfer-Encoding: base64\r\n";
  $message .= 'Content-Disposition: attachment; filename="' . $f['name'] . "\"\r\n\r\n";
  $message .= chunk_split(base64_encode($content)) . "\r\n";
}

$message .= '--' . $boundary . "--\r\n";

$encodedSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
$mailOk = @mail($to, $encodedSubject, $message, implode("\r\n", $headers));

// Always treat as success if lead was saved on disk (email may still arrive later / land in spam)
$savedOk = is_file($leadDir . '/application.json');

if ($mailOk || $savedOk) {
  echo json_encode([
    'ok' => true,
    'to' => $to,
    'mailOk' => (bool)$mailOk,
    'leadId' => $leadId,
    'filesSaved' => count($savedFiles),
  ]);
} else {
  http_response_code(500);
  echo json_encode(['ok' => false, 'error' => 'Could not save or email application']);
}
