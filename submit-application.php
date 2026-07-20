<?php
/**
 * FundingExpressAi — application intake
 * Emails completed applications to prospect@expressfundingai.com
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

$raw = file_get_contents('php://input');
$data = json_decode($raw, true);

if (!is_array($data)) {
  http_response_code(400);
  echo json_encode(['ok' => false, 'error' => 'Invalid JSON']);
  exit;
}

$to = 'prospect@expressfundingai.com';
$from = 'noreply@expressfundingai.com';
$biz = isset($data['business']) && is_array($data['business']) ? $data['business'] : [];
$owner = isset($data['owner']) && is_array($data['owner']) ? $data['owner'] : [];
$debts = isset($data['debts']) && is_array($data['debts']) ? $data['debts'] : [];
$revenue = isset($data['revenue']) && is_array($data['revenue']) ? $data['revenue'] : [];
$estimate = isset($data['estimate']) && is_array($data['estimate']) ? $data['estimate'] : [];

$bizName = isset($biz['legalName']) && $biz['legalName'] !== '' ? $biz['legalName'] : 'New lead';
$subject = 'New FundingExpressAi application — ' . $bizName;

$lines = [];
$lines[] = 'NEW APPLICATION — FundingExpressAi';
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
if (!empty($revenue['statementFiles']) && is_array($revenue['statementFiles'])) {
  $lines[] = 'Uploaded files:';
  foreach ($revenue['statementFiles'] as $f) {
    $lines[] = ' - ' . $f;
  }
} else {
  $lines[] = 'Uploaded files: none';
}
$lines[] = '';
$lines[] = '=== AI ESTIMATE ===';
$lines[] = 'Tier: ' . ($estimate['tier'] ?? '');
$lines[] = 'Range: ' . ($estimate['display'] ?? '');
$lines[] = 'Avg used: $' . number_format((float)($estimate['averageRevenue'] ?? 0), 0);
$lines[] = '';
$lines[] = 'Reply to the business email above to continue this lead.';

$body = implode("\r\n", $lines);

$headers = [];
$headers[] = 'From: FundingExpressAi <' . $from . '>';
$headers[] = 'Reply-To: ' . (!empty($biz['email']) ? $biz['email'] : $from);
$headers[] = 'MIME-Version: 1.0';
$headers[] = 'Content-Type: text/plain; charset=UTF-8';
$headers[] = 'X-Mailer: FundingExpressAi';

$ok = @mail($to, '=?UTF-8?B?' . base64_encode($subject) . '?=', $body, implode("\r\n", $headers));

if ($ok) {
  echo json_encode(['ok' => true, 'to' => $to]);
} else {
  http_response_code(500);
  echo json_encode(['ok' => false, 'error' => 'mail() failed — check Hostinger email / SPF setup']);
}
