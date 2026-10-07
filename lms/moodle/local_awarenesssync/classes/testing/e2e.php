<?php
// This file is part of the awareness training platform (INCIBE kit -> Moodle as code).

namespace local_awarenesssync\testing;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/completionlib.php');
require_once($CFG->dirroot . '/user/lib.php');
require_once($CFG->dirroot . '/cohort/lib.php');
require_once($CFG->dirroot . '/enrol/cohort/locallib.php');
require_once($CFG->dirroot . '/mod/quiz/locallib.php');

/**
 * Drives the learner side of a synced course with real users and real quiz attempts, for acceptance tests.
 * Only used by cli/e2e_check.php and cli/e2e_scenario.php; never by the sync itself.
 *
 * @package    local_awarenesssync
 */
final class e2e {
    public const USER_PREFIX = 'e2e_';
    /** Cohort of the test users. Test courses enrol this one, never the real employee cohort. */
    public const COHORT = 'e2e-test';
    /** Cycle of the throw-away copy of a real course that `make e2e` deploys, checks and deletes. */
    public const SCRATCH_CYCLE = 9999;

    private \stdClass $course;

    public function __construct(\stdClass $course) {
        $this->course = $course;
    }

    /**
     * Test users take real quiz attempts, and Moodle keeps those after the users are deleted: on a real cycle they would
     * make the sync treat the course as "in use" (blocked changes) and pollute its reports. So learners are only ever
     * simulated on the synthetic course of the lifecycle test or on a scratch cycle.
     */
    public static function is_test_course(string $idnumber): bool {
        return (bool) preg_match('/^(e2etest:\d{4}|[a-z][a-z0-9_-]*:' . self::SCRATCH_CYCLE . ')$/', $idnumber);
    }

    /** Create the test cohort when missing; returns its id. */
    public static function ensure_cohort(): int {
        global $DB;
        if ($id = $DB->get_field('cohort', 'id', ['idnumber' => self::COHORT])) {
            return (int) $id;
        }
        return (int) cohort_add_cohort((object) [
            'contextid' => \context_system::instance()->id, 'name' => 'E2E test users', 'idnumber' => self::COHORT,
            'description' => 'Temporary users of the acceptance tests.', 'descriptionformat' => FORMAT_HTML, 'visible' => 0, 'component' => '',
        ]);
    }

    /** Remove the test cohort once no course enrols it any more. */
    public static function drop_cohort_if_unused(): void {
        global $DB;
        $cohort = $DB->get_record('cohort', ['idnumber' => self::COHORT]);
        if ($cohort && !$DB->record_exists('enrol', ['enrol' => 'cohort', 'customint1' => $cohort->id])) {
            cohort_delete_cohort($cohort);
        }
    }

    /** @return \cm_info[] activities of the course keyed by their permanent key (the cm idnumber), read fresh each call */
    public function modules(): array {
        $modules = [];
        foreach (get_fast_modinfo($this->course)->get_cms() as $cm) {
            if ($cm->idnumber !== '') {
                $modules[$cm->idnumber] = $cm;
            }
        }
        return $modules;
    }

    /** Create (or recreate) a test user, add it to the test cohort and run cohort enrolment. */
    public function make_user(string $name): \stdClass {
        global $DB, $CFG;
        if ($old = $DB->get_record('user', ['username' => self::USER_PREFIX . $name])) {
            delete_user($old);
        }
        $id = user_create_user((object) [
            'username' => self::USER_PREFIX . $name, 'password' => 'E2e-Check-1!', 'firstname' => 'Prueba', 'lastname' => ucfirst($name),
            'email' => self::USER_PREFIX . "$name@example.com", 'auth' => 'manual', 'confirmed' => 1,
            'mnethostid' => $CFG->mnet_localhost_id, 'lang' => 'es',
        ], true, false);
        cohort_add_member($DB->get_field('cohort', 'id', ['idnumber' => self::COHORT], MUST_EXIST), $id);
        enrol_cohort_sync(new \null_progress_trace(), $this->course->id);
        return $DB->get_record('user', ['id' => $id], '*', MUST_EXIST);
    }

    public function user(string $name): ?\stdClass {
        global $DB;
        return $DB->get_record('user', ['username' => self::USER_PREFIX . $name, 'deleted' => 0]) ?: null;
    }

    public function delete_users(): void {
        global $DB;
        foreach ($DB->get_records_select('user', $DB->sql_like('username', ':p') . ' AND deleted = 0', ['p' => self::USER_PREFIX . '%']) as $u) {
            delete_user($u);
        }
    }

    /** Mark a resource as viewed, as opening it in a browser does. */
    public function view(\stdClass $user, string $key): void {
        $cm = get_fast_modinfo($this->course, $user->id)->get_cm($this->modules()[$key]->id);
        (new \completion_info($this->course))->set_module_viewed($cm, $user->id);
    }

