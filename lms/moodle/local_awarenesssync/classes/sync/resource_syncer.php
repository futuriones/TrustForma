<?php
// This file is part of the awareness training platform (INCIBE kit -> Moodle as code).

namespace local_awarenesssync\sync;

use local_awarenesssync\sync_context;
use local_awarenesssync\util;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/mod/resource/locallib.php');

/**
 * A single downloadable/viewable file (mod_resource). 'view' completion means "viewed", which unlocks the quiz.
 *
 * @package    local_awarenesssync
 */
final class resource_syncer extends module_syncer {
    protected static function modulename(): string {
        return 'resource';
    }

    protected static function completion_rule(array $item): array {
        return $item['completion'] === 'view'
            ? [COMPLETION_TRACKING_AUTOMATIC, COMPLETION_VIEW_REQUIRED, 0]
            : [COMPLETION_TRACKING_NONE, COMPLETION_VIEW_NOT_REQUIRED, 0];
    }

    protected static function fields(sync_context $ctx, array $item, ?\stdClass $existing): array {
        $file = $item['file'];
        $viewcompletion = $item['completion'] === 'view';
        return [
            'files' => util::draft_with_files([['filename' => $file['filename'], 'path' => $ctx->manifest->file_path($file)]]),
            'display' => RESOURCELIB_DISPLAY_AUTO,
            'showsize' => 1, 'showtype' => 1, 'showdate' => 0, 'printintro' => 0, 'filterfiles' => 0,
            'revision' => $existing->revision ?? 1,
            'completion' => $viewcompletion ? COMPLETION_TRACKING_AUTOMATIC : COMPLETION_TRACKING_NONE,
            'completionview' => $viewcompletion ? COMPLETION_VIEW_REQUIRED : COMPLETION_VIEW_NOT_REQUIRED,
            // No date on the PDF: the module deadline lives on its quiz only, so the administrator has ONE date per module to manage.
            'completionexpected' => 0,
        ];
    }
}
