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

namespace core_badges;

use award_criteria;
use award_criteria_activity;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/badgeslib.php');
require_once($CFG->libdir . '/completionlib.php');
require_once($CFG->dirroot . '/badges/criteria/award_criteria_activity.php');

/**
 * Tests for the activity completion badge criteria.
 *
 * @package    core_badges
 * @copyright  2026 ISB Bayern
 * @author     Philipp Memmel
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(award_criteria_activity::class)]
final class award_criteria_activity_test extends \advanced_testcase {
    /**
     * Data provider for test_get_completed_criteria_sql.
     *
     * @return array Datasets with aggregation method, completion states per user and module, and expected candidates.
     */
    public static function get_completed_criteria_sql_provider(): array {
        return [
            'all_nobody_completed' => [
                'method' => BADGE_CRITERIA_AGGREGATION_ALL,
                'completions' => [],
                'expected' => [],
            ],
            'all_only_some_completed' => [
                'method' => BADGE_CRITERIA_AGGREGATION_ALL,
                'completions' => [
                    'user1' => ['page1' => COMPLETION_COMPLETE],
                    'user2' => ['page1' => COMPLETION_COMPLETE, 'page2' => COMPLETION_INCOMPLETE],
                ],
                'expected' => [],
            ],
            'all_completed' => [
                'method' => BADGE_CRITERIA_AGGREGATION_ALL,
                'completions' => [
                    'user1' => ['page1' => COMPLETION_COMPLETE, 'page2' => COMPLETION_COMPLETE_PASS],
                    'user2' => ['page1' => COMPLETION_COMPLETE],
                    'user3' => ['page1' => COMPLETION_COMPLETE_FAIL, 'page2' => COMPLETION_COMPLETE],
                ],
                'expected' => ['user1', 'user3'],
            ],
            'any_nobody_completed' => [
                'method' => BADGE_CRITERIA_AGGREGATION_ANY,
                'completions' => [],
                'expected' => [],
            ],
            'any_completed' => [
                'method' => BADGE_CRITERIA_AGGREGATION_ANY,
                'completions' => [
                    'user1' => ['page1' => COMPLETION_COMPLETE],
                    'user2' => ['page2' => COMPLETION_INCOMPLETE],
                ],
                'expected' => ['user1'],
            ],
        ];
    }

    /**
     * Test that get_completed_criteria_sql() only returns users who meet the criteria.
     *
     * @param int $method Aggregation method of the criteria.
     * @param array $completions Completion states per user and module.
     * @param array $expected Users expected to be candidates.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('get_completed_criteria_sql_provider')]
    public function test_get_completed_criteria_sql(int $method, array $completions, array $expected): void {
        global $DB;
        $this->resetAfterTest();

        $generator = $this->getDataGenerator();
        $course = $generator->create_course(['enablecompletion' => COMPLETION_ENABLED]);
        $cmids = [];
        foreach (['page1', 'page2'] as $name) {
            $cmids[$name] = $generator->create_module(
                'page',
                ['course' => $course->id],
                ['completion' => COMPLETION_TRACKING_MANUAL],
            )->cmid;
        }
        $userids = [];
        foreach (['user1', 'user2', 'user3'] as $name) {
            $userids[$name] = $generator->create_and_enrol($course, 'student')->id;
        }
        foreach ($completions as $username => $states) {
            foreach ($states as $pagename => $state) {
                $DB->insert_record('course_modules_completion', [
                    'coursemoduleid' => $cmids[$pagename],
                    'userid' => $userids[$username],
                    'completionstate' => $state,
                    'timemodified' => time(),
                ]);
            }
        }

        /** @var \core_badges_generator $badgegenerator */
        $badgegenerator = $generator->get_plugin_generator('core_badges');
        $badge = $badgegenerator->create_badge(['type' => BADGE_TYPE_COURSE, 'courseid' => $course->id]);
        award_criteria::build(['criteriatype' => BADGE_CRITERIA_TYPE_ACTIVITY, 'badgeid' => $badge->id])->save([
            'agg' => $method,
            'module_' . $cmids['page1'] => $cmids['page1'],
            'module_' . $cmids['page2'] => $cmids['page2'],
        ]);
        $criteria = (new badge($badge->id))->criteria[BADGE_CRITERIA_TYPE_ACTIVITY];

        [$join, $where, $params] = $criteria->get_completed_criteria_sql();
        $candidates = $DB->get_fieldset_sql("SELECT DISTINCT u.id FROM {user} u {$join} WHERE u.deleted = 0 {$where}", $params);

        $expectedids = array_map(fn(string $username): int => $userids[$username], $expected);
        sort($expectedids);
        $candidates = array_map('intval', $candidates);
        sort($candidates);
        $this->assertSame($expectedids, $candidates);
    }
}
