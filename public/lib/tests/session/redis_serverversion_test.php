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

namespace core\session;

use core\tests\session\testable_redis_handler;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\Attributes\DataProvider;
use RedisException;

/**
 * Unit tests for the server version detection and the ping of the Redis session handler.
 *
 * In contrast to {@see redis_test} these tests do not require a Redis server, the connection is mocked.
 *
 * @package   core
 * @copyright 2026 ISB Bayern
 * @author    Philipp Memmel
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversMethod(redis::class, 'get_server_version')]
#[CoversMethod(redis::class, 'check_environment')]
#[CoversMethod(redis::class, 'ping_server')]
final class redis_serverversion_test extends \advanced_testcase {
    /** @var string The server version reported by the mocked Redis connection. */
    private const REPORTED_VERSION = '7.4.0';

    #[\Override]
    public function setUp(): void {
        parent::setUp();

        if (!extension_loaded('redis')) {
            $this->markTestSkipped('Redis extension not loaded.');
        }
    }

    /**
     * Test that the server version is determined using the INFO command.
     *
     * @param bool $infosupported Whether the server supports the INFO command.
     * @param string $expected The expected server version.
     */
    #[DataProvider('get_server_version_provider')]
    public function test_get_server_version(bool $infosupported, string $expected): void {
        $connection = $this->createMock(\Redis::class);
        $info = $connection->expects($this->once())->method('info')->with('server');
        if ($infosupported) {
            $info->willReturn(['redis_version' => self::REPORTED_VERSION]);
        } else {
            // Some proxies e.g envoy or twemproxy lack support of the INFO command.
            $info->willThrowException(new RedisException('Unknown command INFO'));
        }

        $handler = \core\di::get(testable_redis_handler::class);
        $handler->set_connection($connection);

        $this->assertSame($expected, $handler->get_server_version());
    }

    /**
     * Data provider for {@see test_get_server_version()}.
     *
     * @return array[]
     */
    public static function get_server_version_provider(): array {
        return [
            'info_supported' => [
                'infosupported' => true,
                'expected' => self::REPORTED_VERSION,
            ],
            'info_not_supported' => [
                'infosupported' => false,
                'expected' => redis::REDIS_MIN_SERVER_VERSION,
            ],
        ];
    }

    /**
     * Test that in cluster mode the section is passed as second parameter, as the first one determines the target node.
     */
    public function test_get_server_version_in_cluster_mode(): void {
        $connection = $this->createMock(\RedisCluster::class);
        $connection->expects($this->once())
            ->method('info')
            ->with('serverversion', 'server')
            ->willReturn(['redis_version' => self::REPORTED_VERSION]);

        $handler = \core\di::get(testable_redis_handler::class);
        $handler->set_clustermode(true);
        $handler->set_connection($connection);

        $this->assertSame(self::REPORTED_VERSION, $handler->get_server_version());
    }

    /**
     * Test that the ping is not sent to the same node of the cluster on every connection.
     *
     * The PING command has no key phpredis could determine the target node from, so it expects an additional argument
     * which is hashed like a key to select the node. Passing a constant value there would send the ping of every
     * single connection to the very same node of the cluster.
     */
    public function test_ping_server_in_cluster_mode(): void {
        // Collect the values phpredis would hash to determine the node the PING command is sent to.
        $hashedvalues = [];
        $connection = $this->createMock(\RedisCluster::class);
        $connection->method('ping')
            ->willReturnCallback(function (string $hashedvalue) use (&$hashedvalues): bool {
                $hashedvalues[$hashedvalue] = true;
                return true;
            });

        $handler = \core\di::get(testable_redis_handler::class);
        $handler->set_clustermode(true);
        $handler->set_connection($connection);

        // Stop as soon as a second value has been hashed, the maximum number of runs just limits the runtime in case
        // the same value is used over and over again.
        $maxruns = 100;
        $runs = 0;
        while (count($hashedvalues) < 2 && $runs < $maxruns) {
            $this->assertTrue($handler->call_ping_server());
            $runs++;
        }

        $this->assertGreaterThan(1, count($hashedvalues), 'The PING command is always routed to the same node.');
    }

    /**
     * Test the environment check comparing the server version to the minimum required one.
     *
     * @param string $serverversion The version reported by the server.
     * @param bool $expectedstatus The expected status of the environment check.
     */
    #[DataProvider('check_environment_provider')]
    public function test_check_environment(string $serverversion, bool $expectedstatus): void {
        global $CFG;

        require_once($CFG->libdir . '/environmentlib.php');

        $connection = $this->createMock(\Redis::class);
        $connection->method('info')->with('server')->willReturn(['redis_version' => $serverversion]);

        $handler = \core\di::get(testable_redis_handler::class);
        $handler->set_connection($connection);

        $result = $handler->check_environment(new \environment_results('custom_check'));

        $this->assertInstanceOf(\environment_results::class, $result);
        $this->assertSame($expectedstatus, $result->getStatus());
        $this->assertSame($serverversion, $result->getCurrentVersion());
        $this->assertSame(redis::REDIS_MIN_SERVER_VERSION, $result->getNeededVersion());
    }

    /**
     * Data provider for {@see test_check_environment()}.
     *
     * @return array[]
     */
    public static function check_environment_provider(): array {
        return [
            'supported_version' => [
                'serverversion' => self::REPORTED_VERSION,
                'expectedstatus' => true,
            ],
            'minimum_version' => [
                'serverversion' => redis::REDIS_MIN_SERVER_VERSION,
                'expectedstatus' => true,
            ],
            'outdated_version' => [
                'serverversion' => '4.0.14',
                'expectedstatus' => false,
            ],
        ];
    }
}
