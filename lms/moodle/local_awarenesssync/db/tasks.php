<?php
// This file is part of the awareness training platform (INCIBE kit -> Moodle as code).

defined('MOODLE_INTERNAL') || die();

$tasks = [
    [
        'classname' => 'local_awarenesssync\task\send_reminders',
        'blocking' => 0, 'minute' => '0', 'hour' => '8', 'day' => '*', 'month' => '*', 'dayofweek' => '*',
    ],
];
