<?php
// This file is part of the awareness training platform (INCIBE kit -> Moodle as code).

namespace local_awarenesssync\privacy;

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\userlist;

defined('MOODLE_INTERNAL') || die();

/**
 * Privacy declaration. The plugin's own tables (map, log) hold no user data: they describe course content. What it does
 * with personal data is hand it over: the compliance report web service sends each enrolled employee's identity and
 * training results to the GRC portal, and the reminder task sends messages through Moodle's messaging (which stores them).
 * Both are declared here so they appear in Moodle's privacy registry; there is nothing of its own to export or delete.
 *
 * @package    local_awarenesssync
 */
final class provider implements
        \core_privacy\local\metadata\provider,
        \core_privacy\local\request\plugin\provider,
        \core_privacy\local\request\core_userlist_provider {

    public static function get_metadata(collection $collection): collection {
        $collection->add_external_location_link('grcportal', [
            'username' => 'privacy:metadata:grcportal:username',
            'email' => 'privacy:metadata:grcportal:email',
            'idnumber' => 'privacy:metadata:grcportal:idnumber',
            'fullname' => 'privacy:metadata:grcportal:fullname',
            'results' => 'privacy:metadata:grcportal:results',
        ], 'privacy:metadata:grcportal');
        $collection->add_subsystem_link('core_message', [], 'privacy:metadata:core_message');
        return $collection;
    }

    public static function get_contexts_for_userid(int $userid): contextlist {
        return new contextlist();
    }

    public static function get_users_in_context(userlist $userlist) {
    }

    public static function export_user_data(approved_contextlist $contextlist) {
    }

    public static function delete_data_for_all_users_in_context(\context $context) {
    }

    public static function delete_data_for_user(approved_contextlist $contextlist) {
    }

    public static function delete_data_for_users(approved_userlist $userlist) {
    }
}
