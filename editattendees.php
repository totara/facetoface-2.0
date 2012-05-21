<?php
require_once '../../config.php';
require_once 'lib.php';

global $DB, $THEME;

define('MAX_USERS_PER_PAGE', 5000);

$s              = required_param('s', PARAM_INT); // facetoface session ID
$add            = optional_param('add', 0, PARAM_BOOL);
$remove         = optional_param('remove', 0, PARAM_BOOL);
$showall        = optional_param('showall', 0, PARAM_BOOL);
$searchtext     = optional_param('searchtext', '', PARAM_CLEAN); // search string
$suppressemail  = optional_param('suppressemail', false, PARAM_BOOL); // send email notifications
$previoussearch = optional_param('previoussearch', 0, PARAM_BOOL);
$backtoallsessions = optional_param('backtoallsessions', 0, PARAM_INT); // facetoface activity to go back to

if (!$session = facetoface_get_session($s)) {
    print_error('error:incorrectcoursemodulesession', 'facetoface');
}
if (!$facetoface = $DB->get_record('facetoface', array('id'=>$session->facetoface))) {
    print_error('error:incorrectfacetofaceid', 'facetoface');
}
if (!$course = $DB->get_record('course', array('id'=>$facetoface->course))) {
    print_error('error:coursemisconfigured', 'facetoface');
}
if (!$cm = get_coursemodule_from_instance('facetoface', $facetoface->id, $course->id)) {
    print_error('error:incorrectcoursemodule', 'facetoface');
}

/// Check essential permissions
require_course_login($course);
$context = context_course::instance($course->id);
require_capability('mod/facetoface:viewattendees', $context);

/// Get some language strings
$strsearch = get_string('search');
$strshowall = get_string('showall');
$strsearchresults = get_string('searchresults');
$strfacetofaces = get_string('modulenameplural', 'facetoface');
$strfacetoface = get_string('modulename', 'facetoface');

$errors = array();

/// Handle the POST actions sent to the page
if ($frm = data_submitted()) {

    // Add button
    if ($add and !empty($frm->addselect) and confirm_sesskey()) {
        require_capability('mod/facetoface:addattendees', $context);

        foreach ($frm->addselect as $adduser) {
            if (!$adduser = clean_param($adduser, PARAM_INT)) {
                continue; // invalid userid
            }

            // Make sure that the user is enroled in the course
            if (!has_capability('moodle/course:view', $context, $adduser)) {
                $user = $DB->get_record('user', array('id'=>$adduser));

                if (!enrol_try_internal_enrol($course->id, $user->id)) {
                    $errors[] = get_string('error:enrolmentfailed', 'facetoface', fullname($user));
                    $errors[] = get_string('error:addattendee', 'facetoface', fullname($user));
                    continue; // don't sign the user up
                }
            }

            if (facetoface_get_user_submissions($facetoface->id, $adduser)) {
                $erruser = $DB->get_record('user', array('id'=>$adduser),'id, firstname, lastname');
                $errors[] = get_string('error:addalreadysignedupattendee', 'facetoface', fullname($erruser));
            }
            else {
                if (!facetoface_session_has_capacity($session, $context)) {
                    $errors[] = get_string('full', 'facetoface');
                    break; // no point in trying to add other people
                }

                // Check if we are waitlisting or booking
                if ($session->datetimeknown) {
                    $status = MDL_F2F_STATUS_BOOKED;
                } else {
                    $status = MDL_F2F_STATUS_WAITLISTED;
                }

                if (!facetoface_user_signup($session, $facetoface, $course, '', MDL_F2F_BOTH,
                                                $status, $adduser, !$suppressemail)) {
                    $erruser = $DB->get_record('user', array('id'=>$adduser),'id, firstname, lastname');
                    $errors[] = get_string('error:addattendee', 'facetoface', fullname($erruser));
                }
            }
        }
    }
    // Remove button
    else if ($remove and !empty($frm->removeselect) and confirm_sesskey()) {
        require_capability('mod/facetoface:removeattendees', $context);

        foreach ($frm->removeselect as $removeuser) {
            if (!$removeuser = clean_param($removeuser, PARAM_INT)) {
                continue; // invalid userid
            }

            if (facetoface_user_cancel($session, $removeuser, true, $cancelerr)) {
                // Notify the user of the cancellation if the session hasn't started yet
                $timenow = time();
                if (!$suppressemail and !facetoface_has_session_started($session, $timenow)) {
                    facetoface_send_cancellation_notice($facetoface, $session, $removeuser);
                }
            }
            else {
                $errors[] = $cancelerr;
                $erruser = $DB->get_record('user', array('id'=>$removeuser),'id, firstname, lastname');
                $errors[] = get_string('error:removeattendee', 'facetoface', fullname($erruser));
            }
        }

        // Update attendees
        facetoface_update_attendees($session);
    }
    // "Show All" button
    elseif ($showall) {
        $searchtext = '';
        $previoussearch = 0;
    }
}

