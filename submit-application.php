<?php
/**
 * FundingExpressAi — application intake
 * Saves lead + statement files, emails sales@expressfundingai.com with attachments
 *
 * Setup: /submit-application.php?setup=1
 * Status: /submit-application.php?status=1
 * Test send: /submit-application.php?testsend=1
 */
header('X-Content-Type-Options: nosniff');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
  http_response_code(204);
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

$smtpConfigured = !empty($mailCfg['smtp_pass']) && $mailCfg['smtp_pass'] !== 'PUT_SALES_MAILBOX_PASSWORD_HERE';
$toAddress = $mailCfg['to'] ?? 'sales@expressfundingai.com';
$logDir = __DIR__ . DIRECTORY_SEPARATOR . 'uploads';
if (!is_dir($logDir)) @mkdir($logDir, 0755, true);

function fe_mail_log($line) {
  global $logDir;
  @file_put_contents($logDir . DIRECTORY_SEPARATOR . 'mail-log.txt', date('c') . ' ' . $line . "\n", FILE_APPEND);
}

/** Deliver using every available channel; FormSubmit is primary for Hostinger free email. */
function fe_deliver_application_email($mailCfg, $smtpConfigured, $to, $from, $fromName, $replyTo, $subject, $bodyText, $attachPayload = []) {
  $channels = [];
  $mailOk = false;
  $mailVia = [];
  $mailError = [];
  $needsActivation = false;

  // Channel A — FormSubmit (external relay; activate once via /activate-email.html)
  $fs = fe_formsubmit_send($to, $subject, $bodyText, $replyTo ?: $to, $attachPayload);
  $channels['formsubmit'] = $fs;
  if (!empty($fs['ok'])) {
    $mailOk = true;
    $mailVia[] = 'formsubmit';
    if (!empty($fs['needsActivation'])) $needsActivation = true;
    $respMsg = '';
    if (!empty($fs['response'])) {
      $decoded = json_decode($fs['response'], true);
      if (is_array($decoded) && !empty($decoded['message'])) $respMsg = (string)$decoded['message'];
    }
    if ($respMsg !== '' && (stripos($respMsg, 'activat') !== false || stripos($respMsg, 'confirm') !== false)) {
      $needsActivation = true;
    }
  } else {
    $mailError[] = 'formsubmit: ' . ($fs['error'] ?? 'fail');
  }

  // Channel B — SMTP (Hostinger / Titan)
  if ($smtpConfigured) {
    $smtpResult = fe_smtp_send_auto($mailCfg, [
      'to' => $to,
      'from' => $from,
      'from_name' => $fromName,
      'reply_to' => $replyTo ?: $from,
      'subject' => $subject,
      'body' => $bodyText,
      'attachments' => $attachPayload,
    ]);
    $channels['smtp'] = [
      'ok' => !empty($smtpResult['ok']),
      'error' => $smtpResult['error'] ?? '',
      'host' => $smtpResult['host'] ?? '',
      'port' => $smtpResult['port'] ?? '',
    ];
    if (!empty($smtpResult['ok'])) {
      $mailOk = true;
      $mailVia[] = 'smtp:' . ($smtpResult['host'] ?? '') . ':' . ($smtpResult['port'] ?? '');
    } else {
      $mailError[] = 'smtp: ' . ($smtpResult['error'] ?? 'fail');
    }
  } else {
    $channels['smtp'] = ['ok' => false, 'error' => 'not configured'];
  }

  // Channel C — PHP mail()
  if (!$mailOk) {
    $plainHeaders = 'From: ' . $fromName . ' <' . $from . '>' . "\r\n"
      . 'Reply-To: ' . ($replyTo ?: $from) . "\r\n"
      . 'Content-Type: text/plain; charset=UTF-8' . "\r\n"
      . 'X-Mailer: FundingExpressAi';
    $encodedSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
    $phpMailOk = @mail($to, $encodedSubject, $bodyText, $plainHeaders, '-f' . $from);
    if (!$phpMailOk) $phpMailOk = @mail($to, $encodedSubject, $bodyText, $plainHeaders);
    $channels['php_mail'] = ['ok' => (bool)$phpMailOk];
    if ($phpMailOk) {
      $mailOk = true;
      $mailVia[] = 'php_mail';
    } else {
      $mailError[] = 'php_mail: false';
    }
  }

  return [
    'ok' => $mailOk,
    'via' => implode(',', $mailVia),
    'error' => implode(' | ', $mailError),
    'needsActivation' => $needsActivation,
    'channels' => $channels,
  ];
}

