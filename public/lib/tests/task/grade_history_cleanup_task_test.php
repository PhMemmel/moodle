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

namespace core\task;

use function core\fixtures\not_deprecated_function;

/**
 * Tests for the grade history cleanup task.
 *
 * @package    core
 * @copyright  2026 ISB Bayern
 * @author     Philipp Memmel
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(grade_history_cleanup_task::class)]
final class grade_history_cleanup_task_test extends \advanced_testcase {
    /**
     * Test that old history records are deleted in chunks and recent ones are kept.
     */
    public function test_execute(): void {
        global $CFG, $DB;
        $this->resetAfterTest();

        $clock = $this->mock_clock_with_frozen();
        $now = $clock->time();
        $CFG->gradehistorylifetime = 10;
        $oldtime = $now - 11 * DAYSECS;
        $recenttime = $now - 9 * DAYSECS;

        // More than two chunks of old records, so that multiple delete iterations are needed.
        $oldcount = 2 * grade_history_cleanup_task::DELETE_CHUNK_SIZE + 5;
        $recentcount = 3;
        $records = [];
        for ($i = 0; $i < $oldcount + $recentcount; $i++) {
            $records[] = [
                'oldid' => $i + 1,
                'itemid' => 1,
                'userid' => 1,
                'timemodified' => $i < $oldcount ? $oldtime : $recenttime,
            ];
        }
        $DB->insert_records('grade_grades_history', $records);

        $scalerecord = ['oldid' => 1, 'name' => 'Scale', 'scale' => 'a,b', 'description' => ''];
        $DB->insert_record('scale_history', $scalerecord + ['timemodified' => $oldtime]);
        $DB->insert_record('scale_history', $scalerecord + ['timemodified' => $recenttime]);

        $this->expectOutputRegex("/Deleted old grade history records from 'grade_grades_history'/");
        $task = new grade_history_cleanup_task();
        $task->execute();

        $this->assertEquals(
            0,
            $DB->count_records_select('grade_grades_history', 'timemodified < :t', ['t' => $now - 10 * DAYSECS])
        );
        $this->assertEquals($recentcount, $DB->count_records('grade_grades_history'));
        $this->assertEquals(1, $DB->count_records('scale_history'));
        $this->assertTrue($DB->record_exists('scale_history', ['timemodified' => $recenttime]));
    }

    /**
     * Test that nothing is deleted if no lifetime is configured.
     */
    public function test_execute_without_lifetime(): void {
        global $CFG, $DB;
        $this->resetAfterTest();

        $CFG->gradehistorylifetime = 0;
        $DB->insert_record('grade_grades_history', ['oldid' => 1, 'itemid' => 1, 'userid' => 1, 'timemodified' => 1]);

        $task = new grade_history_cleanup_task();
        $task->execute();

        $this->assertEquals(1, $DB->count_records('grade_grades_history'));
    }
}
