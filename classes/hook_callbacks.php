<?php
// This file is part of Moodle - http://moodle.org/.

namespace report_indicadoresdocentes;

defined('MOODLE_INTERNAL') || die();

/**
 * Hook callbacks for learning indicators.
 */
final class hook_callbacks {
    /**
     * Adds a direct entry to the course secondary navigation.
     *
     * The regular report entry remains available under Reports. This extra node
     * provides a faster route for teachers without changing the report page.
     *
     * @param \core\hook\navigation\secondary_extend $hook Navigation hook.
     */
    public static function extend_secondary_navigation(
        \core\hook\navigation\secondary_extend $hook
    ): void {
        global $PAGE, $SITE;

        if (empty($PAGE->course->id) || (int) $PAGE->course->id === (int) $SITE->id) {
            return;
        }

        $context = \context_course::instance($PAGE->course->id);
        if (!has_capability('report/indicadoresdocentes:view', $context)) {
            return;
        }

        $navigation = $hook->get_secondaryview();
        if ($navigation->get('learningindicators')) {
            return;
        }

        $node = \navigation_node::create(
            get_string('navigationlabel', 'report_indicadoresdocentes'),
            new \moodle_url('/report/indicadoresdocentes/index.php', ['course' => $PAGE->course->id]),
            \navigation_node::TYPE_CUSTOM,
            null,
            'learningindicators',
            new \pix_icon('i/report', '')
        );
        $navigation->add_node($node, 'coursereports');
    }
}
