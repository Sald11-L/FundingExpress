<?php
/**
 * SAMPLE — copy to mail-config.php and set smtp_pass.
 * Or use setup-mail.php in the browser once (easier).
 */
return [
  'to' => 'sales@expressfundingai.com',
  'from' => 'sales@expressfundingai.com',
  'from_name' => 'FundingExpressAi',
  'backup_to' => '', // Gmail/Outlook that actually receives mail
  'formsubmit_id' => '39f462475459e8d7e6cfdfa4599ffb7c',
  'smtp_host' => 'smtp.hostinger.com',
  'smtp_port' => 465,
  'smtp_secure' => 'ssl', // ssl (465) or tls (587)
  'smtp_user' => 'sales@expressfundingai.com',
  'smtp_pass' => 'PUT_SALES_MAILBOX_PASSWORD_HERE',
  'smtp_timeout' => 30,
];
