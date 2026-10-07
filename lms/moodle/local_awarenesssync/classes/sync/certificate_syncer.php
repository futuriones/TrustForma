<?php
// This file is part of TrustForma, a Futurion Solutions S.L. product (INCIBE kit -> Moodle as code).

namespace local_awarenesssync\sync;

use local_awarenesssync\map;
use local_awarenesssync\sync_context;
use local_awarenesssync\util;

defined('MOODLE_INTERNAL') || die();

/**
 * The completion certificate (mod_customcert): restricted until every quiz is passed, issued automatically by cron,
 * verifiable by its code. The visual design lives in certtemplates/<name>.json and is applied to the activity's template.
 *
 * @package    local_awarenesssync
 */
final class certificate_syncer extends module_syncer {
    /** Bump when the settings in fields() change, so existing certificates are re-applied on the next deploy. */
    private const BEHAVIOUR = 3;

    protected static function modulename(): string {
        return 'customcert';
    }

    /**
     * mod_customcert draws a certificate from the CURRENT template every time it is downloaded or verified, so
     * re-applying the design also changes the look of certificates that were already issued. The deploy still goes
     * ahead (codes and dates are untouched), but the plan and the audit log say how many are affected.
     */
    public static function sync(sync_context $ctx, array $section, array $item): void {
        global $DB;
        $row = $ctx->course ? map::get($ctx->course->id, $item['key']) : null;
        if ($row && $row->contenthash !== '' && $row->contenthash !== static::applied_hash($ctx, $item)) {
            $issued = $DB->count_records_sql(
                'SELECT COUNT(ci.id) FROM {customcert_issues} ci JOIN {course_modules} cm ON cm.instance = ci.customcertid WHERE cm.id = ?',
                [$row->instanceid]);
            if ($issued) {
                $ctx->record('warning', $item['type'], $item['key'],
                    "$issued certificate(s) already issued are drawn from the current design: they will show the re-applied one");
            }
        }
        parent::sync($ctx, $section, $item);
    }

    /**
     * 'emailstudents' is required for automatic issuing, not just a nicety: mod_customcert's issue task only selects
     * certificates that have emailstudents, emailteachers or emailothers set, and ignores 'issueautomatically' on its
     * own. It also means every employee receives their certificate by email as soon as they qualify.
     */
    protected static function fields(sync_context $ctx, array $item, ?\stdClass $existing): array {
        return [
            'requiredtime' => 0, 'verifyany' => 1, 'deliveryoption' => \mod_customcert\certificate::DELIVERY_OPTION_DOWNLOAD,
            'usecustomfilename' => 0, 'customfilenamepattern' => '', 'emailstudents' => 1, 'emailteachers' => 0,
            'emailothers' => '', 'issueautomatically' => 1, 'language' => '',
            'completion' => COMPLETION_TRACKING_NONE,
        ];
    }

    protected static function applied_hash(sync_context $ctx, array $item): string {
        $design = self::design($item['template']);
        $images = [];
        foreach ($design['elements'] as $element) {
            if (isset($element['file'])) {
                $images[$element['file']] = \file_storage::hash_from_path(self::image_path($element['file']));
            }
        }
        return util::digest([$ctx->applied_hash($item), $design, $images, self::BEHAVIOUR]);
    }

    protected static function after_save(sync_context $ctx, array $item, \stdClass $cm, array $fields): void {
        global $DB;
        $design = self::design($item['template']);
        $templateid = (int) $DB->get_field('customcert', 'templateid', ['id' => $cm->instance], MUST_EXIST);
        $page = $DB->get_record('customcert_pages', ['templateid' => $templateid], '*', IGNORE_MULTIPLE)
            ?: (object) ['id' => (new \mod_customcert\template($DB->get_record('customcert_templates', ['id' => $templateid], '*', MUST_EXIST)))->add_page()];
        $now = time();
        $DB->update_record('customcert_pages', (object) ($design['page'] + ['id' => $page->id, 'timemodified' => $now]));
        $DB->delete_records('customcert_elements', ['pageid' => $page->id]);
        foreach ($design['elements'] as $sequence => $element) {
            if (isset($element['file'])) {
                $element = self::image_element($element);
            }
            $DB->insert_record('customcert_elements', (object) ($element + [
                'pageid' => $page->id, 'sequence' => $sequence + 1, 'timecreated' => $now, 'timemodified' => $now,
            ]));
        }
    }

    /**
     * An "image" element in the design names a file of this plugin ("file": "branding/logo.png", "imagewidth"/"imageheight" in mm).
     * The file goes into the site's customcert image area (once; replaced only when its content changes) and the element's data
     * points at it the way mod_customcert's own form does.
     */
    private static function image_element(array $element): array {
        $path = self::image_path($element['file']);
        $fs = get_file_storage();
        $syscontext = \context_system::instance();
        $name = basename($path);
        $record = ['contextid' => $syscontext->id, 'component' => 'mod_customcert', 'filearea' => 'image',
            'itemid' => 0, 'filepath' => '/', 'filename' => $name];
        $stored = $fs->get_file($syscontext->id, 'mod_customcert', 'image', 0, '/', $name);
        if (!$stored || $stored->get_contenthash() !== \file_storage::hash_from_path($path)) {
            if ($stored) {
                $stored->delete();
            }
            $fs->create_file_from_pathname($record, $path);
        }
        $element['data'] = json_encode([
            'width' => (int) ($element['imagewidth'] ?? 0), 'height' => (int) ($element['imageheight'] ?? 0),
            'contextid' => $syscontext->id, 'filearea' => 'image', 'itemid' => 0, 'filepath' => '/', 'filename' => $name,
        ]);
        unset($element['file'], $element['imagewidth'], $element['imageheight']);
        return $element;
    }

    /** @return string absolute path of a design image; it must be a file inside this plugin (no "..", no other folder) */
    private static function image_path(string $relative): string {
        $root = realpath(__DIR__ . '/../..');
        $path = realpath($root . '/' . $relative);
        if ($path === false || strpos($path, $root . DIRECTORY_SEPARATOR) !== 0 || !is_file($path)) {
            throw new \moodle_exception('certdesignmissing', 'local_awarenesssync', '', $relative);
        }
        return $path;
    }

    /** @return array decoded design file; the file name comes from the manifest so it is restricted to a safe charset */
    private static function design(string $name): array {
        $path = __DIR__ . '/../../certtemplates/' . clean_param($name, PARAM_ALPHANUMEXT) . '.json';
        $design = is_file($path) ? json_decode(file_get_contents($path), true) : null;
        if (!is_array($design) || !isset($design['page'], $design['elements'])) {
            throw new \moodle_exception('certdesignmissing', 'local_awarenesssync', '', $name);
        }
        unset($design['_comment']);
        return $design;
    }
}
