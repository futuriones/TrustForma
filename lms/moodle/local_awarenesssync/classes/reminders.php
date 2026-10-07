<?php
// This file is part of the awareness training platform (INCIBE kit -> Moodle as code).

namespace local_awarenesssync;

defined('MOODLE_INTERNAL') || die();

/**
 * Deadline reminders. The deadline of a module is the native "Expect completed on" date of its quiz, so whatever an
 * administrator sets in Moodle is what learners are reminded about; nothing is stored here (stateless: the caller passes
 * the time window, normally "since the previous run").
 *
 * @package    local_awarenesssync
 */
final class reminders {
    /**
     * Find (and optionally send) the reminders whose moment falls in the window ($from, $to].
     *
     * A "before N days" reminder is due at (deadline - N days); an "overdue" notice at the deadline itself. Only active,
     * non-suspended learners who have not passed that module's quiz are addressed; closed cycles are skipped.
     *
     * @param int[] $days days before the deadline, e.g. [7, 1]
     * @param string|null $onlyidnumber restrict to one course (tests); null = every open awareness course
     * @return array[] ['userid','cmid','course','kind' => 'before'|'overdue','days','due']
     */
    public static function run(int $from, int $to, array $days, bool $overdue, bool $send = true, ?string $onlyidnumber = null): array {
        global $DB;
        $found = [];
        // One 'course' map row per managed course, so no DISTINCT (which some databases refuse on the text columns of c.*).
        $courses = $DB->get_records_sql(
            "SELECT c.* FROM {course} c JOIN {local_awarenesssync_map} m ON m.courseid = c.id AND m.itemtype = 'course'"
            . ($onlyidnumber === null ? '' : ' WHERE c.idnumber = ?'), $onlyidnumber === null ? [] : [$onlyidnumber]);
        foreach ($courses as $course) {
            if (runner::is_closed($course->idnumber)) {
                continue;
            }
            $context = \context_course::instance($course->id);
            $learners = null; // Loaded lazily: most courses have nothing due in the window.
            foreach (get_fast_modinfo($course)->get_instances_of('quiz') as $cm) {
                if (!preg_match('/:M\d{2}:quiz$/', (string) $cm->idnumber) || !$cm->visible || !(int) $cm->completionexpected) {
                    continue;
                }
                $due = (int) $cm->completionexpected;
                $events = [];
                // "Due in N days" only makes sense while the deadline is still ahead. After a long gap between runs (cron
                // was down) several moments fall in one window: the passed ones are dropped and only the nearest is sent.
                $nearest = null;
                foreach ($days as $n) {
                    $at = $due - $n * DAYSECS;
                    if ($n > 0 && $at > $from && $at <= $to && $due > $to && ($nearest === null || $n < $nearest)) {
                        $nearest = (int) $n;
                    }
                }
                if ($nearest !== null) {
                    $events[] = ['before', $nearest];
                }
                if ($overdue && $due > $from && $due <= $to) {
                    $events[] = ['overdue', 0];
                }
                if (!$events) {
                    continue;
                }
                // Only what the selection and the message text need: not every learner's whole account row (password hash
                // included). message_send() loads the full record itself, and only for the people who do get a message.
                $learners ??= get_enrolled_users($context, 'mod/quiz:attempt', 0, 'u.id, u.deleted, u.suspended, u.lang, u.timezone', 'u.id', 0, 0, true);
                $passed = $DB->get_records_select('course_modules_completion', 'coursemoduleid = ? AND completionstate IN (?, ?)',
                    [$cm->id, COMPLETION_COMPLETE, COMPLETION_COMPLETE_PASS], '', 'userid');
                foreach ($learners as $user) {
                    if (isset($passed[$user->id]) || $user->deleted || $user->suspended) {
                        continue;
                    }
                    foreach ($events as [$kind, $n]) {
                        if ($send) {
                            // One recipient that cannot be messaged must not cost everybody after them their reminder:
                            // the window is not replayed (see task\send_reminders).
                            try {
                                self::send($course, $cm, $user, $kind, $n, $due);
                            } catch (\Throwable $e) {
                                mtrace("local_awarenesssync: reminder to user {$user->id} failed: " . $e->getMessage());
                                continue;
                            }
                        }
                        $found[] = ['userid' => (int) $user->id, 'cmid' => (int) $cm->id, 'course' => $course->idnumber,
                            'kind' => $kind, 'days' => $n, 'due' => $due];
                    }
                }
            }
        }
        return $found;
    }

    private static function send(\stdClass $course, \cm_info $cm, \stdClass $user, string $kind, int $days, int $due): void {
        $section = get_section_name($course, $cm->get_section_info());
        $a = (object) [
            'module' => $section, 'date' => userdate($due, get_string('strftimedaydate', 'langconfig'), $user->timezone),
            'days' => $days, 'course' => format_string($course->fullname),
        ];
        $url = new \moodle_url('/course/view.php', ['id' => $course->id]);
        $key = $kind === 'overdue' ? 'deadlineoverdue' : 'deadlinereminder';
        $suffix = $kind === 'overdue' ? 'overdue' : ($days === 1 ? 'before1' : 'before');
        $lang = $user->lang ?: $course->lang ?: 'es';
        $strings = get_string_manager();
        $body = $strings->get_string("msg_{$suffix}_body", 'local_awarenesssync', $a, $lang) . "\n\n" . $url->out(false);
        $message = new \core\message\message();
        $message->component = 'local_awarenesssync';
        $message->name = $key;
        $message->userfrom = \core_user::get_noreply_user();
        $message->userto = (int) $user->id;
        $message->subject = $strings->get_string("msg_{$suffix}_subject", 'local_awarenesssync', $a, $lang);
        $message->fullmessage = $body;
        $message->fullmessageformat = FORMAT_PLAIN;
        $message->fullmessagehtml = nl2br(s($body));
        $message->smallmessage = $message->subject;
        $message->notification = 1;
        $message->contexturl = $url->out(false);
        $message->contexturlname = format_string($course->fullname);
        $message->courseid = $course->id;
        message_send($message);
    }
}
