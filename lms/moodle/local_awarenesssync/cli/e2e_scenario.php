<?php
// This file is part of the awareness training platform (INCIBE kit -> Moodle as code).

/**
 * Building blocks for the content-lifecycle acceptance test (lms/tools/tests/test_e2e_lifecycle.py). Each call does one
 * small thing as a real learner and prints one JSON document; the test drives builds and deploys in between and asserts.
 *
 *   e2e_scenario.php --idnumber=X --do=setup                    keeper passes module 1, starter only views its PDF
 *   e2e_scenario.php --idnumber=X --do=facts --quiz=K --pdf=K --question=Q   what each test user looks like right now
 *   e2e_scenario.php --idnumber=X --do=attempt --user=starter --quiz=K --correct=1
 *   e2e_scenario.php --idnumber=X --do=cms                      every activity key with its visibility
 *   e2e_scenario.php --idnumber=X --do=issue-certificate --user=U   record an issued certificate for a test user
 *   e2e_scenario.php --idnumber=X --do=due                      every activity key with its "completion expected" date
 *   e2e_scenario.php --idnumber=X --do=setdue --quiz=K --date='2026-12-31 23:59:59'   an admin edits "Expect completed on"
 *   e2e_scenario.php --idnumber=X --do=setenddate --date=...   /  --do=setreview --quiz=K   /  --do=compliance
 *   e2e_scenario.php --idnumber=X --do=remind --from=... --date=... [--days=7,1 --overdue=1 --send=0]
 *   e2e_scenario.php --idnumber=X --do=leave-cohort --user=U   /  --do=suspend-account --user=U   /  --do=row --user=U
 *   e2e_scenario.php --idnumber=X --do=unmanaged               the API must refuse a course this plugin did not deploy
 *   e2e_scenario.php --idnumber=X --do=ensure-cohort           (before the first deploy: the course need not exist yet)
 *   e2e_scenario.php --idnumber=X --do=cleanup   /  --do=delete-course
 *
 * Local pilot only, and only on test courses (e2etest:* or a scratch cycle 9999), like e2e_check.php.
 *
 * @package    local_awarenesssync
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');

[$o] = cli_get_params([
    'idnumber' => '', 'do' => '', 'user' => '', 'quiz' => '', 'pdf' => '', 'question' => '', 'correct' => '1', 'help' => false,
    'date' => '', 'from' => '', 'days' => '7,1', 'overdue' => '1', 'send' => '0',
], ['h' => 'help']);
if ($o['help'] || !$o['idnumber'] || !$o['do']) {
    cli_error('Usage: e2e_scenario.php --idnumber=X --do=setup|facts|attempt|cms|cleanup [...]', $o['help'] ? 0 : 2);
}
if (!preg_match('~^https?://(localhost|127\.0\.0\.1)(:\d+)?(/|$)~', $CFG->wwwroot)) {
    cli_error("Refusing to create test users on {$CFG->wwwroot}: local pilot only.", 2);
}

// Everything here (test users, real attempts, GUI edits, deleting the course) only ever touches a test course: the
// synthetic one of lms/tools/tests/test_e2e_lifecycle.py or a scratch cycle; never a real cycle such as concienciacion:2026.
if (!\local_awarenesssync\testing\e2e::is_test_course($o['idnumber'])) {
    cli_error("Refusing to run on '{$o['idnumber']}': only e2etest:* or a scratch cycle "
        . \local_awarenesssync\testing\e2e::SCRATCH_CYCLE . ' can be used here.', 2);
}
\core\session\manager::set_user(get_admin());
if ($o['do'] === 'ensure-cohort') {
    cli_writeln(json_encode(['cohort' => \local_awarenesssync\testing\e2e::ensure_cohort()]));
    exit(0);
}
$course = $DB->get_record('course', ['idnumber' => $o['idnumber']]);
if (!$course && in_array($o['do'], ['cleanup', 'delete-course'], true)) {
    // Tidying up after a run that never got as far as creating the course.
    \local_awarenesssync\testing\e2e::drop_cohort_if_unused();
    cli_writeln(json_encode(['deleted' => null]));
    exit(0);
}
if (!$course) {
    cli_error("No course with idnumber '{$o['idnumber']}'.", 2);
}
$e2e = new \local_awarenesssync\testing\e2e($course);
$report = function () use ($o): array {
    [$c, $y] = explode(':', $o['idnumber']);
    return \local_awarenesssync\external\get_compliance_report::execute($c, (int) $y);
};
$out = [];

switch ($o['do']) {
    case 'setup':
        $keeper = $e2e->make_user('keeper');
        $starter = $e2e->make_user('starter');
        $e2e->view($keeper, $o['pdf']);
        $e2e->attempt($keeper, $o['quiz'], true);
        $e2e->view($starter, $o['pdf']);
        $out = ['keeper' => $keeper->id, 'starter' => $starter->id];
        break;
    case 'facts':
        foreach (['keeper', 'starter', 'newcomer'] as $name) {
            if (!$user = $e2e->user($name)) {
                continue;
            }
            [$attempts, $grade] = $e2e->attempts_and_grade($user, $o['quiz']);
            $out[$name] = [
                'pdf' => $e2e->state($user, $o['pdf']), 'quiz' => $e2e->state($user, $o['quiz']),
                'attempts' => $attempts, 'grade' => $grade,
                'first_attempt_text' => $e2e->question_text_seen($user, $o['quiz'], $o['question'], 1),
                'latest_attempt_text' => $e2e->question_text_seen($user, $o['quiz'], $o['question']),
                'review' => $e2e->review_flags($user, $o['quiz']),
            ];
        }
        break;
    case 'attempt':
        $user = $e2e->user($o['user']) ?: $e2e->make_user($o['user']);
        $e2e->view($user, $o['pdf'] ?: str_replace(':quiz', ':pdf', $o['quiz']));
        $out = ['grade' => $e2e->attempt($user, $o['quiz'], (bool) $o['correct']), 'text' => $e2e->question_text_seen($user, $o['quiz'], $o['question'])];
        break;
    case 'cms':
        foreach ($e2e->modules() as $key => $cm) {
            $out[$key] = (int) $cm->visible;
        }
        break;
    case 'issue-certificate':
        // As mod_customcert's issue task leaves it: one row per user and certificate. Lets the lifecycle test prove the plan
        // warning for a design that is re-applied while certificates already exist.
        $user = $e2e->user($o['user']) ?: cli_error("No test user '{$o['user']}'.", 2);
        foreach ($e2e->modules() as $key => $cm) {
            if ($cm->modname === 'customcert') {
                $DB->insert_record('customcert_issues', (object) [
                    'userid' => $user->id, 'customcertid' => $cm->instance, 'code' => bin2hex(random_bytes(8)), 'emailed' => 1, 'timecreated' => time(),
                ]);
                $out['issued'] = $key;
            }
        }
        break;
    case 'due':
        foreach ($e2e->modules() as $key => $cm) {
            $out[$key] = $cm->completionexpected ? date('Y-m-d H:i:s', (int) $cm->completionexpected) : null;
        }
        break;
    case 'setdue':
        // What an administrator does with Quiz settings > "Expect completed on": the date changes, completion is not touched.
        $cm = $e2e->modules()[$o['quiz']];
        $ts = strtotime($o['date']);
        $DB->set_field('course_modules', 'completionexpected', $ts, ['id' => $cm->id]);
        \core_completion\api::update_completion_date_event($cm->id, 'quiz', $cm->instance, $ts);
        \course_modinfo::purge_course_module_cache($course->id, $cm->id);
        $out = ['due' => date('Y-m-d H:i:s', $ts)];
        break;
    case 'setenddate':
        $DB->set_field('course', 'enddate', strtotime($o['date']), ['id' => $course->id]);
        $out = ['enddate' => $o['date']];
        break;
    case 'setreview':
        // Quiz settings > Review options: also show the right answer, at every moment.
        $cm = $e2e->modules()[$o['quiz']];
        $DB->set_field('quiz', 'reviewrightanswer', 0x1110, ['id' => $cm->instance]);
        $out = ['rightanswer' => true];
        break;
    case 'compliance':
        $data = $report();
        foreach ($data['users'] as $u) {
            if (strpos($u['username'], \local_awarenesssync\testing\e2e::USER_PREFIX) === 0) {
                $out[substr($u['username'], strlen(\local_awarenesssync\testing\e2e::USER_PREFIX))] = $u['compliance_status'];
            }
        }
        $out['_totals'] = $data['totals']['compliance'];
        break;
    case 'deploys':
        // Deploys that ran to their end, and the content edition the API reports as live.
        $out = ['count' => $DB->count_records('local_awarenesssync_log', ['courseid' => $course->id, 'action' => \local_awarenesssync\runner::DEPLOYED]),
            'version' => \local_awarenesssync\runner::deployed_version((int) $course->id)];
        break;
    case 'row':
        // How the API reports one test user right now (null when the person is not listed at all), plus the totals.
        $data = $report();
        $rows = array_column($data['users'], null, 'username');
        $out = ['row' => $rows[\local_awarenesssync\testing\e2e::USER_PREFIX . $o['user']] ?? null, 'enrolled' => $data['totals']['enrolled']];
        break;
    case 'leave-cohort':
        // The person leaves the company: HR (or the directory sync) takes them out of the cohort.
        require_once($CFG->dirroot . '/enrol/cohort/locallib.php');
        $user = $e2e->user($o['user']);
        cohort_remove_member($DB->get_field('cohort', 'id', ['idnumber' => \local_awarenesssync\testing\e2e::COHORT], MUST_EXIST), $user->id);
        enrol_cohort_sync(new \null_progress_trace(), $course->id);
        $out = ['left' => $o['user']];
        break;
    case 'suspend-account':
        $DB->set_field('user', 'suspended', 1, ['id' => $e2e->user($o['user'])->id]);
        $out = ['suspended' => $o['user']];
        break;
    case 'unmanaged':
        // A course nobody deployed through this plugin, whose idnumber merely looks like "<id>:<year>".
        require_once($CFG->dirroot . '/course/lib.php');
        if ($left = $DB->get_record('course', ['idnumber' => 'e2eplain:2026'])) {
            delete_course($left, false); // Left behind by an interrupted run.
        }
        $plain = create_course((object) ['fullname' => 'E2E plain course', 'shortname' => 'E2EPLAIN-' . time(), 'idnumber' => 'e2eplain:2026',
            'category' => $course->category]);
        try {
            \local_awarenesssync\external\get_compliance_report::execute('e2eplain', 2026);
            $out = ['refused' => false];
        } catch (\moodle_exception $e) {
            $out = ['refused' => $e->errorcode === 'reportnocourse'];
        } finally {
            delete_course($plain, false);
        }
        break;
    case 'remind':
        // Reminders that fall in the window (from, to]; --send=1 also delivers them (Moodle messaging -> SMTP/Mailpit).
        $sent = \local_awarenesssync\reminders::run(strtotime($o['from']), strtotime($o['date']), array_map('intval', explode(',', $o['days'])),
            (bool) $o['overdue'], (bool) $o['send'], $o['idnumber']);
        foreach ($sent as $s) {
            $u = $DB->get_record('user', ['id' => $s['userid']], 'username');
            $out[] = substr($u->username, strlen(\local_awarenesssync\testing\e2e::USER_PREFIX)) . ':' . $s['kind'] . ($s['days'] ? $s['days'] : '');
        }
        break;
    case 'inspect-quiz':
        $quizcm = $e2e->modules()[$o['quiz']];
        $entry = $DB->get_record_sql(
            "SELECT qbe.id FROM {question_bank_entries} qbe JOIN {question_categories} qc ON qc.id = qbe.questioncategoryid
              WHERE qbe.idnumber = ? AND qc.contextid = ?", [$o['question'], context_course::instance($course->id)->id]);
        $out = [
            'slots' => $DB->count_records('quiz_slots', ['quizid' => $quizcm->instance]),
            'versions' => $entry ? $DB->count_records('question_versions', ['questionbankentryid' => $entry->id]) : 0,
            'hidden' => $entry ? !$DB->record_exists_select('question_versions', "questionbankentryid = ? AND status <> 'hidden'", [$entry->id]) : null,
        ];
        break;
    case 'cleanup':
        $e2e->delete_users();
        $out = ['deleted' => true];
        break;
    case 'delete-course':
        require_once($CFG->dirroot . '/course/lib.php');
        $categoryid = (int) $course->category;
        delete_course($course, false);
        // The cycle's own category goes with it. Its parent too for the synthetic course (category "E2E" exists only for
        // the test); a scratch cycle sits under the REAL course category, which is never removed.
        $levels = strpos($o['idnumber'], 'e2etest:') === 0 ? 2 : 1;
        while ($levels-- > 0 && $categoryid && !$DB->record_exists('course', ['category' => $categoryid])
                && !$DB->record_exists('course_categories', ['parent' => $categoryid])) {
            $parent = (int) $DB->get_field('course_categories', 'parent', ['id' => $categoryid]);
            \core_course_category::get($categoryid)->delete_full(false);
            $categoryid = $parent;
        }
        \local_awarenesssync\testing\e2e::drop_cohort_if_unused();
        $out = ['deleted' => $o['idnumber']];
        break;
    default:
        cli_error("Unknown --do={$o['do']}", 2);
}
cli_writeln(json_encode($out, JSON_UNESCAPED_UNICODE));
