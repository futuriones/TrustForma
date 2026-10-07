<?php
// This file is part of the awareness training platform (INCIBE kit -> Moodle as code).

defined('MOODLE_INTERNAL') || die();

$on = MESSAGE_PERMITTED + MESSAGE_DEFAULT_ENABLED;
$messageproviders = [
    // A module deadline is coming up and the learner has not passed its test.
    'deadlinereminder' => ['defaults' => ['popup' => $on, 'email' => $on]],
    // The module deadline has passed.
    'deadlineoverdue' => ['defaults' => ['popup' => $on, 'email' => $on]],
];
