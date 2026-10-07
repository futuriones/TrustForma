<?php
// This file is part of the awareness training platform (INCIBE kit -> Moodle as code).

namespace local_awarenesssync\sync;

use local_awarenesssync\sync_context;
use local_awarenesssync\util;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/mod/folder/locallib.php');

/**
 * A folder of downloadable files (the leaflets).
 *
 * @package    local_awarenesssync
 */
final class folder_syncer extends module_syncer {
    protected static function modulename(): string {
        return 'folder';
    }

    protected static function fields(sync_context $ctx, array $item, ?\stdClass $existing): array {
        $files = array_map(fn($f) => ['filename' => $f['filename'], 'path' => $ctx->manifest->file_path($f)], $item['files']);
        return [
            'files' => util::draft_with_files($files),
            'display' => 0, 'showexpanded' => 1, 'showdownloadfolder' => 1, 'forcedownload' => 1,
            'revision' => $existing->revision ?? 1,
        ];
    }

    /**
     * folder_update_instance() takes the draft id from the submitted request, which does not exist in a CLI run,
     * so on update the files would silently stay old. Saving the draft area explicitly is idempotent for creates too.
     */
    protected static function after_save(sync_context $ctx, array $item, \stdClass $cm, array $fields): void {
        file_save_draft_area_files($fields['files'], \context_module::instance($cm->id)->id, 'mod_folder', 'content', 0,
            ['subdirs' => true]);
    }
}
