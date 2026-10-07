<?php
// This file is part of the awareness training platform (INCIBE kit -> Moodle as code).

namespace local_awarenesssync\sync;

use local_awarenesssync\map;
use local_awarenesssync\sync_context;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/mod/quiz/locallib.php');

/**
 * One quiz per module: pass mark, completion on pass, restricted until the module PDF was viewed.
 *
 * @package    local_awarenesssync
 */
final class quiz_syncer extends module_syncer {
    /** Quiz-level maximum grade. Every question is worth 1 mark, so a mark of 10 is 100 %. */
    private const MAX_GRADE = 10;

    private const GRADE_METHODS = [
        'highest' => QUIZ_GRADEHIGHEST, 'average' => QUIZ_GRADEAVERAGE, 'first' => QUIZ_ATTEMPTFIRST, 'last' => QUIZ_ATTEMPTLAST,
    ];

    protected static function modulename(): string {
        return 'quiz';
    }

    protected static function completion_rule(array $item): array {
        return [COMPLETION_TRACKING_AUTOMATIC, COMPLETION_VIEW_NOT_REQUIRED, 1];
    }

    public static function sync(sync_context $ctx, array $section, array $item): void {
        if ($hard = self::retirements_with_attempts($ctx, $item)) {
            // Moodle itself refuses to remove questions from an attempted quiz, so no flag can make this safe.
            foreach ($hard as $key) {
                $ctx->record('blocked', 'quiz', $item['key'], "retires question $key from a quiz that already has attempts; "
                    . 'Moodle forbids it. Keep the question for this cycle and retire it in the next one');
            }
            return;
        }
        if ($risks = self::risks_with_attempts($ctx, $item)) {
            foreach ($risks as $risk) {
                $ctx->record($ctx->allowstructure ? 'update' : 'blocked', 'quiz', $item['key'], $ctx->allowstructure
                    ? "$risk (allowed by --allow-structure-change)"
                    : "$risk, which would re-score existing attempts. Start a new cycle instead, or pass --allow-structure-change");
            }
            if (!$ctx->allowstructure) {
                return;
            }
        }
        parent::sync($ctx, $section, $item);
        // Plan and apply must agree: the bank is reported in plan mode, and in apply mode it is synced here whenever the
        // quiz itself was not re-saved (in that case after_save() already did it).
        if (!$ctx->apply) {
            question_syncer::sync_bank($ctx, $item);
            self::plan_retirements($ctx, $item);
        } else if (empty($ctx->saved[$item['key']])) {
            question_syncer::sync_bank($ctx, $item);
        }
    }

    /** Plan-mode report of the slots after_save() would retire, so the plan lists every retirement. */
    private static function plan_retirements(sync_context $ctx, array $item): void {
        global $DB;
        $row = $ctx->course ? map::get($ctx->course->id, $item['key']) : null;
        $cm = $row ? $DB->get_record('course_modules', ['id' => $row->instanceid]) : null;
        $quiz = $cm ? $DB->get_record('quiz', ['id' => $cm->instance]) : null;
        if (!$quiz) {
            return;
        }
        $inquiz = self::slot_entries($quiz->id);
        foreach ($ctx->manifest->data['retired_question_keys'] as $key) {
            $entry = question_syncer::entry_for_key($ctx, $key);
            if ($entry && isset($inquiz[$entry->id])) {
                $ctx->record('retire', 'question', $key, "removed from {$item['key']}");
            }
        }
    }

    /** @return string[] retired question keys that still have a slot in a quiz which already has attempts */
    private static function retirements_with_attempts(sync_context $ctx, array $item): array {
        global $DB;
        $row = $ctx->course ? map::get($ctx->course->id, $item['key']) : null;
        $cm = $row ? $DB->get_record('course_modules', ['id' => $row->instanceid]) : null;
        $quiz = $cm ? $DB->get_record('quiz', ['id' => $cm->instance]) : null;
        if (!$quiz || !quiz_has_attempts($quiz->id)) {
            return [];
        }
        $inquiz = self::slot_entries($quiz->id);
        $keys = [];
        foreach ($ctx->manifest->data['retired_question_keys'] as $key) {
            $entry = question_syncer::entry_for_key($ctx, $key);
            if ($entry && isset($inquiz[$entry->id])) {
                $keys[] = $key;
            }
        }
        return $keys;
    }

