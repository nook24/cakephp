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
 * @since         3.0.0
 * @license       https://opensource.org/licenses/mit-license.php MIT License
 */
namespace Cake\Datasource;

use Cake\Cache\Cache;
use Cake\Core\Exception\CakeException;
use Closure;
use Psr\SimpleCache\CacheInterface;
use Traversable;

/**
 * Handles caching queries and loading results from the cache.
 *
 * Used by {@link \Cake\Datasource\QueryTrait} internally.
 *
 * @internal
 * @see \Cake\Datasource\QueryTrait::cache() for the public interface.
 */
class QueryCacher
{
    /**
     * The key or function to generate a key.
     *
     * @var \Closure|string
     */
    protected Closure|string $key;

    /**
     * Config for cache engine.
     *
     * @var \Psr\SimpleCache\CacheInterface|string
     */
    protected CacheInterface|string $config;

    /**
     * Constructor.
     *
     * @param \Closure|string $key The key or function to generate a key.
     * @param \Psr\SimpleCache\CacheInterface|string $config The cache config name or cache engine instance.
     */
    public function __construct(Closure|string $key, CacheInterface|string $config)
    {
        $this->key = $key;
        $this->config = $config;
    }

    /**
     * Load the cached results from the cache or run the query.
     *
     * @param object $query The query the cache read is for.
     * @return mixed|null Either the cached results or null.
     */
    public function fetch(object $query): mixed
    {
        $key = $this->resolveKey($query);
        $storage = $this->resolveCacher();
        $result = $storage->get($key);
        if (!$result) {
            return null;
        }

        return $result;
    }

    /**
     * Store the result set into the cache.
     *
     * @param object $query The query the cache read is for.
     * @param \Traversable $results The result set to store.
     * @return bool True if the data was successfully cached, false on failure
     */
    public function store(object $query, Traversable $results): bool
    {
        $key = $this->resolveKey($query);
        $storage = $this->resolveCacher();

        return $storage->set($key, $results);
    }

    /**
     * Get/generate the cache key.
     *
     * @param object $query The query to generate a key for.
     * @return string
     * @throws \Cake\Core\Exception\CakeException
     */
    protected function resolveKey(object $query): string
    {
        if (is_string($this->key)) {
            return $this->key;
        }
        $func = $this->key;
        $key = $func($query);
        if (!is_string($key)) {
            $msg = sprintf('Cache key functions must return a string. Got %s.', var_export($key, true));
            throw new CakeException($msg);
        }

        return $key;
    }

    /**
     * Get the cache engine.
     *
     * @return \Psr\SimpleCache\CacheInterface
     */
    protected function resolveCacher(): CacheInterface
    {
        if (is_string($this->config)) {
            return Cache::pool($this->config);
        }

        return $this->config;
    }
}
