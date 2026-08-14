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
$PAGE->requires->js_call_amd('report_indicadoresdocentes/dashboard', 'init');

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

$coursechart = new \core\chart_pie();
$coursechart->set_labels([
    get_string('approved', 'report_indicadoresdocentes'),
    get_string('failed', 'report_indicadoresdocentes'),
    get_string('ungraded', 'report_indicadoresdocentes'),
]);
$coursegradeseries = new \core\chart_series(get_string('students', 'report_indicadoresdocentes'), [
    $data['coursegrades']['approved'],
    $data['coursegrades']['failed'],
    $data['coursegrades']['ungraded'],
]);
$coursegradeseries->set_colors(['#198754', '#dc3545', '#ffc107']);
$coursechart->add_series($coursegradeseries);

$currentperformancechart = new \core\chart_pie();
$currentperformancechart->set_labels([
    get_string('currentlypassing', 'report_indicadoresdocentes'),
    get_string('currentlynotpassing', 'report_indicadoresdocentes'),
    get_string('withoutavailablegrades', 'report_indicadoresdocentes'),
]);
$currentperformanceseries = new \core\chart_series(get_string('students', 'report_indicadoresdocentes'), [
    $data['currentperformance']['approved'],
    $data['currentperformance']['failed'],
    $data['currentperformance']['ungraded'],
]);
$currentperformanceseries->set_colors(['#198754', '#dc3545', '#ffc107']);
$currentperformancechart->add_series($currentperformanceseries);
$resourcemodnames = ['resource', 'page', 'url', 'book', 'folder'];
$activitycharthtml = '';
if ($data['activities']) {
    $topactivities = array_filter($data['activities'], static fn(array $activity): bool =>
        !in_array($activity['modname'], $resourcemodnames, true));
    uasort($topactivities, static fn(array $left, array $right): int => $right['interactions'] <=> $left['interactions']);
    $activitychart = new \core\chart_bar();
    $activitychart->set_horizontal(true);
    $activitychart->set_labels(array_column($topactivities, 'name'));
    $activitychart->add_series(new \core\chart_series(
        get_string('interactions', 'report_indicadoresdocentes'),
        array_column($topactivities, 'interactions')
    ));
    $activitycharthtml = html_writer::div($OUTPUT->render($activitychart), 'report-indicadores-chart-scroll', [
        'tabindex' => '0',
        'role' => 'region',
        'aria-label' => get_string('interactionsbyactivity', 'report_indicadoresdocentes'),
        'style' => '--report-activity-count: ' . count($topactivities),
    ]);
}

$dailychart = new \core\chart_line();
$dailychart->set_labels(array_map(
    static fn(int $timestamp): string => userdate($timestamp, get_string('strftimedateshort')),
    array_keys($data['daily'])
));
$dailychart->add_series(new \core\chart_series(
    get_string('interactions', 'report_indicadoresdocentes'),
    array_values($data['daily'])
));

