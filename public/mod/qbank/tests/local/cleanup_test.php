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

namespace mod_qbank\local;


use backup;
use backup_controller;
use context_module;
use restore_controller;
use restore_dbops;

/**
 * Test class of the cleanup class of mod_qbank.
 *
 * @package     mod_qbank
 * @copyright   2025 ISB Bayern
 * @author      Philipp Memmel <philipp.memmel@gmail.com>
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class cleanup_test extends \advanced_testcase {

    /**
     * When restoring a course, you can change the start date, which shifts other dates.
     * This test checks that certain dates are correctly modified.
     *
     * @covers \restore_dbops::create_new_course()
     * @return void
     */
    public function test_fix_wrongly_linked_default_categories(): void {
        global $CFG, $DB;
        $this->resetAfterTest();
        // Create the situation caused by the bug in MDL-83000.
        $course = $this->getDataGenerator()->create_course();
        $newcourse = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        $CFG->siteadmins = strval($user->id);
        $qbank = $this->getDataGenerator()->get_plugin_generator('mod_qbank')->create_instance(['course' => $course->id]);
        $context = \context_module::instance($qbank->cmid);
        $questiongenerator = $this->getDataGenerator()->get_plugin_generator('core_question');

        print_r($DB->get_records('question_categories'));
/*
        $bc = new backup_controller(backup::TYPE_1COURSE, $course->id, backup::FORMAT_MOODLE,
                backup::INTERACTIVE_NO, backup::MODE_SAMESITE, $user->id);


        $backupid       = $bc->get_backupid();
        $backupbasepath = $bc->get_plan()->get_basepath();

        $bc->execute_plan();
        $results = $bc->get_results();
        $file = $results['backup_destination'];

        $bc->destroy();

        // Check if we need to unzip the file because the backup temp dir does not contains backup files.
        if (!file_exists($backupbasepath . "/moodle_backup.xml")) {
            $file->extract_to_pathname(get_file_packer('application/vnd.moodle.backup'), $backupbasepath);
        }

        // Create new course.
        $newcourseid = restore_dbops::create_new_course('duplicated course', 'duplicated course', $course->category);
        $rc = new restore_controller($backupid, $newcourseid,
                backup::INTERACTIVE_NO, backup::MODE_SAMESITE, $user->id, backup::TARGET_NEW_COURSE);
        $rc->execute_precheck();
        $rc->execute_plan();
        $rc->destroy();
        print_r($DB->get_records('question_categories'));*/

        $bc = new backup_controller(backup::TYPE_1ACTIVITY, $qbank->cmid, backup::FORMAT_MOODLE,
                backup::INTERACTIVE_NO, backup::MODE_IMPORT, $user->id);
        $backupid = $bc->get_backupid();
        $bc->execute_plan();
        $bc->destroy();

        // Do restore.
        $rc = new restore_controller($backupid, $newcourse->id,
                backup::INTERACTIVE_NO, backup::MODE_IMPORT, $user->id, backup::TARGET_CURRENT_ADDING);
        $this->assertTrue($rc->execute_precheck());
        $rc->execute_plan();

        // Find cmid.
        $tasks = $rc->get_plan()->get_tasks();
        $cmcontext = \context_module::instance($qbank->cmid);
        $newcmid = 0;
        foreach ($tasks as $task) {
            if (is_subclass_of($task, 'restore_activity_task')) {
                if ($task->get_old_contextid() == $cmcontext->id) {
                    $newcmid = $task->get_moduleid();
                    break;
                }
            }
        }
        $rc->destroy();
        print_r($newcmid);
    }
}
