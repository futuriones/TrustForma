<?php
// This file is part of the awareness training platform (INCIBE kit -> Moodle as code).

namespace local_awarenesssync\sync;

use core_question\local\bank\question_version_status;
use local_awarenesssync\map;
use local_awarenesssync\sync_context;
use local_awarenesssync\util;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/questionlib.php');

/**
 * Keeps the course question bank in step with a quiz item's questions.
 *
 * A question is identified by its permanent key (M05-Q03), stored as the bank entry idnumber. Changing a question's
 * text or answers creates a NEW VERSION of the same bank entry, so attempts already taken keep pointing at the
 * version the learner actually saw, while new attempts use the latest one (quiz slots reference "always latest").
 *
 * @package    local_awarenesssync
 */
final class question_syncer {
    /**
     * Create or version the questions of one quiz item. In plan mode it only records what would happen.
     *
     * @return int[] question key => id of the latest question version (apply mode only)
     */
    public static function sync_bank(sync_context $ctx, array $quizitem): array {
        global $DB;
        $catid = self::category_id($ctx);
        $ids = [];
        foreach ($quizitem['questions'] as $q) {
            $hash = util::digest($q);
            $row = $ctx->course ? map::get($ctx->course->id, $q['key']) : null;
            $entry = $catid ? $DB->get_record('question_bank_entries', ['idnumber' => $q['key'], 'questioncategoryid' => $catid]) : null;
            if (!$entry) {
                $ctx->record('create', 'question', $q['key'], 'version 1');
            } else if (!$row || $row->contenthash !== $hash) {
                $ctx->record('update', 'question', $q['key'], 'new version');
            } else {
                $ids[$q['key']] = self::latest_question_id((int) $entry->id);
                continue;
            }
            if (!$ctx->apply) {
                continue;
            }
            $qtype = \question_bank::get_qtype('multichoice');
            $form = self::form($q, $catid);
            if ($entry) {
                $existing = $DB->get_record('question', ['id' => self::latest_question_id((int) $entry->id)], '*', MUST_EXIST);
                $saved = $qtype->save_question($existing, $form);
            } else {
                $saved = $qtype->save_question((object) ['qtype' => 'multichoice', 'category' => $catid], $form);
                $entry = get_question_bank_entry($saved->id);
            }
            map::put($ctx->course->id, $q['key'], 'question', (int) $entry->id, $hash);
            $ids[$q['key']] = (int) $saved->id;
        }
        return $ids;
    }

    /** Bank entry for a permanent question key, or null. */
    public static function entry_for_key(sync_context $ctx, string $key): ?\stdClass {
        global $DB;
        $catid = self::category_id($ctx);
        return $catid ? ($DB->get_record('question_bank_entries', ['idnumber' => $key, 'questioncategoryid' => $catid]) ?: null) : null;
    }

    /** Hide (never delete) a retired question in the bank; returns true if it changed anything. */
    public static function hide_entry(\stdClass $entry): bool {
        global $DB;
        if ($DB->record_exists_select('question_versions', "questionbankentryid = ? AND status <> ?",
                [$entry->id, question_version_status::QUESTION_STATUS_HIDDEN])) {
            $DB->set_field('question_versions', 'status', question_version_status::QUESTION_STATUS_HIDDEN,
                ['questionbankentryid' => $entry->id]);
            return true;
        }
        return false;
    }

    private static function category_id(sync_context $ctx): ?int {
        if (!$ctx->course) {
            return null;
        }
        $context = \context_course::instance($ctx->course->id);
        if ($ctx->apply) {
            return (int) question_make_default_categories([$context])->id;
        }
        $category = question_get_default_category($context->id);
        return $category ? (int) $category->id : null;
    }

    private static function latest_question_id(int $entryid): int {
        global $DB;
        return (int) $DB->get_field_sql('SELECT questionid FROM {question_versions} WHERE questionbankentryid = ? ORDER BY version DESC',
            [$entryid], IGNORE_MULTIPLE);
    }

    /** The form data a teacher's question editor would submit for a single-answer multiple-choice question. */
    private static function form(array $q, int $categoryid): \stdClass {
        $html = fn(string $text) => ['text' => s($text), 'format' => FORMAT_HTML];
        $empty = ['text' => '', 'format' => FORMAT_HTML];
        $form = (object) [
            'category' => $categoryid,
            'name' => $q['key'],
            'idnumber' => $q['key'],
            'status' => question_version_status::QUESTION_STATUS_READY,
            'questiontext' => $html($q['text']),
            'generalfeedback' => $empty,
            'defaultmark' => 1,
            'penalty' => 0.3333333,
            'single' => 1,
            'shuffleanswers' => $q['shuffle'] ? 1 : 0,
            'answernumbering' => 'abc',
            'showstandardinstruction' => 0,
            'correctfeedback' => $empty,
            'partiallycorrectfeedback' => $empty,
            'incorrectfeedback' => $empty,
            'shownumcorrect' => 0,
            'answer' => [], 'fraction' => [], 'feedback' => [],
        ];
        foreach (['a', 'b', 'c', 'd'] as $letter) {
            $form->answer[] = $html($q['options'][$letter]);
            $form->fraction[] = $letter === $q['answer'] ? 1.0 : 0.0;
            $form->feedback[] = $empty;
        }
        return $form;
    }
}
