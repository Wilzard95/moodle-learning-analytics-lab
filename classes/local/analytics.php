<?php
// This file is part of Moodle - http://moodle.org/.

namespace report_indicadoresdocentes\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Builds read-only course indicators from Moodle core data.
 */
final class analytics {
    /** @var \stdClass */
    private $course;

    /** @var \context_course */
    private $context;

    /** @var int */
    private $groupid;

    /** @var int */
    private $timefrom;

    /** @var int */
    private $timeto;

    /**
     * Constructor.
     *
     * @param \stdClass $course Course record.
     * @param \context_course $context Course context.
     * @param int $groupid Selected group, or zero.
     * @param int $timefrom Inclusive period start.
     * @param int $timeto Inclusive period end.
     */
    public function __construct(
        \stdClass $course,
        \context_course $context,
        int $groupid,
        int $timefrom,
        int $timeto
    ) {
        global $CFG;

        require_once($CFG->libdir . '/completionlib.php');
        require_once($CFG->libdir . '/grade/constants.php');
        $this->course = $course;
        $this->context = $context;
        $this->groupid = $groupid;
        $this->timefrom = $timefrom;
        $this->timeto = $timeto;
    }

    /**
     * Builds the complete dashboard data set.
     *
     * @return array
     */
    public function build(): array {
        $students = $this->get_students();
        $activities = $this->get_activities();
        $studentids = array_keys($students);

        if (!$students) {
            return [
                'students' => [],
                'activities' => $activities,
                'coursegrades' => $this->empty_grade_summary(),
                'daily' => [],
                'totalinteractions' => 0,
                'studentdetails' => [],
                'completionenabled' => false,
            ];
        }

        $grades = $this->get_activity_grades($activities, $studentids);
        $completion = $this->get_completion($activities, $studentids);
        $participation = $this->get_participation($activities, $studentids, $completion);
        $interactions = $this->get_interactions($activities, $studentids);

        foreach ($activities as $cmid => &$activity) {
            // For modules without a dedicated submission model or configured
            // completion rule, an activity interaction is the available evidence.
            if (empty($participation[$cmid])
                    && !in_array($activity['modname'], ['assign', 'forum', 'quiz'], true)
                    && !isset($completion[$cmid])) {
                $participation[$cmid] = $interactions['byactivityusers'][$cmid] ?? [];
                $activity['evidencelabel'] = get_string('evidence_interaction', 'report_indicadoresdocentes');
            }
            $activity['participation'] = $participation[$cmid] ?? [];
            $activity['participated'] = count($activity['participation']);
            $activity['participationrate'] = $this->percentage($activity['participated'], count($students));
            $activity['grades'] = $grades[$cmid] ?? $this->empty_grade_summary();
            $activity['completion'] = $completion[$cmid] ?? ['complete' => 0, 'pass' => 0, 'fail' => 0];
            $activity['interactions'] = $interactions['byactivity'][$cmid] ?? 0;
            $activity['viewers'] = count($interactions['byactivityusers'][$cmid] ?? []);
            $activity['viewrate'] = $this->percentage($activity['viewers'], count($students));
        }
        unset($activity);

        return [
            'students' => $students,
            'activities' => $activities,
            'coursegrades' => $this->get_course_grades($studentids),
            'daily' => $interactions['daily'],
            'totalinteractions' => $interactions['total'],
            'studentdetails' => $this->get_student_details(
                $students,
                $activities,
                $grades,
                $completion,
                $interactions['bystudent']
            ),
            'completionenabled' => !empty($completion),
        ];
    }

    /**
     * Gets active enrolled learners, respecting the selected group.
     *
     * @return array User records keyed by id.
     */
    private function get_students(): array {
        global $DB;

        $fields = \core_user\fields::for_name()->get_sql('u', false, '', '', false)->selects;
        $students = get_enrolled_users(
            $this->context,
            'moodle/course:isincompletionreports',
            $this->groupid,
            'u.id, u.picture, u.imagealt, u.email, ' . $fields,
            'u.lastname, u.firstname',
            0,
            0,
            false
        );
        if (!$students) {
            return [];
        }

        $sql = "SELECT DISTINCT ue.userid
                  FROM {user_enrolments} ue
                  JOIN {enrol} e ON e.id = ue.enrolid
                 WHERE e.courseid = :courseid
                   AND e.status = :enrolenabled
                   AND ue.status = :useractive";
        $nonsuspended = $DB->get_fieldset_sql($sql, [
            'courseid' => $this->course->id,
            'enrolenabled' => \ENROL_INSTANCE_ENABLED,
            'useractive' => \ENROL_USER_ACTIVE,
        ]);
        return array_intersect_key($students, array_fill_keys(array_map('intval', $nonsuspended), true));
    }

