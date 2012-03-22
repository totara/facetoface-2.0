<?php

require_once '../../config.php';
require_once 'lib.php';

global $DB, $OUTPUT;

$id = optional_param('id', 0, PARAM_INT); // Course Module ID
$f = optional_param('f', 0, PARAM_INT); // facetoface ID
$location = optional_param('location', '', PARAM_TEXT); // location
$download = optional_param('download', '', PARAM_ALPHA); // download attendance

if ($id) {
    if (!$cm = $DB->get_record('course_modules', array('id'=>$id))) {
        print_error('error:incorrectcoursemoduleid', 'facetoface');
    }
    if (!$course = $DB->get_record('course', array('id'=>$cm->course))) {
        print_error('error:coursemisconfigured', 'facetoface');
    }
    if (!$facetoface = $DB->get_record('facetoface', array('id'=>$cm->instance))) {
        print_error('error:incorrectcoursemodule', 'facetoface');
    }
}
elseif ($f) {
    if (!$facetoface = $DB->get_record('facetoface', array('id'=>$f))) {
        print_error('error:incorrectfacetofaceid', 'facetoface');
    }
    if (!$course = $DB->get_record('course', array('id'=>$facetoface->course))) {
        print_error('error:coursemisconfigured', 'facetoface');
    }
    if (!$cm = get_coursemodule_from_instance('facetoface', $facetoface->id, $course->id)) {
        print_error('error:incorrectcoursemoduleid', 'facetoface');
    }
}
else {
    print_error('error:mustspecifycoursemodulefacetoface', 'facetoface');
}

$context = get_context_instance(CONTEXT_MODULE, $cm->id);
require_course_login($course,true,$cm);

if (!empty($download)) {
    require_capability('mod/facetoface:viewattendees', $context);
    facetoface_download_attendance($facetoface->name, $facetoface->id, $location, $download);
    exit();
}

require_capability('mod/facetoface:view', $context);

add_to_log($course->id, 'facetoface', 'view', "view.php?id=$cm->id", $facetoface->id, $cm->id);


$PAGE->set_url('/mod/facetoface/view.php', array('id' => $cm->id));
$PAGE->set_context($context);
$PAGE->set_cm($cm);

$title = $course->shortname . ': ' . format_string($facetoface->name);

$PAGE->set_title($title);
$PAGE->set_heading($course->fullname);

$pagetitle = format_string($facetoface->name);

echo $OUTPUT->header();

if (empty($cm->visible) and !has_capability('mod/facetoface:viewemptyactivities', $context)) {
    notice(get_string('activityiscurrentlyhidden'));
}
echo $OUTPUT->box_start();
echo $OUTPUT->heading(get_string('allsessionsin', 'facetoface', $facetoface->name), 2);

if ($facetoface->intro) {
    echo $OUTPUT->box_start('generalbox','description');
    echo format_text($facetoface->intro, $facetoface->introformat);
    echo $OUTPUT->box_end();
}

$locations = get_locations($facetoface->id);
if (count($locations) > 2) {
    echo '<form method="get" action="view.php">';
    echo '<div><input type="hidden" name="f" value="'.$facetoface->id.'"/>';
    echo html_writer::select($locations, 'location', $location, '');
    echo '<input type="submit" value="'.get_string('showbylocation','facetoface').'"/>';
    echo '</div></form>';
}

print_session_list($course->id, $facetoface->id, $location);

if (has_capability('mod/facetoface:viewattendees', $context)) {
    echo $OUTPUT->heading(get_string('exportattendance', 'facetoface'));
    echo '<form method="get" action="view.php">';
    echo '<div><input type="hidden" name="f" value="'.$facetoface->id.'"/>';
    echo get_string('format', 'facetoface') . '&nbsp;';
    $formats = array('excel' => get_string('excelformat', 'facetoface'),
                     'ods' => get_string('odsformat', 'facetoface'));
    echo html_writer::select($formats, 'download', 'excel', '');
    echo '<input type="submit" value="'.get_string('exporttofile','facetoface').'"/>';
    echo '</div></form>';
}

echo $OUTPUT->box_end();
echo $OUTPUT->footer($course);

