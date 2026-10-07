<?php
// This file is part of the awareness training platform (INCIBE kit -> Moodle as code).

/**
 * End-to-end acceptance check of the learner journey on a synced course, using real (temporary) users.
 *
 *   php local/awarenesssync/cli/e2e_check.php --idnumber=concienciacion:2026
 *
 * Creates users "e2e_*", enrols them through the test cohort, takes real quiz attempts through the quiz API,
 * runs the completion and certificate cron tasks, checks the results and deletes the users again.
 *
 * Never on a real cycle: Moodle keeps the attempts of deleted users, so they would make the sync treat that course as
 * "in use" (blocked changes) and stay in its reports. `make e2e` deploys the real course content as scratch cycle 9999,
 * runs this on it and deletes it. Local pilot only.
 *
 * @package    local_awarenesssync
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');
require_once($CFG->libdir . '/completionlib.php');

[$options] = cli_get_params(['idnumber' => '', 'keep' => false, 'help' => false], ['h' => 'help']);
if ($options['help'] || !$options['idnumber']) {
    cli_error('Usage: e2e_check.php --idnumber=<course id>:9999 [--keep]', $options['help'] ? 0 : 2);
}
if (!preg_match('~^https?://(localhost|127\.0\.0\.1)(:\d+)?(/|$)~', $CFG->wwwroot)) {
    cli_error("Refusing to create test users on {$CFG->wwwroot}. This check is for the local pilot only.", 2);
}
if (!\local_awarenesssync\testing\e2e::is_test_course($options['idnumber'])) {
    cli_error("Refusing to run on '{$options['idnumber']}': test attempts would stay in a real cycle. Use `make e2e` (scratch cycle "
        . \local_awarenesssync\testing\e2e::SCRATCH_CYCLE . ').', 2);
}

\core\session\manager::set_user(get_admin());
$course = $DB->get_record('course', ['idnumber' => $options['idnumber']], '*', MUST_EXIST);
$e2e = new \local_awarenesssync\testing\e2e($course);
$ctx = context_course::instance($course->id);

$results = ['pass' => 0, 'fail' => 0];
$check = function (string $what, bool $ok, string $detail = '') use (&$results) {
    $results[$ok ? 'pass' : 'fail']++;
    cli_writeln(sprintf('  %s %s%s', $ok ? 'PASS' : 'FAIL', $what, $detail !== '' ? "  [$detail]" : ''));
};

$quizkeys = array_values(array_filter(array_keys($e2e->modules()), fn($k) => str_ends_with($k, ':quiz')));
sort($quizkeys);
$pdfof = fn(string $quizkey) => str_replace(':quiz', ':pdf', $quizkey);
$done = fn($user, $key) => $e2e->state($user, $key) === COMPLETION_COMPLETE_PASS;

$e2e->delete_users();
$users = ['aprobado' => $e2e->make_user('aprobado'), 'suspenso' => $e2e->make_user('suspenso'), 'sinempezar' => $e2e->make_user('sinempezar')];
[$pass, $fail, $idle] = array_values($users);
$studentrole = (int) $DB->get_field('role', 'id', ['shortname' => 'student'], MUST_EXIST);

cli_writeln('== Enrolment via cohort');
foreach ($users as $name => $u) {
    $check("e2e_$name enrolled as student", is_enrolled($ctx, $u, '', true) && user_has_role_assignment($u->id, $studentrole, $ctx->id));
}
$check('test users are enrolled in this scratch course only (no real cycle is touched)',
    !array_filter($users, fn($u) => array_keys(enrol_get_all_users_courses($u->id)) !== [(int) $course->id]));

$q1 = $quizkeys[0];
cli_writeln("== Restriction: quiz locked until the PDF is viewed ($q1)");
$check('quiz is NOT available before the PDF is viewed', !$e2e->available($pass, $q1));
$e2e->view($pass, $pdfof($q1));
$check('PDF shows as completed after viewing', $e2e->state($pass, $pdfof($q1)) === COMPLETION_COMPLETE);
$check('quiz IS available after the PDF is viewed', $e2e->available($pass, $q1));

cli_writeln('== Failing then passing a quiz');
$e2e->view($fail, $pdfof($q1));
$bad = $e2e->attempt($fail, $q1, false);
$check('all-wrong attempt scores 0 / 10', $bad !== null && abs($bad) < 0.001, (string) $bad);
$check('failed quiz is NOT complete', !in_array($e2e->state($fail, $q1), [COMPLETION_COMPLETE, COMPLETION_COMPLETE_PASS], true));
$seen = $e2e->review_flags($fail, $q1);
$check('after a test the learner sees the mark and which answers were right, not the correct option or feedback',
    $seen !== null && $seen['marks'] && $seen['correctness'] && !$seen['rightanswer'] && !$seen['feedback'], json_encode($seen));
$good = $e2e->attempt($pass, $q1, true);
$check('all-correct attempt scores 10 / 10', $good !== null && abs($good - 10) < 0.001, (string) $good);
$check('passed quiz is complete (pass)', $done($pass, $q1));
$retry = $e2e->attempt($fail, $q1, true);
$check('failed learner can retry and pass (best grade counts)', $retry !== null && abs($retry - 10) < 0.001 && $done($fail, $q1));

cli_writeln("== Completing every module as 'aprobado'");
foreach ($quizkeys as $quizkey) {
    if ($e2e->state($pass, $pdfof($quizkey)) !== COMPLETION_COMPLETE) {
        $e2e->view($pass, $pdfof($quizkey));
    }
    if (!$done($pass, $quizkey)) {
        $e2e->attempt($pass, $quizkey, true);
    }
}
$check('all ' . count($quizkeys) . ' quizzes passed', count(array_filter($quizkeys, fn($k) => $done($pass, $k))) === count($quizkeys));

cli_writeln('== Course completion and certificate (cron tasks)');
$certkey = array_values(array_filter(array_keys($e2e->modules()), fn($k) => str_ends_with($k, ':certificate')))[0] ?? null;
$check('certificate is locked for a learner who has not finished', $certkey !== null && !$e2e->available($idle, $certkey));
$check('certificate is available to the learner who finished all quizzes', $certkey !== null && $e2e->available($pass, $certkey));
(new \core\task\completion_regular_task())->execute();
$check('course is marked complete for the learner who passed everything', $e2e->course_completed($pass));
$check('course is NOT complete for the partial learner', !$e2e->course_completed($fail));
ob_start();
(new \mod_customcert\task\issue_certificates_task())->execute();
ob_end_clean();
$cert = $certkey ? $DB->get_record('customcert', ['id' => $e2e->modules()[$certkey]->instance]) : null;
$issue = $cert ? $DB->get_record('customcert_issues', ['customcertid' => $cert->id, 'userid' => $pass->id]) : null;
$check('certificate was issued automatically with a verification code', $issue && !empty($issue->code), $issue->code ?? 'none');
$check('no certificate issued to the learner who did not finish', !$cert || !$DB->record_exists('customcert_issues', ['customcertid' => $cert->id, 'userid' => $fail->id]));
if ($issue) {
    $template = new \mod_customcert\template($DB->get_record('customcert_templates', ['id' => $cert->templateid], '*', MUST_EXIST));
    $tmp = make_request_directory() . '/cert.pdf';
    file_put_contents($tmp, $template->generate_pdf(false, $pass->id, true));
    $text = preg_replace('/\s+/u', ' ', (string) shell_exec('pdftotext -layout ' . escapeshellarg($tmp) . ' - 2>/dev/null'));
    $check('certificate PDF shows the learner name', str_contains($text, 'Prueba') && str_contains($text, 'Aprobado'));
    $check('certificate PDF shows the verification code', str_contains($text, $issue->code));
    $check('certificate PDF names the course', str_contains($text, $course->fullname), str_contains($text, $course->fullname) ? '' : mb_substr($text, 0, 300));
    $check('certificate PDF has Spanish accents intact', str_contains($text, 'FINALIZACIÓN'));
}

cli_writeln('== Compliance API (what CISO Assistant will call)');
$tokenfile = '/secrets/grc_token';
$token = is_file($tokenfile) ? trim(file_get_contents($tokenfile)) : '';
$api = function (string $function, string $usetoken, array $extra = []) use ($CFG) {
    // Explicit '&': Moodle sets arg_separator.output to '&amp;', which would corrupt the query string.
    // POST, like the documented CISO Assistant call: the token must not end up in the URL (web server logs).
    $query = http_build_query(['wstoken' => $usetoken, 'wsfunction' => $function, 'moodlewsrestformat' => 'json'] + $extra, '', '&');
    // This script runs in the php-fpm container; the web tier (nginx) is another one, given by AWARENESS_INTERNAL_URL.
    // Send the public Host so Moodle does not redirect to its wwwroot.
    $host = parse_url($CFG->wwwroot, PHP_URL_HOST) . (($port = parse_url($CFG->wwwroot, PHP_URL_PORT)) ? ":$port" : '');
    $internal = rtrim(getenv('AWARENESS_INTERNAL_URL') ?: 'http://localhost', '/');
    $body = @file_get_contents($internal . '/webservice/rest/server.php', false,
        stream_context_create(['http' => ['method' => 'POST', 'content' => $query, 'ignore_errors' => true,
            'header' => "Host: $host\r\nContent-Type: application/x-www-form-urlencoded\r\n"]]));
    return json_decode((string) $body, true) ?? ['raw' => substr((string) $body, 0, 300), 'headers' => $http_response_header[0] ?? 'no response'];
};
// The same call as the GRC portal's workflow makes it: a GET (its engine cannot POST a file download) with the token in an
// Authorization header, which the web tier moves into the wstoken argument. The URL itself carries no secret.
$apiget = function (string $usetoken, array $params) use ($CFG) {
    $query = http_build_query(['wsfunction' => 'local_awarenesssync_get_compliance_report', 'moodlewsrestformat' => 'json'] + $params, '', '&');
    $host = parse_url($CFG->wwwroot, PHP_URL_HOST) . (($port = parse_url($CFG->wwwroot, PHP_URL_PORT)) ? ":$port" : '');
    $internal = rtrim(getenv('AWARENESS_INTERNAL_URL') ?: 'http://localhost', '/');
    $body = @file_get_contents($internal . '/webservice/rest/server.php?' . $query, false,
        stream_context_create(['http' => ['method' => 'GET', 'ignore_errors' => true, 'header' => "Host: $host\r\nAuthorization: Bearer $usetoken\r\n"]]));
    return json_decode((string) $body, true) ?? ['raw' => substr((string) $body, 0, 300)];
};
$check('an API token exists (run `make configure`)', $token !== '');
$report = $api('local_awarenesssync_get_compliance_report', $token, ['course' => explode(':', $options['idnumber'])[0], 'cycle' => (int) explode(':', $options['idnumber'])[1]]);
$check('report call succeeds', isset($report['users']), isset($report['users']) ? '' : json_encode($report));
$byname = array_column($report['users'] ?? [], null, 'username');
$check('report lists the completed learner as completed with the certificate code',
    ($byname['e2e_aprobado']['status'] ?? '') === 'completed' && ($byname['e2e_aprobado']['certificate_code'] ?? '') === ($issue->code ?? 'x') && !empty($byname['e2e_aprobado']['completed_at']));
$check('report shows 9/9 modules passed with best grade 10 for that learner',
    count(array_filter($byname['e2e_aprobado']['modules'] ?? [], fn($m) => $m['passed'] && $m['quiz_best_grade'] == 10 && $m['pdf_viewed'])) === count($quizkeys));
$check('report lists the partial learner as in_progress with 1 module passed',
    ($byname['e2e_suspenso']['status'] ?? '') === 'in_progress' && count(array_filter($byname['e2e_suspenso']['modules'] ?? [], fn($m) => $m['passed'])) === 1);
$check('report lists the idle learner as not_started', ($byname['e2e_sinempezar']['status'] ?? '') === 'not_started');
$check('report totals add up', isset($report['totals']) && array_sum(array_intersect_key($report['totals'],
    array_flip(['completed', 'in_progress', 'not_started', 'overdue']))) === $report['totals']['enrolled']);
$check('report carries version, pass mark and content version', ($report['version'] ?? 0) === 1 && ($report['pass_percent'] ?? 0) == 80 && !empty($report['content_version']));
$other = $api('core_user_get_users', $token, ['criteria' => [['key' => 'username', 'value' => 'e2e_aprobado']]]);
// Per-module deadlines (completion.schedule: monthly): due_date/overdue are additive fields, the old ones are unchanged above.
$mods = $byname['e2e_suspenso']['modules'] ?? [];
$due = array_column($mods, 'due_date');
$check('every module reports a deadline and an overdue flag',
    count($mods) === count($quizkeys) && !in_array('', $due, true) && count(array_filter($mods, fn($m) => is_bool($m['overdue'] ?? null))) === count($mods));
// Deadlines are owned by the administrator (Quiz settings > "Expect completed on"), so no shape is assumed beyond "the API says what Moodle holds".
$check('the API reports exactly the Moodle "completion expected" date of every module quiz',
    $due === array_map(fn($k) => (new DateTime('@' . (int) $e2e->modules()[$k]->completionexpected))
        ->setTimezone(core_date::get_server_timezone_object())->format('c'), $quizkeys));
$check('the module PDFs carry no deadline (one date per module, on its quiz)',
    !array_filter($e2e->modules(), fn($cm, $key) => str_ends_with($key, ':pdf') && (int) $cm->completionexpected, ARRAY_FILTER_USE_BOTH));
$check('report totals carry with_overdue_modules', isset($report['totals']['with_overdue_modules']) && is_int($report['totals']['with_overdue_modules']));
$compliance = $report['totals']['compliance'] ?? [];
$check('every listed user has a compliance_status, and the completed learner is compliant',
    count(array_filter($byname, fn($u) => in_array($u['compliance_status'] ?? '', ['compliant', 'on_track', 'degraded', 'failed'], true))) === count($byname)
        && ($byname['e2e_aprobado']['compliance_status'] ?? '') === 'compliant');
$check('compliance totals add up to the enrolled learners', array_sum($compliance) === ($report['totals']['enrolled'] ?? -1), json_encode($compliance));
$check('the API token cannot call any other Moodle function', isset($other['exception']) && !isset($other['users']), $other['errorcode'] ?? '');
$check('an invalid token is rejected', ($api('local_awarenesssync_get_compliance_report', 'not-a-token', ['course' => 'x', 'cycle' => 1])['errorcode'] ?? '') === 'invalidtoken');
$check('the API user cannot log in through the UI (auth=webservice)', $DB->get_field('user', 'auth', ['username' => 'grc_api']) === 'webservice');
$ids = ['course' => explode(':', $options['idnumber'])[0], 'cycle' => (int) explode(':', $options['idnumber'])[1]];
$viaheader = $apiget($token, $ids);
$check('GET with the token in an Authorization header returns the same report (nothing secret in the URL)',
    ($viaheader['detail'] ?? '') === 'full' && array_column($viaheader['users'] ?? [], 'username') === array_column($report['users'] ?? [], 'username')
        && ($viaheader['totals'] ?? null) === ($report['totals'] ?? false), $viaheader['errorcode'] ?? $viaheader['raw'] ?? '');
$check('a wrong token in the header is rejected', ($apiget('0123456789abcdef0123456789abcdef', $ids)['errorcode'] ?? '') === 'invalidtoken');
$small = $apiget($token, $ids + ['detail' => 'totals']);
$check('detail=totals answers with the same totals and no personal data',
    ($small['detail'] ?? '') === 'totals' && ($small['users'] ?? null) === [] && ($small['totals'] ?? null) === ($report['totals'] ?? false)
        && strlen(json_encode($small)) < 2000, $small['errorcode'] ?? $small['raw'] ?? '');
$check('every listed user says whether the enrolment is active', $byname && count(array_filter($byname, fn($u) => ($u['enrolment_active'] ?? null) === true)) === count($byname));

// A suspended enrolment must stay visible in the evidence but leave the totals (it used to vanish from both).
$before = $report['totals']['enrolled'] ?? -1;
$DB->execute('UPDATE {user_enrolments} SET status = ? WHERE userid = ? AND enrolid IN (SELECT id FROM {enrol} WHERE courseid = ?)',
    [ENROL_USER_SUSPENDED, $idle->id, $course->id]);
$suspended = $api('local_awarenesssync_get_compliance_report', $token, ['course' => explode(':', $options['idnumber'])[0], 'cycle' => (int) explode(':', $options['idnumber'])[1]]);
$row = array_column($suspended['users'] ?? [], null, 'username')['e2e_sinempezar'] ?? null;
$check('a suspended enrolment is still listed, flagged inactive, and not counted in totals',
    $row !== null && $row['enrolment_active'] === false && ($suspended['totals']['enrolled'] ?? -1) === $before - 1,
    $row === null ? 'user missing from the report' : '');

if (!$options['keep']) {
    $e2e->delete_users();
    cli_writeln('(test users deleted; use --keep to inspect them)');
}
cli_writeln(sprintf("\n== %d passed, %d failed", $results['pass'], $results['fail']));
exit($results['fail'] ? 1 : 0);
