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
 * Unit tests for the server version detection of the Redis session handler.
 *
 * In contrast to {@see redis_test} these tests do not require a Redis server, the connection is mocked.
 *
 * @package   core
 * @copyright 2026 ISB Bayern
 * @author    Philipp Memmel
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversMethod(redis::class, 'get_server_version')]
final class redis_serverversion_test extends \advanced_testcase {
    /** @var string The server version reported by the mocked Redis connection. */
    private const REPORTED_VERSION = '7.4.0';

    /** @var string The server version configured via $CFG->session_redis_version. */
    private const CONFIGURED_VERSION = '7.2.0';

    #[\Override]
    public function setUp(): void {
        parent::setUp();

        if (!extension_loaded('redis')) {
            $this->markTestSkipped('Redis extension not loaded.');
        }
    }

    /**
     * Test that the INFO command is only sent to the server when the server version is not configured.
     *
     * @param string|null $configuredversion The value of $CFG->session_redis_version, null if it is not configured.
     * @param bool $encrypt Whether the connection to the server is encrypted using TLS.
     * @param bool $infosupported Whether the server supports the INFO command.
     * @param bool $expectinfo Whether the INFO command is expected to be sent to the server.
     * @param string $expected The expected server version.
     */
    #[DataProvider('get_server_version_provider')]
    public function test_get_server_version(
        ?string $configuredversion,
        bool $encrypt,
        bool $infosupported,
        bool $expectinfo,
        string $expected,
    ): void {
        global $CFG;

        $this->resetAfterTest();

        if ($configuredversion !== null) {
            $CFG->session_redis_version = $configuredversion;
        }

        $connection = $this->createMock(\Redis::class);
        if ($expectinfo) {
            $info = $connection->expects($this->once())->method('info')->with('server');
            if ($infosupported) {
                $info->willReturn(['redis_version' => self::REPORTED_VERSION]);
            } else {
                // Some proxies e.g envoy or twemproxy lack support of the INFO command.
                $info->willThrowException(new RedisException('Unknown command INFO'));
            }
        } else {
            $connection->expects($this->never())->method('info');
        }

        $handler = \core\di::get(testable_redis_handler::class);
        $handler->set_connection($connection);

        $this->assertSame($expected, $handler->call_get_server_version($encrypt));
    }

    /**
     * Data provider for {@see test_get_server_version()}.
     *
     * @return array[]
     */
    public static function get_server_version_provider(): array {
        return [
            'configured_version' => [
                'configuredversion' => self::CONFIGURED_VERSION,
                'encrypt' => false,
                'infosupported' => true,
                'expectinfo' => false,
                'expected' => self::CONFIGURED_VERSION,
            ],
            'configured_version_with_tls' => [
                'configuredversion' => self::CONFIGURED_VERSION,
                'encrypt' => true,
                'infosupported' => true,
                'expectinfo' => true,
                'expected' => self::REPORTED_VERSION,
            ],
            'no_configured_version' => [
                'configuredversion' => null,
                'encrypt' => false,
                'infosupported' => true,
                'expectinfo' => true,
                'expected' => self::REPORTED_VERSION,
            ],
            'no_configured_version_without_info_support' => [
                'configuredversion' => null,
                'encrypt' => false,
                'infosupported' => false,
                'expectinfo' => true,
                'expected' => redis::REDIS_MIN_SERVER_VERSION,
            ],
        ];
    }

    /**
     * Test that in cluster mode the INFO command is not always routed to the same node.
     *
     * The INFO command has no key phpredis could determine the target node from, so it expects an additional first
     * argument which is hashed like a key to select the node. Passing a constant value there would send the version
     * check of every single connection to the very same node of the cluster.
     */
    public function test_get_server_version_cluster(): void {
        global $CFG;

        $this->resetAfterTest();

        // Multiple hosts enable the cluster mode of the handler.
        $CFG->session_redis_host = implode(',', ['127.0.0.1:7000', '127.0.0.1:7001', '127.0.0.1:7002']);

        // Collect the values phpredis would hash to determine the node the INFO command is sent to.
        $hashedvalues = [];
        $connection = $this->createMock(\RedisCluster::class);
        $connection->method('info')
            ->willReturnCallback(function (string $hashedvalue, string ...$sections) use (&$hashedvalues): array {
                $this->assertSame(['server'], $sections, 'The INFO command has to request the server section.');
                $hashedvalues[$hashedvalue] = true;
                return ['redis_version' => self::REPORTED_VERSION];
            });

        $handler = \core\di::get(testable_redis_handler::class);
        $handler->set_connection($connection);

        $runs = 5;
        for ($run = 0; $run < $runs; $run++) {
            $this->assertSame(self::REPORTED_VERSION, $handler->call_get_server_version(false));
        }

        $this->assertCount($runs, $hashedvalues, 'The INFO command is always routed to the same node.');
    }
}