    /**
     * Gets reportable course modules.
     *
     * @return array Activity data keyed by course-module id.
     */
    private function get_activities(): array {
        $modinfo = get_fast_modinfo($this->course);
        $activities = [];

        foreach ($modinfo->get_cms() as $cm) {
            if (!$cm->visible || $cm->deletioninprogress) {
                continue;
            }
            $activities[$cm->id] = [
                'cmid' => $cm->id,
                'instance' => $cm->instance,
                'name' => format_string($cm->name, true, ['context' => $this->context]),
                'modname' => $cm->modname,
                'typename' => get_string('modulename', $cm->modname),
                'evidencelabel' => $this->get_evidence_label($cm->modname),
            ];
        }

        return $activities;
    }

    /**
     * Gets the evidence label for an activity type.
     *
     * @param string $modname Module name.
     * @return string
     */
    private function get_evidence_label(string $modname): string {
        if (in_array($modname, ['assign', 'forum', 'quiz'], true)) {
            return get_string('evidence_' . $modname, 'report_indicadoresdocentes');
        }
        return get_string('evidence_completion', 'report_indicadoresdocentes');
    }

    /**
     * Gets evidence of submission, participation, attempt or completion.
     *
     * @param array $activities Activities keyed by cmid.
     * @param array $studentids Student ids.
     * @param array $completion Completion data.
     * @return array User-id sets keyed by cmid.
     */
    private function get_participation(array $activities, array $studentids, array $completion): array {
        global $DB;

        $result = [];
        [$usersql, $userparams] = $DB->get_in_or_equal($studentids, \SQL_PARAMS_NAMED, 'pu');

        foreach ($activities as $cmid => $activity) {
            $records = [];
            if ($activity['modname'] === 'assign') {
                $sql = "SELECT DISTINCT userid
                          FROM {assign_submission}
                         WHERE assignment = :instance
                           AND userid $usersql
                           AND latest = 1
                           AND status = :status";
                $params = ['instance' => $activity['instance'], 'status' => 'submitted'] + $userparams;
                $records = $DB->get_fieldset_sql($sql, $params);
            } else if ($activity['modname'] === 'forum') {
                $sql = "SELECT DISTINCT p.userid
                          FROM {forum_posts} p
                          JOIN {forum_discussions} d ON d.id = p.discussion
                         WHERE d.forum = :instance
                           AND p.userid $usersql
                           AND p.deleted = 0";
                $records = $DB->get_fieldset_sql($sql, ['instance' => $activity['instance']] + $userparams);
            } else if ($activity['modname'] === 'quiz') {
                $sql = "SELECT DISTINCT userid
                          FROM {quiz_attempts}
                         WHERE quiz = :instance
                           AND userid $usersql
                           AND preview = 0";
                $records = $DB->get_fieldset_sql($sql, ['instance' => $activity['instance']] + $userparams);
            } else if (isset($completion[$cmid])) {
                $records = array_keys($completion[$cmid]['users']);
            }
            $result[$cmid] = array_fill_keys(array_map('intval', $records), true);
        }

        return $result;
    }

    /**
     * Gets activity grade summaries and per-user status.
     *
     * @param array $activities Activities keyed by cmid.
     * @param array $studentids Student ids.
     * @return array
     */
    private function get_activity_grades(array $activities, array $studentids): array {
        global $DB;

        if (!$activities) {
            return [];
        }

        [$usersql, $userparams] = $DB->get_in_or_equal($studentids, \SQL_PARAMS_NAMED, 'gu');
        $items = $DB->get_records('grade_items', ['courseid' => $this->course->id, 'itemtype' => 'mod']);
        $itemmap = [];
        foreach ($items as $item) {
            if ((int) $item->itemnumber !== 0 || (int) $item->gradetype === \GRADE_TYPE_NONE) {
                continue;
            }
            $itemmap[$item->itemmodule . ':' . $item->iteminstance] = $item;
        }

        $result = [];
        foreach ($activities as $cmid => $activity) {
            $key = $activity['modname'] . ':' . $activity['instance'];
            if (!isset($itemmap[$key])) {
                continue;
            }
            $item = $itemmap[$key];
            $sql = "SELECT userid, finalgrade
                      FROM {grade_grades}
                     WHERE itemid = :itemid
                       AND userid $usersql
                       AND finalgrade IS NOT NULL
                       AND excluded = 0";
            $records = $DB->get_records_sql($sql, ['itemid' => $item->id] + $userparams);
            $result[$cmid] = $this->summarise_grades($item, $records);
        }

        return $result;
    }

