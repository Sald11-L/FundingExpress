<?php
/**
 * Secure leads inbox — view applications + download bank statements
 * even when Hostinger email delivery fails.
 *
 * URL: https://expressfundingai.com/leads-viewer.php
 * Password: same as sales@ mailbox password (from mail-config.php)
 */
header('X-Content-Type-Options: nosniff');
session_start();

$configFile = __DIR__ . '/mail-config.php';
$mailCfg = is_file($configFile) ? include $configFile : [];
$pass = is_array($mailCfg) ? (string)($mailCfg['smtp_pass'] ?? '') : '';
$uploads = __DIR__ . '/uploads';

function fe_viewer_authed($pass) {
  return $pass !== '' && !empty($_SESSION['fe_leads_ok']) && hash_equals($pass, (string)$_SESSION['fe_leads_ok']);
}

// Download a statement file
if (isset($_GET['dl']) && fe_viewer_authed($pass)) {
  $lead = preg_replace('/[^A-Za-z0-9._-]/', '', (string)$_GET['lead']);
  $file = basename((string)$_GET['dl']);
  $path = $uploads . '/' . $lead . '/' . $file;
  if ($lead && $file && is_file($path) && strpos(realpath($path), realpath($uploads)) === 0) {
    $mime = 'application/octet-stream';
    $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
    if ($ext === 'pdf') $mime = 'application/pdf';
    if ($ext === 'png') $mime = 'image/png';
    if ($ext === 'jpg' || $ext === 'jpeg') $mime = 'image/jpeg';
    header('Content-Type: ' . $mime);
    header('Content-Disposition: attachment; filename="' . $file . '"');
    header('Content-Length: ' . filesize($path));
    readfile($path);
    exit;
  }
  http_response_code(404);
  echo 'File not found';
  exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $try = (string)($_POST['password'] ?? '');
  if ($pass !== '' && hash_equals($pass, $try)) {
    $_SESSION['fe_leads_ok'] = $pass;
    header('Location: leads-viewer.php');
    exit;
  }
  $error = 'Wrong password. Use the sales@ mailbox password you saved in mail setup.';
}

if (isset($_GET['logout'])) {
  unset($_SESSION['fe_leads_ok']);
  header('Location: leads-viewer.php');
  exit;
}

header('Content-Type: text/html; charset=utf-8');

if (!fe_viewer_authed($pass)) {
  echo '<!DOCTYPE html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Leads inbox</title>';
  echo '<style>body{font-family:Segoe UI,system-ui,sans-serif;background:#f4f7f5;padding:40px 16px}.box{max-width:420px;margin:0 auto;background:#fff;padding:28px;border-radius:14px;border:1px solid #d7e3dc}h1{font-size:1.2rem}input{width:100%;padding:12px;border:1px solid #c9d6cf;border-radius:10px;box-sizing:border-box}button{margin-top:14px;width:100%;padding:12px;border:0;border-radius:10px;background:#0B8F4E;color:#fff;font-weight:800}.err{color:#9b1c1c;font-weight:600}</style></head><body><div class="box">';
  echo '<h1>FundingExpressAi leads</h1><p>Enter the sales@ mailbox password to view applications and download bank statements.</p>';
  if ($pass === '') echo '<p class="err">Mail is not configured yet. Open submit-application.php?setup=1 first.</p>';
  if ($error) echo '<p class="err">' . htmlspecialchars($error) . '</p>';
  echo '<form method="post"><input type="password" name="password" required placeholder="sales@ password"><button type="submit">Open leads</button></form>';
  echo '</div></body></html>';
  exit;
}

// List leads
$leads = [];
if (is_dir($uploads)) {
  foreach (scandir($uploads, SCANDIR_SORT_DESCENDING) as $dir) {
    if ($dir === '.' || $dir === '..' || $dir === '.htaccess' || $dir === 'mail-log.txt') continue;
    $json = $uploads . '/' . $dir . '/application.json';
    if (!is_file($json)) continue;
    $data = json_decode((string)file_get_contents($json), true);
    $files = [];
    foreach (scandir($uploads . '/' . $dir) as $f) {
      if ($f === '.' || $f === '..' || $f === 'application.json') continue;
      if (is_file($uploads . '/' . $dir . '/' . $f)) $files[] = $f;
    }
    $leads[] = [
      'id' => $dir,
      'data' => is_array($data) ? $data : [],
      'files' => $files,
      'mtime' => filemtime($json),
    ];
  }
}

echo '<!DOCTYPE html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Leads inbox</title>';
echo '<style>
body{font-family:Segoe UI,system-ui,sans-serif;background:#f4f7f5;margin:0;padding:24px;color:#10231a}
.wrap{max-width:900px;margin:0 auto}
h1{font-size:1.35rem;margin:0 0 6px}
.top{display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;margin-bottom:18px}
a{color:#0B8F4E;font-weight:700;text-decoration:none}
.card{background:#fff;border:1px solid #d7e3dc;border-radius:14px;padding:18px 20px;margin-bottom:14px}
.card h2{font-size:1.05rem;margin:0 0 8px}
.meta{color:#5a6b63;font-size:.88rem;margin-bottom:10px}
pre{background:#f4f7f5;padding:12px;border-radius:10px;overflow:auto;font-size:.78rem;line-height:1.4}
.files a{display:inline-block;margin:4px 8px 4px 0;padding:6px 10px;background:#e8f7ef;border-radius:8px;font-size:.85rem}
.empty{padding:28px;background:#fff;border-radius:14px;border:1px solid #d7e3dc;color:#5a6b63}
</style></head><body><div class="wrap">';
echo '<div class="top"><div><h1>Leads inbox</h1><div class="meta">' . count($leads) . ' saved application(s) · attachments downloadable here</div></div>';
echo '<div><a href="?logout=1">Log out</a></div></div>';

if (!$leads) {
  echo '<div class="empty">No applications saved yet. Submit a test application on the website, then refresh this page.</div>';
} else {
  foreach ($leads as $lead) {
    $d = $lead['data'];
    $biz = $d['business'] ?? [];
    $est = $d['estimate'] ?? [];
    $name = $biz['legalName'] ?? 'Application';
    $email = $biz['email'] ?? '';
    $phone = $biz['phone'] ?? '';
    echo '<div class="card">';
    echo '<h2>' . htmlspecialchars($name) . '</h2>';
    echo '<div class="meta">Lead ID: ' . htmlspecialchars($lead['id']) . ' · ' . date('M j, Y g:i A', $lead['mtime']);
    if ($email) echo ' · ' . htmlspecialchars($email);
    if ($phone) echo ' · ' . htmlspecialchars($phone);
    if (!empty($est['display'])) echo ' · Estimate: ' . htmlspecialchars((string)$est['display']);
    echo '</div>';
    if ($lead['files']) {
      echo '<div class="files"><strong>Bank statements:</strong><br>';
      foreach ($lead['files'] as $f) {
        $href = '?lead=' . rawurlencode($lead['id']) . '&dl=' . rawurlencode($f);
        echo '<a href="' . htmlspecialchars($href) . '">' . htmlspecialchars($f) . '</a>';
      }
      echo '</div>';
    } else {
      echo '<div class="meta">No statement files on this lead.</div>';
    }
    echo '<details style="margin-top:10px"><summary>Full application JSON</summary><pre>' . htmlspecialchars(json_encode($d, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) . '</pre></details>';
    echo '</div>';
  }
}
echo '</div></body></html>';
