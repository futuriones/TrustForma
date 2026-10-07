<?php
// This file is part of the awareness training platform (INCIBE kit -> Moodle as code).

defined('MOODLE_INTERNAL') || die();

$observers = [
    ['eventname' => '\core\event\course_deleted', 'callback' => '\local_awarenesssync\observer::course_deleted'],
];