    /**
     * Gets course grade summary.
     *
     * @param array $studentids Student ids.
     * @return array
     */
    private function get_course_grades(array $studentids): array {
        global $DB;

        $item = $DB->get_record('grade_items', [
            'courseid' => $this->course->id,
            'itemtype' => 'course',
        ]);
        if (!$item) {
            return $this->empty_grade_summary();
        }
        [$usersql, $userparams] = $DB->get_in_or_equal($studentids, \SQL_PARAMS_NAMED, 'cu');
        $sql = "SELECT userid, finalgrade
                  FROM {grade_grades}
                 WHERE itemid = :itemid
                   AND userid $usersql
                   AND finalgrade IS NOT NULL
                   AND excluded = 0";
        $records = $DB->get_records_sql($sql, ['itemid' => $item->id] + $userparams);
        $summary = $this->summarise_grades($item, $records);
        $summary['ungraded'] = count($studentids) - $summary['graded'];
        return $summary;
    }

    /**
     * Summarises grades using gradepass or the configured institutional threshold.
     *
     * @param \stdClass $item Grade item.
     * @param array $records Grade records.
     * @return array
     */
    private function summarise_grades(\stdClass $item, array $records): array {
        $passgrade = $this->get_pass_grade($item);
        $summary = $this->empty_grade_summary();
        $summary['passgrade'] = $passgrade;
        foreach ($records as $record) {
            $userid = (int) $record->userid;
            $grade = (float) $record->finalgrade;
            $status = $grade >= $passgrade ? 'approved' : 'failed';
            $summary[$status]++;
            $summary['graded']++;
            $summary['users'][$userid] = [
                'grade' => $grade,
                'status' => $status,
            ];
        }
        return $summary;
    }

    /**
     * Resolves the passing grade for a numeric grade item.
     *
     * @param \stdClass $item Grade item.
     * @return float
     */
    private function get_pass_grade(\stdClass $item): float {
        if ((float) $item->gradepass > (float) $item->grademin) {
            return (float) $item->gradepass;
        }
        $defaultpass = (float) get_config('report_indicadoresdocentes', 'defaultpassgrade');
        $institutionalmax = (float) get_config('report_indicadoresdocentes', 'institutionalgrademax');
        $defaultpass = $defaultpass > 0 ? $defaultpass : 3.0;
        $institutionalmax = $institutionalmax > 0 ? $institutionalmax : 5.0;
        $range = (float) $item->grademax - (float) $item->grademin;
        return (float) $item->grademin + ($defaultpass / $institutionalmax) * $range;
    }

    /**
     * Gets completion summaries and user states.
     *
     * @param array $activities Activities keyed by cmid.
     * @param array $studentids Student ids.
     * @return array
     */
    private function get_completion(array $activities, array $studentids): array {
        global $DB;

        if (!$activities) {
            return [];
        }
        $enabledcmids = [];
        $modinfo = get_fast_modinfo($this->course);
        foreach ($activities as $cmid => $unused) {
            if ($modinfo->get_cm($cmid)->completion !== \COMPLETION_TRACKING_NONE) {
                $enabledcmids[] = $cmid;
            }
        }
        if (!$enabledcmids) {
            return [];
        }

        [$cmsql, $cmparams] = $DB->get_in_or_equal($enabledcmids, \SQL_PARAMS_NAMED, 'ccm');
        [$usersql, $userparams] = $DB->get_in_or_equal($studentids, \SQL_PARAMS_NAMED, 'ccu');
        $sql = "SELECT id, coursemoduleid, userid, completionstate
                  FROM {course_modules_completion}
                 WHERE coursemoduleid $cmsql
                   AND userid $usersql
                   AND completionstate > 0";
        $records = $DB->get_records_sql($sql, $cmparams + $userparams);
        $result = [];
        foreach ($enabledcmids as $cmid) {
            $result[$cmid] = ['complete' => 0, 'pass' => 0, 'fail' => 0, 'users' => []];
        }
        foreach ($records as $record) {
            $cmid = (int) $record->coursemoduleid;
            $userid = (int) $record->userid;
            $state = (int) $record->completionstate;
            $result[$cmid]['complete']++;
            $result[$cmid]['pass'] += $state === \COMPLETION_COMPLETE_PASS ? 1 : 0;
            $result[$cmid]['fail'] += $state === \COMPLETION_COMPLETE_FAIL ? 1 : 0;
            $result[$cmid]['users'][$userid] = $state;
        }
        return $result;
    }