$deliverygroups = [];
foreach ($data['activities'] as $activity) {
    $deliverygroups[$activity['modname']]['label'] = $activity['typename'];
    $deliverygroups[$activity['modname']]['activities'][] = $activity;
}
uasort($deliverygroups, static fn(array $left, array $right): int => strcasecmp($left['label'], $right['label']));
$deliveryhtml = '';
if ($deliverygroups) {
    $deliveryoptions = [];
    foreach ($deliverygroups as $modname => $group) {
        $deliveryoptions[$modname] = $group['label'] . ' (' . count($group['activities']) . ')';
    }
    $selecteddeliverytype = (string) array_key_first($deliverygroups);
    $deliveryselect = html_writer::select($deliveryoptions, 'deliverytype', $selecteddeliverytype, false, [
        'id' => 'report-indicadores-delivery-type',
        'class' => 'custom-select report-indicadores-type-select',
        'data-activity-type-select' => '1',
    ]);
    $deliveryhtml .= html_writer::div(
        html_writer::tag('label', get_string('activitytype', 'report_indicadoresdocentes'), [
            'for' => 'report-indicadores-delivery-type',
            'class' => 'font-weight-bold mb-0',
        ]) . $deliveryselect,
        'report-indicadores-type-filter'
    );
    foreach ($deliverygroups as $modname => $group) {
        $typeactivities = $group['activities'];
        $typechart = new \core\chart_bar();
        $typechart->set_horizontal(true);
        $typechart->set_stacked(true);
        $typechart->set_labels(array_column($typeactivities, 'name'));
        $presentedseries = new \core\chart_series(
            get_string('withevidence', 'report_indicadoresdocentes'),
            array_column($typeactivities, 'participated')
        );
        $presentedseries->set_color('#198754');
        $missingseries = new \core\chart_series(
            get_string('withoutevidence', 'report_indicadoresdocentes'),
            array_map(static fn(array $activity): int => $studentcount - $activity['participated'], $typeactivities)
        );
        $missingseries->set_color('#d9dee3');
        $typechart->add_series($presentedseries);
        $typechart->add_series($missingseries);

        $typecontent = $OUTPUT->render($typechart);
        if (has_capability('report/indicadoresdocentes:viewdetails', $context)) {
            $missingtable = new html_table();
            $missingtable->attributes['class'] = 'generaltable report-indicadores-missing-evidence';
            $missingtable->head = [
                get_string('activity', 'report_indicadoresdocentes'),
                get_string('evidence', 'report_indicadoresdocentes'),
                get_string('missingcount', 'report_indicadoresdocentes'),
                get_string('studentswithoutevidence', 'report_indicadoresdocentes'),
            ];
            foreach ($typeactivities as $activity) {
                $missingstudents = array_diff_key($data['students'], $activity['participation']);
                $studentlinks = [];
                foreach ($missingstudents as $student) {
                    $profileurl = new moodle_url('/user/view.php', ['id' => $student->id, 'course' => $courseid]);
                    $studentlinks[] = html_writer::link($profileurl, fullname($student));
                }
                $missingtable->data[] = [
                    $activity['name'],
                    $activity['evidencelabel'],
                    count($missingstudents),
                    $studentlinks ? implode(html_writer::empty_tag('br'), $studentlinks)
                        : get_string('nomissingstudents', 'report_indicadoresdocentes'),
                ];
            }
            $typecontent .= report_indicadoresdocentes_collapsible_table(
                'missing-' . $modname,
                get_string('pendingstudentdetail', 'report_indicadoresdocentes'),
                get_string('pendingstudentdetail_help', 'report_indicadoresdocentes'),
                html_writer::table($missingtable)
            );
        }
        $deliveryhtml .= html_writer::div($typecontent, 'report-indicadores-type-panel', [
            'data-activity-type-panel' => $modname,
            'hidden' => $modname === $selecteddeliverytype ? null : 'hidden',
        ]);
    }
}

$gradedactivities = array_values(array_filter($data['activities'],
    static fn(array $activity): bool => $activity['grades']['passgrade'] !== null));
$approvalchart = new \core\chart_bar();
$approvalchart->set_labels(array_column($gradedactivities, 'name'));
$approvedseries = new \core\chart_series(get_string('approved', 'report_indicadoresdocentes'),
    array_map(static fn(array $activity): int => $activity['grades']['approved'], $gradedactivities));
$approvedseries->set_color('#198754');
$belowseries = new \core\chart_series(get_string('failed', 'report_indicadoresdocentes'),
    array_map(static fn(array $activity): int => $activity['grades']['failed'], $gradedactivities));
$belowseries->set_color('#dc3545');
$ungradedseries = new \core\chart_series(get_string('ungraded', 'report_indicadoresdocentes'),
    array_map(static fn(array $activity): int => $studentcount - $activity['grades']['graded'], $gradedactivities));
$ungradedseries->set_color('#ffc107');
$approvalchart->add_series($approvedseries);
$approvalchart->add_series($belowseries);
$approvalchart->add_series($ungradedseries);

