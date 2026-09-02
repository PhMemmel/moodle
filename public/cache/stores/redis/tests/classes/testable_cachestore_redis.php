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

namespace cachestore_redis\tests;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/cache/stores/redis/lib.php');

/**
 * Testable Redis cache store.
 *
 * Exposes the connection of the store which is otherwise not observable from the outside.
 *
 * @package    cachestore_redis
 * @copyright  2026 ISB Bayern
 * @author     Philipp Memmel
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class testable_cachestore_redis extends \cachestore_redis {
    /**
     * Get the connection which has been established by this store.
     *
     * @return \Redis|\RedisCluster|null The connection, or null if none has been established.
     */
    public function get_redis(): \Redis|\RedisCluster|null {
        return $this->redis;
    }
}
