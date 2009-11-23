<?php

require_once '../../config.php';
require_once 'lib.php';

$id = optional_param('id', 0, PARAM_INT); // Course Module ID
$f = optional_param('f', 0, PARAM_INT); // facetoface ID
$location = optional_param('location', '', PARAM_TEXT); // location
$download = optional_param('download', '', PARAM_ALPHA); // download attendance

if ($id) {
    if (!$cm = get_record('course_modules', 'id', $id)) {
        print_error('error:incorrectcoursemoduleid', 'facetoface');
    }
    if (!$course = get_record('course', 'id', $cm->course)) {
        print_error('error:coursemisconfigured', 'facetoface');
    }
    if (!$facetoface = get_record('facetoface', 'id', $cm->instance)) {
        print_error('error:incorrectcoursemodule', 'facetoface');
    }
}
elseif ($f) {
    if (!$facetoface = get_record('facetoface', 'id', $f)) {
        print_error('error:incorrectfacetofaceid', 'facetoface');
    }
    if (!$course = get_record('course', 'id', $facetoface->course)) {
        print_error('error:coursemisconfigured', 'facetoface');
    }
    if (!$cm = get_coursemodule_from_instance('facetoface', $facetoface->id, $course->id)) {
        print_error('error:incorrectcoursemoduleid', 'facetoface');
    }
}
else {
    print_error('error:mustspecifycoursemodulefacetoface', 'facetoface');
}

if (empty($form->location)) {
    $form->location = '';
}

$location = '';

$context = get_context_instance(CONTEXT_MODULE, $cm->id);

if ($form = data_submitted()) {
    if (!confirm_sesskey()) {
        print_error('confirmsesskeybad', 'error');
    }

    $location = $form->location;
    if (!empty($form->download)) {
        require_capability('mod/facetoface:viewattendees', $context);
        facetoface_download_attendance($facetoface->name, $facetoface->id, $location, $download);
        exit();
    }
}

require_course_login($course);
require_capability('mod/facetoface:view', $context);

add_to_log($course->id, 'facetoface', 'view', "view.php?id=$cm->id", $facetoface->id, $cm->id);

$pagetitle = format_string($facetoface->name);
$navlinks[] = array('name' => get_string('modulenameplural', 'facetoface'), 'link' => "index.php?id=$course->id", 'type' => 'title');
$navlinks[] = array('name' => $pagetitle, 'link' => '', 'type' => 'activityinstance');
$navigation = build_navigation($navlinks);
print_header_simple($pagetitle, '', $navigation, '', '', true,
                    update_module_button($cm->id, $course->id, get_string('modulename', 'facetoface')), navmenu($course, $cm));

if (empty($cm->visible) and !has_capability('mod/facetoface:viewemptyactivities', $context)) {
    notice(get_string('activityiscurrentlyhidden'));
}

print_box_start();
print_heading(get_string('allsessionsin', 'facetoface', $facetoface->name), "center");

require('view.html');

facetoface_print_sessions($course->id, $facetoface->id, $location);

if (has_capability('mod/facetoface:viewattendees', $context)) {
    print_heading(get_string('exportattendance', 'facetoface'), "center");
    require('view_download.html');
}

print_box_end();
print_footer($course);
