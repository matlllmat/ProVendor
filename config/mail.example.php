<?php
// Copy to mail.local.php or supply SMTP_* environment variables.
return [
    'host' => 'smtp.gmail.com',
    'port' => 587,
    'encryption' => 'tls',
    'username' => 'your-sender@example.com',
    'password' => 'your-smtp-password',
    'from_email' => 'your-sender@example.com',
    'from_name' => 'ProVendor Security',
];
