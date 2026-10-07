<?php
// This file is part of the awareness training platform (INCIBE kit -> Moodle as code).

namespace local_awarenesssync\external;

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;

defined('MOODLE_INTERNAL') || die();

/**
 * The compliance report consumed by CISO Assistant's scheduled evidence workflow.
 *
 * CONTRACT (see AGENTS.md): additive changes only; bump REPORT_VERSION for anything else. One call returns everything a
 * GRC evidence record needs: one row per enrolled employee, per-module results, and totals.
 *
 * @package    local_awarenesssync
 */
final class get_compliance_report extends external_api {
    /** Version of the response shape. */
    public const REPORT_VERSION = 1;

    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'course' => new external_value(PARAM_ALPHANUMEXT, 'Course id from course.yaml, e.g. "concienciacion"'),
            'cycle' => new external_value(PARAM_INT, 'Cycle year, e.g. 2026'),
            'detail' => new external_value(PARAM_ALPHA, '"full" (default): one row per person. "totals": the same report with an empty `users` list, '
                . 'for callers that only need the counts or can only read a small answer (a workflow engine testing the call)', VALUE_DEFAULT, 'full'),
        ]);
    }

    public static function execute(string $course, int $cycle, string $detail = 'full'): array {
        global $DB;
        ['course' => $course, 'cycle' => $cycle, 'detail' => $detail] = self::validate_parameters(self::execute_parameters(),
            ['course' => $course, 'cycle' => $cycle, 'detail' => $detail]);
        if (!in_array($detail, ['full', 'totals'], true)) {
            throw new \invalid_parameter_exception('detail must be "full" or "totals"');
        }
        $syscontext = \context_system::instance();
        self::validate_context($syscontext);
        require_capability('local/awarenesssync:viewreport', $syscontext);

        $moodlecourse = $DB->get_record('course', ['idnumber' => "$course:$cycle"]);
        // Only courses this plugin deployed: any other course whose idnumber happens to look like "<id>:<year>" must not be
        // readable with the GRC token (same error as a missing course, so its existence is not revealed either).
        if (!$moodlecourse || !$DB->record_exists('local_awarenesssync_map', ['courseid' => $moodlecourse->id, 'itemtype' => 'course'])) {
            throw new \moodle_exception('reportnocourse', 'local_awarenesssync', '', "$course:$cycle");
        }
        $context = \context_course::instance($moodlecourse->id);
        $modules = self::modules($moodlecourse);
        $fields = 'u.id, u.username, u.email, u.idnumber, u.firstname, u.lastname, u.suspended';
        // Everybody who was ever enrolled is listed (a suspended or expired enrolment must not make a person vanish from
        // the evidence); only the active ones count in the totals, and each row says which kind it is.
        $users = get_enrolled_users($context, 'mod/quiz:attempt', 0, $fields, 'u.lastname, u.firstname', 0, 0, false);
        $active = get_enrolled_users($context, 'mod/quiz:attempt', 0, 'u.id', '', 0, 0, true);

        $cmids = array_merge(array_column($modules, 'pdf_cm'), array_column($modules, 'quiz_cm'));
        $completion = self::completion_rows($cmids);
        $quizgrades = self::quiz_grades(array_column($modules, 'quiz_instance'));
        $coursedone = $DB->get_records('course_completions', ['course' => $moodlecourse->id], '', 'userid, timecompleted');
        $certificates = self::certificates($moodlecourse->id);
        $enrolled = self::enrolment_dates($moodlecourse->id);
        $now = time();
        $due = (int) $moodlecourse->enddate;
        // The programme is over when its last deadline has passed: the course end date, or a later module deadline an administrator set.
        $final = max($due, 0, ...array_column($modules, 'due'));

        $rows = [];
        $totals = ['enrolled' => 0, 'completed' => 0, 'in_progress' => 0, 'not_started' => 0, 'overdue' => 0,
            'with_overdue_modules' => 0, 'compliance' => ['compliant' => 0, 'on_track' => 0, 'degraded' => 0, 'failed' => 0]];
        foreach ($users as $u) {
            $mods = [];
            $started = false;
            $anyoverdue = false;
            foreach ($modules as $key => $m) {
                $pdf = $completion[$m['pdf_cm']][$u->id] ?? null;
                $quiz = $completion[$m['quiz_cm']][$u->id] ?? null;
                $grade = $quizgrades[$m['quiz_instance']][$u->id] ?? null;
                $passed = $quiz !== null && (int) $quiz->completionstate === COMPLETION_COMPLETE_PASS;
                $started = $started || $pdf !== null || $grade !== null;
                $modoverdue = !$passed && $m['due'] && $now > $m['due'];
                $anyoverdue = $anyoverdue || $modoverdue;
                $mods[] = [
                    'key' => $key,
                    'pdf_viewed' => $pdf !== null && in_array((int) $pdf->completionstate, [COMPLETION_COMPLETE, COMPLETION_COMPLETE_PASS], true),
                    'quiz_best_grade' => $grade,
                    'passed' => $passed,
                    'passed_at' => $passed ? self::iso((int) $quiz->timemodified) : '',
                    'due_date' => self::iso($m['due']),
                    'overdue' => $modoverdue,
                ];
            }
            $doneat = (int) ($coursedone[$u->id]->timecompleted ?? 0);
            $status = $doneat ? 'completed' : ($due && $now > $due ? 'overdue' : ($started ? 'in_progress' : 'not_started'));
            // GRC state: compliant (course done) > failed (programme over, not done) > degraded (a module deadline missed) > on_track.
            $compliance = $doneat ? 'compliant' : ($final && $now > $final ? 'failed' : ($anyoverdue ? 'degraded' : 'on_track'));
            // A suspended ACCOUNT cannot train either (the reminders skip it too), whatever its enrolment says.
            $isactive = isset($active[$u->id]) && !$u->suspended;
            if ($isactive) {
                $totals['enrolled']++;
                $totals[$status]++;
                $totals['with_overdue_modules'] += $anyoverdue ? 1 : 0;
                $totals['compliance'][$compliance]++;
            }
            $rows[] = [
                'username' => $u->username, 'email' => $u->email, 'idnumber' => $u->idnumber, 'fullname' => fullname($u),
                'enrolled_at' => self::iso($enrolled[$u->id] ?? 0), 'enrolment_active' => $isactive, 'status' => $status,
                'compliance_status' => $compliance,
                'completed_at' => self::iso($doneat), 'certificate_code' => $certificates[$u->id] ?? '',
                'modules' => $mods,
            ];
        }
        $totals['coverage_percent'] = $totals['enrolled'] ? round(100 * $totals['completed'] / $totals['enrolled'], 1) : 0.0;

        return [
            'version' => self::REPORT_VERSION,
            'generated_at' => self::iso($now),
            'course' => $course, 'cycle' => $cycle, 'shortname' => $moodlecourse->shortname,
            'content_version' => \local_awarenesssync\runner::deployed_version((int) $moodlecourse->id),
            'start_date' => self::iso((int) $moodlecourse->startdate), 'due_date' => self::iso($due),
            'pass_percent' => self::pass_percent($modules),
            'detail' => $detail,
            'totals' => $totals, 'users' => $detail === 'totals' ? [] : $rows,
        ];
    }

    public static function execute_returns(): external_single_structure {
        $str = fn(string $d) => new external_value(PARAM_RAW, $d);
        return new external_single_structure([
            'version' => new external_value(PARAM_INT, 'Response shape version'),
            'generated_at' => $str('ISO 8601'), 'course' => $str('course id'), 'cycle' => new external_value(PARAM_INT, 'cycle year'),
            'shortname' => $str('Moodle shortname'), 'content_version' => $str('Content edition last deployed'),
            'start_date' => $str('ISO 8601'), 'due_date' => $str('ISO 8601'),
            'pass_percent' => new external_value(PARAM_FLOAT, 'Pass mark of the quizzes, %; null when the course has no quiz yet', VALUE_DEFAULT, null, NULL_ALLOWED),
            'detail' => new external_value(PARAM_ALPHA, 'full | totals: with "totals" the `users` list is empty on purpose (see `totals.enrolled`)', VALUE_DEFAULT, 'full'),
            'totals' => new external_single_structure([
                'enrolled' => new external_value(PARAM_INT, ''), 'completed' => new external_value(PARAM_INT, ''),
                'in_progress' => new external_value(PARAM_INT, ''), 'not_started' => new external_value(PARAM_INT, ''),
                'overdue' => new external_value(PARAM_INT, 'not completed and past the COURSE due date'),
                'coverage_percent' => new external_value(PARAM_FLOAT, 'completed / enrolled'),
                'with_overdue_modules' => new external_value(PARAM_INT, 'active users with at least one module past its own deadline and not passed'),
                'compliance' => new external_single_structure([
                    'compliant' => new external_value(PARAM_INT, 'active users who completed the course'),
                    'on_track' => new external_value(PARAM_INT, 'no module past its deadline'),
                    'degraded' => new external_value(PARAM_INT, 'at least one module past its deadline, programme not over'),
                    'failed' => new external_value(PARAM_INT, 'last deadline passed without completing the course'),
                ]),
            ]),
            'users' => new external_multiple_structure(new external_single_structure([
                'username' => $str(''), 'email' => $str(''), 'idnumber' => $str('Employee id if set'), 'fullname' => $str(''),
                'enrolled_at' => $str('ISO 8601'),
                'enrolment_active' => new external_value(PARAM_BOOL, 'false for a suspended/expired enrolment or a suspended account; those users are not in totals'),
                'status' => $str('completed | in_progress | not_started | overdue'),
                'compliance_status' => $str('compliant | on_track | degraded | failed (see docs/ciso-assistant-integration.md)'),
                'completed_at' => $str('ISO 8601, empty until complete'), 'certificate_code' => $str('Verification code, empty until issued'),
                'modules' => new external_multiple_structure(new external_single_structure([
                    'key' => $str('Module key, e.g. M01'), 'pdf_viewed' => new external_value(PARAM_BOOL, ''),
                    'quiz_best_grade' => new external_value(PARAM_FLOAT, 'Best grade out of 10; null if never attempted', VALUE_DEFAULT, null, NULL_ALLOWED),
                    'passed' => new external_value(PARAM_BOOL, ''), 'passed_at' => $str('ISO 8601, empty if not passed'),
                    'due_date' => $str('Module deadline, ISO 8601, empty if none'),
                    'overdue' => new external_value(PARAM_BOOL, 'not passed and past the module deadline'),
                ])),
            ])),
        ]);
    }

    /** @return array[] module key => ['pdf_cm','quiz_cm','quiz_instance','pass_percent','due'] read from the activities' permanent keys */
    private static function modules(\stdClass $course): array {
        global $DB;
        $modules = [];
        foreach (get_fast_modinfo($course)->get_cms() as $cm) {
            if (!preg_match('/:(M\d{2}):(pdf|quiz)$/', $cm->idnumber, $m) || !$cm->visible) {
                continue;
            }
            $modules[$m[1]] ??= ['pdf_cm' => 0, 'quiz_cm' => 0, 'quiz_instance' => 0, 'pass_percent' => null, 'due' => 0];
            if ($m[2] === 'pdf') {
                $modules[$m[1]]['pdf_cm'] = (int) $cm->id;
            } else {
                $modules[$m[1]]['quiz_cm'] = (int) $cm->id;
                $modules[$m[1]]['due'] = (int) $cm->completionexpected; // The module deadline (initial value from the sync, then whatever the admin set).
                $modules[$m[1]]['quiz_instance'] = (int) $cm->instance;
                $gi = $DB->get_record('grade_items', ['itemtype' => 'mod', 'itemmodule' => 'quiz', 'iteminstance' => $cm->instance, 'itemnumber' => 0]);
                $modules[$m[1]]['pass_percent'] = $gi && $gi->grademax > 0 ? round(100 * $gi->gradepass / $gi->grademax, 1) : null;
            }
        }
        ksort($modules);
        return array_filter($modules, fn($m) => $m['pdf_cm'] && $m['quiz_cm']);
    }

    private static function pass_percent(array $modules): ?float {
        $values = array_filter(array_column($modules, 'pass_percent'), fn($v) => $v !== null);
        return $values ? (float) min($values) : null;
    }

    /** @return array cmid => userid => completion row */
    private static function completion_rows(array $cmids): array {
        global $DB;
        if (!$cmids) {
            return [];
        }
        [$in, $params] = $DB->get_in_or_equal($cmids);
        $out = [];
        foreach ($DB->get_recordset_select('course_modules_completion', "coursemoduleid $in", $params, '', 'id, coursemoduleid, userid, completionstate, timemodified') as $r) {
            $out[$r->coursemoduleid][$r->userid] = $r;
        }
        return $out;
    }

    /** @return array quizid => userid => best grade */
    private static function quiz_grades(array $quizids): array {
        global $DB;
        if (!$quizids) {
            return [];
        }
        [$in, $params] = $DB->get_in_or_equal($quizids);
        $out = [];
        foreach ($DB->get_recordset_select('quiz_grades', "quiz $in", $params, '', 'id, quiz, userid, grade') as $r) {
            $out[$r->quiz][$r->userid] = round((float) $r->grade, 2);
        }
        return $out;
    }

    /** @return string[] userid => certificate verification code */
    private static function certificates(int $courseid): array {
        global $DB;
        // Only the certificate this plugin manages (its cm idnumber ends in :CERT:certificate): a second, hand-made or
        // hidden-old certificate in the course must not decide which code a person is reported with. First issue wins.
        $sql = "SELECT ci.id, ci.userid, ci.code
                  FROM {customcert_issues} ci
                  JOIN {customcert} c ON c.id = ci.customcertid
                  JOIN {course_modules} cm ON cm.instance = c.id AND cm.course = c.course AND cm.deletioninprogress = 0
                  JOIN {modules} m ON m.id = cm.module AND m.name = 'customcert'
                 WHERE c.course = ? AND " . $DB->sql_like('cm.idnumber', '?') . '
              ORDER BY ci.timecreated, ci.id';
        $out = [];
        foreach ($DB->get_recordset_sql($sql, [$courseid, '%:CERT:certificate']) as $r) {
            $out[$r->userid] ??= $r->code;
        }
        return $out;
    }

    /** @return int[] userid => first enrolment time */
    private static function enrolment_dates(int $courseid): array {
        global $DB;
        return $DB->get_records_sql_menu(
            'SELECT ue.userid, MIN(ue.timecreated) FROM {user_enrolments} ue JOIN {enrol} e ON e.id = ue.enrolid WHERE e.courseid = ? GROUP BY ue.userid', [$courseid]);
    }

    private static function iso(int $timestamp): string {
        return $timestamp ? (new \DateTime('@' . $timestamp))->setTimezone(\core_date::get_server_timezone_object())->format('c') : '';
    }
}
