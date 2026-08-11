<?php
// This file is part of Moodle - http://moodle.org/.

use core\report_helper;
use report_indicadoresdocentes\local\analytics;

require('../../config.php');

$courseid = required_param('course', PARAM_INT);
$groupid = optional_param('group', 0, PARAM_INT);
$period = optional_param('period', -1, PARAM_INT);
$allowedperiods = [7, 30, 90, 0];

$course = get_course($courseid);
$defaultperiod = !empty($course->enddate) && $course->enddate < time() ? 0 : 30;
if ($period === -1) {
    $period = $defaultperiod;
} else if (!in_array($period, $allowedperiods, true)) {
    $period = $defaultperiod;
}
$context = context_course::instance($courseid);
require_login($course);
require_capability('report/indicadoresdocentes:view', $context);

$canaccessallgroups = has_capability('moodle/site:accessallgroups', $context);
$useridforgroups = $canaccessallgroups ? 0 : $USER->id;
$groups = groups_get_all_groups($courseid, $useridforgroups, 0, 'g.id, g.name');
if ($groupid && !isset($groups[$groupid])) {
    throw new moodle_exception('errorinvalidgroup', 'group');
}

$now = time();
$timefrom = $period ? $now - ($period * DAYSECS) : 0;
$url = new moodle_url('/report/indicadoresdocentes/index.php', [
    'course' => $courseid,
    'group' => $groupid,
    'period' => $period,
]);

$PAGE->set_url($url);
$PAGE->set_pagelayout('report');
$PAGE->set_title(get_string('pluginname', 'report_indicadoresdocentes'));
$PAGE->set_heading($course->fullname);
$PAGE->navigation->override_active_url(new moodle_url('/report/indicadoresdocentes/index.php', ['course' => $courseid]));
$PAGE->requires->css('/report/indicadoresdocentes/styles.css');

$analytics = new analytics($course, $context, $groupid, $timefrom, $now);
$data = $analytics->build();
$studentcount = count($data['students']);

echo $OUTPUT->header();
report_helper::print_report_selector(get_string('pluginname', 'report_indicadoresdocentes'));

echo html_writer::start_div('report-indicadores-filters d-flex flex-wrap gap-3 mb-4');
$periodoptions = [
    7 => get_string('period7', 'report_indicadoresdocentes'),
    30 => get_string('period30', 'report_indicadoresdocentes'),
    90 => get_string('period90', 'report_indicadoresdocentes'),
    0 => get_string('periodall', 'report_indicadoresdocentes'),
];
$periodurl = new moodle_url($url, ['period' => null]);
echo html_writer::tag('span', get_string('period', 'report_indicadoresdocentes'), ['class' => 'align-self-center font-weight-bold']);
echo $OUTPUT->single_select($periodurl, 'period', $periodoptions, $period, null, 'periodselect');
if ($groups) {
    $groupoptions = [0 => get_string('allgroups', 'report_indicadoresdocentes')];
    foreach ($groups as $group) {
        $groupoptions[$group->id] = format_string($group->name, true, ['context' => $context]);
    }
    $groupurl = new moodle_url($url, ['group' => null]);
    echo $OUTPUT->single_select($groupurl, 'group', $groupoptions, $groupid, null, 'groupselect');
}
echo html_writer::end_div();

echo html_writer::start_div('report-indicadores-cards');
echo report_indicadoresdocentes_card(get_string('students', 'report_indicadoresdocentes'), $studentcount);
echo report_indicadoresdocentes_card(get_string('activities', 'report_indicadoresdocentes'), count($data['activities']));
echo report_indicadoresdocentes_card(
    get_string('interactions', 'report_indicadoresdocentes'),
    format_float($data['totalinteractions'], 0)
);
echo report_indicadoresdocentes_card(
    get_string('courseapproval', 'report_indicadoresdocentes'),
    $data['coursegrades']['approved'] . ' / ' . $data['coursegrades']['graded']
);
echo html_writer::end_div();

if (!$studentcount) {
    echo $OUTPUT->notification(get_string('nodata', 'report_indicadoresdocentes'), 'info');
    echo $OUTPUT->footer();
    exit;
}

echo $OUTPUT->heading(get_string('courseapproval', 'report_indicadoresdocentes'), 3);
$coursechart = new \core\chart_pie();
$coursechart->set_labels([
    get_string('approved', 'report_indicadoresdocentes'),
    get_string('failed', 'report_indicadoresdocentes'),
    get_string('ungraded', 'report_indicadoresdocentes'),
]);
$coursechart->add_series(new \core\chart_series(get_string('students', 'report_indicadoresdocentes'), [
    $data['coursegrades']['approved'],
    $data['coursegrades']['failed'],
    $data['coursegrades']['ungraded'],
]));
echo $OUTPUT->render($coursechart);