$viewchart = new \core\chart_bar();
$viewchart->set_horizontal(true);
$viewchart->set_stacked(true);
$viewactivities = array_values($data['activities']);
$viewchart->set_labels(array_column($viewactivities, 'name'));
$withviewseries = new \core\chart_series(
    get_string('withview', 'report_indicadoresdocentes'),
    array_map(static fn(array $activity): float => round($activity['viewrate'], 1), $viewactivities)
);
$withviewseries->set_color('#0f6cbf');
$withoutviewseries = new \core\chart_series(
    get_string('withoutview', 'report_indicadoresdocentes'),
    array_map(static fn(array $activity): float => round(100 - $activity['viewrate'], 1), $viewactivities)
);
$withoutviewseries->set_color('#d9dee3');
$viewchart->add_series($withviewseries);
$viewchart->add_series($withoutviewseries);
$viewcharthtml = html_writer::div($OUTPUT->render($viewchart), 'report-indicadores-chart-scroll', [
    'tabindex' => '0',
    'role' => 'region',
    'aria-label' => get_string('viewsbyactivity_scroll', 'report_indicadoresdocentes'),
    'style' => '--report-activity-count: ' . count($viewactivities),
]);

$completable = array_filter($data['activities'], static fn(array $activity): bool =>
    isset($activity['completion']['users']));
$completiontotal = count($completable) * $studentcount;
$completedtotal = array_sum(array_map(static fn(array $activity): int =>
    $activity['completion']['complete'], $completable));
$progresschart = new \core\chart_pie();
$progresschart->set_labels([
    get_string('completedstate', 'report_indicadoresdocentes'),
    get_string('pendingstate', 'report_indicadoresdocentes'),
]);
$progressseries = new \core\chart_series(get_string('totalcourseactivities', 'report_indicadoresdocentes'), [
    $completedtotal,
    max(0, $completiontotal - $completedtotal),
]);
$progressseries->set_colors(['#198754', '#adb5bd']);
$progresschart->add_series($progressseries);

$resources = array_values(array_filter($data['activities'], static fn(array $activity): bool =>
    in_array($activity['modname'], $resourcemodnames, true)));
$resourcechart = new \core\chart_bar();
$resourcechart->set_horizontal(true);
$resourcechart->set_labels(array_column($resources, 'name'));
$resourcechart->add_series(new \core\chart_series(
    get_string('interactions', 'report_indicadoresdocentes'),
    array_column($resources, 'interactions')
));
$attentionapprovalhtml = report_indicadoresdocentes_attention_table($data, $courseid, $context, 'approval');
$attentiondeliveryhtml = report_indicadoresdocentes_attention_table($data, $courseid, $context, 'deliveries');
$currentaverage = $data['currentperformance']['average'] === null
    ? get_string('ungraded', 'report_indicadoresdocentes')
    : format_float($data['currentperformance']['average'], 2) . ' / ' .
        format_float((float) get_config('report_indicadoresdocentes', 'institutionalgrademax') ?: 5.0, 1);
$coveragevalue = $data['currentperformance']['gradedactivities'] . ' / ' .
    $data['currentperformance']['totalactivities'] . ' (' .
    format_float($data['currentperformance']['coverage'], 1) . '%)';
