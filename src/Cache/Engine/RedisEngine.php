<?php
declare(strict_types=1);

/**
 * CakePHP(tm) : Rapid Development Framework (https://cakephp.org)
 * Copyright (c) Cake Software Foundation, Inc. (https://cakefoundation.org)
 *
 * Licensed under The MIT License
 * For full copyright and license information, please see the LICENSE.txt
 * Redistributions of files must retain the above copyright notice.
 *
 * @copyright     Copyright (c) Cake Software Foundation, Inc. (https://cakefoundation.org)
 * @link          https://cakephp.org CakePHP(tm) Project
 * @since         2.2.0
 * @license       https://opensource.org/licenses/mit-license.php MIT License
 */

namespace Cake\Cache\Engine;

use Cake\Cache\CacheEngine;
use Cake\Core\Exception\CakeException;
use Cake\Log\Log;
use DateInterval;
use Generator;
use Redis;
use RedisCluster;
use RedisClusterException;
use RedisException;

/**
 * Redis storage engine for cache.
 */
class RedisEngine extends CacheEngine
{
    /**
     * Redis wrapper.
     *
     * @var \Redis
     */
    protected Redis|RedisCluster $Redis;

    /**
     * The default config used unless overridden by runtime configuration
     *
     * - `clusterName` Redis cluster name
     * - `database` database number to use for connection.
     * - `duration` Specify how long items in this cache configuration last.
     * - `groups` List of groups or 'tags' associated to every key stored in this config.
     *    handy for deleting a complete group from cache.
     * - `password` Redis server password.
     * - `persistent` Connect to the Redis server with a persistent connection
     * - `port` port number to the Redis server.
     * - `tls` connect to the Redis server using TLS.
     * - `prefix` Prefix appended to all entries. Good for when you need to share a keyspace
     *    with either another cache config or another application.
     * - `scanCount` Number of keys to ask for each scan (default: 10)
     * - `server` URL or IP to the Redis server host.
     * - `timeout` timeout in seconds (float).
     * - `unix_socket` Path to the unix socket file (default: false)
     * - `readTimeout` Read timeout in seconds (float).
     * - `nodes` When using redis-cluster, the URL or IP addresses of the
     *   Redis cluster nodes.
     *   Format: an array of strings in the form `<ip>:<port>`, like:
     *   [
     *       '<ip>:<port>',
     *       '<ip>:<port>',
     *       '<ip>:<port>',
     *   ]
     * - `failover` Failover mode (distribute,distribute_slaves,error,none). Cluster mode only.
     * - `clearUsesFlushDb` Enable clear() and clearBlocking() to use FLUSHDB. This will be
     *   faster than standard clear()/clearBlocking() but will ignore prefixes and will
     *   cause dataloss if other applications are sharing a redis database.
     *
     * @var array<string, mixed>
     */
    protected array $defaultConfig = [
        'clusterName' => null,
        'database' => 0,
        'duration' => 3600,
        'groups' => [],
        'password' => false,
        'persistent' => true,
        'port' => 6379,
        'tls' => false,
        'prefix' => 'cake_',
        'host' => null,
        'server' => '127.0.0.1',
        'timeout' => 0,
        'unix_socket' => false,
        'scanCount' => 10,
        'readTimeout' => 0,
        'nodes' => [],
        'failover' => null,
        'clearUsesFlushDb' => false,
    ];

    /**
     * Initialize the Cache Engine
     *
     * Called automatically by the cache frontend
     *
     * @param array<string, mixed> $config array of setting for the engine
     * @return bool True if the engine has been successfully initialized, false if not
     */
    public function init(array $config = []): bool
    {
        if (!extension_loaded('redis')) {
            throw new CakeException('The `redis` extension must be enabled to use RedisEngine.');
        }

        if (!empty($config['host'])) {
            $config['server'] = $config['host'];
        }

        parent::init($config);

        return $this->connect();
    }

    /**
     * Connects to a Redis server
     *
     * @return bool True if Redis server was connected
     */
    protected function connect(): bool
    {
        if (!empty($this->config['nodes']) || !empty($this->config['clusterName'])) {
            return $this->connectRedisCluster();
        }

        return $this->connectRedis();
    }