    /**
     * Gets student interactions from the standard log store.
     *
     * @param array $activities Activities keyed by cmid.
     * @param array $studentids Student ids.
     * @return array
     */
    private function get_interactions(array $activities, array $studentids): array {
        global $DB;

        [$usersql, $userparams] = $DB->get_in_or_equal($studentids, \SQL_PARAMS_NAMED, 'lu');
        $params = [
            'courseid' => $this->course->id,
            'anonymous' => 0,
            'timefrom' => $this->timefrom,
            'timeto' => $this->timeto,
            'component' => 'report_indicadoresdocentes',
        ] + $userparams;
        $where = "courseid = :courseid
                  AND anonymous = :anonymous
                  AND userid $usersql
                  AND timecreated >= :timefrom
                  AND timecreated <= :timeto
                  AND component <> :component";
        $sql = "SELECT userid,
                       contextlevel,
                       contextinstanceid,
                       COUNT(id) AS eventcount,
                       MIN(timecreated) AS firstevent,
                       MAX(timecreated) AS lastevent
                  FROM {logstore_standard_log}
                 WHERE $where
              GROUP BY userid, contextlevel, contextinstanceid";
        $records = $DB->get_recordset_sql($sql, $params);

        $byactivity = [];
        $byactivityusers = [];
        $bystudent = [];
        $total = 0;
        foreach ($records as $record) {
            $userid = (int) $record->userid;
            $count = (int) $record->eventcount;
            $total += $count;
            if (!isset($bystudent[$userid])) {
                $bystudent[$userid] = ['count' => 0, 'last' => 0, 'activedays' => 0];
            }
            $bystudent[$userid]['count'] += $count;
            $bystudent[$userid]['last'] = max($bystudent[$userid]['last'], (int) $record->lastevent);
            if ((int) $record->contextlevel === \CONTEXT_MODULE && isset($activities[(int) $record->contextinstanceid])) {
                $cmid = (int) $record->contextinstanceid;
                $byactivity[$cmid] = ($byactivity[$cmid] ?? 0) + $count;
                $byactivityusers[$cmid][$userid] = true;
            }
        }

        $records->close();
        $daily = $this->get_daily_interactions($where, $params);
        foreach ($daily['users'] as $userid => $days) {
            if (!isset($bystudent[$userid])) {
                $bystudent[$userid] = ['count' => 0, 'last' => 0, 'activedays' => 0];
            }
            $bystudent[$userid]['activedays'] = count($days);
        }
        return [
            'total' => $total,
            'byactivity' => $byactivity,
            'byactivityusers' => $byactivityusers,
            'bystudent' => $bystudent,
            'daily' => $daily['counts'],
        ];
    }

