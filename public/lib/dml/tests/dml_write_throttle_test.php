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

namespace core;

/**
 * Tests for the write throttling of \moodle_database.
 *
 * @package    core
 * @copyright  2026 ISB Bayern
 * @author     Philipp Memmel
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\moodle_database::class)]
final class dml_write_throttle_test extends \advanced_testcase {
    /**
     * Connects a second database instance with the given additional dboptions.
     *
     * @param array $options Additional dboptions.
     * @return \moodle_database The connected database instance, to be disposed by the caller.
     */
    private function connect_with_options(array $options): \moodle_database {
        global $DB;

        $cfg = $DB->export_dbconfig();
        $db = \moodle_database::get_driver_instance($cfg->dbtype, $cfg->dblibrary);
        $db->connect(
            $cfg->dbhost,
            $cfg->dbuser,
            $cfg->dbpass,
            $cfg->dbname,
            $cfg->prefix,
            array_merge($cfg->dboptions ?? [], $options),
        );
        return $db;
    }

    /**
     * Test the pause calculation, including the disabled state and the cap.
     *
     * @param array $options The write slowdown dboptions.
     * @param float $duration Duration of the last write in seconds.
     * @param float $expected Expected pause in seconds.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('pause_after_write_provider')]
    public function test_pause_after_write(array $options, float $duration, float $expected): void {
        $db = $this->connect_with_options($options);

        $start = hrtime(true);
        $pause = $db->pause_after_write($duration);
        $elapsed = (hrtime(true) - $start) / 1e9;

        $this->assertEqualsWithDelta($expected, $pause, 0.0001);
        // The sleep is never shorter than requested; no upper bound to keep the test stable on slow machines.
        $this->assertGreaterThanOrEqual($expected - 0.001, $elapsed);

        $db->dispose();
    }

    /**
     * Data provider for {@see self::test_pause_after_write()}.
     *
     * @return array Datasets with dboptions, duration of the last write and expected pause.
     */
    public static function pause_after_write_provider(): array {
        return [
            'disabled_by_default' => ['options' => [], 'duration' => 0.01, 'expected' => 0.0],
            'factor_zero' => ['options' => ['writeslowdownfactor' => 0], 'duration' => 0.01, 'expected' => 0.0],
            'factor_applied' => ['options' => ['writeslowdownfactor' => 2], 'duration' => 0.01, 'expected' => 0.02],
            'capped_by_max' => [
                'options' => ['writeslowdownfactor' => 10, 'writeslowdownmax' => 0.03],
                'duration' => 1.0,
                'expected' => 0.03,
            ],
        ];
    }

    /**
     * Test that the write is executed, its result returned and the pause is proportional to the write duration.
     */
    public function test_throttled_write(): void {
        $db = $this->connect_with_options(['writeslowdownfactor' => 2]);

        $called = 0;
        $start = hrtime(true);
        $result = $db->throttled_write(function () use (&$called): string {
            $called++;
            usleep(20000); // Simulates a write taking 0.02 seconds.
            return 'result';
        });
        $elapsed = (hrtime(true) - $start) / 1e9;

        $this->assertSame(1, $called);
        $this->assertSame('result', $result);
        // Write (0.02 s) plus pause (factor 2 * 0.02 s).
        $this->assertGreaterThanOrEqual(0.06 - 0.001, $elapsed);

        $db->dispose();
    }
}
