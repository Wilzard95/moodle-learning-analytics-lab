<?php
// This file is part of Moodle - http://moodle.org/.

namespace report_indicadoresdocentes\privacy;

defined('MOODLE_INTERNAL') || die();

/**
 * Privacy provider: this report stores no personal data.
 */
class provider implements \core_privacy\local\metadata\null_provider {
    /**
     * Returns the reason why no data is stored.
     *
     * @return string
     */
    public static function get_reason(): string {
        return 'privacy:metadata';
    }
}