$currentperformancestats = html_writer::div(
    report_indicadoresdocentes_card(get_string('currentgroupaverage', 'report_indicadoresdocentes'), $currentaverage) .
    report_indicadoresdocentes_card(get_string('evaluationcoverage', 'report_indicadoresdocentes'), $coveragevalue) .
    report_indicadoresdocentes_card(
        get_string('currentpasscriterion', 'report_indicadoresdocentes'),
        format_float($data['currentperformance']['passgrade'], 1)
    ),
    'report-indicadores-current-stats'
);
$currentperformancehtml = html_writer::tag('section',
    html_writer::tag('h4', get_string('currentperformance', 'report_indicadoresdocentes'), [
        'class' => 'report-indicadores-combined-title',
    ]) .
    html_writer::tag('p', get_string('currentperformance_help', 'report_indicadoresdocentes'), [
        'class' => 'report-indicadores-chart-description',
    ]) .
    $currentperformancestats .
    html_writer::div($OUTPUT->render($currentperformancechart), 'report-indicadores-current-chart'),
    ['class' => 'report-indicadores-current-performance']
);
$courseoverviewhtml = html_writer::div(
    html_writer::tag('section',
        html_writer::tag('h4', get_string('courseapproval', 'report_indicadoresdocentes'), [
            'class' => 'report-indicadores-combined-title',
        ]) .
        html_writer::tag('p', get_string('courseapproval_help', 'report_indicadoresdocentes'), [
            'class' => 'report-indicadores-chart-description',
        ]) .
        $OUTPUT->render($coursechart),
        ['class' => 'report-indicadores-combined-chart']
    ) .
    html_writer::tag('section',
        html_writer::tag('h4', get_string('courseprogress', 'report_indicadoresdocentes'), [
            'class' => 'report-indicadores-combined-title',
        ]) .
        html_writer::tag('p', get_string('courseprogress_help', 'report_indicadoresdocentes'), [
            'class' => 'report-indicadores-chart-description',
        ]) .
        $OUTPUT->render($progresschart),
        [
            'class' => 'report-indicadores-combined-chart',
            'data-progress-total' => $completiontotal,
            'data-progress-total-label' => get_string('totalactivities', 'report_indicadoresdocentes'),
        ]
    ),
    'report-indicadores-combined-charts'
) . $currentperformancehtml;
$dashboardtabs = [
    'courseoverview' => [
        'label' => get_string('courseoverview', 'report_indicadoresdocentes'),
        'description' => get_string('courseoverview_help', 'report_indicadoresdocentes'),
        'chart' => $courseoverviewhtml,
    ],
    'daily' => [
        'label' => get_string('accessbyday', 'report_indicadoresdocentes'),
        'description' => get_string('accessbyday_help', 'report_indicadoresdocentes'),
        'chart' => $OUTPUT->render($dailychart),
    ],
    'activityapproval' => [
        'label' => get_string('approvalbyactivity', 'report_indicadoresdocentes'),
        'description' => get_string('approvalbyactivity_help', 'report_indicadoresdocentes'),
        'chart' => $OUTPUT->render($approvalchart) . $attentionapprovalhtml,
    ],
    'deliveries' => [
        'label' => get_string('deliveriesbyactivity', 'report_indicadoresdocentes'),
        'description' => get_string('deliveriesbyactivity_help', 'report_indicadoresdocentes'),
        'chart' => $deliveryhtml . $attentiondeliveryhtml,
    ],
    'views' => [
        'label' => get_string('viewsbyactivity', 'report_indicadoresdocentes'),
        'description' => get_string('viewsbyactivity_help', 'report_indicadoresdocentes'),
        'chart' => $viewcharthtml,
    ],
    'activity' => [
        'label' => get_string('interactionsbyactivity', 'report_indicadoresdocentes'),
        'description' => get_string('interactionsbyactivity_help', 'report_indicadoresdocentes'),
        'chart' => $activitycharthtml,
    ],
    'resources' => [
        'label' => get_string('resourcesconsulted', 'report_indicadoresdocentes'),
        'description' => get_string('resourcesconsulted_help', 'report_indicadoresdocentes'),
        'chart' => $OUTPUT->render($resourcechart),
    ],
];

echo html_writer::start_tag('section', [
    'class' => 'report-indicadores-dashboard',
    'data-region' => 'learning-dashboard',
    'aria-labelledby' => 'report-indicadores-dashboard-title',
]);
echo $OUTPUT->heading(get_string('visualsummary', 'report_indicadoresdocentes'), 2, null,
    'report-indicadores-dashboard-title');
