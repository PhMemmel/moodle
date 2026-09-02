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

namespace core\tests\session;

/**
 * Testable Redis session handler.
 *
 * Exposes the internal state of the handler which is otherwise not observable from the outside.
 *
 * @package    core
 * @copyright  2026 ISB Bayern
 * @author     Philipp Memmel
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class testable_redis extends \core\session\redis {
    /**
     * Get the connection which has been established by this handler.
     *
     * @return \Redis|\RedisCluster|null The connection, or null if none has been established yet.
     */
    public function get_connection(): \Redis|\RedisCluster|null {
        return $this->connection;
    }

    /**
     * Whether this handler has been configured to use a persistent connection.
     *
     * @return bool True if persistent connections are being used.
     */
    public function is_persistent(): bool {
        return $this->persistent;
    }

    /**
     * Get the persistent connection ID this handler has been configured with.
     *
     * @return string|null The persistent connection ID, or null if none has been configured.
     */
    public function get_persistentid(): ?string {
        return $this->persistentid;
    }
}