    /**
     * Connects to a Redis cluster server
     *
     * @return bool True if Redis server was connected
     */
    protected function connectRedisCluster(): bool
    {
        $connected = false;

        if (empty($this->config['nodes'])) {
            // @codeCoverageIgnoreStart
            if (class_exists(Log::class)) {
                Log::error('RedisEngine requires one or more nodes in cluster mode');
            }
            // @codeCoverageIgnoreEnd

            return false;
        }

        // @codeCoverageIgnoreStart
        $ssl = [];
        if ($this->config['tls']) {
            $map = [
                'ssl_ca' => 'cafile',
                'ssl_key' => 'local_pk',
                'ssl_cert' => 'local_cert',
                'verify_peer' => 'verify_peer',
                'verify_peer_name' => 'verify_peer_name',
                'allow_self_signed' => 'allow_self_signed',
            ];

            foreach ($map as $configKey => $sslOption) {
                if (array_key_exists($configKey, $this->config)) {
                    $ssl[$sslOption] = $this->config[$configKey];
                }
            }
        }
        // @codeCoverageIgnoreEnd

        try {
            $this->Redis = new RedisCluster(
                $this->config['clusterName'],
                $this->config['nodes'],
                (float)$this->config['timeout'],
                (float)$this->config['readTimeout'],
                $this->config['persistent'],
                $this->config['password'],
                $this->config['tls'] ? ['ssl' => $ssl] : null, // @codeCoverageIgnore
            );

            $connected = true;
        } catch (RedisClusterException $e) {
            $connected = false;

            // @codeCoverageIgnoreStart
            if (class_exists(Log::class)) {
                Log::error('RedisEngine could not connect to the redis cluster. Got error: ' . $e->getMessage());
            }
            // @codeCoverageIgnoreEnd
        }

        $failover = match ($this->config['failover']) {
            RedisCluster::FAILOVER_DISTRIBUTE, 'distribute' => RedisCluster::FAILOVER_DISTRIBUTE,
            RedisCluster::FAILOVER_DISTRIBUTE_SLAVES, 'distribute_slaves' => RedisCluster::FAILOVER_DISTRIBUTE_SLAVES,
            RedisCluster::FAILOVER_ERROR, 'error' => RedisCluster::FAILOVER_ERROR,
            RedisCluster::FAILOVER_NONE, 'none' => RedisCluster::FAILOVER_NONE,
            default => null,
        };

        if ($failover !== null) {
            $this->Redis->setOption(RedisCluster::OPT_SLAVE_FAILOVER, $failover);
        }

        return $connected;
    }

    /**
     * Connects to a Redis server
     *
     * @return bool True if Redis server was connected
     */
    protected function connectRedis(): bool
    {
        $tls = $this->config['tls'] === true ? 'tls://' : '';

        $map = [
            'ssl_ca' => 'cafile',
            'ssl_key' => 'local_pk',
            'ssl_cert' => 'local_cert',
        ];

        $ssl = [];
        foreach ($map as $key => $context) {
            if (!empty($this->config[$key])) {
                $ssl[$context] = $this->config[$key];
            }
        }

        try {
            $this->Redis = $this->createRedisInstance();
            if (!empty($this->config['unix_socket'])) {
                $return = $this->Redis->connect($this->config['unix_socket']);
            } elseif (empty($this->config['persistent'])) {
                $return = $this->connectTransient($tls . $this->config['server'], $ssl);
            } else {
                $return = $this->connectPersistent($tls . $this->config['server'], $ssl);
            }
        } catch (RedisException $e) {
            if (class_exists(Log::class)) {
                Log::error('RedisEngine could not connect. Got error: ' . $e->getMessage());
            }

            return false;
        }

        if ($return && $this->config['password']) {
            $return = $this->Redis->auth($this->config['password']);
        }
        if ($return) {
            return $this->Redis->select((int)$this->config['database']);
        }

        return $return;
    }

    /**
     * Connects to a Redis server using a new connection.
     *
     * @param string $server Server to connect to.
     * @param array $ssl SSL context options.
     * @throws \RedisException
     * @return bool True if Redis server was connected
     */
    protected function connectTransient(string $server, array $ssl): bool
    {
        if ($ssl === []) {
            return $this->Redis->connect(
                $server,
                (int)$this->config['port'],
                (int)$this->config['timeout'],
            );
        }

        return $this->Redis->connect(
            $server,
            (int)$this->config['port'],
            (int)$this->config['timeout'],
            null,
            0,
            0.0,
            ['ssl' => $ssl],
        );
    }

