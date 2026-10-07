<?php
// This file is part of the awareness training platform (INCIBE kit -> Moodle as code).

defined('MOODLE_INTERNAL') || die();

$functions = [
    'local_awarenesssync_get_compliance_report' => [
        'classname' => 'local_awarenesssync\external\get_compliance_report',
        'methodname' => 'execute',
        'description' => 'Per-employee training completion and quiz results for one course cycle (evidence for GRC tools).',
        'type' => 'read',
        'ajax' => false,
        'capabilities' => 'local/awarenesssync:viewreport',
    ],
];

$services = [
    'Awareness compliance' => [
        'functions' => ['local_awarenesssync_get_compliance_report'],
        'restrictedusers' => 1,
        'enabled' => 1,
        'shortname' => 'awareness_compliance',
    ],
];
