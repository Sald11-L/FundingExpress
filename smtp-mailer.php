<?php
/**
 * Lightweight SMTP client for Hostinger (no Composer).
 * Supports SSL (465) and STARTTLS (587).
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
    $altHost = (stripos($host, 'titan') !== false) ? 'smtp.hostinger.com' : 'smtp.titan.email';
    $remoteAlt = ($secure === 'ssl' ? 'ssl://' : '') . $altHost;
    $fp = @stream_socket_client($remoteAlt . ':' . $port, $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT);
    if (!$fp) {
      return ['ok' => false, 'error' => "SMTP connect failed: $errstr ($errno)"];
    }
    $host = $altHost;
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
  if (!$ok) { fclose($fp); return ['ok' => false, 'error' => "SMTP password rejected: $resp"]; }

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

  // Dot-stuffing
  $data = preg_replace('/^\./m', '..', $data);
  fwrite($fp, $data . "\r\n.\r\n");
  list($ok, $code, $resp) = $expect(250);
  if (!$ok) { fclose($fp); return ['ok' => false, 'error' => "Message rejected: $resp"]; }

  $write('QUIT');
  fclose($fp);
  return ['ok' => true, 'error' => '', 'host' => $host, 'port' => $port];
}
