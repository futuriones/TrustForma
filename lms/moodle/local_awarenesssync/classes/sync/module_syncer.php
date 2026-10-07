<?php
// This file is part of the awareness training platform (INCIBE kit -> Moodle as code).

namespace local_awarenesssync\sync;

use local_awarenesssync\map;
use local_awarenesssync\sync_context;
use local_awarenesssync\util;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/course/lib.php');
require_once($CFG->dirroot . '/course/modlib.php');
require_once($CFG->libdir . '/resourcelib.php');
require_once($CFG->libdir . '/completionlib.php');

/**
 * Common create / update / move logic for one activity per manifest item.
 *
 * The item key is stored as the course module idnumber, so reports can tell modules apart by their permanent key
 * without going through the map table.
 *
 * @package    local_awarenesssync
 */
abstract class module_syncer {
    /** @return string Moodle module name, e.g. 'resource' */
    abstract protected static function modulename(): string;

    /**
     * Module-specific fields merged into the moduleinfo passed to add/update_moduleinfo.
     *
     * @param \stdClass|null $existing the current module instance record when updating
     * @return array
     */
    abstract protected static function fields(sync_context $ctx, array $item, ?\stdClass $existing): array;

    /**
     * Settings the Moodle administrator owns once the activity exists: the manifest only supplies their INITIAL value (on create).
     * On update they are read back from the existing activity, so a deploy never overwrites what an admin set in the GUI.
     * Base: "Expect completed on" (the module deadline). Subclasses add more (quiz review options).
     *
     * @param array $fields what fields() computed from the manifest
     * @return array $fields with the admin-owned entries replaced by the current Moodle values
     */
    protected static function admin_owned(array $fields, \stdClass $cm, ?\stdClass $existing): array {
        $fields['completionexpected'] = (int) $cm->completionexpected;
        return $fields;
    }

    /** Hook after a create or update, given the final moduleinfo. */
    protected static function after_save(sync_context $ctx, array $item, \stdClass $cm, array $fields): void {
    }

    /** Object passed as $mform to add_moduleinfo; only mod_page inspects it (for truthiness). */
    protected static function mform(): ?\stdClass {
        return null;
    }

    /** Item keys that must be completed before this activity becomes available. */
    protected static function requires(array $item): array {
        return $item['requires'] ?? [];
    }

    /** What "up to date" means for an item; subclasses add inputs that live outside the manifest (e.g. a design file). */
    protected static function applied_hash(sync_context $ctx, array $item): string {
        return $ctx->applied_hash($item);
    }

    /**
     * The completion RULE this item must have: [completion, completionview, completionpassgrade]. Must agree with what
     * fields() puts in the moduleinfo; used to detect (in plan mode too) a rule change that would wipe learner state.
     */
    protected static function completion_rule(array $item): array {
        return [COMPLETION_TRACKING_NONE, COMPLETION_VIEW_NOT_REQUIRED, 0];
    }

    /** Section record for a manifest section; in plan mode (no $ctx->sections) it is looked up through the map. */
    private static function section_record(sync_context $ctx, array $section): ?\stdClass {
        global $DB;
        if (isset($ctx->sections[$section['key']])) {
            return $ctx->sections[$section['key']];
        }
        $row = $ctx->course ? map::get($ctx->course->id, $section['key']) : null;
        return $row ? ($DB->get_record('course_sections', ['id' => $row->instanceid]) ?: null) : null;
    }