// ---- Status ----
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['status'])) {
  header('Content-Type: application/json; charset=utf-8');
  $logFile = $logDir . '/mail-log.txt';
  $tail = '';
  if (is_file($logFile)) {
    $lines = @file($logFile, FILE_IGNORE_NEW_LINES);
    if (is_array($lines)) $tail = implode("\n", array_slice($lines, -12));
  }
  echo json_encode([
    'ok' => true,
    'to' => $toAddress,
    'smtpConfigured' => $smtpConfigured,
    'smtpHost' => $mailCfg['smtp_host'] ?? '',
    'smtpPort' => $mailCfg['smtp_port'] ?? '',
    'configFileExists' => is_file($configFile),
    'mailLogTail' => $tail,
    'hint' => 'Open ?testsend=1 to force a test email and see channel results.',
  ], JSON_PRETTY_PRINT);
  exit;
}

// ---- Force test send (uses FormSubmit + SMTP) ----
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['testsend'])) {
  header('Content-Type: application/json; charset=utf-8');
  $subject = 'FundingExpressAi TEST ' . date('Y-m-d H:i:s T');
  $body = "This is a delivery test to sales@expressfundingai.com.\n\n"
    . "Time: " . date('c') . "\n"
    . "If email still does not arrive, open leads-viewer.php (sales@ password) — applications and bank statement files are saved there.\n"
    . "First-time FormSubmit activation: open activate-email.html and confirm the email FormSubmit sends.\n";
  $result = fe_deliver_application_email(
    $mailCfg,
    $smtpConfigured,
    $toAddress,
    $mailCfg['from'],
    $mailCfg['from_name'] ?? 'FundingExpressAi',
    $toAddress,
    $subject,
    $body,
    []
  );
  fe_mail_log('TESTSEND mailOk=' . ($result['ok'] ? '1' : '0') . ' via=' . $result['via'] . ' err=' . $result['error'] . ' activate=' . ($result['needsActivation'] ? '1' : '0'));
  $smtpOk = !empty($result['channels']['smtp']['ok']);
  echo json_encode([
    'ok' => $result['ok'],
    'to' => $toAddress,
    'mailVia' => $result['via'],
    'mailError' => $result['error'],
    'needsActivation' => $result['needsActivation'],
    'smtpConfigured' => $smtpConfigured,
    'smtpAccepted' => $smtpOk,
    'channels' => $result['channels'],
    'attachmentsNote' => 'Bank statements are attached on SMTP (and FormSubmit after activation). They are ALWAYS saved in leads-viewer.php for download.',
    'nextStep' => !$smtpOk && !$result['ok']
      ? 'Open activate-email.html, confirm FormSubmit, then retry. Or use leads-viewer.php for attachments.'
      : '1) Check sales@ webmail Inbox + Spam. 2) If empty, open https://expressfundingai.com/leads-viewer.php — files are there. 3) Activate FormSubmit via /activate-email.html if not done.',
  ], JSON_PRETTY_PRINT);
  exit;
}

