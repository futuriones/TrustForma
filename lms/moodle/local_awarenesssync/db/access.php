<?php
// This file is part of the awareness training platform (INCIBE kit -> Moodle as code).

defined('MOODLE_INTERNAL') || die();

$capabilities = [
    // Read the training-completion report through the web service. Only the dedicated GRC role gets it (see configure_site).
    'local/awarenesssync:viewreport' => [
        'captype' => 'read',
        'contextlevel' => CONTEXT_SYSTEM,
        'archetypes' => [],
        'riskbitmask' => RISK_PERSONAL,
    ],
];
