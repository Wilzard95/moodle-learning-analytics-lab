<?php
// This file is part of Moodle - http://moodle.org/.

defined('MOODLE_INTERNAL') || die();

/**
 * Adds the report to course navigation when the user can view it.
 *
 * @param navigation_node $navigation Course navigation node.
 * @param stdClass $course Course record.
 * @param context_course $context Course context.
 */
function report_indicadoresdocentes_extend_navigation_course(
    navigation_node $navigation,
    stdClass $course,
    context_course $context
): void {
    if (!has_capability('report/indicadoresdocentes:view', $context)) {
        return;
    }

    $url = new moodle_url('/report/indicadoresdocentes/index.php', ['course' => $course->id]);
    $navigation->add(
        get_string('pluginname', 'report_indicadoresdocentes'),
        $url,
        navigation_node::TYPE_SETTING,
        null,
        null,
        new pix_icon('i/report', '')
    );
}

