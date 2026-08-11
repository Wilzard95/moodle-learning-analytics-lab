<?php
// This file is part of Moodle - http://moodle.org/.

defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig) {
    $settings->add(new admin_setting_configtext(
        'report_indicadoresdocentes/defaultpassgrade',
        get_string('defaultpassgrade', 'report_indicadoresdocentes'),
        get_string('defaultpassgrade_desc', 'report_indicadoresdocentes'),
        '3.0',
        PARAM_FLOAT
    ));
    $settings->add(new admin_setting_configtext(
        'report_indicadoresdocentes/institutionalgrademax',
        get_string('institutionalgrademax', 'report_indicadoresdocentes'),
        get_string('institutionalgrademax_desc', 'report_indicadoresdocentes'),
        '5.0',
        PARAM_FLOAT
    ));
}

