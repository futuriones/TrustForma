<?php
// This file is part of the awareness training platform (INCIBE kit -> Moodle as code).

namespace local_awarenesssync\sync;

use local_awarenesssync\sync_context;
use local_awarenesssync\util;

defined('MOODLE_INTERNAL') || die();

// The module's add/update_instance rely on locallib helpers that the UI form normally loads.
require_once($CFG->dirroot . '/mod/page/locallib.php');

/**
 * An HTML page with embedded images (tips, posters). Image files are stored in the page's own file area and
 * referenced as @@PLUGINFILE@@/name in the HTML.
 *
 * @package    local_awarenesssync
 */
final class page_syncer extends module_syncer {
    protected static function modulename(): string {
        return 'page';
    }

    /** page_add_instance only saves the embedded files when it receives a form object; it never calls it. */
    protected static function mform(): ?\stdClass {
        return new \stdClass();
    }

    protected static function fields(sync_context $ctx, array $item, ?\stdClass $existing): array {
        $files = array_map(fn($f) => ['filename' => $f['filename'], 'path' => $ctx->manifest->file_path($f)], $item['files']);
        return [
            'page' => ['text' => $item['content_html'], 'format' => FORMAT_HTML, 'itemid' => util::draft_with_files($files)],
            'display' => RESOURCELIB_DISPLAY_AUTO,
            'printintro' => 0, 'printlastmodified' => 0,
            'revision' => $existing->revision ?? 1,
        ];
    }
}
