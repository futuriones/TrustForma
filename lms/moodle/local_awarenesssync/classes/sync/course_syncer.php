<?php
// This file is part of the awareness training platform (INCIBE kit -> Moodle as code).

namespace local_awarenesssync\sync;

use local_awarenesssync\map;
use local_awarenesssync\sync_context;
use local_awarenesssync\util;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/course/lib.php');
require_once($CFG->dirroot . '/enrol/cohort/locallib.php');

/**
 * Creates/updates the Moodle course for one cycle, its category path and the cohort enrolment.
 *
 * @package    local_awarenesssync
 */
final class course_syncer {
    public static function sync(sync_context $ctx): void {
        global $DB;
        $m = $ctx->manifest->course();
        $course = $DB->get_record('course', ['idnumber' => $m['idnumber']]) ?: null;
        // Only what an update writes is in the hash. Start/end dates and the category are initial defaults (used on create):
        // afterwards the administrator owns them in the course settings (dates) and in the course management page (moving
        // the course to another category). A category edited in course.yaml for an existing cycle therefore changes nothing.
        $hash = util::digest([$m['fullname'], $m['shortname'], $m['summary_html'], $m['lang']]);

        if (!$course) {
            $ctx->record('create', 'course', $m['idnumber'], "{$m['shortname']} in {$m['category']} / {$m['cycle']}");
            if ($ctx->apply) {
                $ctx->course = self::create($ctx);
                map::put($ctx->course->id, $m['idnumber'], 'course', $ctx->course->id, $hash);
            }
        } else {
            $ctx->course = $course;
            $row = map::get($course->id, $m['idnumber']);
            if (!$row || $row->contenthash !== $hash) {
                $ctx->record('update', 'course', $m['idnumber'], 'fullname/summary');
                if ($ctx->apply) {
                    update_course((object) [
                        'id' => $course->id, 'fullname' => $m['fullname'], 'shortname' => $m['shortname'],
                        'summary' => $m['summary_html'], 'summaryformat' => FORMAT_HTML, 'lang' => $m['lang'],
                    ]);
                    $ctx->course = $DB->get_record('course', ['id' => $course->id], '*', MUST_EXIST);
                    map::put($course->id, $m['idnumber'], 'course', $course->id, $hash);
                }
            }
        }
        self::sync_enrolment($ctx);
    }

    private static function create(sync_context $ctx): \stdClass {
        $m = $ctx->manifest->course();
        return create_course((object) [
            'fullname' => $m['fullname'], 'shortname' => $m['shortname'], 'idnumber' => $m['idnumber'],
            'category' => self::ensure_category([$m['category'], (string) $m['cycle']]),
            'summary' => $m['summary_html'], 'summaryformat' => FORMAT_HTML,
            'format' => 'topics', 'lang' => $m['lang'], 'visible' => 1, 'enablecompletion' => 1,
            'startdate' => $ctx->manifest->start_timestamp(), 'enddate' => $ctx->due,
            'showactivitydates' => 1, 'showcompletionconditions' => 1, 'newsitems' => 0, 'showgrades' => 0,
        ]);
    }

    /** @param string[] $names category path, outermost first; created when missing */
    private static function ensure_category(array $names): int {
        global $DB;
        $parent = 0;
        foreach ($names as $name) {
            $existing = $DB->get_record('course_categories', ['name' => $name, 'parent' => $parent]);
            $parent = $existing ? (int) $existing->id : (int) \core_course_category::create(['name' => $name, 'parent' => $parent])->id;
        }
        return $parent;
    }

    /** Enrol everybody in the configured cohort as students; new cohort members are enrolled by cohort sync. */
    private static function sync_enrolment(sync_context $ctx): void {
        global $DB;
        $cohortname = $ctx->manifest->data['enrol']['cohort'];
        $key = $ctx->manifest->course()['idnumber'] . ':enrol';
        $cohort = $DB->get_record('cohort', ['idnumber' => $cohortname]);
        if (!$cohort) {
            throw new \moodle_exception('cohortmissing', 'local_awarenesssync', '', $cohortname);
        }
        if ($ctx->course && ($row = map::get($ctx->course->id, $key)) &&
                $DB->record_exists('enrol', ['id' => $row->instanceid, 'enrol' => 'cohort'])) {
            return;
        }
        $ctx->record('create', 'enrol', $key, "cohort '{$cohortname}' as students");
        if (!$ctx->apply) {
            return;
        }
        $roleid = (int) $DB->get_field('role', 'id', ['shortname' => 'student'], MUST_EXIST);
        $existing = $DB->get_record('enrol', ['courseid' => $ctx->course->id, 'enrol' => 'cohort', 'customint1' => $cohort->id]);
        $instanceid = $existing ? (int) $existing->id
            : (int) enrol_get_plugin('cohort')->add_instance($ctx->course, ['customint1' => $cohort->id, 'roleid' => $roleid]);
        map::put($ctx->course->id, $key, 'enrol', $instanceid, util::digest($cohortname));
        enrol_cohort_sync(new \null_progress_trace(), $ctx->course->id);
    }
}
