<?php
// This file is part of the awareness training platform (INCIBE kit -> Moodle as code).

namespace local_awarenesssync;

defined('MOODLE_INTERNAL') || die();

/**
 * Small shared helpers: canonical hashing and draft-area file staging.
 *
 * @package    local_awarenesssync
 */
final class util {
    /** Recursively sort associative arrays so equal data always serialises identically. */
    public static function canonical($value) {
        if (!is_array($value)) {
            return $value;
        }
        $isList = array_is_list($value);
        $out = array_map([self::class, 'canonical'], $value);
        if (!$isList) {
            ksort($out);
        }
        return $out;
    }

    public static function digest($value): string {
        return hash('sha256', json_encode(self::canonical($value), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /**
     * Stage files in a new draft area of the current user, as the file managers of the UI would.
     *
     * @param array $files list of ['filename' => string, 'path' => absolute path on disk]
     * @return int draft item id
     */
    public static function draft_with_files(array $files): int {
        global $USER;
        $draftid = file_get_unused_draft_itemid();
        $fs = get_file_storage();
        $usercontext = \context_user::instance($USER->id);
        foreach ($files as $file) {
            $fs->create_file_from_pathname([
                'contextid' => $usercontext->id, 'component' => 'user', 'filearea' => 'draft',
                'itemid' => $draftid, 'filepath' => '/', 'filename' => $file['filename'],
            ], $file['path']);
        }
        return $draftid;
    }

    /** An empty draft area, needed for the intro editor field that add/update_moduleinfo expect. */
    public static function empty_draft(): int {
        return file_get_unused_draft_itemid();
    }
}
