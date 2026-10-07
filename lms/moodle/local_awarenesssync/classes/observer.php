<?php
// This file is part of the awareness training platform (INCIBE kit -> Moodle as code).

namespace local_awarenesssync;

defined('MOODLE_INTERNAL') || die();

/**
 * Event observers.
 *
 * @package    local_awarenesssync
 */
final class observer {
    /**
     * A deleted course leaves nothing behind in the map (it holds ids that no longer exist). The audit log is kept:
     * it is the record of what was changed and when, and outlives the course it describes.
     */
    public static function course_deleted(\core\event\course_deleted $event): void {
        global $DB;
        $DB->delete_records('local_awarenesssync_map', ['courseid' => $event->objectid]);
    }
}
