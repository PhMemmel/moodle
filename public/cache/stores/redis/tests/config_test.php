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

namespace cachestore_redis;

use cachestore_redis;
use moodleform;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\Attributes\DataProvider;
use Redis;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/formslib.php');
require_once(__DIR__ . '/../lib.php');

/**
 * Tests for the configuration handling of the Redis cache store.
 *
 * These tests do not require a Redis server to be available.
 *
 * @package   cachestore_redis
 * @copyright 2026 ISB Bayern
 * @author    Philipp Memmel
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversMethod(\cachestore_redis::class, 'config_get_configuration_array')]
#[CoversMethod(\cachestore_redis::class, 'config_set_edit_form_data')]
final class config_test extends \advanced_testcase {
    #[\Override]
    public function setUp(): void {
        parent::setUp();

        if (!cachestore_redis::are_requirements_met()) {
            $this->markTestSkipped('Could not test cachestore_redis, the redis extension is not available.');
        }
    }

    /**
     * Build a complete set of 'add instance' form data.
     *
     * @param array $overrides Values overriding the defaults.
     * @return \stdClass The form data.
     */
    protected function get_form_data(array $overrides = []): \stdClass {
        return (object) array_merge([
            'server' => '127.0.0.1',
            'prefix' => 'phpunit',
            'password' => '',
            'serializer' => Redis::SERIALIZER_PHP,
            'compressor' => cachestore_redis::COMPRESSOR_NONE,
            'connectiontimeout' => cachestore_redis::CONNECTION_TIMEOUT,
            'readtimeout' => cachestore_redis::CONNECTION_TIMEOUT,
            'encryption' => false,
            'cafile' => '',
            'clustermode' => false,
            'persistent' => false,
            'persistentid' => '',
        ], $overrides);
    }

    /**
     * Test that the persistent connection settings are taken over from the form data.
     */
    public function test_config_get_configuration_array_contains_persistent_settings(): void {
        $data = $this->get_form_data(['persistent' => true, 'persistentid' => 'someid']);

        $configuration = cachestore_redis::config_get_configuration_array($data);

        $this->assertTrue($configuration['persistent']);
        $this->assertSame('someid', $configuration['persistentid']);
    }

    /**
     * Test that the persistent connection settings are absent when they have not been enabled.
     */
    public function test_config_get_configuration_array_without_persistent_settings(): void {
        $configuration = cachestore_redis::config_get_configuration_array($this->get_form_data());

        $this->assertFalse($configuration['persistent']);
        $this->assertSame('', $configuration['persistentid']);
    }

    /**
     * Test that the persistent connection settings are dropped in cluster mode, where they do not apply.
     */
    public function test_config_get_configuration_array_ignores_persistent_settings_in_cluster_mode(): void {
        $data = $this->get_form_data([
            'clustermode' => true,
            'persistent' => true,
            'persistentid' => 'someid',
        ]);

        $configuration = cachestore_redis::config_get_configuration_array($data);

        $this->assertArrayNotHasKey('persistent', $configuration);
        $this->assertArrayNotHasKey('persistentid', $configuration);
    }

    /**
     * Data provider for {@see test_config_set_edit_form_data_contains_persistent_settings()}.
     *
     * @return array[] The stored configuration and the values expected to be passed to the form.
     */
    public static function config_set_edit_form_data_contains_persistent_settings_provider(): array {
        return [
            'persistent_with_id' => [
                'config' => ['persistent' => true, 'persistentid' => 'someid'],
                'expected' => ['persistent' => true, 'persistentid' => 'someid'],
            ],
            'persistent_without_id' => [
                'config' => ['persistent' => true, 'persistentid' => ''],
                'expected' => ['persistent' => true],
            ],
            'not_persistent' => [
                'config' => ['persistent' => false, 'persistentid' => ''],
                'expected' => [],
            ],
            'not_configured' => [
                'config' => [],
                'expected' => [],
            ],
        ];
    }

    /**
     * Test that the stored persistent connection settings are passed on to the edit form.
     *
     * @param array $config The stored store configuration.
     * @param array $expected The persistent connection values expected to be passed to the form.
     */
    #[DataProvider('config_set_edit_form_data_contains_persistent_settings_provider')]
    public function test_config_set_edit_form_data_contains_persistent_settings(array $config, array $expected): void {
        $editform = $this->createMock(moodleform::class);
        $editform->expects($this->once())
            ->method('set_data')
            ->with($this->callback(function (array $data) use ($expected): bool {
                foreach (['persistent', 'persistentid'] as $key) {
                    if (!array_key_exists($key, $expected)) {
                        // Values which have not been configured must not be passed on to the form at all.
                        if (array_key_exists($key, $data)) {
                            return false;
                        }
                        continue;
                    }
                    if (!array_key_exists($key, $data) || $data[$key] !== $expected[$key]) {
                        return false;
                    }
                }
                return true;
            }));

        cachestore_redis::config_set_edit_form_data($editform, array_merge(['server' => '127.0.0.1'], $config));
    }
}
