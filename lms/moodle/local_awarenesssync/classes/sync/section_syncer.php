<?php
// This file is part of the awareness training platform (INCIBE kit -> Moodle as code).

namespace local_awarenesssync\sync;

use local_awarenesssync\map;
use local_awarenesssync\sync_context;
use local_awarenesssync\util;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/course/lib.php');

/**
 * Creates/updates the course sections of a manifest and keeps them in manifest order.
 *
 * Identity is the manifest key (via the map table), not the section number, so inserting a module in the middle
 * of the manifest never repurposes an existing section. Sections that leave the manifest are hidden, never deleted.
 *
 * @package    local_awarenesssync
 */
final class section_syncer {
    public static function sync(sync_context $ctx): void {
        global $DB;
        $sections = $ctx->manifest->sections();
        if ($ctx->apply) {
            course_create_sections_if_missing($ctx->course, range(0, count($sections) - 1));
        }
        $claimed = [];
        foreach ($sections as $index => $s) {
            $hash = util::digest([$s['title'], $s['summary_html'], $s['visible']]);
            $row = $ctx->course ? map::get($ctx->course->id, $s['key']) : null;
            $record = $row ? $DB->get_record('course_sections', ['id' => $row->instanceid]) : null;
            if ($record) {
                $claimed[$record->id] = true;
                if ($row->contenthash !== $hash) {
                    $ctx->record('update', 'section', $s['key'], $s['title']);
                } else if ((bool) $record->visible !== $s['visible']) {
                    // Hidden earlier (it left the manifest and came back): the content is unchanged but visibility must follow.
                    $ctx->record('update', 'section', $s['key'], $s['visible'] ? 'shown again' : 'hidden');
                }
            } else {
                $ctx->record('create', 'section', $s['key'], $s['title']);
            }
            if (!$ctx->apply) {
                continue;
            }
            if (!$record) {
                $record = self::claim_or_create($ctx, $index, $claimed);
                $claimed[$record->id] = true;
            }
            if (!$row || $row->contenthash !== $hash) {
                course_update_section($ctx->course, $record, ['name' => $s['title'], 'summary' => $s['summary_html'], 'summaryformat' => FORMAT_HTML]);
                if ((bool) $record->visible !== $s['visible']) {
                    set_section_visible($ctx->course->id, $record->section, $s['visible'] ? 1 : 0);
                }
                map::put($ctx->course->id, $s['key'], 'section', $record->id, $hash);
            } else if ((bool) $record->visible !== $s['visible']) {
                set_section_visible($ctx->course->id, $record->section, $s['visible'] ? 1 : 0);
            }
            $ctx->sections[$s['key']] = $DB->get_record('course_sections', ['id' => $record->id], '*', MUST_EXIST);
        }
        self::hide_orphans($ctx, $claimed);
    }

    /** Take the unmapped, empty section at $index if there is one, otherwise append a new section. */
    private static function claim_or_create(sync_context $ctx, int $index, array $claimed): \stdClass {
        global $DB;
        $mapped = array_column(array_filter(map::for_course($ctx->course->id), fn($r) => $r->itemtype === 'section'), 'instanceid');
        $candidate = $DB->get_record('course_sections', ['course' => $ctx->course->id, 'section' => $index]);
        if ($candidate && !in_array($candidate->id, $mapped) && !isset($claimed[$candidate->id]) && $candidate->sequence === '' &&
                $candidate->name === null) {
            return $candidate;
        }
        return course_create_section($ctx->course);
    }

    /** Put sections in manifest order once everything exists; run after items so numbers are final. */
    public static function order(sync_context $ctx): void {
        global $DB;
        if (!$ctx->apply) {
            return;
        }
        foreach ($ctx->manifest->sections() as $index => $s) {
            $record = $DB->get_record('course_sections', ['id' => $ctx->sections[$s['key']]->id], '*', MUST_EXIST);
            if ((int) $record->section !== $index) {
                move_section_to($ctx->course, $record->section, $index, true);
                $ctx->record('update', 'section', $s['key'], "moved to position {$index}");
            }
        }
    }

    private static function hide_orphans(sync_context $ctx, array $claimed): void {
        global $DB;
        if (!$ctx->course) {
            return;
        }
        foreach (map::for_course($ctx->course->id) as $row) {
            if ($row->itemtype !== 'section' || isset($claimed[$row->instanceid])) {
                continue;
            }
            $record = $DB->get_record('course_sections', ['id' => $row->instanceid]);
            if ($record && $record->visible) {
                $ctx->record('hide', 'section', $row->itemkey, 'no longer in the manifest');
                if ($ctx->apply) {
                    set_section_visible($ctx->course->id, $record->section, 0);
                }
            }
        }
    }
}
