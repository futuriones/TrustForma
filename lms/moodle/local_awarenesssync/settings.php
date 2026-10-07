<?php
// This file is part of the awareness training platform (INCIBE kit -> Moodle as code).
//
// Deadlines themselves are NOT settings of this plugin: they are the native "Expect completed on" date of each module's quiz.

defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig) {
    $settings = new admin_settingpage('local_awarenesssync', get_string('pluginname', 'local_awarenesssync'));
    $ADMIN->add('localplugins', $settings);

    $settings->add(new admin_setting_heading('local_awarenesssync/remindershead',
        get_string('reminders', 'local_awarenesssync'), get_string('reminders_desc', 'local_awarenesssync')));
    $settings->add(new admin_setting_configcheckbox('local_awarenesssync/reminders_enabled',
        get_string('reminders_enabled', 'local_awarenesssync'), get_string('reminders_enabled_desc', 'local_awarenesssync'), 0));
    $settings->add(new admin_setting_configtext('local_awarenesssync/reminder_days',
        get_string('reminder_days', 'local_awarenesssync'), get_string('reminder_days_desc', 'local_awarenesssync'), '7,1',
        '/^\s*\d+(\s*[,;]\s*\d+)*\s*$/'));
    $settings->add(new admin_setting_configcheckbox('local_awarenesssync/overdue_notice',
        get_string('overdue_notice', 'local_awarenesssync'), get_string('overdue_notice_desc', 'local_awarenesssync'), 1));
}
