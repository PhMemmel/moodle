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
 * Unit tests for the server version detection and the node handling of the Redis session handler.
 *
 * In contrast to {@see redis_test} these tests do not require a Redis server, the connection is mocked.
 *
 * @package   core
 * @copyright 2026 ISB Bayern
 * @author    Philipp Memmel
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversMethod(redis::class, '__construct')]
#[CoversMethod(redis::class, 'get_server_version')]
final class redis_serverversion_test extends \advanced_testcase {
    /** @var string The server version reported by the mocked Redis connection. */
    private const REPORTED_VERSION = '7.4.0';

    /** @var string The server version configured via $CFG->session_redis_version. */
    private const CONFIGURED_VERSION = '7.2.0';

    /** @var string[] The nodes used to test the cluster mode. */
    private const CLUSTER_NODES = ['127.0.0.1:7000', '127.0.0.1:7001', '127.0.0.1:7002'];

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

        $this->assertSame($expected, $handler->get_server_version($encrypt));
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
     * Test that the list of nodes is randomised in cluster mode.
     */
    public function test_node_list_is_randomised_in_cluster_mode(): void {
        global $CFG;

        $this->resetAfterTest();

        // Multiple hosts enable the cluster mode of the handler.
        $CFG->session_redis_host = implode(',', self::CLUSTER_NODES);
        $clusterruns = 20;

        $firstnodes = [];
        for ($i = 0; $i < $clusterruns; $i++) {
            $hosts = \core\di::get(testable_redis_handler::class)->get_hosts();
            // All configured nodes have to be part of the list, only the order may differ.
            $this->assertEqualsCanonicalizing(self::CLUSTER_NODES, $hosts);
            $firstnodes[] = reset($hosts);
        }

        $this->assertGreaterThan(1, count(array_unique($firstnodes)));
    }

    /**
     * Test that the list of nodes is not modified when only a single host is configured.
     */
    public function test_node_list_is_not_randomised_for_a_single_host(): void {
        global $CFG;

        $this->resetAfterTest();

        $CFG->session_redis_host = '127.0.0.1';

        $this->assertSame(['127.0.0.1'], \core\di::get(testable_redis_handler::class)->get_hosts());
    }

    /**
     * Test that in cluster mode the INFO command is sent to the first node of the randomised node list.
     */
    public function test_get_server_version_cluster(): void {
        global $CFG;

        $this->resetAfterTest();

        $CFG->session_redis_host = implode(',', self::CLUSTER_NODES);

        $connection = $this->createMock(\RedisCluster::class);
        $connection->expects($this->once())
            ->method('info')
            ->with(self::CLUSTER_NODES[1], 'server')
            ->willReturn(['redis_version' => self::REPORTED_VERSION]);

        $handler = \core\di::get(testable_redis_handler::class);
        $handler->set_connection($connection);

        // The nodes are passed in the order which has been determined while connecting.
        $nodes = [self::CLUSTER_NODES[1], self::CLUSTER_NODES[2], self::CLUSTER_NODES[0]];
        $this->assertSame(self::REPORTED_VERSION, $handler->get_server_version(false, $nodes));
    }
}
