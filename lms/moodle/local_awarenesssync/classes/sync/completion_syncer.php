<?php
// This file is part of the awareness training platform (INCIBE kit -> Moodle as code).

namespace local_awarenesssync\sync;

use local_awarenesssync\map;
use local_awarenesssync\sync_context;
use local_awarenesssync\util;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/completionlib.php');
require_once($CFG->dirroot . '/completion/criteria/completion_criteria_activity.php');

/**
 * Course completion = every required quiz passed.
 *
 * Criteria are changed incrementally. completion_info::clear_criteria() is deliberately NOT used: it also deletes
 * every learner's course-completion data, which would erase audit evidence whenever a module is added mid-cycle.
 *
 * @package    local_awarenesssync
 */
final class completion_syncer {
    public static function sync(sync_context $ctx): void {
        global $DB;
        $key = $ctx->manifest->course()['idnumber'] . ':completion';
        $required = $ctx->manifest->data['completion']['required_item_keys'];
        $detail = count($required) . ' quizzes must be passed';

        if (!$ctx->course) {
            $ctx->record('create', 'completion', $key, $detail);
            return;
        }
        $cmids = [];
        foreach ($required as $itemkey) {
            $row = $ctx->course ? map::get($ctx->course->id, $itemkey) : null;
            if (!$row) {
                // Plan mode before the quizzes exist: the criteria will need to be set.
                $ctx->record('update', 'completion', $key, $detail);
                return;
            }
            $cmids[] = (int) $row->instanceid;
        }
        sort($cmids);
        $hash = util::digest($cmids);
        $row = map::get($ctx->course->id, $key);
        if ($row && $row->contenthash === $hash) {
            return;
        }
        $existing = $DB->get_records('course_completion_criteria',
            ['course' => $ctx->course->id, 'criteriatype' => COMPLETION_CRITERIA_TYPE_ACTIVITY], '', 'id, moduleinstance');
        $have = array_column($existing, 'id', 'moduleinstance');
        $gone = array_diff(array_keys($have), $cmids);
        foreach ($gone as $cmid) {
            // Learners' records of having met a criterion are audit evidence (invariant 2b).
            if (!$ctx->allowstructure && $DB->record_exists('course_completion_crit_compl', ['criteriaid' => $have[$cmid]])) {
                $ctx->record('blocked', 'completion', $key, "removes a required quiz (cm $cmid) that learners have already completed; "
                    . 'their completion record would be erased. Start a new cycle instead, or pass --allow-structure-change');
                return;
            }
        }
        $ctx->record('update', 'completion', $key, $detail);
        if (!$ctx->apply) {
            return;
        }

        if ($add = array_diff($cmids, array_keys($have))) {
            $data = (object) ['id' => $ctx->course->id, 'criteria_activity' => array_fill_keys($add, 1)];
            (new \completion_criteria_activity())->update_config($data); // Takes $data by reference.
        }
        foreach ($gone as $cmid) {
            // Only the criterion goes; the learners' completion rows are kept as history even under --allow-structure-change.
            $DB->delete_records('course_completion_criteria', ['id' => $have[$cmid]]);
        }
        self::ensure_aggregation($ctx->course->id);
        // Learners who have not finished must be re-evaluated against the new criteria; finished ones keep their completion.
        $DB->set_field_select('course_completions', 'reaggregate', time(), 'course = ? AND timecompleted IS NULL', [$ctx->course->id]);
        map::put($ctx->course->id, $key, 'completion', 0, $hash);
    }

    /** "All criteria must be met" for the course and for the activity criteria group. */
    private static function ensure_aggregation(int $courseid): void {
        foreach ([null, COMPLETION_CRITERIA_TYPE_ACTIVITY] as $type) {
            $agg = new \completion_aggregation(['course' => $courseid, 'criteriatype' => $type]);
            $agg->setMethod(COMPLETION_AGGREGATION_ALL);
            $agg->save();
        }
    }
}
