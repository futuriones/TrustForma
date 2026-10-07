<?php
// This file is part of the awareness training platform (INCIBE kit -> Moodle as code).

namespace local_awarenesssync;

defined('MOODLE_INTERNAL') || die();

/**
 * State shared by all syncers during one run. In plan mode ($apply = false) nothing is written to Moodle:
 * syncers only call {@see record()}.
 *
 * @package    local_awarenesssync
 */
final class sync_context {
    /** @var bool true = write to Moodle, false = only report what would change */
    public bool $apply;
    public manifest $manifest;
    /** @var \stdClass|null the Moodle course; null while planning a course that does not exist yet */
    public ?\stdClass $course = null;
    /** @var array[] planned/applied actions: ['action','type','key','detail'] */
    public array $actions = [];
    /** @var \stdClass[] section records keyed by manifest section key (apply mode) */
    public array $sections = [];
    public int $due;
    /**
     * Allow changes that re-score existing quiz attempts (add/retire a question, change the pass mark or grading
     * method of a quiz that already has attempts). Off by default: attempts are audit evidence.
     */
    public bool $allowstructure;

    public function __construct(manifest $manifest, bool $apply, bool $allowstructure = false) {
        $this->manifest = $manifest;
        $this->apply = $apply;
        $this->allowstructure = $allowstructure;
        $this->due = $manifest->due_timestamp();
    }

    /** @var int how many of $actions are already in the audit log */
    private int $logged = 0;
    /** @var bool[] item keys whose module was created/updated (and after_save ran) in this run */
    public array $saved = [];

    public function record(string $action, string $type, string $key, string $detail = ''): void {
        $this->actions[] = ['action' => $action, 'type' => $type, 'key' => $key, 'detail' => $detail];
    }

    /**
     * Write the actions recorded since the last call to the audit log. Called after each step has succeeded, so a deploy
     * that fails half-way still leaves a record of what was applied; the step that failed is never logged as applied.
     */
    public function flush_log(): void {
        if (!$this->apply || !$this->course) {
            return;
        }
        for (; $this->logged < count($this->actions); $this->logged++) {
            $this->log_row($this->actions[$this->logged]);
        }
    }

    /** Record that a deploy stopped with an error (what came before it is already logged). */
    public function log_failure(\Throwable $e): void {
        if ($this->apply && $this->course) {
            $this->log_row(['action' => 'failed', 'type' => 'deploy', 'key' => $this->manifest->course()['idnumber'],
                'detail' => \core_text::substr($e->getMessage(), 0, 1000)]);
        }
    }

    /**
     * Mark that this deploy ran to its end, so the content edition it carries is the one fully live in the course
     * ({@see runner::deployed_version()}). Written when the run changed something, and once per edition otherwise (the
     * first deploy after this marker was introduced). It is a log row, not an action: a no-op deploy still reports none.
     */
    public function log_deployed(): void {
        global $DB;
        if (!$this->apply || !$this->course || in_array('blocked', array_column($this->actions, 'action'), true)) {
            return;
        }
        if ($this->actions || !$DB->record_exists('local_awarenesssync_log', ['courseid' => $this->course->id,
                'action' => runner::DEPLOYED, 'contentversion' => $this->manifest->content_version()])) {
            $this->log_row(['action' => runner::DEPLOYED, 'type' => 'deploy', 'key' => $this->manifest->course()['idnumber'],
                'detail' => count($this->actions) . ' change(s) applied']);
        }
    }

    private function log_row(array $a): void {
        global $DB;
        $DB->insert_record('local_awarenesssync_log', (object) [
            'courseid' => $this->course->id, 'contentversion' => $this->manifest->content_version(), 'action' => $a['action'],
            'itemtype' => $a['type'], 'itemkey' => $a['key'], 'detail' => $a['detail'], 'timecreated' => time(),
        ]);
    }

    /**
     * Hash of what an item looks like when applied. Dates are deliberately NOT part of it: deadlines and quiz review options are
     * initial defaults, owned by the Moodle administrator once the activity exists (see {@see module_syncer::admin_owned()}).
     */
    public function applied_hash(array $item): string {
        return util::digest([$item['content_hash']]);
    }

    /** @var int|null default deadline (timestamp) of the section being synced; used only when an activity is created */
    public ?int $sectiondue = null;

    /** Point the context at the section whose items are about to be synced (per-module default deadlines). */
    public function enter_section(array $section): void {
        $this->sectiondue = $this->manifest->section_due_timestamp($section);
    }

    /** Default deadline for a new activity: its module's, or the course's outside a section. Never applied to existing ones. */
    public function item_due(): int {
        return $this->sectiondue ?? $this->due;
    }
}
