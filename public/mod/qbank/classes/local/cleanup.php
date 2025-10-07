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

/**
 * A class providing cleanups for mod_qbank.
 *
 * @package     mod_qbank
 * @copyright   2025 ISB Bayern
 * @author      Philipp Memmel <philipp.memmel@gmail.com>
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class cleanup {
    public function fix_wrongly_linked_default_categories(): void {
        global $DB;
        $sql = "SELECT dc.* FROM {question_categories} dc JOIN {question_categories} tc ON dc.parent = tc.id AND dc.contextid <> tc.contextid WHERE tc.parent = 0";
        $rs = $DB->get_recordset_sql($sql);
        foreach ($rs as $record) {
            $contextid = $record->contextid;
            $correcttopcat = $DB->get_record('question_categories', ['contextid' => $contextid, 'parent' => 0]);
            $record->parent = $correcttopcat->id;
            $DB->update_record('question_categories', $record);
        }
        $rs->close();
    }
}
