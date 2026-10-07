<?php
// This file is part of the awareness training platform (INCIBE kit -> Moodle as code).
//
// Syncs a build manifest into Moodle idempotently and exposes the compliance report API.

defined('MOODLE_INTERNAL') || die();

$plugin->component = 'local_awarenesssync';
$plugin->version   = 2026100103;
$plugin->requires  = 2024100700; // Moodle 4.5.
$plugin->supported = [405, 405];
// The certificate activity and the report's certificate codes are mod_customcert's (pinned in lms/docker/plugins.lock).
$plugin->dependencies = ['mod_customcert' => ANY_VERSION];
$plugin->maturity  = MATURITY_ALPHA;
$plugin->release   = '0.1.0';
