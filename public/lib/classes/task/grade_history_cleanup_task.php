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
 * A scheduled task.
 *
 * @package    core
 * @copyright  2019 Simey Lameze <simey@moodle.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
namespace core\task;
defined('MOODLE_INTERNAL') || die();

/**
 * Simple task to clean grade history tables.
 *
 * @copyright  2019 Simey Lameze <simey@moodle.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class grade_history_cleanup_task extends scheduled_task {
    /** @var int Maximum number of records deleted per query, to keep transactions (and replicated changes) small. */
    const DELETE_CHUNK_SIZE = 500;

    /**
     * Get a descriptive name for this task (shown to admins).
     *
     * @return string
     */
    public function get_name() {
        return get_string('taskgradehistorycleanup', 'admin');
    }

    /**
     * Cleanup history tables.
     */
    public function execute() {
        global $CFG;

        if (!empty($CFG->gradehistorylifetime)) {
            $now = \core\di::get(\core\clock::class)->time();
            $histlifetime = $now - ($CFG->gradehistorylifetime * DAYSECS);
            $tables = [
                'grade_outcomes_history',
                'grade_categories_history',
                'grade_items_history',
                'grade_grades_history',
                'scale_history'
            ];
            foreach ($tables as $table) {
                $this->delete_old_records_in_chunks($table, $histlifetime);
                mtrace("Deleted old grade history records from '$table'");
            }
        }
    }

    /**
     * Deletes all records older than the given timestamp from the given table in chunks.
     *
     * @param string $table The history table to clean up.
     * @param int $histlifetime Records with timemodified before this timestamp will be deleted.
     */
    protected function delete_old_records_in_chunks(string $table, int $histlifetime): void {
        global $DB;

        $params = ['histlifetime' => $histlifetime];
        while (
            $boundaries = $DB->get_records_select(
                $table,
                'timemodified < :histlifetime',
                $params,
                'timemodified ASC, id ASC',
                'id, timemodified',
                self::DELETE_CHUNK_SIZE - 1,
                1
            )
        ) {
            $boundary = reset($boundaries);
            $DB->throttled_write(fn() => $DB->delete_records_select(
                $table,
                'timemodified < :boundarytime OR (timemodified = :boundarytimeeq AND id <= :boundaryid)',
                [
                    'boundarytime' => $boundary->timemodified,
                    'boundarytimeeq' => $boundary->timemodified,
                    'boundaryid' => $boundary->id,
                ]
            ));
        }
        // Less than a full chunk is left.
        $DB->throttled_write(fn() => $DB->delete_records_select($table, 'timemodified < :histlifetime', $params));
    }
}
