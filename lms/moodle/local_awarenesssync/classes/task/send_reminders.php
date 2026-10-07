<?php
// This file is part of the awareness training platform (INCIBE kit -> Moodle as code).

namespace local_awarenesssync\task;

use local_awarenesssync\reminders;

defined('MOODLE_INTERNAL') || die();

/**
 * Daily: send the deadline reminders that fell due since the previous run. Off until an administrator enables it in
 * Site administration > Plugins > Local plugins > Awareness training sync.
 *
 * @package    local_awarenesssync
 */
final class send_reminders extends \core\task\scheduled_task {
    public function get_name(): string {
        return get_string('task_send_reminders', 'local_awarenesssync');
    }

    public function execute(): void {
        $now = time();
        $last = (int) get_config('local_awarenesssync', 'reminders_lastrun');
        set_config('reminders_lastrun', $now, 'local_awarenesssync');
        if (!get_config('local_awarenesssync', 'reminders_enabled')) {
            return; // Still advance the window, so enabling it later does not replay old deadlines.
        }
        $days = array_values(array_filter(array_map('intval', preg_split('/[\s,;]+/', (string) get_config('local_awarenesssync', 'reminder_days'))),
            fn($n) => $n > 0));
        $sent = reminders::run($last ?: $now - DAYSECS, $now, $days, (bool) get_config('local_awarenesssync', 'overdue_notice'));
        mtrace('local_awarenesssync: ' . count($sent) . ' reminder(s) sent');
    }
}