    /**
     * Take one attempt as $user, answering every question correctly or every question wrongly, by posting the same
     * form data a browser would. Returns the quiz grade out of 10, or null if none.
     */
    public function attempt(\stdClass $user, string $key, bool $correct): ?float {
        global $DB;
        $cm = $this->modules()[$key];
        \core\session\manager::set_user($user);
        try {
            $quizobj = \mod_quiz\quiz_settings::create($cm->instance, $user->id);
            $number = $DB->count_records('quiz_attempts', ['quiz' => $cm->instance, 'userid' => $user->id]) + 1;
            $created = quiz_prepare_and_start_new_attempt($quizobj, $number, null, false, [], [], $user->id);
            $attemptobj = \mod_quiz\quiz_attempt::create($created->id);
            $post = ['slots' => implode(',', $attemptobj->get_slots())];
            foreach ($attemptobj->get_slots() as $slot) {
                $qa = $attemptobj->get_question_attempt($slot);
                $right = (int) $qa->get_correct_response()['answer'];
                $post[$qa->get_qt_field_name('answer')] = $correct ? $right : ($right + 1) % 4;
                $post[$qa->get_control_field_name('sequencecheck')] = $qa->get_sequence_check_count();
            }
            $now = time();
            $attemptobj->process_submitted_actions($now, false, $post);
            \mod_quiz\quiz_attempt::create($created->id)->process_finish($now, false);
        } finally {
            \core\session\manager::set_user(get_admin());
        }
        $grade = $DB->get_field('quiz_grades', 'grade', ['quiz' => $cm->instance, 'userid' => $user->id]);
        return $grade === false ? null : (float) $grade;
    }

    /**
     * The text of one question exactly as the learner saw it in one of their attempts (1-based; 0 = latest attempt),
     * identified by the question's permanent key. This is what proves old attempts keep the version they were shown.
     */
    public function question_text_seen(\stdClass $user, string $quizkey, string $questionkey, int $attemptnumber = 0): ?string {
        global $DB;
        $attempts = $DB->get_records('quiz_attempts', ['quiz' => $this->modules()[$quizkey]->instance, 'userid' => $user->id], 'attempt ASC');
        $attempt = $attemptnumber
            ? (array_values(array_filter($attempts, fn($a) => (int) $a->attempt === $attemptnumber))[0] ?? null)
            : (end($attempts) ?: null);
        if (!$attempt) {
            return null;
        }
        $attemptobj = \mod_quiz\quiz_attempt::create($attempt->id);
        foreach ($attemptobj->get_slots() as $slot) {
            $question = $attemptobj->get_question_attempt($slot)->get_question();
            if ($question->name === $questionkey) {
                return trim(strip_tags($question->questiontext));
            }
        }
        return null;
    }

    /**
     * What the learner is allowed to see of their latest finished attempt on a quiz, as Moodle's review page decides it:
     * ['marks' => bool, 'correctness' => bool, 'rightanswer' => bool, 'feedback' => bool]; null without a finished attempt.
     */
    public function review_flags(\stdClass $user, string $quizkey): ?array {
        global $DB;
        $attempts = $DB->get_records('quiz_attempts', ['quiz' => $this->modules()[$quizkey]->instance, 'userid' => $user->id,
            'state' => \mod_quiz\quiz_attempt::FINISHED], 'attempt DESC', 'id', 0, 1);
        if (!$attempts) {
            return null;
        }
        \core\session\manager::set_user($user);
        try {
            $options = \mod_quiz\quiz_attempt::create(reset($attempts)->id)->get_display_options(true);
        } finally {
            \core\session\manager::set_user(get_admin());
        }
        $on = fn($v) => $v == \question_display_options::VISIBLE;
        return ['marks' => $options->marks >= \question_display_options::MARK_AND_MAX, 'correctness' => $on($options->correctness),
            'rightanswer' => $on($options->rightanswer), 'feedback' => $on($options->feedback) || $on($options->generalfeedback)];
    }

    /** Number of attempts and best grade of a user on a quiz. */
    public function attempts_and_grade(\stdClass $user, string $quizkey): array {
        global $DB;
        $instance = $this->modules()[$quizkey]->instance;
        $grade = $DB->get_field('quiz_grades', 'grade', ['quiz' => $instance, 'userid' => $user->id]);
        return [$DB->count_records('quiz_attempts', ['quiz' => $instance, 'userid' => $user->id]), $grade === false ? null : (float) $grade];
    }

    /** Completion state constant (COMPLETION_*) of an activity for a user. */
    public function state(\stdClass $user, string $key): int {
        $cm = get_fast_modinfo($this->course, $user->id)->get_cm($this->modules()[$key]->id);
        return (int) (new \completion_info($this->course))->get_data($cm, false, $user->id)->completionstate;
    }

    /** Whether the activity is currently available to the user (restrictions satisfied). */
    public function available(\stdClass $user, string $key): bool {
        return (bool) get_fast_modinfo($this->course, $user->id)->get_cm($this->modules()[$key]->id)->available;
    }

    public function course_completed(\stdClass $user): bool {
        global $DB;
        return !empty($DB->get_field('course_completions', 'timecompleted', ['course' => $this->course->id, 'userid' => $user->id]));
    }
}
