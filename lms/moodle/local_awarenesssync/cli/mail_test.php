<?php
// This file is part of the awareness training platform (INCIBE kit -> Moodle as code).

/**
 * Send one test email through the configured outgoing mail (.env -> config.php) and report the result. Never prints
 * credentials or tokens.
 *
 *   php local/awarenesssync/cli/mail_test.php --to=someone@example.com
 *
 * @package    local_awarenesssync
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');

[$o] = cli_get_params(['to' => '', 'help' => false], ['h' => 'help']);
if ($o['help'] || !filter_var($o['to'], FILTER_VALIDATE_EMAIL)) {
    cli_error('Usage: mail_test.php --to=address@example.com', $o['help'] ? 0 : 2);
}

$auth = $CFG->smtpauthtype ?: 'LOGIN';
cli_writeln(sprintf('Sending via %s, security=%s, auth=%s, user=%s', $CFG->smtphosts, $CFG->smtpsecure ?: 'none', $auth, $CFG->smtpuser ?: '(none)'));
if ($auth === 'XOAUTH2' && !get_config('core', 'smtpoauthservice')) {
    cli_error('XOAUTH2 is selected but no OAuth 2 service is configured: run `make configure`. Not falling back to another method.', 1);
}

$to = (object) ['id' => -99, 'email' => $o['to'], 'firstname' => 'Test', 'lastname' => 'Recipient', 'username' => 'mailtest', 'mailformat' => 1,
    'deleted' => 0, 'auth' => 'manual', 'suspended' => 0, 'emailstop' => 0, 'maildisplay' => 1, 'lang' => 'es', 'confirmed' => 1,
    'firstnamephonetic' => '', 'lastnamephonetic' => '', 'middlename' => '', 'alternatename' => ''];
$sent = email_to_user($to, core_user::get_noreply_user(), 'Prueba de correo de la plataforma de formación',
    "Este es un mensaje de prueba.\n{$CFG->wwwroot}", '<p>Este es un mensaje de prueba.</p>');
if ($sent) {
    cli_writeln('OK: the message was accepted by the mail server.');
    exit(0);
}
cli_writeln('FAILED: the mail server refused the message. Checklist:');
foreach ([
    '535 5.7.3 / 535 5.7.8 (auth failed): microsoft: service principal registered in Exchange (New-ServicePrincipal with the ENTERPRISE APP object id), '
        . 'SMTP AUTH enabled on the mailbox, admin consent given, wait up to 60 min for replication. google: consent given by the service mailbox itself, '
        . 'client trusted in Admin console, 2-step verification does not matter for OAuth.',
    'invalid_grant / token errors: the system account must be (re)connected: OAuth 2 services > "Awareness SMTP" > Connect to a system account.',
    'connection refused / TLS: MOODLE_SMTP_HOST (smtp.office365.com:587 or smtp.gmail.com:587) with MOODLE_SMTP_SECURE=tls.',
    'SendAsDenied: MOODLE_NOREPLY differs from MOODLE_SMTP_USER; use the same address or grant Send As.',
] as $hint) {
    cli_writeln("  - $hint");
}
cli_writeln('Details: Site administration > Reports > Logs / the php-fpm container log (no secrets are logged).');
exit(1);