    /**
     * Counts daily interactions in one bounded aggregate query.
     *
     * @param string $basewhere Common log WHERE clause.
     * @param array $baseparams Common parameters.
     * @return array
     */
    private function get_daily_interactions(string $basewhere, array $baseparams): array {
        global $DB;

        $counts = [];
        $users = [];
        $laststart = usergetmidnight($this->timeto);
        $start = max(usergetmidnight($this->timefrom), $laststart - (92 * \DAYSECS));
        $timezone = \core_date::get_user_timezone_object();
        $reference = new \DateTimeImmutable('@' . $this->timeto);
        $offset = $timezone->getOffset($reference);
        $params = $baseparams;
        $params['timefrom'] = max($start, $this->timefrom);
        $params['dayoffset'] = $offset;
        $params['dayseconds'] = \DAYSECS;
        $sql = "SELECT userid,
                       FLOOR((timecreated + :dayoffset) / :dayseconds) AS daybucket,
                       COUNT(id) AS eventcount
                  FROM {logstore_standard_log}
                 WHERE $basewhere
              GROUP BY userid, daybucket";
        $records = $DB->get_recordset_sql($sql, $params);
        foreach ($records as $record) {
            $daystart = ((int) $record->daybucket * \DAYSECS) - $offset;
            $counts[$daystart] = ($counts[$daystart] ?? 0) + (int) $record->eventcount;
            $users[(int) $record->userid][$daystart] = true;
        }
        $records->close();
        for ($daystart = $start; $daystart <= $laststart; $daystart += \DAYSECS) {
            $counts[$daystart] = $counts[$daystart] ?? 0;
        }
        ksort($counts);
        return ['counts' => $counts, 'users' => $users];
    }

    /**
     * Builds per-student detail.
     *
     * @param array $students Student records.
     * @param array $activities Activities.
     * @param array $grades Grade data.
     * @param array $completion Completion data.
     * @param array $interactions Interaction data.
     * @return array
     */
    private function get_student_details(
        array $students,
        array $activities,
        array $grades,
        array $completion,
        array $interactions
    ): array {
        $details = [];
        $assignmenttotal = count(array_filter($activities,
            static fn(array $activity): bool => $activity['modname'] === 'assign'));
        foreach ($students as $userid => $student) {
            $completed = 0;
            $approved = 0;
            $participated = 0;
            $submitted = 0;
            $gradevalues = [];
            $pendingactivities = [];
            $notapprovedactivities = [];
            foreach ($activities as $cmid => $activity) {
                $completed += isset($completion[$cmid]['users'][$userid]) ? 1 : 0;
                $hasevidence = isset($activity['participation'][$userid]);
                $participated += $hasevidence ? 1 : 0;
                $submitted += $activity['modname'] === 'assign' && $hasevidence ? 1 : 0;
                if (!$hasevidence) {
                    $pendingactivities[] = $activity['name'];
                }
                $gradestatus = $grades[$cmid]['users'][$userid]['status'] ?? '';
                $approved += $gradestatus === 'approved' ? 1 : 0;
                if ($gradestatus === 'failed') {
                    $notapprovedactivities[] = $activity['name'];
                }
                if (isset($grades[$cmid]['users'][$userid]['grade'])) {
                    $gradevalues[] = $grades[$cmid]['users'][$userid]['grade'];
                }
            }
            $details[$userid] = [
                'user' => $student,
                'interactions' => $interactions[$userid]['count'] ?? 0,
                'activedays' => $interactions[$userid]['activedays'] ?? 0,
                'last' => $interactions[$userid]['last'] ?? 0,
                'completed' => $completed,
                'approved' => $approved,
                'participated' => $participated,
                'activitytotal' => count($activities),
                'submitted' => $submitted,
                'assignmenttotal' => $assignmenttotal,
                'graded' => count($gradevalues),
                'averagegrade' => $gradevalues ? array_sum($gradevalues) / count($gradevalues) : null,
                'pendingactivities' => $pendingactivities,
                'notapprovedactivities' => $notapprovedactivities,
            ];
        }
        uasort($details, static function(array $left, array $right): int {
            $bycompleted = $left['completed'] <=> $right['completed'];
            if ($bycompleted !== 0) {
                return $bycompleted;
            }
            $byapproved = $left['approved'] <=> $right['approved'];
            if ($byapproved !== 0) {
                return $byapproved;
            }
            return strcasecmp(fullname($left['user']), fullname($right['user']));
        });
        return $details;
    }

    /**
     * Returns an empty grade summary.
     *
     * @return array
     */
    private function empty_grade_summary(): array {
        return ['approved' => 0, 'failed' => 0, 'ungraded' => 0, 'graded' => 0, 'passgrade' => null, 'users' => []];
    }

    /**
     * Calculates a percentage.
     *
     * @param int $numerator Numerator.
     * @param int $denominator Denominator.
     * @return float
     */
    private function percentage(int $numerator, int $denominator): float {
        return $denominator > 0 ? round(($numerator / $denominator) * 100, 1) : 0.0;
    }
}