echo html_writer::tag('p', get_string('visualsummary_help', 'report_indicadoresdocentes'), [
    'class' => 'report-indicadores-dashboard-intro',
]);
echo html_writer::start_div('report-indicadores-tabs', ['role' => 'tablist']);
foreach ($dashboardtabs as $key => $tab) {
    $active = $key === 'courseoverview';
    echo html_writer::tag('button', $tab['label'], [
        'type' => 'button',
        'class' => 'report-indicadores-tab' . ($active ? ' is-active' : ''),
        'id' => 'report-indicadores-tab-' . $key,
        'role' => 'tab',
        'aria-selected' => $active ? 'true' : 'false',
        'aria-controls' => 'report-indicadores-panel-' . $key,
        'tabindex' => $active ? '0' : '-1',
        'data-dashboard-tab' => $key,
    ]);
}
echo html_writer::end_div();
foreach ($dashboardtabs as $key => $tab) {
    $active = $key === 'courseoverview';
    echo html_writer::start_div('report-indicadores-chart-panel' . ($active ? ' is-active' : ''), [
        'id' => 'report-indicadores-panel-' . $key,
        'role' => 'tabpanel',
        'aria-labelledby' => 'report-indicadores-tab-' . $key,
        'data-dashboard-panel' => $key,
        'hidden' => $active ? null : 'hidden',
    ]);
    echo html_writer::tag('h3', $tab['label'], ['class' => 'report-indicadores-chart-title']);
    echo html_writer::tag('p', $tab['description'], ['class' => 'report-indicadores-chart-description']);
    echo $tab['chart'];
    echo html_writer::end_div();
}
echo html_writer::end_tag('section');

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
    get_string('studentswhoviewed', 'report_indicadoresdocentes'),
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
        $activity['viewers'] . ' / ' . $studentcount . ' (' . format_float($activity['viewrate'], 1) . '%)',
    ];
}
echo report_indicadoresdocentes_collapsible_table(
    'activities',
    get_string('participationandcompletion', 'report_indicadoresdocentes'),
    get_string('activitytable_help', 'report_indicadoresdocentes'),
    html_writer::table($activitytable),
    ['activityapproval', 'deliveries', 'views', 'activity', 'resources']
);

if (has_capability('report/indicadoresdocentes:viewdetails', $context)) {
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
    echo report_indicadoresdocentes_collapsible_table(
        'students',
        get_string('studentdetail', 'report_indicadoresdocentes'),
        get_string('studenttable_help', 'report_indicadoresdocentes'),
        html_writer::table($studenttable),
        ['daily', 'deliveries']
    );
}

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

/**
 * Builds the priority-attention student table shown inside relevant tabs.
 *
 * @param array $data Dashboard data.
 * @param int $courseid Course id.
 * @param context_course $context Course context.
 * @param string $key Unique table key for the containing tab.
 * @return string
 */
