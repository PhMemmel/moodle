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

use core\session\redis;

/**
 * Testable version of the Redis session handler.
 *
 * It allows to inject a connection into the handler.
 *
 * @package    core
 * @copyright  2026 ISB Bayern
 * @author     Philipp Memmel
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class testable_redis_handler extends redis {
    /**
     * Set the connection which is used by the handler.
     *
     * @param \Redis|\RedisCluster|null $connection The connection to use.
     */
    public function set_connection(\Redis|\RedisCluster|null $connection): void {
        $this->connection = $connection;
    }

    /**
     * Set whether the handler operates in cluster mode.
     *
     * @param bool $clustermode True, if the handler should operate in cluster mode.
     */
    public function set_clustermode(bool $clustermode): void {
        $this->clustermode = $clustermode;
    }

    /**
     * Call the protected method sending a PING command to the server.
     *
     * @return bool True, if the server responded to the ping.
     */
    public function call_ping_server(): bool {
        return $this->ping_server();
    }
}
