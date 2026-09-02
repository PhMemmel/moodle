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

/**
 * Redis Cache Store - English language strings
 *
 * @package   cachestore_redis
 * @copyright 2013 Adam Durana
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['ca_file'] = 'Certificate Authority (CA) file path';
$string['ca_file_help'] = 'Location of Certificate Authority file on local filesystem';
$string['clustermode'] = 'Cluster mode';
$string['clustermode_help'] = 'Enabling cluster mode will run the Redis Cluster function, allowing your server to serve multiple servers to handle concurrent requests simultaneously.';
$string['clustermodeunavailable'] = 'Redis Cluster is currently unavailable. Please ensure that the PHP Redis extension supports Redis Cluster functionality.';
$string['compressor_none'] = 'No compression.';
$string['compressor_php_gzip'] = 'Use gzip compression.';
$string['compressor_php_zstd'] = 'Use Zstandard compression.';
$string['connectiontimeout'] = 'Connection timeout';
$string['connectiontimeout_help'] = 'This sets the timeout when attempting to connect to the Redis server.';
$string['encrypt_connection'] = 'Use Transport Layer Security (TLS) encryption';
$string['encrypt_connection_help'] = 'Use Transport Layer Security (TLS) to connect to Redis. Do not use \'tls://\' in the hostname for Redis; use this option instead.';
$string['password'] = 'Password';
$string['password_help'] = 'This sets the password of the Redis server.';
$string['persistent'] = 'Use persistent connection';
$string['persistent_help'] = 'Persistent connections may improve performance in heavy traffic environments. This setting has no effect in cluster mode, where connections are always persistent.

The behaviour depends on the phpredis setting redis.pconnect.pooling_enabled in php.ini, which Moodle can not influence:

* redis.pconnect.pooling_enabled = 1 (the phpredis default): Connections are pooled by host and port only and the persistent connection ID is ignored. A connection released at the end of a request may be taken over by any other Redis client of the same PHP worker which connects persistently to the same host and port, and it inherits the database that was selected on it. This cache store never selects a database and therefore assumes database 0, so make sure that no other Redis client of this site selects a different database on the same host and port. In particular, do not combine this with the $CFG->session_redis_database setting of the Redis session handler.
* redis.pconnect.pooling_enabled = 0: Connections are kept per host, port and persistent connection ID. Set a persistent connection ID which is used by this cache store only.';
$string['persistentid'] = 'Persistent connection ID';
$string['persistentid_help'] = 'A connection ID separating otherwise identical connections from each other, for example when different Redis databases are being accessed.

This is only taken into account when connection pooling has been disabled with redis.pconnect.pooling_enabled = 0 in php.ini. With the phpredis default of redis.pconnect.pooling_enabled = 1 the ID is ignored and provides no isolation.';
$string['pluginname'] = 'Redis';
$string['prefix'] = 'Key prefix';
$string['prefix_help'] = 'This prefix is used for all key names on the Redis server.
* If you only have one Moodle instance using this server, you can leave this value default.
* Due to key length restrictions, a maximum of 5 characters is permitted.';
$string['prefixinvalid'] = 'Invalid prefix. You can only use a-z A-Z 0-9-_.';
$string['privacy:metadata:redis'] = 'The Redis cachestore plugin stores data briefly as part of its caching functionality. This data is stored on an Redis server where data is regularly removed.';
$string['privacy:metadata:redis:data'] = 'The various data stored in the cache';
$string['readtimeout'] = 'Read timeout';
$string['readtimeout_help'] = 'This sets the number of seconds to wait for a read from the Redis server.';
$string['serializer_igbinary'] = 'Igbinary serializer';
$string['serializer_php'] = 'Default PHP serializer';
$string['server'] = 'Server(s)';
$string['server_help'] = 'Redis server to use for testing.

Some example values:

* testredis.abc.com - To connect to a Redis server by hostname (Port 6379 by default).
* testredis.abc.com:1234 - To connect to a Redis server by hostname with a specific port.
* 1.2.3.4 - To connect to a Redis server by IP address (Port 6379 by default).
* 1.2.3.4:1234 - To connect to a Redis server by IP address with a specific port.
* unix:///var/redis.sock - To connect to a Redis server using a Unix socket.
* /var/redis.sock - To connect to a Redis server using a Unix socket (alternative format).

If cluster mode is enabled, specify servers separated by a new line, for example:<br>
  172.23.0.11<br>
  172.23.0.12<br>
  172.23.0.13<br>

For further information, see <a href="https://redis.io/docs/reference/clients/#accepting-client-connections">Accepting Client Connections</a> and <a href="https://redis.io/resources/clients/#php">Redis PHP clients</a>.';
$string['task_ttl'] = 'Free up memory used by expired entries in Redis caches';
$string['test_clustermode'] = 'Cluster mode';
$string['test_clustermode_desc'] = 'Enable Test in Redis Cluster mode.';
$string['test_password'] = 'Test server password';
$string['test_password_desc'] = 'Redis test server password.';
$string['test_serializer'] = 'Serializer';
$string['test_serializer_desc'] = 'Serializer to use for testing.';
$string['test_server'] = 'Test server';
$string['test_server_desc'] = 'Redis server to use for testing.

Some example values:

* testredis.abc.com - To connect to a Redis server by hostname (Port 6379 by default).
* testredis.abc.com:1234 - To connect to a Redis server by hostname with a specific port.
* 1.2.3.4 - To connect to a Redis server by IP address (Port 6379 by default).
* 1.2.3.4:1234 - To connect to a Redis server by IP address with a specific port.
* unix:///var/redis.sock - To connect to a Redis server using a Unix socket.
* /var/redis.sock - To connect to a Redis server using a Unix socket (alternative format).

If cluster mode is enabled, specify servers separated by a new line, for example:<br>
  172.23.0.11<br>
  172.23.0.12<br>
  172.23.0.13<br>

For further information, see <a href="https://redis.io/docs/reference/clients/#accepting-client-connections">Accepting Client Connections</a> and <a href="https://redis.io/resources/clients/#php">Redis PHP clients</a>.';
$string['test_ttl'] = 'Testing Time-to-Live (TTL)';
$string['test_ttl_desc'] = 'Run the performance test using a cache that requires TTL (slower sets).';
$string['usecompressor'] = 'Use compressor';
$string['usecompressor_help'] = 'Specifies the compressor to use after serializing. It is done at Moodle Cache API level, not at php-redis level.';
$string['useserializer'] = 'Use serializer';
$string['useserializer_help'] = 'Specifies the serializer to use for serializing.
The valid serializers are Redis::SERIALIZER_PHP or Redis::SERIALIZER_IGBINARY.
The latter is supported only when phpredis is configured with --enable-redis-igbinary option and the igbinary extension is loaded.';
