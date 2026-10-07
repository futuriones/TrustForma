<?php
// This file is part of the awareness training platform (INCIBE kit -> Moodle as code).

namespace local_awarenesssync;

defined('MOODLE_INTERNAL') || die();

/**
 * Persistent map from stable manifest keys to Moodle objects, plus the content hash last applied.
 *
 * @package    local_awarenesssync
 */
final class map {
    private const TABLE = 'local_awarenesssync_map';

    public static function get(int $courseid, string $key): ?\stdClass {
        global $DB;
        return $DB->get_record(self::TABLE, ['courseid' => $courseid, 'itemkey' => $key]) ?: null;
    }

    public static function put(int $courseid, string $key, string $type, int $instanceid, string $hash): void {
        global $DB;
        $now = time();
        if ($row = self::get($courseid, $key)) {
            $row->itemtype = $type;
            $row->instanceid = $instanceid;
            $row->contenthash = $hash;
            $row->timemodified = $now;
            $DB->update_record(self::TABLE, $row);
            return;
        }
        $DB->insert_record(self::TABLE, (object) [
            'courseid' => $courseid, 'itemkey' => $key, 'itemtype' => $type, 'instanceid' => $instanceid,
            'contenthash' => $hash, 'timecreated' => $now, 'timemodified' => $now,
        ]);
    }

    /** @return \stdClass[] all rows of a course indexed by key */
    public static function for_course(int $courseid): array {
        global $DB;
        return $DB->get_records(self::TABLE, ['courseid' => $courseid], '', 'itemkey, id, courseid, itemtype, instanceid, contenthash');
    }
}
