<?php
// This file is part of the awareness training platform (INCIBE kit -> Moodle as code).

/**
 * Freeze a finished training cycle so no deploy can change it any more (audit evidence).
 *
 *   php local/awarenesssync/cli/close_cycle.php --idnumber=concienciacion:2026
 *
 * Learners can still finish the course; only sync deploys are refused. There is deliberately no way to reopen a
 * cycle from here: start the next cycle instead.
 *
 * @package    local_awarenesssync
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');

[$options] = cli_get_params(['idnumber' => '', 'help' => false], ['h' => 'help']);
if ($options['help'] || !preg_match('/^[a-z0-9_-]+:[0-9]{4}$/', $options['idnumber'])) {
    cli_error("Usage: close_cycle.php --idnumber=<course id>:<cycle>   e.g. concienciacion:2026", $options['help'] ? 0 : 2);
}

try {
    \core\session\manager::set_user(get_admin());
    $closed = \local_awarenesssync\runner::close_cycle($options['idnumber']);
} catch (moodle_exception $e) {
    cli_error('ERROR: ' . $e->getMessage(), 4);
}
cli_writeln($closed ? "closed: {$options['idnumber']} (deploys to it are now refused)" : "already closed: {$options['idnumber']}");