echo $OUTPUT->heading(get_string('participationandcompletion', 'report_indicadoresdocentes'), 3);
$activitytable = new html_table();
$activitytable->attributes['class'] = 'generaltable report-indicadores-activities';
$activitytable->head = [
    get_string('activity', 'report_indicadoresdocentes'),
    get_string('type', 'report_indicadoresdocentes'),
    get_string('evidence', 'report_indicadoresdocentes'),
    get_string('participationrate', 'report_indicadoresdocentes'),
    get_string('grades', 'report_indicadoresdocentes'),
    get_string('completion', 'report_indicadoresdocentes'),
    get_string('interactions', 'report_indicadoresdocentes'),
];
foreach ($data['activities'] as $activity) {
    $graderesult = get_string('notapplicable', 'report_indicadoresdocentes');
    if ($activity['grades']['passgrade'] !== null) {
        $graderesult = get_string('approved', 'report_indicadoresdocentes') . ': ' . $activity['grades']['approved'] .
            html_writer::empty_tag('br') . get_string('failed', 'report_indicadoresdocentes') . ': ' . $activity['grades']['failed'] .
            html_writer::empty_tag('br') . get_string('ungraded', 'report_indicadoresdocentes') . ': ' .
            ($studentcount - $activity['grades']['graded']);
    }
    $completionresult = isset($activity['completion']['users'])
        ? $activity['completion']['complete'] . ' / ' . $studentcount
        : get_string('notapplicable', 'report_indicadoresdocentes');
    $activitytable->data[] = [
        $activity['name'],
        $activity['typename'],
        $activity['evidencelabel'],
        $activity['participated'] . ' / ' . $studentcount . ' (' . format_float($activity['participationrate'], 1) . '%)',
        $graderesult,
        $completionresult,
        format_float($activity['interactions'], 0),
    ];
}
echo html_writer::table($activitytable);

if ($data['activities']) {
    $topactivities = $data['activities'];
    uasort($topactivities, static fn(array $left, array $right): int => $right['interactions'] <=> $left['interactions']);
    $topactivities = array_slice($topactivities, 0, 15, true);
    echo $OUTPUT->heading(get_string('interactionsbyactivity', 'report_indicadoresdocentes'), 3);
    $activitychart = new \core\chart_bar();
    $activitychart->set_horizontal(true);
    $activitychart->set_labels(array_column($topactivities, 'name'));
    $activitychart->add_series(new \core\chart_series(
        get_string('interactions', 'report_indicadoresdocentes'),
        array_column($topactivities, 'interactions')
    ));
    echo $OUTPUT->render($activitychart);
}

echo $OUTPUT->heading(get_string('accessbyday', 'report_indicadoresdocentes'), 3);
$dailychart = new \core\chart_line();
$dailychart->set_labels(array_map(
    static fn(int $timestamp): string => userdate($timestamp, get_string('strftimedateshort')),
    array_keys($data['daily'])
));
$dailychart->add_series(new \core\chart_series(
    get_string('interactions', 'report_indicadoresdocentes'),
    array_values($data['daily'])
));
echo $OUTPUT->render($dailychart);

if (has_capability('report/indicadoresdocentes:viewdetails', $context)) {
    echo $OUTPUT->heading(get_string('studentdetail', 'report_indicadoresdocentes'), 3);
    $studenttable = new html_table();
    $studenttable->attributes['class'] = 'generaltable report-indicadores-students';
    $studenttable->head = [
        get_string('student', 'report_indicadoresdocentes'),
        get_string('interactions', 'report_indicadoresdocentes'),
        get_string('active_days', 'report_indicadoresdocentes'),
        get_string('lastinteraction', 'report_indicadoresdocentes'),
        get_string('completedactivities', 'report_indicadoresdocentes'),
        get_string('approvedactivities', 'report_indicadoresdocentes'),
    ];
    foreach ($data['studentdetails'] as $detail) {
        $profileurl = new moodle_url('/user/view.php', ['id' => $detail['user']->id, 'course' => $courseid]);
        $studenttable->data[] = [
            html_writer::link($profileurl, fullname($detail['user'])),
            format_float($detail['interactions'], 0),
            $detail['activedays'],
            $detail['last'] ? userdate($detail['last']) : get_string('never', 'report_indicadoresdocentes'),
            $detail['completed'],
            $detail['approved'],
        ];
    }
    echo html_writer::table($studenttable);
}

echo html_writer::start_div('alert alert-info mt-4');
echo html_writer::tag('p', get_string('dataqualification', 'report_indicadoresdocentes'));
echo html_writer::tag('p', get_string('currentenrolments', 'report_indicadoresdocentes'));
echo html_writer::tag('p', get_string('periodscope', 'report_indicadoresdocentes'));
echo html_writer::tag('p', get_string('standardlogwarning', 'report_indicadoresdocentes'));
echo html_writer::tag('p', get_string('privacywarning', 'report_indicadoresdocentes'), ['class' => 'mb-0']);
echo html_writer::end_div();
echo $OUTPUT->footer();

/**
 * Renders a dashboard summary card.
 *
 * @param string $label Card label.
 * @param string|int $value Card value.
 * @return string
 */
function report_indicadoresdocentes_card(string $label, $value): string {
    return html_writer::div(
        html_writer::div(s($value), 'report-indicadores-card-value') .
        html_writer::div(s($label), 'report-indicadores-card-label'),
        'card report-indicadores-card'
    );
}
