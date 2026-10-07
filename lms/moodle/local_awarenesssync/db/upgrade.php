<?php
// This file is part of the awareness training platform (INCIBE kit -> Moodle as code).

/**
 * Upgrade steps. Fresh installs use install.xml; every schema change also needs a step here.
 *
 * @package    local_awarenesssync
 */

defined('MOODLE_INTERNAL') || die();

/**
 * @param int $oldversion
 * @return bool
 */
function xmldb_local_awarenesssync_upgrade($oldversion) {
    global $DB;
    $dbman = $DB->get_manager();

    if ($oldversion < 2026093000) {
        // Tables were added after the empty plugin skeleton was first installed.
        foreach (['local_awarenesssync_map', 'local_awarenesssync_log'] as $name) {
            if (!$dbman->table_exists($name)) {
                $dbman->install_one_table_from_xmldb_file(__DIR__ . '/install.xml', $name);
            }
        }
        upgrade_plugin_savepoint(true, 2026093000, 'local', 'awarenesssync');
    }

    if ($oldversion < 2026100100) {
        // Deadlines moved to the module's quiz only ("Expect completed on"); the PDF no longer carries one. Clear the old value
        // through the core API so the calendar events go with it. Completion state is untouched (a date is not a completion rule).
        $pdfs = $DB->get_records_sql(
            "SELECT cm.id, cm.course, cm.instance FROM {course_modules} cm JOIN {modules} m ON m.id = cm.module AND m.name = 'resource'
              WHERE cm.completionexpected <> 0 AND cm.idnumber LIKE ?", ['%:pdf']);
        foreach ($pdfs as $cm) {
            $DB->set_field('course_modules', 'completionexpected', 0, ['id' => $cm->id]);
            \core_completion\api::update_completion_date_event($cm->id, 'resource', $cm->instance, null); // null = delete the calendar event.
            \course_modinfo::purge_course_module_cache($cm->course, $cm->id);
        }
        upgrade_plugin_savepoint(true, 2026100100, 'local', 'awarenesssync');
    }

    return true;
}
