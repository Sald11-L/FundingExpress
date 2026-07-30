<?php
/**
 * Lightweight SMTP client for Hostinger (no Composer).
 * Supports SSL (465) and STARTTLS (587), with auto host/port retry.
 */

function fe_smtp_send(array $cfg, array $mail) {
  $host = $cfg['smtp_host'] ?? 'smtp.hostinger.com';
  $port = (int)($cfg['smtp_port'] ?? 465);
  $secure = strtolower((string)($cfg['smtp_secure'] ?? 'ssl')); // ssl | tls
  $user = (string)($cfg['smtp_user'] ?? '');
  $pass = (string)($cfg['smtp_pass'] ?? '');
  $timeout = (int)($cfg['smtp_timeout'] ?? 30);

  $to = (string)($mail['to'] ?? '');
  $from = (string)($mail['from'] ?? $user);
  $fromName = (string)($mail['from_name'] ?? 'FundingExpressAi');
  $replyTo = (string)($mail['reply_to'] ?? $from);
  $subject = (string)($mail['subject'] ?? 'Application');
  $body = (string)($mail['body'] ?? '');
  $attachments = isset($mail['attachments']) && is_array($mail['attachments']) ? $mail['attachments'] : [];

  if ($to === '' || $user === '' || $pass === '') {
    return ['ok' => false, 'error' => 'SMTP not configured (missing to/user/pass)'];
  }

  $remote = ($secure === 'ssl' ? 'ssl://' : '') . $host;
  $errno = 0;
  $errstr = '';
  $fp = @stream_socket_client($remote . ':' . $port, $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT);
  if (!$fp) {
    return ['ok' => false, 'error' => "SMTP connect failed ($host:$port): $errstr ($errno)"];
  }
  stream_set_timeout($fp, $timeout);

  $read = function () use ($fp) {
    $data = '';
    while (!feof($fp)) {
      $line = fgets($fp, 515);
      if ($line === false) break;
      $data .= $line;
      if (isset($line[3]) && $line[3] === ' ') break;
    }
    return $data;
  };
  $write = function ($cmd) use ($fp) {
    return fwrite($fp, $cmd . "\r\n");
  };
  $expect = function ($codes) use ($read) {
    $resp = $read();
    $code = (int)substr($resp, 0, 3);
    $ok = false;
    foreach ((array)$codes as $c) {
      if ($code === (int)$c) { $ok = true; break; }
    }
    return [$ok, $code, trim($resp)];
  };

  list($ok, $code, $resp) = $expect(220);
  if (!$ok) { fclose($fp); return ['ok' => false, 'error' => "SMTP greeting: $resp"]; }

  $write('EHLO expressfundingai.com');
  list($ok, $code, $resp) = $expect(250);
  if (!$ok) {
    $write('HELO expressfundingai.com');
    list($ok, $code, $resp) = $expect(250);
    if (!$ok) { fclose($fp); return ['ok' => false, 'error' => "EHLO/HELO failed: $resp"]; }
  }

  if ($secure === 'tls') {
    $write('STARTTLS');
    list($ok, $code, $resp) = $expect(220);
    if (!$ok) { fclose($fp); return ['ok' => false, 'error' => "STARTTLS failed: $resp"]; }
    if (!stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
      fclose($fp);
      return ['ok' => false, 'error' => 'TLS negotiation failed'];
    }
    $write('EHLO expressfundingai.com');
    list($ok, $code, $resp) = $expect(250);
    if (!$ok) { fclose($fp); return ['ok' => false, 'error' => "EHLO after TLS failed: $resp"]; }
  }

  $write('AUTH LOGIN');
  list($ok, $code, $resp) = $expect(334);
  if (!$ok) { fclose($fp); return ['ok' => false, 'error' => "AUTH LOGIN rejected: $resp"]; }
  $write(base64_encode($user));
  list($ok, $code, $resp) = $expect(334);
  if (!$ok) { fclose($fp); return ['ok' => false, 'error' => "SMTP username rejected: $resp"]; }
  $write(base64_encode($pass));
  list($ok, $code, $resp) = $expect(235);
  if (!$ok) { fclose($fp); return ['ok' => false, 'error' => "SMTP password rejected on $host:$port — use the sales@ mailbox password"]; }

  $write('MAIL FROM:<' . $from . '>');
  list($ok, $code, $resp) = $expect(250);
  if (!$ok) { fclose($fp); return ['ok' => false, 'error' => "MAIL FROM failed: $resp"]; }

  $write('RCPT TO:<' . $to . '>');
  list($ok, $code, $resp) = $expect([250, 251]);
  if (!$ok) { fclose($fp); return ['ok' => false, 'error' => "RCPT TO failed: $resp"]; }

  $write('DATA');
  list($ok, $code, $resp) = $expect(354);
  if (!$ok) { fclose($fp); return ['ok' => false, 'error' => "DATA failed: $resp"]; }

  $boundary = '=_FE_' . md5(uniqid((string)mt_rand(), true));
  $encodedSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
  $safeFromName = str_replace(['"', "\r", "\n"], '', $fromName);
  $bodyCrLf = str_replace(["\r\n", "\r", "\n"], ["\n", "\n", "\r\n"], $body);

  $data = '';
  $data .= 'Date: ' . date('r') . "\r\n";
  $data .= 'From: ' . $safeFromName . ' <' . $from . ">\r\n";
  $data .= 'To: <' . $to . ">\r\n";
  $data .= 'Reply-To: <' . $replyTo . ">\r\n";
  $data .= 'Subject: ' . $encodedSubject . "\r\n";
  $data .= "MIME-Version: 1.0\r\n";
  $data .= "X-Mailer: FundingExpressAi-SMTP\r\n";
  $data .= 'Content-Type: multipart/mixed; boundary="' . $boundary . "\"\r\n\r\n";
  $data .= '--' . $boundary . "\r\n";
  $data .= "Content-Type: text/plain; charset=UTF-8\r\n";
  $data .= "Content-Transfer-Encoding: 8bit\r\n\r\n";
  $data .= $bodyCrLf . "\r\n";

  foreach ($attachments as $att) {
    if (empty($att['path']) || !is_file($att['path'])) continue;
    $content = @file_get_contents($att['path']);
    if ($content === false) continue;
    if (strlen($content) > 8 * 1024 * 1024) continue;
    $name = str_replace(['"', "\r", "\n"], '', $att['name'] ?? basename($att['path']));
    $mime = $att['mime'] ?? 'application/octet-stream';
    $data .= '--' . $boundary . "\r\n";
    $data .= 'Content-Type: ' . $mime . '; name="' . $name . "\"\r\n";
    $data .= "Content-Transfer-Encoding: base64\r\n";
    $data .= 'Content-Disposition: attachment; filename="' . $name . "\"\r\n\r\n";
    $data .= chunk_split(base64_encode($content)) . "\r\n";
  }
  $data .= '--' . $boundary . "--\r\n";
  $data = preg_replace('/^\./m', '..', $data);
  fwrite($fp, $data . "\r\n.\r\n");
  list($ok, $code, $resp) = $expect(250);
  if (!$ok) { fclose($fp); return ['ok' => false, 'error' => "Message rejected: $resp"]; }

  $write('QUIT');
  fclose($fp);
  return ['ok' => true, 'error' => '', 'host' => $host, 'port' => $port];
}