function print_session_list($courseid, $facetofaceid, $location) {
    global $CFG, $USER, $DB, $OUTPUT;

    $timenow = time();

    $context = get_context_instance(CONTEXT_COURSE, $courseid, $USER->id);
    $viewattendees = has_capability('mod/facetoface:viewattendees', $context);
    $editsessions = has_capability('mod/facetoface:editsessions', $context);

    $bookedsession = null;
    if ($submissions = facetoface_get_user_submissions($facetofaceid, $USER->id)) {
        $submission = array_shift($submissions);
        $bookedsession = $submission;
    }

    $customfields = facetoface_get_session_customfields();

    // Table headers
    $tableheader = array();
    foreach ($customfields as $field) {
        if (!empty($field->showinsummary)) {
            $tableheader[] = format_string($field->name);
        }
    }
    $tableheader[] = get_string('date', 'facetoface');
    $tableheader[] = get_string('time', 'facetoface');
    if ($viewattendees) {
        $tableheader[] = get_string('capacity', 'facetoface');
    }
    else {
        $tableheader[] = get_string('seatsavailable', 'facetoface');
    }
    $tableheader[] = get_string('status', 'facetoface');
    $tableheader[] = get_string('options', 'facetoface');

    $upcomingdata = array();
    $upcomingtbddata = array();
    $previousdata = array();
    $upcomingrowclass = array();
    $upcomingtbdrowclass = array();
    $previousrowclass = array();

    if ($sessions = facetoface_get_sessions($facetofaceid, $location) ) {
        foreach ($sessions as $session) {
            $sessionrow = array();

            $sessionstarted = false;
            $sessionfull = false;
            $sessionwaitlisted = false;
            $isbookedsession = false;

            // Custom fields
            $customdata = $DB->get_records('facetoface_session_data', array('sessionid'=>$session->id), '', 'fieldid, data');
            foreach ($customfields as $field) {
                if (empty($field->showinsummary)) {
                    continue;
                }

                if (empty($customdata[$field->id])) {
                    $sessionrow[] = '&nbsp;';
                }
                else {
                    if (CUSTOMFIELD_TYPE_MULTISELECT == $field->type) {
                        $sessionrow[] = str_replace(CUSTOMFIELD_DELIMITER, '<br />', format_string($customdata[$field->id]->data));
                    } else {
                        $sessionrow[] = format_string($customdata[$field->id]->data);
                    }

                }
            }

            // Dates/times
            $allsessiondates = '';
            $allsessiontimes = '';
            if ($session->datetimeknown) {
                foreach ($session->sessiondates as $date) {
                    if (!empty($allsessiondates)) {
                        $allsessiondates .= '<br/>';
                    }
                    $allsessiondates .= userdate($date->timestart, get_string('strftimedate'));
                    if (!empty($allsessiontimes)) {
                        $allsessiontimes .= '<br/>';
                    }
                    $allsessiontimes .= userdate($date->timestart, get_string('strftimetime')).
                        ' - '.userdate($date->timefinish, get_string('strftimetime'));
                }
            }
            else {
                $allsessiondates = get_string('wait-listed', 'facetoface');
                $allsessiontimes = get_string('wait-listed', 'facetoface');
                $sessionwaitlisted = true;
            }
            $sessionrow[] = $allsessiondates;
            $sessionrow[] = $allsessiontimes;

            // Capacity
            $signupcount = facetoface_get_num_attendees($session->id, MDL_F2F_STATUS_APPROVED);
            $stats = $session->capacity - $signupcount;
            if ($viewattendees){
                $stats = $signupcount.' / '.$session->capacity;
            }
            else {
                $stats = max(0, $stats);
            }
            $sessionrow[] = $stats;

            // Status
            $status  = get_string('bookingopen', 'facetoface');
            if ($session->datetimeknown && facetoface_has_session_started($session, $timenow) && facetoface_is_session_in_progress($session, $timenow)) {
                $status = get_string('sessioninprogress', 'facetoface');
                $sessionstarted = true;
            }
            elseif ($session->datetimeknown && facetoface_has_session_started($session, $timenow)) {
                $status = get_string('sessionover', 'facetoface');
                $sessionstarted = true;
            }
            elseif ($bookedsession && $session->id == $bookedsession->sessionid) {
                $signupstatus = facetoface_get_status($bookedsession->statuscode);

                $status = get_string('status_'.$signupstatus, 'facetoface');
                $isbookedsession = true;
            }
            elseif ($signupcount >= $session->capacity) {
                $status = get_string('bookingfull', 'facetoface');
                $sessionfull = true;
            }

            $sessionrow[] = $status;

            // Options
            $options = '';
            if ($editsessions) {
                $options .= ' <a href="sessions.php?s='.$session->id.'" title="'.get_string('editsession', 'facetoface').'">'
                    . '<img src="'.$OUTPUT->pix_url('t/edit').'" class="iconsmall" alt="'.get_string('edit', 'facetoface').'" /></a> '
                    . '<a href="sessions.php?s='.$session->id.'&amp;c=1" title="'.get_string('copysession', 'facetoface').'">'
                    . '<img src="'.$OUTPUT->pix_url('t/copy').'" class="iconsmall" alt="'.get_string('copy', 'facetoface').'" /></a> '
                    . '<a href="sessions.php?s='.$session->id.'&amp;d=1" title="'.get_string('deletesession', 'facetoface').'">'
                    . '<img src="'.$OUTPUT->pix_url('t/delete').'" class="iconsmall" alt="'.get_string('delete').'" /></a><br />';
            }
            if ($viewattendees){
                $options .= '<a href="attendees.php?s='.$session->id.'&amp;backtoallsessions='.$facetofaceid.'" title="'.get_string('seeattendees', 'facetoface').'">'.get_string('attendees', 'facetoface').'</a><br />';
            }
            if ($isbookedsession) {
                $options .= '<a href="signup.php?s='.$session->id.'&amp;backtoallsessions='.$facetofaceid.'" title="'.get_string('moreinfo', 'facetoface').'">'.get_string('moreinfo', 'facetoface').'</a><br />';

                $options .= '<a href="cancelsignup.php?s='.$session->id.'&amp;backtoallsessions='.$facetofaceid.'" title="'.get_string('cancelbooking', 'facetoface').'">'.get_string('cancelbooking', 'facetoface').'</a>';
            }
            elseif (!$sessionstarted and !$bookedsession) {
                $options .= '<a href="signup.php?s='.$session->id.'&amp;backtoallsessions='.$facetofaceid.'">'.get_string('signup', 'facetoface').'</a>';
            }
            if (empty($options)) {
                $options = get_string('none', 'facetoface');
            }
            $sessionrow[] = $options;

            // Set the CSS class for the row
            $rowclass = '';
            if ($sessionstarted) {
                $rowclass = 'dimmed_text';
            }
            elseif ($isbookedsession) {
                $rowclass = 'highlight';
            }
            elseif ($sessionfull) {
                $rowclass = 'dimmed_text';
            }

            // Put the row in the right table
            if ($sessionstarted) {
                $previousrowclass[] = $rowclass;
                $previousdata[] = $sessionrow;
            }
            elseif ($sessionwaitlisted) {
                $upcomingtbdrowclass[] = $rowclass;
                $upcomingtbddata[] = $sessionrow;
            }
            else { // Normal scheduled session
                $upcomingrowclass[] = $rowclass;
                $upcomingdata[] = $sessionrow;
            }
        }
    }

    // Upcoming sessions
    echo $OUTPUT->heading(get_string('upcomingsessions', 'facetoface'));
    if (empty($upcomingdata) and empty($upcomingtbddata)) {
        print_string('noupcoming', 'facetoface');
    }
    else {
        $upcomingtable = new html_table();
        $upcomingtable->summary = get_string('upcomingsessionslist', 'facetoface');
        $upcomingtable->head = $tableheader;
        $upcomingtable->rowclass = array_merge($upcomingrowclass, $upcomingtbdrowclass);
        $upcomingtable->width = '100%';
        $upcomingtable->data = array_merge($upcomingdata, $upcomingtbddata);
        echo html_writer::table($upcomingtable);
    }

    if ($editsessions) {
        echo '<p><a href="sessions.php?f='.$facetofaceid.'">'.get_string('addsession', 'facetoface').'</a></p>';
    }

    // Previous sessions
    if (!empty($previousdata)) {
        echo $OUTPUT->heading(get_string('previoussessions', 'facetoface'));
        $previoustable = new html_table();
        $previoustable->summary = get_string('previoussessionslist', 'facetoface');
        $previoustable->head = $tableheader;
        $previoustable->rowclass = $previousrowclass;
        $previoustable->width = '100%';
        $previoustable->data = $previousdata;
        echo html_writer::table($previoustable);
    }
}

/**
 * Get facetoface locations
 *
 * @param   interger    $facetofaceid
 * @return  array
 */
function get_locations($facetofaceid) {
    global $CFG, $DB;

    $locationfieldid = $DB->get_field('facetoface_session_field', 'id', array('shortname'=>'location'));
    if (!$locationfieldid) {
        return array();
    }

    $sql = "SELECT DISTINCT d.data AS location
              FROM {facetoface} f
              JOIN {facetoface_sessions} s ON s.facetoface = f.id
              JOIN {facetoface_session_data} d ON d.sessionid = s.id
             WHERE f.id = $facetofaceid AND d.fieldid = $locationfieldid";

    if ($records = $DB->get_records_sql($sql)) {
        $locationmenu[''] = get_string('alllocations', 'facetoface');

        $i=1;
        foreach ($records as $record) {
            $locationmenu[$record->location] = $record->location;
            $i++;
        }

        return $locationmenu;
    }

    return array();
}
