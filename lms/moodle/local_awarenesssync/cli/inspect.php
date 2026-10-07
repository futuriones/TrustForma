<?php
// This file is part of the awareness training platform (INCIBE kit -> Moodle as code).

/**
 * Read-only dump of a synced course: sections, activities, completion, restrictions, quiz questions.
 *
 *   php local/awarenesssync/cli/inspect.php --idnumber=concienciacion:2026
 *
 * @package    local_awarenesssync
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');

[$options] = cli_get_params(['idnumber' => '', 'help' => false], ['h' => 'help']);
if ($options['help'] || !$options['idnumber']) {
    cli_error('Usage: inspect.php --idnumber=<course idnumber>', $options['help'] ? 0 : 2);
}
$course = $DB->get_record('course', ['idnumber' => $options['idnumber']], '*', MUST_EXIST);
$modinfo = get_fast_modinfo($course);
$fmt = fn($ts) => $ts ? userdate($ts, '%Y-%m-%d %H:%M') : '-';

cli_writeln("{$course->shortname} | {$course->fullname} | lang={$course->lang} | completion=" . (int) $course->enablecompletion .
    " | {$fmt($course->startdate)} .. {$fmt($course->enddate)}");
foreach ($modinfo->get_section_info_all() as $section) {
    $name = $section->name ?: '(General)';
    cli_writeln(sprintf("\n[%d] %s  visible=%d", $section->section, $name, $section->visible));
    foreach ($modinfo->get_cms() as $cm) {
        if ((int) $cm->sectionnum !== (int) $section->section) {
            continue;
        }
        $extra = [];
        $extra[] = 'completion=' . $cm->completion . ($cm->completionview ? '+view' : '') . ($cm->completionpassgrade ? '+pass' : '');
        if ($cm->completionexpected) {
            $extra[] = 'due=' . $fmt($cm->completionexpected);
        }
        if ($cm->availability) {
            $extra[] = 'restricted';
        }
        if ($cm->modname === 'quiz') {
            $quiz = $DB->get_record('quiz', ['id' => $cm->instance]);
            $slots = $DB->count_records('quiz_slots', ['quizid' => $quiz->id]);
            $extra[] = "slots={$slots} sumgrades={$quiz->sumgrades} grade={$quiz->grade}";
            $gi = $DB->get_record('grade_items', ['itemtype' => 'mod', 'itemmodule' => 'quiz', 'iteminstance' => $quiz->id]);
            $extra[] = 'gradepass=' . ($gi ? (float) $gi->gradepass : '?');
        }
        $files = $DB->count_records_select('files', "contextid = ? AND filename <> '.' AND component LIKE 'mod\_%' AND filearea IN ('content')",
            [context_module::instance($cm->id)->id]);
        if ($files) {
            $extra[] = "files={$files}";
        }
        cli_writeln(sprintf('    cm%-4d %-11s vis=%d %s  {%s}', $cm->id, $cm->modname, $cm->visible, $cm->name, implode(' ', $extra)));
    }
}
$criteria = $DB->count_records('course_completion_criteria', ['course' => $course->id]);
cli_writeln("\ncourse completion criteria: {$criteria}; map rows: " . $DB->count_records('local_awarenesssync_map', ['courseid' => $course->id]));