    /**
     * Connects to a Redis server using a persistent connection.
     *
     * @param string $server Server to connect to.
     * @param array $ssl SSL context options.
     * @throws \RedisException
     * @return bool True if Redis server was connected
     */
    protected function connectPersistent(string $server, array $ssl): bool
    {
        $persistentId = $this->config['port'] . $this->config['timeout'] . $this->config['database'];

        if ($ssl === []) {
            return $this->Redis->pconnect(
                $server,
                (int)$this->config['port'],
                (int)$this->config['timeout'],
                $persistentId,
            );
        }

        return $this->Redis->pconnect(
            $server,
            (int)$this->config['port'],
            (int)$this->config['timeout'],
            $persistentId,
            0,
            0.0,
            ['ssl' => $ssl],
        );
    }

    /**
     * Write data for key into cache.
     *
     * @param string $key Identifier for the data
     * @param mixed $value Data to be cached
     * @param \DateInterval|int|null $ttl Optional. The TTL value of this item. If no value is sent and
     *   the driver supports TTL then the library may set a default value
     *   for it or let the driver take care of that.
     * @return bool True if the data was successfully cached, false on failure
     */
    public function set(string $key, mixed $value, DateInterval|int|null $ttl = null): bool
    {
        $key = $this->key($key);
        $value = $this->serialize($value);

        $duration = $this->duration($ttl);
        if ($duration === 0) {
            return $this->Redis->set($key, $value);
        }

        return $this->Redis->setEx($key, $duration, $value);
    }

    /**
     * Read a key from the cache
     *
     * @param string $key Identifier for the data
     * @param mixed $default Default value to return if the key does not exist.
     * @return mixed The cached data, or the default if the data doesn't exist, has
     *   expired, or if there was an error fetching it
     */
    public function get(string $key, mixed $default = null): mixed
    {
        $value = $this->Redis->get($this->key($key));
        if ($value === false) {
            return $default;
        }

        return $this->unserialize($value);
    }

    /**
     * @inheritDoc
     */
    public function has(string $key): bool
    {
        $res = $this->Redis->exists($this->key($key));

        return is_int($res) ? $res > 0 : $res === true;
    }

    /**
     * Increments the value of an integer cached key & update the expiry time
     *
     * @param string $key Identifier for the data
     * @param int $offset How much to increment
     * @return int|false New incremented value, false otherwise
     */
    public function increment(string $key, int $offset = 1): int|false
    {
        $duration = $this->config['duration'];
        $key = $this->key($key);

        $value = $this->Redis->incrBy($key, $offset);
        if ($duration > 0) {
            $this->Redis->expire($key, $duration);
        }

        return $value;
    }

    /**
     * Decrements the value of an integer cached key & update the expiry time
     *
     * @param string $key Identifier for the data
     * @param int $offset How much to subtract
     * @return int|false New decremented value, false otherwise
     */
    public function decrement(string $key, int $offset = 1): int|false
    {
        $duration = $this->config['duration'];
        $key = $this->key($key);

        $value = $this->Redis->decrBy($key, $offset);
        if ($duration > 0) {
            $this->Redis->expire($key, $duration);
        }

        return $value;
    }

    /**
     * Delete a key from the cache
     *
     * @param string $key Identifier for the data
     * @return bool True if the value was successfully deleted, false if it didn't exist or couldn't be removed
     */
    public function delete(string $key): bool
    {
        $key = $this->key($key);

        return (int)$this->Redis->del($key) > 0;
    }

    /**
     * Delete a key from the cache asynchronously
     *
     * Just unlink a key from the cache. The actual removal will happen later asynchronously.
     *
     * @param string $key Identifier for the data
     * @return bool True if the value was successfully deleted, false if it didn't exist or couldn't be removed
     */
    public function deleteAsync(string $key): bool
    {
        $key = $this->key($key);

        return (int)$this->Redis->unlink($key) > 0;
    }

    /**
     * Delete all keys from the cache
     *
     * @return bool True if the cache was successfully cleared, false otherwise
     */
    public function clear(): bool
    {
        if ($this->getConfig('clearUsesFlushDb')) {
            $this->flushDB(true);

            return true;
        }

        $isAllDeleted = true;
        $pattern = $this->config['prefix'] . '*';

        foreach ($this->scanKeys($pattern) as $key) {
            $isDeleted = ((int)$this->Redis->unlink($key) > 0);
            $isAllDeleted = $isAllDeleted && $isDeleted;
        }

        return $isAllDeleted;
    }

