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
namespace Cake\Test\TestCase\Database\Driver;

use Cake\Database\Connection;
use Cake\Database\Driver\Postgres;
use Cake\Database\DriverFeatureEnum;
use Cake\Database\Query\SelectQuery;
use Cake\Datasource\ConnectionManager;
use Cake\TestSuite\TestCase;
use Mockery;
use PDO;

/**
 * Tests Postgres driver
 */
class PostgresTest extends TestCase
{
    /**
     * Test connecting to Postgres with default configuration
     */
    public function testConnectionConfigDefault(): void
    {
        $driver = $this->getMockBuilder(Postgres::class)
            ->onlyMethods(['createPdo'])
            ->getMock();
        $dsn = 'pgsql:host=localhost;port=5432;dbname=cake';
        $expected = [
            'persistent' => true,
            'host' => 'localhost',
            'username' => 'root',
            'password' => '',
            'database' => 'cake',
            'schema' => 'public',
            'port' => 5432,
            'encoding' => 'utf8',
            'timezone' => null,
            'flags' => [],
            'init' => [],
            'log' => false,
            'ssl_key' => null,
            'ssl_cert' => null,
            'ssl_ca' => null,
            'ssl' => false,
            'ssl_mode' => null,
        ];

        $expected['flags'] += [
            PDO::ATTR_PERSISTENT => true,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ];

        $connection = Mockery::mock('PDO');

        $connection->shouldReceive('quote')
            ->andReturnArg(0);

        $connection->shouldReceive('exec')->with('SET NAMES utf8')->once();
        $connection->shouldReceive('exec')->with('SET search_path TO public')->once();

        $driver->expects($this->once())->method('createPdo')
            ->with($dsn, $expected)
            ->willReturn($connection);

        $driver->connect();
    }

    /**
     * Test connecting to Postgres with custom configuration
     */
    public function testConnectionConfigCustom(): void
    {
        $config = [
            'persistent' => false,
            'host' => 'foo',
            'database' => 'bar',
            'username' => 'user',
            'password' => 'pass',
            'port' => 3440,
            'flags' => [1 => true, 2 => false],
            'encoding' => 'a-language',
            'timezone' => 'Antarctica',
            'schema' => 'fooblic',
            'init' => ['Execute this', 'this too'],
            'log' => false,
            'ssl_key' => '/path/to/key',
            'ssl_cert' => '/path/to/crt',
            'ssl_ca' => '/path/to/ca',
            'ssl' => true,
            'ssl_mode' => 'verify-ca',
        ];
        $driver = $this->getMockBuilder(Postgres::class)
            ->onlyMethods(['createPdo'])
            ->setConstructorArgs([$config])
            ->getMock();
        $dsn = 'pgsql:host=foo;port=3440;dbname=bar;sslmode=verify-ca;sslkey=/path/to/key;sslcert=/path/to/crt;sslrootcert=/path/to/ca';

        $expected = $config;
        $expected['flags'] += [
            PDO::ATTR_PERSISTENT => false,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ];

        $connection = Mockery::mock('PDO');

        $connection->shouldReceive('quote')
            ->andReturnArg(0);

        $connection->shouldReceive('exec')->with('SET NAMES a-language')->once();
        $connection->shouldReceive('exec')->with('SET search_path TO fooblic')->once();
        $connection->shouldReceive('exec')->with('Execute this')->once();
        $connection->shouldReceive('exec')->with('this too')->once();
        $connection->shouldReceive('exec')->with('SET timezone = Antarctica')->once();

        $driver->expects($this->once())->method('createPdo')
            ->with($dsn, $expected)
            ->willReturn($connection);

        $driver->connect();
    }

    /**
     * Tests that insert queries get a "RETURNING *" string at the end
     */
    public function testInsertReturning(): void
    {
        $driver = $this->getMockBuilder(Postgres::class)
            ->onlyMethods(['createPdo', 'getPdo', 'connect', 'enabled'])
            ->setConstructorArgs([[]])
            ->getMock();
        $driver->method('enabled')->willReturn(true);
        $connection = new Connection(['driver' => $driver, 'log' => false]);

        $query = $connection->insertQuery('articles', ['id' => 1, 'title' => 'foo']);
        $this->assertStringEndsWith(' RETURNING *', $query->sql());

        $query = $connection->insertQuery('articles', ['id' => 1, 'title' => 'foo']);
        $query->epilog('FOO');
        $this->assertStringEndsWith(' FOO', $query->sql());
    }