function report_indicadoresdocentes_attention_table(
    array $data,
    int $courseid,
    context_course $context,
    string $key
): string {
    if (!has_capability('report/indicadoresdocentes:viewdetails', $context)) {
        return '';
    }
    $details = $data['studentdetails'];
    uasort($details, static function(array $left, array $right): int {
        $leftdelivery = $left['assignmenttotal'] ? $left['submitted'] / $left['assignmenttotal'] : 1;
        $rightdelivery = $right['assignmenttotal'] ? $right['submitted'] / $right['assignmenttotal'] : 1;
        $leftparticipation = $left['activitytotal'] ? $left['participated'] / $left['activitytotal'] : 1;
        $rightparticipation = $right['activitytotal'] ? $right['participated'] / $right['activitytotal'] : 1;
        return ($leftdelivery <=> $rightdelivery)
            ?: ($leftparticipation <=> $rightparticipation)
            ?: (($left['averagegrade'] ?? -1) <=> ($right['averagegrade'] ?? -1))
            ?: ($left['approved'] <=> $right['approved'])
            ?: ($left['completed'] <=> $right['completed'])
            ?: ($left['interactions'] <=> $right['interactions'])
            ?: strcasecmp(fullname($left['user']), fullname($right['user']));
    });
    $table = new html_table();
    $table->attributes['class'] = 'generaltable report-indicadores-attention';
    $table->head = [
        get_string('priority', 'report_indicadoresdocentes'),
        get_string('student', 'report_indicadoresdocentes'),
        get_string('deliveries', 'report_indicadoresdocentes'),
        get_string('participation', 'report_indicadoresdocentes'),
        get_string('averagegrade', 'report_indicadoresdocentes'),
        get_string('approvedactivities', 'report_indicadoresdocentes'),
        get_string('completedactivities', 'report_indicadoresdocentes'),
        get_string('interactions', 'report_indicadoresdocentes'),
        get_string('pendingactivities', 'report_indicadoresdocentes'),
        get_string('notapprovedactivities', 'report_indicadoresdocentes'),
    ];
    foreach (array_values($details) as $position => $detail) {
        $profileurl = new moodle_url('/user/view.php', ['id' => $detail['user']->id, 'course' => $courseid]);
        $table->data[] = [
            $position + 1,
            html_writer::link($profileurl, fullname($detail['user'])),
            $detail['submitted'] . ' / ' . $detail['assignmenttotal'],
            $detail['participated'] . ' / ' . $detail['activitytotal'],
            $detail['averagegrade'] === null
                ? get_string('ungraded', 'report_indicadoresdocentes')
                : format_float($detail['averagegrade'], 2),
            $detail['approved'],
            $detail['completed'],
            format_float($detail['interactions'], 0),
            $detail['pendingactivities']
                ? implode(html_writer::empty_tag('br'), array_map('s', $detail['pendingactivities']))
                : get_string('none', 'moodle'),
            $detail['notapprovedactivities']
                ? implode(html_writer::empty_tag('br'), array_map('s', $detail['notapprovedactivities']))
                : get_string('none', 'moodle'),
        ];
    }
    return report_indicadoresdocentes_collapsible_table(
        'attention-' . $key,
        get_string('attentionpriority', 'report_indicadoresdocentes'),
        get_string('attentionpriority_help', 'report_indicadoresdocentes'),
        html_writer::table($table)
    );
}

/**
 * Renders a table inside an accessible collapsible section.
 *
 * @param string $key Stable section key.
 * @param string $title Section title.
 * @param string $description Short explanation of the table.
 * @param string $tablehtml Rendered table.
 * @param array $visibletabs Dashboard tabs where the whole section is relevant.
 * @return string
 */
function report_indicadoresdocentes_collapsible_table(
    string $key,
    string $title,
    string $description,
    string $tablehtml,
    array $visibletabs = []
): string {
    $regionid = 'report-indicadores-table-' . $key;
    $button = html_writer::tag('button', get_string('showdata', 'report_indicadoresdocentes'), [
        'type' => 'button',
        'class' => 'btn btn-outline-primary report-indicadores-table-toggle',
        'aria-expanded' => 'false',
        'aria-controls' => $regionid,
        'data-table-toggle' => $key,
        'data-show-label' => get_string('showdata', 'report_indicadoresdocentes'),
        'data-hide-label' => get_string('hidedata', 'report_indicadoresdocentes'),
    ]);
    $header = html_writer::div(
        html_writer::div(
            html_writer::tag('h3', $title, ['class' => 'report-indicadores-table-title']) .
            html_writer::tag('p', $description, ['class' => 'report-indicadores-table-description']),
            'report-indicadores-table-heading'
        ) . $button,
        'report-indicadores-table-header'
    );
    $content = html_writer::div($tablehtml, 'report-indicadores-table-content', [
        'id' => $regionid,
        'data-table-panel' => $key,
        'hidden' => 'hidden',
    ]);

    $attributes = ['class' => 'report-indicadores-table-section'];
    if ($visibletabs) {
        $attributes['data-dashboard-context'] = implode(',', $visibletabs);
        $attributes['hidden'] = in_array('courseoverview', $visibletabs, true) ? null : 'hidden';
    }
    return html_writer::tag('section', $header . $content, $attributes);
}
