<?php
// This file is part of Moodle - http://moodle.org/.

defined('MOODLE_INTERNAL') || die();

$callbacks = [
    [
        'hook' => \core\hook\navigation\secondary_extend::class,
        'callback' => \report_indicadoresdocentes\hook_callbacks::class . '::extend_secondary_navigation',
    ],
];