    public static function sync(sync_context $ctx, array $section, array $item): void {
        global $DB;
        $key = $item['key'];
        $ctx->enter_section($section); // Default deadline of this module, used only when the activity is created.
        $hash = static::applied_hash($ctx, $item);
        $sectionrec = self::section_record($ctx, $section);
        $row = $ctx->course ? map::get($ctx->course->id, $key) : null;
        $cm = $row ? self::find_cm((int) $row->instanceid, static::modulename()) : null;

        if (!$cm) {
            $ctx->record('create', $item['type'], $key, $item['name']);
        } else if ($row->contenthash !== $hash) {
            if (self::completion_rule_would_change($cm, static::completion_rule($item)) && !$ctx->allowstructure &&
                    $DB->record_exists('course_modules_completion', ['coursemoduleid' => $cm->id])) {
                // Moodle wipes every learner's completion state for the activity when its rule changes.
                $ctx->record('blocked', $item['type'], $key, 'changes the completion rule of an activity learners have already used; '
                    . 'their "viewed"/"passed" state would be wiped. Start a new cycle instead, or pass --allow-structure-change');
                return;
            }
            $ctx->record('update', $item['type'], $key, $item['name']);
        } else if ($sectionrec && (int) $cm->section !== (int) $sectionrec->id) {
            $ctx->record('update', $item['type'], $key, 'moved to another section');
            if ($ctx->apply) {
                moveto_module($cm, $sectionrec);
            }
            return;
        } else if ((int) $cm->visible !== (int) $section['visible']) {
            // Hidden earlier (e.g. the item left the manifest and came back): content is unchanged but it must be shown again.
            // The wanted state comes from the manifest, not from the section record, which may still be hidden while planning.
            $ctx->record('update', $item['type'], $key, $section['visible'] ? 'shown again' : 'hidden with its section');
            if ($ctx->apply) {
                set_coursemodule_visible($cm->id, (int) $section['visible']);
            }
            return;
        } else {
            return;
        }
        if (!$ctx->apply) {
            return;
        }

        // All-or-nothing per item: a failure after add_moduleinfo() (question save, file save…) must not leave a
        // half-built activity behind for the next deploy to duplicate.
        $transaction = $DB->start_delegated_transaction();
        try {
            $existing = $cm ? $DB->get_record(static::modulename(), ['id' => $cm->instance]) : null;
            $fields = static::fields($ctx, $item, $existing ?: null);
            if ($cm) {
                $fields = static::admin_owned($fields, $cm, $existing ?: null);
            }
            $info = self::moduleinfo($ctx, $item, $sectionrec, $fields);
            if ($cm) {
                // The event fired by update_moduleinfo needs the 'modname' that only this loader adds.
                $cm = get_coursemodule_from_id(static::modulename(), $cm->id, $ctx->course->id, false, MUST_EXIST);
                $info->coursemodule = $cm->id;
                $info->instance = $cm->instance;
                if (self::completion_settings_changed($cm, $info)) {
                    // Moodle then wipes and recalculates every learner's completion state for this activity.
                    // It is unavoidable when the rule itself changes, and must never happen for a plain content update.
                    $info->completionunlocked = 1;
                    $ctx->record('update', $item['type'], $key, 'completion rule changed: learner state for this activity is recalculated');
                }
                update_moduleinfo($cm, $info, $ctx->course);
                $cmid = (int) $cm->id;
                if ((int) $cm->section !== (int) $sectionrec->id) {
                    moveto_module(get_coursemodule_from_id(null, $cmid, $ctx->course->id, false, MUST_EXIST), $sectionrec);
                }
            } else {
                $result = add_moduleinfo($info, $ctx->course, static::mform());
                $cmid = (int) $result->coursemodule;
                // Remember the new activity at once with an empty hash: if anything below fails without a rollback taking
                // it away, the next run finds it and updates it instead of creating a second one.
                map::put($ctx->course->id, $key, $item['type'], $cmid, '');
            }
            static::after_save($ctx, $item, get_coursemodule_from_id(null, $cmid, $ctx->course->id, false, MUST_EXIST), $fields);
            map::put($ctx->course->id, $key, $item['type'], $cmid, $hash);
            $ctx->saved[$key] = true;
            $transaction->allow_commit();
        } catch (\Throwable $e) {
            $transaction->rollback($e);
        }
    }

    /** Whether moving an existing activity to this rule differs from what it has now. */
    private static function completion_rule_would_change(\stdClass $cm, array $rule): bool {
        return (int) $cm->completion !== (int) $rule[0] || (int) $cm->completionview !== (int) $rule[1]
            || (int) $cm->completionpassgrade !== (int) $rule[2];
    }

    /**
     * Keep the "must be completed first" restriction of an activity pointing at the CURRENT course modules of the
     * items it requires. Separate from the content hash on purpose: a prerequisite that was recreated (new cmid) must
     * re-point the restriction without re-uploading or re-saving the activity.
     */
    public static function sync_restrictions(sync_context $ctx, array $section, array $item): void {
        global $DB;
        if (!$ctx->course || empty($item['requires'])) {
            return;
        }
        $row = map::get($ctx->course->id, $item['key']);
        $cm = $row ? $DB->get_record('course_modules', ['id' => $row->instanceid]) : null;
        if (!$cm) {
            return; // Not created yet (plan mode): it will be created with its restriction.
        }
        $want = self::availability_json($ctx, $item['requires']);
        if (json_decode($want, true) == json_decode((string) $cm->availability, true)) {
            return;
        }
        $ctx->record('update', $item['type'], $item['key'], 'restriction re-pointed to the current required activities');
        if ($ctx->apply) {
            $DB->set_field('course_modules', 'availability', $want === '' ? null : $want, ['id' => $cm->id]);
            \course_modinfo::purge_course_module_cache($ctx->course->id, $cm->id);
        }
    }

