<?php

// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * This file is part of the Database module for Moodle
 *
 * @copyright 2005 Martin Dougiamas  http://dougiamas.com
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @package mod_data
 */

require_once('../../config.php');
require_once('lib.php');
require_once('export_form.php');

// database ID
$d = required_param('d', PARAM_INT);
$exportuser = optional_param('exportuser', false, PARAM_BOOL); // Flag for exporting user details
$exporttime = optional_param('exporttime', false, PARAM_BOOL); // Flag for exporting date/time information
$exportapproval = optional_param('exportapproval', false, PARAM_BOOL); // Flag for exporting user details
$tags = optional_param('exporttags', false, PARAM_BOOL); // Flag for exporting user details.
$redirectbackto = optional_param('backto', '', PARAM_LOCALURL); // The location to redirect back to.

$url = new moodle_url('/mod/data/export.php', array('d' => $d));
$PAGE->set_url($url);

if (! $data = $DB->get_record('data', array('id'=>$d))) {
    throw new \moodle_exception('wrongdataid', 'data');
}

if (! $cm = get_coursemodule_from_instance('data', $data->id, $data->course)) {
    throw new \moodle_exception('invalidcoursemodule');
}

if(! $course = $DB->get_record('course', array('id'=>$cm->course))) {
    throw new \moodle_exception('invalidcourseid');
}

// fill in missing properties needed for updating of instance
$data->course     = $cm->course;
$data->cmidnumber = $cm->idnumber;
$data->instance   = $cm->instance;

$context = context_module::instance($cm->id);

require_login($course, false, $cm);
require_capability(DATA_CAP_EXPORT, $context);

// get fields for this database
$fieldrecords = $DB->get_records('data_fields', array('dataid'=>$data->id), 'id');

if(empty($fieldrecords)) {
    if (has_capability('mod/data:managetemplates', $context)) {
        redirect($CFG->wwwroot.'/mod/data/field.php?d='.$data->id);
    } else {
        throw new \moodle_exception('nofieldindatabase', 'data');
    }
}

// populate objets for this databases fields
$fields = array();
foreach ($fieldrecords as $fieldrecord) {
    $fields[]= data_get_field($fieldrecord, $data);
}

$mform = new mod_data_export_form(new moodle_url('/mod/data/export.php', ['d' => $data->id,
    'backto' => $redirectbackto]), $fields, $cm, $data);

if ($mform->is_cancelled()) {
    $redirectbackto = !empty($redirectbackto) ? $redirectbackto :
        new \moodle_url('/mod/data/view.php', ['d' => $data->id]);
    redirect($redirectbackto);
} else if ($formdata = (array) $mform->get_data()) {

    $selectedfields = array();
    foreach ($formdata as $key => $value) {
        //field form elements are field_1 field_2 etc. 0 if not selected. 1 if selected.
        if (strpos($key, 'field_')===0 && !empty($value)) {
            $selectedfields[] = substr($key, 6);
        }
    }

    $currentgroup = groups_get_activity_group($cm);

    $exportdata = data_get_exportdata($data->id, $fields, $selectedfields, $currentgroup, $context,
        $exportuser, $exporttime, $exportapproval, $tags);
    $count = count($exportdata);

    $fieldswithfileexport = array_filter($fields, fn($field) => $field->file_export_supported()
            && in_array($field->field->id, $selectedfields));

    $exportfiles = !empty($formdata['exportfiles']) && !empty($fieldswithfileexport);

    switch ($formdata['exporttype']) {
        case 'csv':
            $exportfilecontent = data_export_csv($exportdata, $formdata['delimiter_name'], $data->name, $count, $exportfiles);
            break;
        case 'xls':
            // Currently xls export does not support files.
            data_export_xls($exportdata, $data->name, $count);
            break;
        case 'ods':
            $exportfilecontent = data_export_ods($exportdata, $data->name, $count, $exportfiles);
            break;
    }

    // We will generate a zip archive to download, so we inform the moodleform that the download already has been
    // done for it to continue to work after the zip download.
    \core_form\util::form_download_complete();
    $filename = $data->name . '-' . $count . '-record';
    if ($count > 1) {
        $filename .= 's';
    }
    $zipwriter = \core_files\archive_writer::get_stream_writer($filename . '.zip',
        \core_files\archive_writer::ZIP_WRITER);

    foreach ($fieldswithfileexport as $field) {
        $records = $DB->get_records('data_content', ['fieldid' => $field->field->id], '', 'recordid');
        foreach ($records as $record) {
            $file = $field->export_file_value($record->recordid);
            $pathinzip = '/files/';
            if (empty($formdata['keeporiginalfilenames'])) {
                $pathinzip .= $record->recordid . '_' . $field->field->id . '_';
            }
            $pathinzip .= $file->get_filename();
            $zipwriter->add_file_from_stored_file($pathinzip, $file);
        }
    }
    $zipwriter->add_file_from_string($filename . '.' . $formdata['exporttype'], $exportfilecontent);
    $zipwriter->finish();
    exit(0);
}

// Build header to match the rest of the UI.
$PAGE->add_body_class('mediumwidth');
$PAGE->set_title($data->name);
$PAGE->set_heading($course->fullname);
$PAGE->force_settings_menu(true);
$PAGE->set_secondary_active_tab('modulepage');
$PAGE->activityheader->disable();
echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('exportentries', 'data'));

groups_print_activity_menu($cm, $url);

$mform->display();

echo $OUTPUT->footer();

die();
