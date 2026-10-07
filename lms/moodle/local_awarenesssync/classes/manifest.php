<?php
// This file is part of the awareness training platform (INCIBE kit -> Moodle as code).

namespace local_awarenesssync;

defined('MOODLE_INTERNAL') || die();

/**
 * A build manifest (lms/tools/schema/manifest.schema.json) plus the files next to it.
 *
 * @package    local_awarenesssync
 */
final class manifest {
    /** The only manifest_version this plugin understands. Bump together with lms/tools/build.py. */
    public const SUPPORTED_VERSION = 1;

    /** @var array decoded manifest */
    public array $data;
    /** @var string directory holding manifest.json and files/ */
    public string $dir;

    private function __construct(array $data, string $dir) {
        $this->data = $data;
        $this->dir = $dir;
    }

    /**
     * @param string $path path to manifest.json
     * @throws \moodle_exception when the manifest is missing, malformed or of an unknown version
     */
    public static function load(string $path): self {
        if (!is_file($path)) {
            throw new \moodle_exception('manifestmissing', 'local_awarenesssync', '', $path);
        }
        $data = json_decode(file_get_contents($path), true);
        if (!is_array($data) || !isset($data['manifest_version'], $data['course'], $data['sections'])) {
            throw new \moodle_exception('manifestinvalid', 'local_awarenesssync', '', $path);
        }
        if ($data['manifest_version'] !== self::SUPPORTED_VERSION) {
            throw new \moodle_exception('manifestversion', 'local_awarenesssync', '',
                ['found' => $data['manifest_version'], 'supported' => self::SUPPORTED_VERSION]);
        }
        // Everything the syncers dereference must be there before anything is written, or a malformed manifest fails
        // half-way through an apply.
        foreach (['content_version', 'completion', 'enrol', 'retired_question_keys'] as $required) {
            if (!isset($data[$required])) {
                throw new \moodle_exception('manifestinvalid', 'local_awarenesssync', '', "$path (missing '$required')");
            }
        }
        $course = $data['course'];
        foreach (['id', 'cycle', 'idnumber', 'shortname', 'fullname', 'category', 'lang', 'summary_html', 'start_date', 'due_date'] as $required) {
            if (!isset($course[$required])) {
                throw new \moodle_exception('manifestinvalid', 'local_awarenesssync', '', "$path (missing course.$required)");
            }
        }
        // The idnumber is the identity of the cycle: it must be exactly <course id>:<cycle>, so a manifest cannot
        // target some other course (for instance one created by hand).
        if (!is_string($course['idnumber']) || !preg_match('/^[a-z0-9_-]+:[0-9]{4}$/', $course['idnumber']) ||
                $course['idnumber'] !== $course['id'] . ':' . $course['cycle']) {
            throw new \moodle_exception('manifestinvalid', 'local_awarenesssync', '', "$path (course.idnumber must be <id>:<cycle>)");
        }
        foreach (['require_pdf_view', 'required_item_keys'] as $required) {
            if (!isset($data['completion'][$required])) {
                throw new \moodle_exception('manifestinvalid', 'local_awarenesssync', '', "$path (missing completion.$required)");
            }
        }
        if (!isset($data['enrol']['cohort'])) {
            throw new \moodle_exception('manifestinvalid', 'local_awarenesssync', '', "$path (missing enrol.cohort)");
        }
        return new self($data, dirname($path));
    }

    public function course(): array {
        return $this->data['course'];
    }

    public function sections(): array {
        return $this->data['sections'];
    }

    public function content_version(): string {
        return $this->data['content_version'];
    }

    /**
     * Absolute path of a manifest file, confined to <manifest dir>/files/. The manifest supplies both the path and the
     * checksum, so the checksum cannot protect against a crafted path such as ../../../../etc/passwd.
     *
     * @throws \moodle_exception when the path or file name is unsafe
     */
    public function file_path(array $file): string {
        $rel = (string) ($file['path'] ?? '');
        $name = (string) ($file['filename'] ?? '');
        if ($rel === '' || strpos($rel, 'files/') !== 0 || strpos($rel, "\0") !== false || strpos($rel, '\\') !== false ||
                preg_match('#(^|/)\.\.(/|$)#', $rel)) {
            throw new \moodle_exception('filesinvalid', 'local_awarenesssync', '', "unsafe path: $rel");
        }
        if ($name === '' || clean_param($name, PARAM_FILE) !== $name) {
            throw new \moodle_exception('filesinvalid', 'local_awarenesssync', '', "unsafe file name: $name");
        }
        $full = $this->dir . '/' . $rel;
        $real = realpath($full);
        $base = realpath($this->dir . '/files');
        if ($real !== false && ($base === false || strpos($real, $base . DIRECTORY_SEPARATOR) !== 0)) {
            throw new \moodle_exception('filesinvalid', 'local_awarenesssync', '', "path escapes the build directory: $rel");
        }
        return $full; // A missing file is reported by verify_files().
    }

    /** Every file dict referenced by any item. */
    public function all_files(): \Generator {
        foreach ($this->sections() as $section) {
            foreach ($section['items'] as $item) {
                if (isset($item['file'])) {
                    yield $item['file'];
                }
                foreach ($item['files'] ?? [] as $file) {
                    yield $file;
                }
            }
        }
    }

    /** @return string[] problems: missing files or files whose bytes differ from the recorded sha256 */
    public function verify_files(): array {
        $problems = [];
        foreach ($this->all_files() as $file) {
            try {
                $path = $this->file_path($file);
            } catch (\moodle_exception $e) {
                $problems[] = $e->debuginfo ?: $e->getMessage();
                continue;
            }
            if (!is_file($path)) {
                $problems[] = "missing: {$file['path']}";
            } else if (hash_file('sha256', $path) !== $file['sha256']) {
                $problems[] = "checksum mismatch: {$file['path']}";
            }
        }
        return $problems;
    }

    /** End of the due date (23:59:59) in the server timezone, as a timestamp. */
    public function due_timestamp(): int {
        return $this->day_timestamp($this->course()['due_date'], '23:59:59');
    }

    /** Deadline of one section (module): its own due_date when the course is scheduled per module, else the course's. */
    public function section_due_timestamp(array $section): int {
        return isset($section['due_date']) ? $this->day_timestamp($section['due_date'], '23:59:59') : $this->due_timestamp();
    }

    public function start_timestamp(): int {
        return $this->day_timestamp($this->course()['start_date'], '00:00:00');
    }

    private function day_timestamp(string $date, string $time): int {
        return (new \DateTime("$date $time", \core_date::get_server_timezone_object()))->getTimestamp();
    }
}