    /** Hide (never delete) activities that used to be in the manifest and are not any more. */
    public static function hide_removed(sync_context $ctx): void {
        global $DB;
        if (!$ctx->course) {
            return;
        }
        $wanted = [];
        foreach ($ctx->manifest->sections() as $section) {
            foreach ($section['items'] as $item) {
                $wanted[$item['key']] = true;
            }
        }
        foreach (map::for_course($ctx->course->id) as $row) {
            if (!in_array($row->itemtype, ['resource', 'page', 'folder', 'quiz', 'certificate'], true) || isset($wanted[$row->itemkey])) {
                continue;
            }
            $cm = $DB->get_record('course_modules', ['id' => $row->instanceid]);
            if ($cm && $cm->visible) {
                $ctx->record('hide', $row->itemtype, $row->itemkey, 'no longer in the manifest');
                if ($ctx->apply) {
                    set_coursemodule_visible($cm->id, 0);
                }
            }
        }
    }

    private static function find_cm(int $cmid, string $modname): ?\stdClass {
        global $DB;
        $cm = $DB->get_record_sql(
            'SELECT cm.* FROM {course_modules} cm JOIN {modules} m ON m.id = cm.module WHERE cm.id = ? AND m.name = ? AND cm.deletioninprogress = 0',
            [$cmid, $modname]);
        return $cm ?: null;
    }

    private static function moduleinfo(sync_context $ctx, array $item, \stdClass $section, array $fields): \stdClass {
        global $DB;
        $requires = self::availability_json($ctx, static::requires($item));
        return (object) array_merge([
            'modulename' => static::modulename(),
            'module' => (int) $DB->get_field('modules', 'id', ['name' => static::modulename()], MUST_EXIST),
            'course' => $ctx->course->id,
            'section' => (int) $section->section,
            'visible' => (int) $section->visible,
            'visibleoncoursepage' => 1,
            'name' => $item['name'],
            'introeditor' => ['text' => '', 'format' => FORMAT_HTML, 'itemid' => util::empty_draft()],
            'showdescription' => 0,
            'cmidnumber' => $item['key'],
            'groupmode' => 0,
            'groupingid' => 0,
            'completion' => COMPLETION_TRACKING_NONE,
            'completionview' => COMPLETION_VIEW_NOT_REQUIRED,
            'completionexpected' => 0,
            'availabilityconditionsjson' => $requires,
        ], $fields);
    }

    /**
     * Whether an update would change the completion RULE of an existing activity. update_moduleinfo() ignores rule
     * changes unless 'completionunlocked' is set, and then resets all users' state; the due date
     * ('completionexpected') is always applied and never resets anything.
     */
    private static function completion_settings_changed(\stdClass $cm, \stdClass $info): bool {
        return (int) $cm->completion !== (int) $info->completion
            || (int) $cm->completionview !== (int) $info->completionview
            || (int) $cm->completionpassgrade !== (int) ($info->completionpassgrade ?? 0);
    }

    /** "Must be marked complete" restrictions for the given item keys (shown greyed out until met). */
    private static function availability_json(sync_context $ctx, array $requiredkeys): string {
        $conditions = [];
        foreach ($requiredkeys as $key) {
            if ($row = map::get($ctx->course->id, $key)) {
                $conditions[] = ['type' => 'completion', 'cm' => (int) $row->instanceid, 'e' => COMPLETION_COMPLETE];
            } else if ($ctx->apply) {
                // Dropping it silently would publish an unrestricted quiz (or certificate) that nothing ever repairs.
                throw new \moodle_exception('requiresmissing', 'local_awarenesssync', '', $key);
            }
        }
        if (!$conditions) {
            return '';
        }
        return json_encode(['op' => '&', 'c' => $conditions, 'showc' => array_fill(0, count($conditions), true)]);
    }
}