// ---- Setup form ----
if (
  ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['setup']))
  || ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['fe_setup']))
) {
  header('Content-Type: text/html; charset=utf-8');
  $msg = '';
  $err = '';
  $ok = false;

  if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['fe_setup'])) {
    $pass = isset($_POST['smtp_pass']) ? trim((string)$_POST['smtp_pass']) : '';
    $host = isset($_POST['smtp_host']) ? trim((string)$_POST['smtp_host']) : 'smtp.hostinger.com';
    $port = isset($_POST['smtp_port']) ? (int)$_POST['smtp_port'] : 465;
    $secure = isset($_POST['smtp_secure']) ? trim((string)$_POST['smtp_secure']) : 'ssl';
    if ($pass === '' && !$smtpConfigured) {
      $err = 'Enter the sales@ mailbox password.';
    } else {
      if ($pass !== '') {
        $mailCfg['smtp_pass'] = $pass;
      }
      $mailCfg['to'] = 'sales@expressfundingai.com';
      $mailCfg['from'] = 'sales@expressfundingai.com';
      $mailCfg['from_name'] = 'FundingExpressAi';
      $mailCfg['smtp_host'] = $host !== '' ? $host : 'smtp.hostinger.com';
      $mailCfg['smtp_port'] = $port > 0 ? $port : 465;
      $mailCfg['smtp_secure'] = in_array($secure, ['ssl', 'tls'], true) ? $secure : 'ssl';
      $mailCfg['smtp_user'] = 'sales@expressfundingai.com';
      $mailCfg['smtp_timeout'] = 30;
      $php = "<?php\n// Auto-generated — do not commit.\nreturn " . var_export($mailCfg, true) . ";\n";
      if (@file_put_contents($configFile, $php) === false) {
        $err = 'Could not write mail-config.php. Check public_html write permissions.';
      } else {
        $smtpConfigured = !empty($mailCfg['smtp_pass']);
        $result = fe_deliver_application_email(
          $mailCfg,
          $smtpConfigured,
          $mailCfg['to'],
          $mailCfg['from'],
          $mailCfg['from_name'],
          $mailCfg['from'],
          'FundingExpressAi setup test — ' . date('H:i:s'),
          "Setup test from expressfundingai.com\n\nIf this arrived, application email delivery is working.\n",
          []
        );
        fe_mail_log('SETUP mailOk=' . ($result['ok'] ? '1' : '0') . ' via=' . $result['via'] . ' err=' . $result['error']);
        if (!empty($result['channels']['smtp']['ok'])) {
          $mailCfg['smtp_host'] = $result['channels']['smtp']['host'] ?: $mailCfg['smtp_host'];
          $mailCfg['smtp_port'] = $result['channels']['smtp']['port'] ?: $mailCfg['smtp_port'];
          $mailCfg['smtp_secure'] = ((int)$mailCfg['smtp_port'] === 587) ? 'tls' : 'ssl';
          @file_put_contents($configFile, "<?php\nreturn " . var_export($mailCfg, true) . ";\n");
        }
        if (!empty($result['ok'])) {
          $ok = true;
          $msg = 'Delivery channel OK via: ' . $result['via'] . '. Check sales@ inbox/spam now.';
          if (!empty($result['needsActivation'])) {
            $msg .= ' IMPORTANT: FormSubmit sent an activation email — open sales@ and click Confirm/Activate, then open ?testsend=1 again.';
          }
        } else {
          $err = 'All channels failed: ' . $result['error'];
        }
      }
    }
  }

  echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">';
  echo '<title>FundingExpressAi mail setup</title>';
  echo '<style>body{font-family:Segoe UI,system-ui,sans-serif;background:#f4f7f5;margin:0;padding:40px 16px;color:#10231a}.box{max-width:520px;margin:0 auto;background:#fff;border:1px solid #d7e3dc;border-radius:14px;padding:28px}h1{font-size:1.2rem;margin:0 0 8px}p{color:#5a6b63;line-height:1.5}label{display:block;font-weight:700;font-size:.85rem;margin:14px 0 6px}input,select{width:100%;box-sizing:border-box;padding:11px 12px;border:1px solid #c9d6cf;border-radius:10px}button{margin-top:18px;width:100%;padding:13px;border:0;border-radius:10px;background:#0B8F4E;color:#fff;font-weight:800;cursor:pointer}.ok{background:#e8f7ef;color:#086138;padding:12px;border-radius:10px;margin-bottom:12px;font-weight:600}.err{background:#fdecec;color:#9b1c1c;padding:12px;border-radius:10px;margin-bottom:12px;font-weight:600}.warn{background:#fff8e6;color:#7a5b00;padding:12px;border-radius:10px;margin-bottom:12px;font-weight:600}a{color:#0B8F4E;font-weight:700}</style></head><body><div class="box">';
  echo '<h1>Connect sales@ email</h1>';
  echo '<div class="warn">Hostinger free Business Email often blocks server SMTP. We also send through FormSubmit. On first use, sales@ gets an activation email — you must click it.</div>';
  echo '<p>SMTP configured: <strong>' . ($smtpConfigured ? 'yes' : 'no') . '</strong> &middot; To: <strong>' . htmlspecialchars($toAddress) . '</strong></p>';
  if ($ok && $msg) echo '<div class="ok">' . htmlspecialchars($msg) . '</div>';
  if ($err) echo '<div class="err">' . htmlspecialchars($err) . '</div>';
  echo '<form method="post"><input type="hidden" name="fe_setup" value="1">';
  echo '<label>Password for sales@ (Hostinger mailbox password)</label><input type="password" name="smtp_pass" ' . ($smtpConfigured ? '' : 'required') . ' autocomplete="current-password" placeholder="' . ($smtpConfigured ? 'Leave blank to keep current password' : 'Required') . '">';
  if ($smtpConfigured) {
    echo '<p style="font-size:.82rem">Leave password blank and submit to re-test with the saved password.</p>';
  }
  echo '<label>SMTP host</label><select name="smtp_host"><option value="smtp.hostinger.com">smtp.hostinger.com</option><option value="smtp.titan.email">smtp.titan.email</option></select>';
  echo '<label>Port</label><select name="smtp_port" id="p"><option value="465">465 SSL</option><option value="587">587 TLS</option></select>';
  echo '<label>Encryption</label><select name="smtp_secure" id="s"><option value="ssl">ssl</option><option value="tls">tls</option></select>';
  echo '<button type="submit">Save / re-test delivery</button></form>';
  echo '<p style="margin-top:18px"><a href="?testsend=1">Run JSON test send</a> &nbsp;|&nbsp; <a href="?status=1">Status / log</a></p>';
  echo '<script>document.getElementById("p").onchange=function(){document.getElementById("s").value=this.value==="587"?"tls":"ssl"};</script>';
  echo '</div></body></html>';
  exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  header('Content-Type: application/json; charset=utf-8');
  http_response_code(405);
  echo json_encode(['ok' => false, 'error' => 'Method not allowed. Use ?setup=1 to configure email.']);
  exit;
}