    /**
     * Changes that would silently re-score learners who already took this quiz. Moodle rescales every attempt by the
     * quiz's CURRENT total mark, so adding a 4th question turns an old perfect 3/3 into 7.5/10, below an 80 % pass mark.
     *
     * @return string[] what the manifest would change; empty when the quiz has no attempts or nothing risky changes
     */
    private static function risks_with_attempts(sync_context $ctx, array $item): array {
        global $DB;
        $row = $ctx->course ? map::get($ctx->course->id, $item['key']) : null;
        $cm = $row ? $DB->get_record('course_modules', ['id' => $row->instanceid]) : null;
        $quiz = $cm ? $DB->get_record('quiz', ['id' => $cm->instance]) : null;
        if (!$quiz || !quiz_has_attempts($quiz->id)) {
            return [];
        }
        $risks = [];
        $inquiz = self::slot_entries($quiz->id);
        foreach ($item['questions'] as $q) {
            $entry = question_syncer::entry_for_key($ctx, $q['key']);
            if (!$entry || !isset($inquiz[$entry->id])) {
                $risks[] = "adds question {$q['key']}";
            }
        }
        $gradeitem = $DB->get_record('grade_items', ['itemtype' => 'mod', 'itemmodule' => 'quiz', 'iteminstance' => $quiz->id, 'itemnumber' => 0]);
        $newpass = round(self::MAX_GRADE * $item['pass_percent'] / 100, 5);
        if ($gradeitem && abs((float) $gradeitem->gradepass - $newpass) > 0.00001) {
            $risks[] = 'changes the pass mark from ' . round((float) $gradeitem->gradepass, 2) . " to $newpass";
        }
        // Moodle's QUIZ_GRADE* constants are strings, so compare as ints.
        if ((int) $quiz->grademethod !== (int) self::GRADE_METHODS[$item['grademethod']]) {
            $risks[] = "changes the grading method to {$item['grademethod']}";
        }
        return $risks;
    }

    protected static function fields(sync_context $ctx, array $item, ?\stdClass $existing): array {
        return [
            'timeopen' => 0, 'timeclose' => 0, 'timelimit' => 0, 'overduehandling' => 'autoabandon', 'graceperiod' => 0,
            'preferredbehaviour' => 'deferredfeedback', 'canredoquestions' => 0,
            'attempts' => $item['attempts'], 'attemptonlast' => 0, 'grademethod' => self::GRADE_METHODS[$item['grademethod']],
            'decimalpoints' => 2, 'questiondecimalpoints' => -1, 'questionsperpage' => 0, 'navmethod' => 'free',
            'shuffleanswers' => 1, 'grade' => self::MAX_GRADE, 'quizpassword' => '', 'subnet' => '', 'browsersecurity' => '-',
            'delay1' => 0, 'delay2' => 0, 'showuserpicture' => 0, 'showblocks' => 0,
            'gradepass' => round(self::MAX_GRADE * $item['pass_percent'] / 100, 5),
            'completion' => COMPLETION_TRACKING_AUTOMATIC, 'completionview' => COMPLETION_VIEW_NOT_REQUIRED,
            'completionusegrade' => 1, 'completionpassgrade' => 1, 'completionexpected' => $ctx->item_due(),
        ] + self::review_options($item['review']);
    }

    /** On update the review options (Quiz settings > Review options) stay as the administrator left them. */
    protected static function admin_owned(array $fields, \stdClass $cm, ?\stdClass $existing): array {
        $fields = parent::admin_owned($fields, $cm, $existing);
        if (!$existing) {
            return $fields;
        }
        // Replace the manifest's review flags by the ones stored in the quiz (column bitmask -> form field names).
        $whats = ['attempt', 'correctness', 'marks', 'maxmarks', 'specificfeedback', 'generalfeedback', 'rightanswer', 'overallfeedback'];
        $bits = ['immediately' => 0x01000, 'open' => 0x00100, 'closed' => 0x00010];
        foreach ($whats as $what) {
            foreach ($bits as $when => $bit) {
                unset($fields["{$what}{$when}"]);
                if ((int) $existing->{"review{$what}"} & $bit) {
                    $fields["{$what}{$when}"] = 1;
                }
            }
        }
        return $fields;
    }

