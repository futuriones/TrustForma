<?php
// This file is part of the awareness training platform (INCIBE kit -> Moodle as code).

/**
 * Sync a build manifest into Moodle.
 *
 *   php local/awarenesssync/cli/sync.php --manifest=/build/concienciacion-2026/manifest.json --plan
 *   php local/awarenesssync/cli/sync.php --manifest=... --apply
 *
 * @package    local_awarenesssync
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');

[$options] = cli_get_params([
    'manifest' => '', 'plan' => false, 'apply' => false, 'only' => '', 'fail-on-changes' => false,
    'allow-structure-change' => false, 'help' => false,
], ['h' => 'help']);

if ($options['help'] || !$options['manifest'] || ($options['plan'] === $options['apply'])) {
    cli_writeln("Usage: sync.php --manifest=PATH (--plan | --apply) [--only=type,type] [--fail-on-changes] [--allow-structure-change]\n" .
        "  --plan                    show what would change; writes nothing (exit 4 if something is blocked)\n" .
        "  --apply                   create/update Moodle content to match the manifest (plans first, refuses if blocked)\n" .
        "  --only=quiz,page          restrict item types (debugging)\n" .
        "  --fail-on-changes         exit 3 if there is anything to do (used to prove idempotency)\n" .
        "  --allow-structure-change  permit changes that re-score existing quiz attempts (add/retire questions, pass mark)");
    exit($options['help'] ? 0 : 2);
}

try {
    $only = array_filter(explode(',', $options['only']));
    $actions = \local_awarenesssync\runner::run($options['manifest'], (bool) $options['apply'], $only, (bool) $options['allow-structure-change']);
} catch (moodle_exception $e) {
    cli_error('ERROR: ' . $e->getMessage() . ($e->debuginfo ? "\n  " . $e->debuginfo : ''), 4);
}

$verb = $options['apply'] ? 'applied' : 'planned';
foreach ($actions as $a) {
    cli_writeln(sprintf('%-8s %-12s %s%s', $a['action'], $a['type'], $a['key'], $a['detail'] !== '' ? "  ({$a['detail']})" : ''));
}
$counts = array_count_values(array_column($actions, 'action'));
ksort($counts);
cli_writeln($actions
    ? "== {$verb}: " . implode(', ', array_map(fn($k, $v) => "$v $k", array_keys($counts), $counts))
    : '== nothing to do: Moodle already matches the manifest');
if (isset($counts['blocked'])) {
    // A deploy plans first and refuses when the plan has a blocked change, so a blocked line in an APPLY run means the
    // situation changed between that check and the write (a learner started an attempt): the other lines were applied.
    cli_writeln("\n!! {$counts['blocked']} change(s) BLOCKED (reasons above): they would re-score, wipe or freeze learner evidence. " . ($options['apply']
        ? 'They became blocked while the deploy was running: every OTHER change listed above was applied. Run the plan again.'
        : 'Nothing was written.'));
    exit(4);
}
exit($options['fail-on-changes'] && $actions ? 3 : 0);