header('Content-Type: application/json; charset=utf-8');

$to = $mailCfg['to'];
$from = $mailCfg['from'];

$contentType = $_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? '';
$data = null;
$uploadDebug = [];

if (stripos($contentType, 'multipart/form-data') !== false || !empty($_POST)) {
  $payload = isset($_POST['payload']) ? $_POST['payload'] : '';
  $data = json_decode($payload, true);
  if (!is_array($data)) {
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

$subject = 'New FundingExpressAi application — ' . $bizName
  . (count($savedFiles) ? ' (' . count($savedFiles) . ' statement file' . (count($savedFiles) === 1 ? '' : 's') . ')' : '');

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
$lines[] = '';
$lines[] = 'If attachments are missing in this email, open: https://expressfundingai.com/leads-viewer.php';
$lines[] = '(Log in with the sales@ mailbox password — statement files are stored with each lead.)';

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

$deliverBody = $bodyText . "\r\n\r\n[Files on server: uploads/" . $leadId . "/]";
$deliver = fe_deliver_application_email(
  $mailCfg,
  $smtpConfigured,
  $to,
  $from,
  $mailCfg['from_name'] ?? 'FundingExpressAi',
  $replyTo,
  $subject,
  $deliverBody,
  $attachPayload
);
$mailOk = !empty($deliver['ok']);
$mailVia = $deliver['via'];
$mailError = $deliver['error'];
$needsActivation = !empty($deliver['needsActivation']);

fe_mail_log(
  'lead=' . $leadId . ' to=' . $to
  . ' mailOk=' . ($mailOk ? '1' : '0')
  . ' via=' . $mailVia
  . ' files=' . count($savedFiles)
  . ($mailError !== '' ? ' err=' . $mailError : '')
  . ($needsActivation ? ' NEEDS_FORMSUBMIT_ACTIVATION' : '')
);

$savedOk = is_file($leadDir . DIRECTORY_SEPARATOR . 'application.json');

if ($mailOk || $savedOk) {
  echo json_encode([
    'ok' => true,
    'to' => $to,
    'mailOk' => (bool)$mailOk,
    'mailVia' => $mailVia,
    'mailError' => $mailOk ? '' : $mailError,
    'needsActivation' => $needsActivation,
    'smtpConfigured' => $smtpConfigured,
    'channels' => $deliver['channels'],
    'leadId' => $leadId,
    'filesSaved' => count($savedFiles),
    'filesAttached' => count($attachPayload),
  ]);
} else {
  http_response_code(500);
  echo json_encode(['ok' => false, 'error' => 'Could not save or email application', 'mailError' => $mailError]);
}