    /**
     * Map the manifest's review policy (the INITIAL value; see {@see admin_owned()}) to Moodle's per-moment review flags. Learners always see their mark once the
     * attempt is submitted; what else they see is what the policy controls:
     *   marks_only   the mark
     *   correctness  the mark, the attempt and which answers were right/wrong (never the correct option or feedback)
     *   after_close  everything, but only once the quiz has closed (we set no close date, so effectively never)
     *   immediately  everything, right after the attempt
     *
     * The keys are the form field names quiz_process_options() reads ("marksimmediately"), NOT the column names
     * ("reviewmarks…"): with the column names Moodle silently stores 0 for every review flag. Review settings are
     * presentation, not scoring, so changing them on a quiz with attempts is a plain update, never a "blocked" change.
     */
    private static function review_options(string $policy): array {
        $moments = ['immediately', 'open', 'closed'];
        $flags = [];
        foreach (['marks', 'maxmarks'] as $what) {
            foreach ($moments as $when) {
                $flags["{$what}{$when}"] = 1;
            }
        }
        $all = ['attempt', 'correctness', 'specificfeedback', 'generalfeedback', 'rightanswer'];
        [$detail, $detailwhen] = [
            'marks_only' => [[], []],
            'correctness' => [['attempt', 'correctness'], $moments],
            'after_close' => [$all, ['closed']],
            'immediately' => [$all, $moments],
        ][$policy];
        foreach ($detail as $what) {
            foreach ($detailwhen as $when) {
                $flags["{$what}{$when}"] = 1;
            }
        }
        return $flags;
    }

    protected static function after_save(sync_context $ctx, array $item, \stdClass $cm, array $fields): void {
        global $DB;
        $quiz = $DB->get_record('quiz', ['id' => $cm->instance], '*', MUST_EXIST);
        $quiz->cmid = $cm->id;
        $ids = question_syncer::sync_bank($ctx, $item);

        // Add a slot for every active question that has none. New questions go last; existing order is left alone.
        $inquiz = self::slot_entries($quiz->id);
        foreach ($item['questions'] as $q) {
            $entry = question_syncer::entry_for_key($ctx, $q['key']);
            if ($entry && !isset($inquiz[$entry->id])) {
                quiz_add_quiz_question($ids[$q['key']], $quiz);
                $ctx->record('link', 'question', $q['key'], "added to {$item['key']}");
            }
        }
        self::retire($ctx, $item, $quiz);

        $DB->set_field('quiz_sections', 'shufflequestions', $item['shuffle_questions'] ? 1 : 0, ['quizid' => $quiz->id]);
        $calculator = \mod_quiz\quiz_settings::create($quiz->id)->get_grade_calculator();
        $calculator->recompute_quiz_sumgrades();
        if (quiz_has_attempts($quiz->id)) {
            $calculator->recompute_all_final_grades();
        }
    }

    /** Take retired questions out of this quiz and hide them in the bank. Attempts already taken are untouched. */
    private static function retire(sync_context $ctx, array $item, \stdClass $quiz): void {
        foreach ($ctx->manifest->data['retired_question_keys'] as $key) {
            $entry = question_syncer::entry_for_key($ctx, $key);
            if (!$entry) {
                continue;
            }
            $slot = self::slot_entries($quiz->id)[$entry->id] ?? null;
            if ($slot !== null) {
                \mod_quiz\quiz_settings::create($quiz->id)->get_structure()->remove_slot($slot);
                $ctx->record('retire', 'question', $key, "removed from {$item['key']}");
            }
            if (question_syncer::hide_entry($entry)) {
                $ctx->record('retire', 'question', $key, 'hidden in the question bank');
            }
        }
    }

    /** @return int[] question bank entry id => slot number, for one quiz */
    private static function slot_entries(int $quizid): array {
        global $DB;
        return $DB->get_records_sql_menu(
            "SELECT qr.questionbankentryid, qs.slot
               FROM {quiz_slots} qs
               JOIN {question_references} qr ON qr.itemid = qs.id AND qr.component = 'mod_quiz' AND qr.questionarea = 'slot'
              WHERE qs.quizid = ?", [$quizid]);
    }
}