/** Try common Hostinger / Titan SMTP combos until one works. */
function fe_smtp_send_auto(array $cfg, array $mail) {
  $attempts = [
    ['smtp_host' => 'smtp.hostinger.com', 'smtp_port' => 465, 'smtp_secure' => 'ssl'],
    ['smtp_host' => 'smtp.hostinger.com', 'smtp_port' => 587, 'smtp_secure' => 'tls'],
    ['smtp_host' => 'smtp.titan.email', 'smtp_port' => 465, 'smtp_secure' => 'ssl'],
    ['smtp_host' => 'smtp.titan.email', 'smtp_port' => 587, 'smtp_secure' => 'tls'],
  ];
  // Prefer configured host first
  if (!empty($cfg['smtp_host'])) {
    array_unshift($attempts, [
      'smtp_host' => $cfg['smtp_host'],
      'smtp_port' => (int)($cfg['smtp_port'] ?? 465),
      'smtp_secure' => $cfg['smtp_secure'] ?? 'ssl',
    ]);
  }

  $errors = [];
  $seen = [];
  foreach ($attempts as $attempt) {
    $key = $attempt['smtp_host'] . ':' . $attempt['smtp_port'] . ':' . $attempt['smtp_secure'];
    if (isset($seen[$key])) continue;
    $seen[$key] = true;
    $tryCfg = array_merge($cfg, $attempt);
    $result = fe_smtp_send($tryCfg, $mail);
    if (!empty($result['ok'])) {
      return $result;
    }
    $errors[] = $key . ' => ' . ($result['error'] ?? 'fail');
  }
  return ['ok' => false, 'error' => implode(' | ', $errors)];
}

/** Backup relay — no Hostinger SMTP password needed. First use may require clicking a confirm link in sales@. */
function fe_formsubmit_send($to, $subject, $body, $replyEmail) {
  $url = 'https://formsubmit.co/ajax/' . rawurlencode($to);
  $payload = json_encode([
    'name' => 'FundingExpressAi Application',
    'email' => $replyEmail ?: $to,
    '_subject' => $subject,
    'message' => $body,
    '_template' => 'box',
    '_captcha' => 'false',
    '_honey' => '',
  ]);

  if (function_exists('curl_init')) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
      CURLOPT_POST => true,
      CURLOPT_POSTFIELDS => $payload,
      CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_TIMEOUT => 45,
      CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $resp = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $cerr = curl_error($ch);
    curl_close($ch);
    if ($resp === false) {
      return ['ok' => false, 'error' => 'FormSubmit curl error: ' . $cerr];
    }
    $data = json_decode($resp, true);
    $success = is_array($data) && (
      (isset($data['success']) && ($data['success'] === true || $data['success'] === 'true'))
      || (isset($data['message']) && stripos((string)$data['message'], 'activat') !== false)
    );
    if ($code >= 200 && $code < 300 && ($success || $code === 200)) {
      $needsActivate = is_array($data) && isset($data['message']) && stripos((string)$data['message'], 'activat') !== false;
      return [
        'ok' => true,
        'error' => '',
        'needsActivation' => $needsActivate,
        'response' => $resp,
      ];
    }
    return ['ok' => false, 'error' => 'FormSubmit HTTP ' . $code . ': ' . substr((string)$resp, 0, 300)];
  }

  $ctx = stream_context_create([
    'http' => [
      'method' => 'POST',
      'header' => "Content-Type: application/json\r\nAccept: application/json\r\n",
      'content' => $payload,
      'timeout' => 45,
      'ignore_errors' => true,
    ],
  ]);
  $resp = @file_get_contents($url, false, $ctx);
  if ($resp === false) {
    return ['ok' => false, 'error' => 'FormSubmit request failed'];
  }
  $data = json_decode($resp, true);
  $ok = is_array($data) && isset($data['success']) && ($data['success'] === true || $data['success'] === 'true');
  return ['ok' => $ok, 'error' => $ok ? '' : substr($resp, 0, 300), 'response' => $resp];
}