/// Main page
$pagetitle = format_string($facetoface->name);

$PAGE->set_cm($cm);
$PAGE->set_url('/mod/facetoface/editattendees.php', array('s' => $s, 'backtoallsessions' => $backtoallsessions));

$PAGE->set_title($pagetitle);
$PAGE->set_heading($course->fullname);
echo $OUTPUT->header();


echo $OUTPUT->box_start();
echo $OUTPUT->heading(get_string('addremoveattendees', 'facetoface'));

/// Get the list of currently signed-up users
$existingusers = facetoface_get_attendees($session->id);
$existingcount = $existingusers ? count($existingusers) : 0;

$select  = "username <> 'guest' AND deleted = 0 AND confirmed = 1";

/// Apply search terms
$searchtext = trim($searchtext);
if ($searchtext !== '') {   // Search for a subset of remaining users
    $LIKE      = $DB->sql_ilike();
    $FULLNAME  = $DB->sql_fullname();

    $fullname_like = $DB->sql_like($LIKE, $searchtext, false);
    $email_like = $DB->sql_like($LIKE, $searchtext, false);
    $idnumber_like = $DB->sql_like($LIKE, $searchtext, false);
    $username_like = $DB->sql_like($LIKE, $searchtext, false);

    $selectsql = " AND ($fullname_like OR $email_like OR $idnumber_like OR $username_like) ";
    $select  .= $selectsql;
}

/// All non-signed up system users
$availableusers = $DB->get_recordset_sql('SELECT id, firstname, lastname, email
                                       FROM {user}
                                      WHERE ' . $select .
                                        ' AND id NOT IN
                                          (
                                            SELECT u.id
                                              FROM {facetoface_signups} s
                                              JOIN {facetoface_signups_status} ss ON s.id = ss.signupid
                                              JOIN {user} u ON u.id=s.userid
                                             WHERE s.sessionid = ?
                                               AND ss.statuscode >= ?
                                               AND ss.superceded = 0
                                          )
                                          ORDER BY lastname ASC, firstname ASC', array($session->id, MDL_F2F_STATUS_BOOKED));

$usercount = $DB->count_records_select('user', $select) - $existingcount;


// Get all signed up non-attendees
$nonattendees = 0;
$nonattendees_rs = $DB->get_recordset_sql(
    "
        SELECT
            u.id,
            u.firstname,
            u.lastname,
            u.email,
            ss.statuscode
        FROM
            {facetoface_sessions} s
        JOIN
            {facetoface_signups} su
         ON s.id = su.sessionid
        JOIN
            {facetoface_signups_status} ss
         ON su.id = ss.signupid
        JOIN
            {user} u
         ON u.id = su.userid
        WHERE
            s.id = ?
        AND ss.superceded != 1
        AND ss.statuscode = ?
        ORDER BY
            u.lastname, u.firstname", array($session->id, MDL_F2F_STATUS_REQUESTED)
        );

$table = new html_table();
$table->head = array(get_string('name'), get_string('email'), get_string('status'));
$table->align = array('left');
$table->size = array('50%');
$table->width = '70%';

foreach ($nonattendees_rs as $user) {
    $data = array();
    $data[] = fullname($user);
    $data[] = $user->email;
    $data[] = get_string('status_'.facetoface_get_status($user->statuscode), 'facetoface');

    $table->data[] = $data;
    $nonattendees++;
}


/// Prints a form to add/remove users from the session
include('editattendees.html');

$nonattendees_rs->close();

if (!empty($errors)) {
    $msg = '<p>';
    foreach ($errors as $e) {
        $msg .= $e . html_writer::empty_tag('br');
    }
    $msg .= '</p>';
    echo $OUTPUT->box_start('center');
    echo $OUTPUT->notification($msg);
    echo $OUTPUT->box_end();
}

// Bottom of the page links
echo '<p style="text-align: center">';
$url = $CFG->wwwroot.'/mod/facetoface/attendees.php?s='.$session->id.'&amp;backtoallsessions='.$backtoallsessions;
echo '<a href="'.$url.'">'.get_string('goback', 'facetoface').'</a></p>';

echo $OUTPUT->box_end();
echo $OUTPUT->footer($course);