    /**
     * Delete all keys from the cache by a blocking operation
     *
     * @return bool True if the cache was successfully cleared, false otherwise
     */
    public function clearBlocking(): bool
    {
        if ($this->getConfig('clearUsesFlushDb')) {
            $this->flushDB(false);

            return true;
        }

        $isAllDeleted = true;
        $pattern = $this->config['prefix'] . '*';

        foreach ($this->scanKeys($pattern) as $key) {
            // Blocking delete
            $isDeleted = ((int)$this->Redis->del($key) > 0);
            $isAllDeleted = $isAllDeleted && $isDeleted;
        }

        return $isAllDeleted;
    }

    /**
     * Write data for key into cache if it doesn't exist already.
     * If it already exists, it fails and returns false.
     *
     * @param string $key Identifier for the data.
     * @param mixed $value Data to be cached.
     * @return bool True if the data was successfully cached, false on failure.
     * @link https://github.com/phpredis/phpredis#set
     */
    public function add(string $key, mixed $value): bool
    {
        $duration = $this->config['duration'];
        $key = $this->key($key);
        $value = $this->serialize($value);

        if ($this->Redis->set($key, $value, ['nx', 'ex' => $duration])) {
            return true;
        }

        return false;
    }

    /**
     * Returns the `group value` for each of the configured groups
     * If the group initial value was not found, then it initializes
     * the group accordingly.
     *
     * @return array<string>
     */
    public function groups(): array
    {
        $result = [];
        foreach ($this->config['groups'] as $group) {
            $value = $this->Redis->get($this->config['prefix'] . $group);
            if (!$value) {
                $value = $this->serialize(1);
                $this->Redis->set($this->config['prefix'] . $group, $value);
            }
            $result[] = $group . $value;
        }

        return $result;
    }

    /**
     * Increments the group value to simulate deletion of all keys under a group
     * old values will remain in storage until they expire.
     *
     * @param string $group name of the group to be cleared
     * @return bool success
     */
    public function clearGroup(string $group): bool
    {
        return (bool)$this->Redis->incr($this->config['prefix'] . $group);
    }

    /**
     * Serialize value for saving to Redis.
     *
     * This is needed instead of using Redis' in built serialization feature
     * as it creates problems incrementing/decrementing initially set integer value.
     *
     * @param mixed $value Value to serialize.
     * @return string
     * @link https://github.com/phpredis/phpredis/issues/81
     */
    protected function serialize(mixed $value): string
    {
        if (is_int($value)) {
            return (string)$value;
        }

        return serialize($value);
    }

    /**
     * Unserialize string value fetched from Redis.
     *
     * @param string $value Value to unserialize.
     * @return mixed
     */
    protected function unserialize(string $value): mixed
    {
        if (preg_match('/^[-]?\d+$/', $value)) {
            return (int)$value;
        }

        return unserialize($value);
    }

    /**
     * Create new Redis instance.
     *
     * @return \Redis
     */
    protected function createRedisInstance(): Redis
    {
        return new Redis();
    }

    /**
     * Unifies Redis and RedisCluster scan() calls and simplifies its use.
     *
     * @param string $pattern Pattern to scan
     * @return \Generator<string>
     */
    private function scanKeys(string $pattern): Generator
    {
        $this->Redis->setOption(Redis::OPT_SCAN, (string)Redis::SCAN_RETRY);

        if ($this->Redis instanceof RedisCluster) {
            foreach ($this->Redis->_masters() as $node) {
                $iterator = null;
                while (true) {
                    // @phpstan-ignore arguments.count, argument.type
                    $keys = $this->Redis->scan($iterator, $node, $pattern, (int)$this->config['scanCount']);
                    if ($keys === false) {
                        break;
                    }

                    foreach ($keys as $key) {
                        yield $key;
                    }
                }
            }
        } else {
            $iterator = null;
            while (true) {
                $keys = $this->Redis->scan($iterator, $pattern, (int)$this->config['scanCount']);
                if ($keys === false) {
                    break;
                }

                foreach ($keys as $key) {
                    yield $key;
                }
            }
        }
    }

    /**
     * Flushes DB
     *
     * @param bool $async Whether to use asynchronous mode
     * @return void
     */
    private function flushDB(bool $async): void
    {
        if ($this->Redis instanceof RedisCluster) {
            foreach ($this->Redis->_masters() as $node) {
                // @phpstan-ignore arguments.count
                $this->Redis->flushDB($node, $async);
            }
        } else {
            $this->Redis->flushDB($async);
        }
    }

    /**
     * Disconnects from the redis server
     */
    public function __destruct()
    {
        if (isset($this->Redis) && empty($this->config['persistent'])) {
            $this->Redis->close();
        }
    }
}