    /**
     * Test that having queries replace the aggregated alias field.
     */
    public function testHavingReplacesAlias(): void
    {
        $driver = $this->getMockBuilder(Postgres::class)
            ->onlyMethods(['connect', 'getPdo', 'version', 'enabled'])
            ->setConstructorArgs([[]])
            ->getMock();
        $driver->method('enabled')
            ->willReturn(true);

        $connection = new Connection(['driver' => $driver, 'log' => false]);

        $query = new SelectQuery($connection);
        $query
            ->select([
                'posts.author_id',
                'post_count' => $query->func()->count('posts.id'),
            ])
            ->groupBy(['posts.author_id'])
            ->having([$query->newExpr()->gte('post_count', 2, 'integer')]);

        $expected = 'SELECT posts.author_id, (COUNT(posts.id)) AS "post_count" ' .
            'GROUP BY posts.author_id HAVING COUNT(posts.id) >= :c0';
        $this->assertSame($expected, $query->sql());
    }

    /**
     * Test that having queries replaces nothing if no alias is used.
     */
    public function testHavingWhenNoAliasIsUsed(): void
    {
        $driver = $this->getMockBuilder(Postgres::class)
            ->onlyMethods(['connect', 'getPdo', 'version', 'enabled'])
            ->setConstructorArgs([[]])
            ->getMock();
        $driver->method('enabled')
            ->willReturn(true);

        $connection = new Connection(['driver' => $driver, 'log' => false]);

        $query = new SelectQuery($connection);
        $query
            ->select([
                'posts.author_id',
                'post_count' => $query->func()->count('posts.id'),
            ])
            ->groupBy(['posts.author_id'])
            ->having([$query->newExpr()->gte('posts.author_id', 2, 'integer')]);

        $expected = 'SELECT posts.author_id, (COUNT(posts.id)) AS "post_count" ' .
            'GROUP BY posts.author_id HAVING posts.author_id >= :c0';
        $this->assertSame($expected, $query->sql());
    }

    /**
     * Tests driver-specific feature support check.
     */
    public function testSupports(): void
    {
        $driver = ConnectionManager::get('test')->getDriver();
        $this->skipIf(!$driver instanceof Postgres);

        $this->assertTrue($driver->supports(DriverFeatureEnum::CTE));
        $this->assertTrue($driver->supports(DriverFeatureEnum::JSON));
        $this->assertTrue($driver->supports(DriverFeatureEnum::SAVEPOINT));
        $this->assertTrue($driver->supports(DriverFeatureEnum::TRUNCATE_WITH_CONSTRAINTS));
        $this->assertTrue($driver->supports(DriverFeatureEnum::WINDOW));
        $this->assertTrue($driver->supports(DriverFeatureEnum::INTERSECT));
        $this->assertTrue($driver->supports(DriverFeatureEnum::INTERSECT_ALL));
        $this->assertTrue($driver->supports(DriverFeatureEnum::SET_OPERATIONS_ORDER_BY));

        $this->assertFalse($driver->supports(DriverFeatureEnum::DISABLE_CONSTRAINT_WITHOUT_TRANSACTION));
    }

    /**
     * Tests value quoting
     */
    public function testQuote(): void
    {
        $driver = ConnectionManager::get('test')->getDriver();
        $this->skipIf(!$driver instanceof Postgres);

        $result = $driver->quote('name');
        $expected = "'name'";
        $this->assertEquals($expected, $result);

        $result = $driver->quote('Model.*');
        $expected = "'Model.*'";
        $this->assertEquals($expected, $result);

        $result = $driver->quote("O'hare");
        $expected = "'O''hare'";
        $this->assertEquals($expected, $result);

        $result = $driver->quote("O''hare");
        $expected = "'O''''hare'";
        $this->assertEquals($expected, $result);

        $result = $driver->quote("O\slash");
        $expected = "'O\slash'";
        $this->assertEquals($expected, $result);
    }
}
