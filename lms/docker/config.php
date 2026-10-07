<?php
// Moodle configuration driven entirely by environment variables (see compose.alpine.yaml / .env).
unset($CFG);
global $CFG;
$CFG = new stdClass();

$CFG->dbtype    = 'pgsql';
$CFG->dblibrary = 'native';
$CFG->dbhost    = getenv('MOODLE_DB_HOST') ?: 'db';
$CFG->dbname    = getenv('MOODLE_DB_NAME') ?: 'moodle';
$CFG->dbuser    = getenv('MOODLE_DB_USER') ?: 'moodle';
$CFG->dbpass    = getenv('MOODLE_DB_PASSWORD') ?: '';
$CFG->prefix    = 'mdl_';
$CFG->dboptions = ['dbpersist' => 0, 'dbport' => 5432];

$CFG->wwwroot   = getenv('MOODLE_WWWROOT') ?: 'http://localhost:8080';
$CFG->dataroot  = '/var/www/moodledata';
$CFG->admin     = 'admin';
// Group-writable only (setgid): moodledata must not be world-writable.
$CFG->directorypermissions = 02770;
// Executable paths (ghostscript, pdftoppm…) can only be set here, never from the admin UI: a stolen admin session
// must not be able to point Moodle at an arbitrary binary (a known route to remote code execution).
$CFG->preventexecpath = true;
$CFG->pathtogs = '/usr/bin/gs';
$CFG->pathtopdftoppm = '/usr/bin/pdftoppm';
// Code comes from the image only (pinned commits, owned by root, read-only at run time): no plugin installation or update from
// the admin UI. A new plugin or version is a change to plugins.lock and a rebuilt image.
$CFG->disableupdateautodeploy = true;

// Outgoing mail, entirely from .env (locked in the admin UI because it is set here). Local pilot default: Mailpit
// (http://localhost:8025), no auth. Production: MOODLE_SMTP_AUTHTYPE=XOAUTH2 (Microsoft 365 / Google Workspace, see README).
$CFG->smtphosts = getenv('MOODLE_SMTP_HOST') ?: 'mailpit:1025';
$CFG->noreplyaddress = getenv('MOODLE_NOREPLY') ?: 'noreply@example.invalid';
$CFG->smtpsecure = getenv('MOODLE_SMTP_SECURE') ?: '';                 // '' | tls (STARTTLS, port 587) | ssl (implicit TLS)
$CFG->smtpauthtype = getenv('MOODLE_SMTP_AUTHTYPE') ?: 'LOGIN';        // LOGIN | PLAIN | CRAM-MD5 | XOAUTH2
$CFG->smtpuser = getenv('MOODLE_SMTP_USER') ?: '';                     // the service mailbox (the XOAUTH2 "user=" value)
// OAuth 2 never falls back to a password: no smtppass is set for XOAUTH2, so a broken token means "mail fails", not "basic auth".
$CFG->smtppass = $CFG->smtpauthtype === 'XOAUTH2' ? '' : (getenv('MOODLE_SMTP_PASSWORD') ?: '');
$CFG->smtpmaxbulk = 1;
$CFG->debugsmtp = false;                                               // The SMTP debug log would contain the XOAUTH2 token.

// Behind a TLS-terminating proxy in production: MOODLE_SSLPROXY=1 is all plain TLS offload needs.
if (getenv('MOODLE_SSLPROXY') === '1') {
    $CFG->sslproxy = true;
}
// Only when the proxy serves Moodle under another host/port than Moodle itself sees (it disables the wwwroot check).
if (getenv('MOODLE_REVERSEPROXY') === '1') {
    $CFG->reverseproxy = true;
}

require_once(__DIR__ . '/lib/setup.php');
