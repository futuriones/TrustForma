<?php
// This file is part of TrustForma, a Futurion Solutions S.L. product (INCIBE kit -> Moodle as code).

/**
 * Idempotent site configuration for the awareness training platform. Safe to run any number of times.
 *
 *   php local/awarenesssync/cli/configure_site.php [--rotate-token] [--rebrand]
 *
 * Does NOT touch anything a human may have tuned on purpose beyond the settings listed here.
 *
 * @package    local_awarenesssync
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');
require_once($CFG->dirroot . '/cohort/lib.php');
require_once($CFG->dirroot . '/user/lib.php');
require_once($CFG->libdir . '/externallib.php');

[$options] = cli_get_params(['rotate-token' => false, 'rebrand' => false, 'help' => false], ['h' => 'help']);
if ($options['help']) {
    cli_writeln("Usage: configure_site.php [--rotate-token] [--rebrand]\n  --rotate-token  revoke the GRC API token and issue a new one (update CISO Assistant afterwards)\n"
        . "  --rebrand       apply the Futurion theme, logos and favicon again (otherwise they are set once and the administrator owns them)");
    exit(0);
}

\core\session\manager::set_user(get_admin());

$changed = [];
$set = function (string $name, $value, ?string $plugin = null) use (&$changed) {
    $current = get_config($plugin, $name);
    if ((string) $current !== (string) $value) {
        set_config($name, $value, $plugin);
        $changed[] = ($plugin ? "$plugin/" : '') . "$name = $value";
    }
};

// Core behaviour the training depends on.
$set('enablecompletion', 1);
$set('enableavailability', 1);
$set('timezone', 'Europe/Madrid');
$set('forcetimezone', 'Europe/Madrid');
$set('country', 'ES');
$set('lang', 'es');
$set('maxbytes', 104857600);                     // 100 MB: the biggest kit PDF is 33 MB.
$set('registerauth', '');                        // No self-registration: accounts come from SSO/cohort import.
$set('defaulthomepage', 1);                      // Send users to "My courses"-style dashboard, not the front page.

// Certificates can be verified by code by anyone (an auditor does not need an account).
$set('verifyallcertificates', 1, 'customcert');

// Cohort enrolment plugin on.
if (!array_key_exists('cohort', enrol_get_plugins(true))) {
    \core\plugininfo\enrol::enable_plugin('cohort', 1);
    $changed[] = 'enrol plugin cohort enabled';
}
// Somebody who leaves the cohort (left the company, changed post) keeps a SUSPENDED enrolment with its role. Moodle's default
// is to unenrol, which removes the person from the compliance report: the evidence of who was trained would lose them.
$set('unenrolaction', ENROL_EXT_REMOVED_SUSPEND, 'enrol_cohort');

// Web services + REST protocol (used by CISO Assistant's evidence workflow, see step 5).
$set('enablewebservices', 1);
$protocols = array_filter(explode(',', (string) get_config('core', 'webserviceprotocols')));
if (!in_array('rest', $protocols, true)) {
    $protocols[] = 'rest';
    set_config('webserviceprotocols', implode(',', $protocols));
    $changed[] = 'webservice protocol rest enabled';
}

// The employee cohort: members of it are enrolled in every cycle course.
if (!$DB->record_exists('cohort', ['idnumber' => 'empleados'])) {
    cohort_add_cohort((object) [
        'contextid' => context_system::instance()->id, 'name' => 'Empleados', 'idnumber' => 'empleados',
        'description' => 'Personas obligadas a realizar la formación en concienciación.',
        'descriptionformat' => FORMAT_HTML, 'visible' => 1, 'component' => '',
    ]);
    $changed[] = "cohort 'empleados' created";
}

// --- Identity for CISO Assistant's evidence workflow: a role that can ONLY read the training report, a user that can
// never log in through the UI (auth 'webservice'), authorised on our service only, and a permanent token in the secrets dir.
$system = context_system::instance();
$roleid = $DB->get_field('role', 'id', ['shortname' => 'grcreader']);
if (!$roleid) {
    $roleid = create_role('Lector GRC (informe de formación)', 'grcreader',
        'Solo puede leer el informe de cumplimiento de la formación por API. Sin acceso a cursos ni a la interfaz.');
    set_role_contextlevels($roleid, [CONTEXT_SYSTEM]);
    $changed[] = "role 'grcreader' created";
}
assign_capability('local/awarenesssync:viewreport', CAP_ALLOW, $roleid, $system->id, true);
assign_capability('webservice/rest:use', CAP_ALLOW, $roleid, $system->id, true);

$grc = $DB->get_record('user', ['username' => 'grc_api', 'deleted' => 0]);
if (!$grc) {
    $grcid = user_create_user((object) [
        'username' => 'grc_api', 'password' => 'Aa1-' . bin2hex(random_bytes(24)), 'auth' => 'webservice', 'confirmed' => 1, // Password satisfies the site policy; login is disabled anyway.
        'firstname' => 'GRC', 'lastname' => 'API', 'email' => 'grc-api@example.invalid', 'mnethostid' => $CFG->mnet_localhost_id,
    ], true, false);
    $grc = $DB->get_record('user', ['id' => $grcid], '*', MUST_EXIST);
    $changed[] = "user 'grc_api' created (auth=webservice: cannot log in)";
}
if (!$grc->confirmed || $grc->suspended) {
    // Web services refuse unconfirmed or suspended users; user_create_user() does not keep 'confirmed'.
    $DB->update_record('user', (object) ['id' => $grc->id, 'confirmed' => 1, 'suspended' => 0]);
    $changed[] = 'grc_api confirmed';
}
if (!user_has_role_assignment($grc->id, $roleid, $system->id)) {
    role_assign($roleid, $grc->id, $system->id);
    $changed[] = "role 'grcreader' assigned to grc_api";
}
$service = $DB->get_record('external_services', ['shortname' => 'awareness_compliance']);
if ($service) {
    if (!$DB->record_exists('external_services_users', ['externalserviceid' => $service->id, 'userid' => $grc->id])) {
        $DB->insert_record('external_services_users', (object) ['externalserviceid' => $service->id, 'userid' => $grc->id, 'timecreated' => time()]);
        $changed[] = 'grc_api authorised on service awareness_compliance';
    }
    if ($options['rotate-token']) {
        $DB->delete_records('external_tokens', ['userid' => $grc->id, 'externalserviceid' => $service->id]);
        $changed[] = 'old API token revoked';
    }
    $token = $DB->get_field('external_tokens', 'token', ['userid' => $grc->id, 'externalserviceid' => $service->id, 'tokentype' => EXTERNAL_TOKEN_PERMANENT], IGNORE_MULTIPLE);
    if (!$token) {
        $token = \core_external\util::generate_token(EXTERNAL_TOKEN_PERMANENT, $service, $grc->id, $system);
        $changed[] = 'API token created';
    }
    // Optional: only these addresses may use the token (the outbound address of the GRC portal's importer host).
    // MOODLE_GRC_TOKEN_IPS in lms/docker/.env, comma-separated addresses or subnets; empty = no restriction.
    $ips = preg_replace('/\s+/', '', (string) getenv('MOODLE_GRC_TOKEN_IPS'));
    if (!preg_match('~^[0-9a-fA-F:.,/-]*$~', $ips)) {
        cli_error('MOODLE_GRC_TOKEN_IPS must be a comma-separated list of IP addresses or subnets.', 2);
    }
    $tokenrow = $DB->get_record('external_tokens', ['userid' => $grc->id, 'externalserviceid' => $service->id, 'tokentype' => EXTERNAL_TOKEN_PERMANENT],
        'id, iprestriction', IGNORE_MULTIPLE);
    if ($tokenrow && (string) $tokenrow->iprestriction !== $ips) {
        $DB->set_field('external_tokens', 'iprestriction', $ips, ['id' => $tokenrow->id]);
        $changed[] = $ips === '' ? 'API token: IP restriction removed' : "API token usable only from $ips";
    }
    $tokenfile = '/secrets/grc_token';
    if (!is_dir(dirname($tokenfile))) {
        // The token exists in Moodle but nobody can read it: say so instead of silently losing it.
        cli_writeln('WARNING: /secrets is not mounted, so the API token was NOT written to a file. Check lms/docker/compose.alpine.yaml.');
    } else if (!is_file($tokenfile) || trim(file_get_contents($tokenfile)) !== $token) {
        // Owner-only from the first byte: write a private temp file and rename it into place (never world-readable, even briefly).
        $old = umask(0077);
        $tmp = $tokenfile . '.tmp';
        $written = file_put_contents($tmp, $token . "\n");
        umask($old);
        if ($written === false || !rename($tmp, $tokenfile)) {
            cli_error('Could not write the API token file ' . $tokenfile, 1);
        }
        @chmod($tokenfile, 0600);
        $changed[] = "token written to lms/docker/secrets/grc_token (gitignored, mode 0600; give it to CISO Assistant's workflow)";
    }
} else {
    cli_writeln('NOTE: web service "awareness_compliance" not found yet; run `make install` (plugin upgrade) then `make configure` again.');
}

// --- Outgoing mail with OAuth 2 (SMTP XOAUTH2), from .env (config.php already forces host/user/auth type). Moodle's own OAuth 2
// issuer holds the client and the service mailbox's refresh token; this only creates/updates that issuer, idempotently.
if (getenv('MOODLE_SMTP_AUTHTYPE') === 'XOAUTH2') {
    $provider = (string) getenv('MOODLE_SMTP_OAUTH_PROVIDER');
    $clientid = (string) getenv('MOODLE_SMTP_OAUTH_CLIENT_ID');
    $secret = (string) getenv('MOODLE_SMTP_OAUTH_CLIENT_SECRET');
    $tenant = (string) getenv('MOODLE_SMTP_OAUTH_TENANT');
    $problems = [];
    if (!in_array($provider, ['microsoft', 'google'], true)) {
        $problems[] = 'MOODLE_SMTP_OAUTH_PROVIDER must be microsoft or google';
    }
    if ($clientid === '' || $secret === '') {
        $problems[] = 'MOODLE_SMTP_OAUTH_CLIENT_ID and MOODLE_SMTP_OAUTH_CLIENT_SECRET are required';
    }
    if ($provider === 'microsoft' && !preg_match('/^[0-9a-f]{8}-([0-9a-f]{4}-){3}[0-9a-f]{12}$/i', $tenant)) {
        $problems[] = 'MOODLE_SMTP_OAUTH_TENANT must be the Directory (tenant) ID (a GUID)';
    }
    if (trim((string) get_config('core', 'smtpuser')) === '' && trim((string) getenv('MOODLE_SMTP_USER')) === '') {
        $problems[] = 'MOODLE_SMTP_USER (the service mailbox) is required';
    }
    if ($problems) {
        // Fail closed: no password fallback, no half-configured issuer.
        cli_error("SMTP XOAUTH2 is selected in .env but incomplete:\n  - " . implode("\n  - ", $problems), 1);
    }
    $issuer = null;
    foreach (\core\oauth2\api::get_all_issuers() as $candidate) {
        if ($candidate->get('name') === 'Awareness SMTP') {
            $issuer = $candidate;
        }
    }
    if (!$issuer) {
        $issuer = \core\oauth2\api::create_standard_issuer($provider,
            $provider === 'microsoft' ? "https://login.microsoftonline.com/$tenant/v2.0" : false);
        $changed[] = "OAuth 2 service 'Awareness SMTP' created ($provider)";
    }
    $wanted = ['name' => 'Awareness SMTP', 'clientid' => $clientid, 'clientsecret' => $secret, 'enabled' => 1,
        'showonloginpage' => \core\oauth2\issuer::SMTPWITHXOAUTH2];
    if ($provider === 'google') {
        // Gmail SMTP/IMAP with XOAUTH2 only accepts this scope; there is no narrower one.
        $wanted['loginscopesoffline'] = 'openid profile email https://mail.google.com/';
    }
    $dirty = false;
    foreach ($wanted as $field => $value) {
        if ((string) $issuer->get($field) !== (string) $value) {
            $issuer->set($field, $value);
            $dirty = true;
        }
    }
    if ($dirty) {
        $issuer->update();
        $changed[] = "OAuth 2 service 'Awareness SMTP' updated (client secret not shown)";
    }
    $set('smtpoauthservice', $issuer->get('id'));
    $connected = (bool) \core\oauth2\api::get_system_account($issuer);
    cli_writeln($connected
        ? 'SMTP XOAUTH2: the service mailbox is connected.'
        : 'SMTP XOAUTH2: NOT connected yet. One-time step: Site administration > Server > OAuth 2 services > "Awareness SMTP" > '
            . '"Connect to a system account", signed in as ' . getenv('MOODLE_SMTP_USER') . '. Then run `make mail-test TO=<address>`.');
}

// Futurion branding: theme colour, SCSS, logos, favicon. INITIAL values only: once applied, the Moodle administrator owns them in
// Site administration > Appearance (AGENTS.md invariant 5c), so a later `make configure` leaves them alone. `--rebrand` (make
// configure REBRAND=1) applies them again, and raising BRANDING_VERSION does the same for everybody when the design changes.
const BRANDING_VERSION = 1;
if ($options['rebrand'] || (int) get_config('local_awarenesssync', 'branding_version') < BRANDING_VERSION) {
    $branding = dirname(__DIR__) . '/branding';
    $fs = get_file_storage();
    $syscontext = context_system::instance();
    foreach (['logo' => 'logo.png', 'logocompact' => 'logocompact.png', 'favicon' => 'favicon.ico'] as $area => $name) {
        $source = "$branding/$name";
        $stored = $fs->get_file($syscontext->id, 'core_admin', $area, 0, '/', $name);
        if (!$stored || $stored->get_contenthash() !== $fs::hash_from_path($source)) {
            $fs->delete_area_files($syscontext->id, 'core_admin', $area, 0);
            $fs->create_file_from_pathname([
                'contextid' => $syscontext->id, 'component' => 'core_admin', 'filearea' => $area,
                'itemid' => 0, 'filepath' => '/', 'filename' => $name,
            ], $source);
            $changed[] = "branding $area uploaded ($name)";
        }
        $set($area, "/$name", 'core_admin');
    }
    $set('theme', 'boost');
    $set('brandcolor', '#f5325b', 'theme_boost');
    $set('scsspre', "\$font-family-sans-serif: 'Inter', system-ui, -apple-system, 'Segoe UI', sans-serif;\n", 'theme_boost');
    $scss = file_get_contents("$branding/brand.scss");
    if ((string) get_config('theme_boost', 'scss') !== $scss) {
        set_config('scss', $scss, 'theme_boost');
        $changed[] = 'theme_boost/scss = branding/brand.scss';
    }
    $set('branding_version', BRANDING_VERSION, 'local_awarenesssync');
    theme_reset_all_caches();
}

purge_all_caches();
cli_writeln($changed ? "configured:\n  - " . implode("\n  - ", $changed) : 'nothing to change: site already configured');
