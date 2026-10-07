<?php
// This file is part of the awareness training platform (INCIBE kit -> Moodle as code).

namespace local_awarenesssync;

use local_awarenesssync\sync\course_syncer;
use local_awarenesssync\sync\section_syncer;

defined('MOODLE_INTERNAL') || die();

/**
 * Applies (or plans) a manifest: course, sections, items, completion, ordering. Idempotent by construction:
 * every syncer compares the manifest's content hash with the one recorded in the map table.
 *
 * @package    local_awarenesssync
 */
final class runner {
    /** Item type => syncer class, in the order types must be handled inside a section. */
    private const ITEM_SYNCERS = [
        'resource' => sync\resource_syncer::class,
        'page' => sync\page_syncer::class,
        'folder' => sync\folder_syncer::class,
        'quiz' => sync\quiz_syncer::class,
        'certificate' => sync\certificate_syncer::class,
    ];

    /** Map item type of the marker row that freezes a cycle (see {@see close_cycle()}). */
    private const CLOSED = 'closed';

    /** Log action of the marker row written when a deploy has run to its end (see {@see deployed_version()}). */
    public const DEPLOYED = 'deployed';

    /**
     * @param string $manifestpath path to manifest.json
     * @param bool $apply false = plan only
     * @param string[] $only restrict to these item types (plus 'course'/'sections'); empty = everything
     * @return array[] actions taken or planned
     */
    public static function run(string $manifestpath, bool $apply, array $only = [], bool $allowstructure = false): array {
        \core\session\manager::set_user(get_admin());
        $manifest = manifest::load($manifestpath);
        if ($problems = $manifest->verify_files()) {
            throw new \moodle_exception('filesinvalid', 'local_awarenesssync', '', implode("\n  ", array_slice($problems, 0, 10)));
        }
        $idnumber = $manifest->course()['idnumber'];
        // One lock around the plan check and the apply, so nothing (a learner attempt is not covered, but another
        // deploy is) can slip in between what was checked and what is written.
        $lock = \core\lock\lock_config::get_lock_factory('local_awarenesssync')->get_lock('sync_' . md5($idnumber), 30);
        if (!$lock) {
            throw new \moodle_exception('locked', 'local_awarenesssync', '', $idnumber);
        }
        try {
            if ($apply) {
                // Never write anything if part of the manifest cannot be applied safely: plan first, refuse on 'blocked'.
                $blocked = array_filter(self::execute($manifest, false, $only, $allowstructure), fn($a) => $a['action'] === 'blocked');
                if ($blocked) {
                    throw new \moodle_exception('blockedchanges', 'local_awarenesssync', '',
                        implode("\n  ", array_map(fn($a) => "{$a['key']}: {$a['detail']}", $blocked)));
                }
            }
            return self::execute($manifest, $apply, $only, $allowstructure);
        } finally {
            $lock->release();
        }
    }

    /**
     * Freeze a cycle: from now on plans report it as blocked and deploys are refused, with no override. Old cycles are
     * audit evidence (AGENTS.md invariant 4). Nothing else changes: learners can still finish the course.
     *
     * @return bool true when it was open and is closed now, false when it was already closed
     */
    public static function close_cycle(string $idnumber): bool {
        global $DB;
        $course = $DB->get_record('course', ['idnumber' => $idnumber]);
        if (!$course) {
            throw new \moodle_exception('cyclenotfound', 'local_awarenesssync', '', $idnumber);
        }
        if (map::get($course->id, $idnumber . ':' . self::CLOSED)) {
            return false;
        }
        map::put($course->id, $idnumber . ':' . self::CLOSED, self::CLOSED, 0, '');
        $DB->insert_record('local_awarenesssync_log', (object) [
            'courseid' => $course->id, 'contentversion' => self::deployed_version((int) $course->id), 'action' => 'close', 'itemtype' => 'course',
            'itemkey' => $idnumber, 'detail' => 'cycle closed: no further deploys', 'timecreated' => time(),
        ]);
        return true;
    }

    public static function is_closed(string $idnumber): bool {
        global $DB;
        $course = $DB->get_record('course', ['idnumber' => $idnumber], 'id');
        return $course && map::get($course->id, $idnumber . ':' . self::CLOSED) !== null;
    }

    /**
     * Content edition that is fully live in a course: the one of the last deploy that ran to its end (its 'deployed'
     * marker row). A deploy that stopped half-way logs its steps with the new edition, so "the newest log row" would
     * report an edition that is only partly applied. Courses deployed before the marker existed fall back to their
     * newest step that is neither a failure nor the closing of the cycle.
     */
    public static function deployed_version(int $courseid): string {
        global $DB;
        $version = $DB->get_field_sql('SELECT contentversion FROM {local_awarenesssync_log} WHERE courseid = ? AND action = ? ORDER BY id DESC',
            [$courseid, self::DEPLOYED], IGNORE_MULTIPLE);
        if ($version === false) {
            $version = $DB->get_field_sql('SELECT contentversion FROM {local_awarenesssync_log} WHERE courseid = ? AND action NOT IN (?, ?) ORDER BY id DESC',
                [$courseid, 'failed', 'close'], IGNORE_MULTIPLE);
        }
        return (string) $version;
    }

    /** One pass over the manifest. In plan mode nothing is written; in apply mode the audit log is written as steps complete. */
    private static function execute(manifest $manifest, bool $apply, array $only, bool $allowstructure): array {
        $ctx = new sync_context($manifest, $apply, $allowstructure);
        $idnumber = $manifest->course()['idnumber'];
        if (self::is_closed($idnumber)) {
            $ctx->record('blocked', 'course', $idnumber, 'this cycle is closed (make close-cycle): closed cycles are frozen audit evidence. '
                . 'Deploy the change to the next cycle instead');
            return $ctx->actions;
        }
        $want = fn(string $type) => !$only || in_array($type, $only, true);
        try {
            course_syncer::sync($ctx);
            $ctx->flush_log();
            section_syncer::sync($ctx);
            $ctx->flush_log();
            foreach ($manifest->sections() as $section) {
                foreach ($section['items'] as $item) {
                    if ($want($item['type'])) {
                        self::ITEM_SYNCERS[$item['type']]::sync($ctx, $section, $item);
                        sync\module_syncer::sync_restrictions($ctx, $section, $item);
                        $ctx->flush_log();
                    }
                }
            }
            sync\module_syncer::hide_removed($ctx);
            $ctx->flush_log();
            if ($want('completion')) {
                sync\completion_syncer::sync($ctx);
                $ctx->flush_log();
            }
            if ($ctx->apply && $ctx->course) {
                section_syncer::order($ctx);
                rebuild_course_cache($ctx->course->id, true);
                $ctx->flush_log();
                if (!$only) { // A run restricted with --only (debugging) is not a complete deploy.
                    $ctx->log_deployed();
                }
            }
        } catch (\Throwable $e) {
            $ctx->log_failure($e);
            throw $e;
        }
        return $ctx->actions;
    }
}
