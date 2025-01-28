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
 * @since         5.2.0
 * @license       https://opensource.org/licenses/mit-license.php MIT License
 */
namespace Cake\Test\TestCase\Database\Schema;

use Cake\Database\Driver\Mysql;
use Cake\Database\Exception\DatabaseException;
use Cake\Datasource\ConnectionManager;
use Cake\TestSuite\TestCase;

/**
 * Test case for SchemaDialect methods
 * that can pass tests across all drivers
 */
class SchemaDialectTest extends TestCase
{
    /**
     * @var array<string>
     */
    protected array $fixtures = [
        'core.Products',
        'core.Users',
        'core.Orders',
    ];

    /**
     * @var \Cake\Database\Schema\SchemaDialect
     */
    protected $dialect;

    /**
     * Setup function
     */
    public function setUp(): void
    {
        parent::setUp();
        $this->dialect = ConnectionManager::get('test')->getDriver()->schemaDialect();
    }

    /**
     * Test that describing nonexistent tables fails.
     *
     * Tests for positive describe() calls are in each platformSchema
     * test case.
     */
    public function testDescribeIncorrectTable(): void
    {
        $this->expectException(DatabaseException::class);
        $this->assertNull($this->dialect->describe('derp'));
    }

    public function testListTables(): void
    {
        $result = $this->dialect->listTables();
        $this->assertNotEmpty($result);
        $this->assertContains('users', $result);
    }

    public function testListTablesWithoutViews(): void
    {
        $result = $this->dialect->listTablesWithoutViews();
        $this->assertNotEmpty($result);
        $this->assertContains('users', $result);
    }

    public function testDescribe(): void
    {
        $result = $this->dialect->describe('users');
        $this->assertNotEmpty($result);
        $this->assertEquals('users', $result->name());
        $this->assertTrue($result->hasColumn('username'));
    }

    public function testDescribeColumns(): void
    {
        $result = $this->dialect->describeColumns('users');
        $this->assertCount(5, $result);
        foreach ($result as $column) {
            // Validate the interface for column array shape
            $this->assertArrayHasKey('name', $column);
            $this->assertTrue(is_string($column['name']));

            $this->assertArrayHasKey('type', $column);
            $this->assertTrue(is_string($column['type']));

            $this->assertArrayHasKey('length', $column);
            $this->assertTrue(is_int($column['length']) || $column['length'] === null);

            $this->assertArrayHasKey('default', $column);

            $this->assertArrayHasKey('null', $column);
            $this->assertTrue(is_bool($column['null']));

            $this->assertArrayHasKey('comment', $column);
            $this->assertTrue(is_string($column['comment']) || $column['comment'] === null);
        }
    }

    public function testDescribeIndexes(): void
    {
        $result = $this->dialect->describeIndexes('orders');
        $this->assertCount(1, $result);
        foreach ($result as $index) {
            // Validate the interface for column array shape
            $this->assertArrayHasKey('name', $index);
            $this->assertTrue(is_string($index['name']));

            $this->assertArrayHasKey('type', $index);
            $this->assertTrue(is_string($index['type']));

            $this->assertArrayHasKey('length', $index);

            $this->assertArrayHasKey('columns', $index);
            $this->assertTrue(is_array($index['columns']));
        }
    }

    public function testDescribeForeignKeys(): void
    {
        $result = $this->dialect->describeForeignKeys('orders');
        $this->assertCount(1, $result);
        foreach ($result as $key) {
            // Validate the interface for column array shape
            $this->assertArrayHasKey('name', $key);
            $this->assertTrue(is_string($key['name']));

            $this->assertArrayHasKey('type', $key);
            $this->assertTrue(is_string($key['type']));

            $this->assertArrayHasKey('columns', $key);
            $this->assertTrue(is_array($key['columns']));

            $this->assertArrayHasKey('references', $key);
            $this->assertTrue(is_array($key['references']));

            $this->assertArrayHasKey('update', $key);
            $this->assertTrue(is_string($key['update']) || $key['update'] === null);
            $this->assertArrayHasKey('delete', $key);
            $this->assertTrue(is_string($key['delete']) || $key['delete'] === null);
        }
    }

    public function testDescribeOptions(): void
    {
        $result = $this->dialect->describeOptions('orders');
        $this->assertTrue(is_array($result));
    }

    public function testDescribeOptionsMysql(): void
    {
        $this->skipIf(!(ConnectionManager::get('test')->getDriver() instanceof Mysql), 'requires mysql');
        $result = $this->dialect->describeOptions('orders');
        $this->assertTrue(is_array($result));
        $this->assertArrayHasKey('engine', $result);
        $this->assertArrayHasKey('collation', $result);
    }
}
