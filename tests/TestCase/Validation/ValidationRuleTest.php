<?php
declare(strict_types=1);

/**
 * CakePHP(tm) Tests <https://book.cakephp.org/view/1196/Testing>
 * Copyright (c) Cake Software Foundation, Inc. (https://cakefoundation.org)
 *
 * Licensed under The MIT License
 * For full copyright and license information, please see the LICENSE.txt
 * Redistributions of files must retain the above copyright notice
 *
 * @copyright     Copyright (c) Cake Software Foundation, Inc. (https://cakefoundation.org)
 * @link          https://book.cakephp.org/view/1196/Testing CakePHP(tm) Tests
 * @since         2.2.0
 * @license       https://opensource.org/licenses/mit-license.php MIT License
 */
namespace Cake\Test\TestCase\Validation;

use Cake\TestSuite\TestCase;
use Cake\Validation\ValidationRule;
use Closure;

/**
 * ValidationRuleTest
 */
class ValidationRuleTest extends TestCase
{
    /**
     * Auxiliary method to test custom validators
     */
    public function willFail(): bool
    {
        return false;
    }

    /**
     * Auxiliary method to test custom validators
     */
    public function willPass(): bool
    {
        return true;
    }

    /**
     * Auxiliary method to test custom validators
     */
    public function willFail3(): string
    {
        return 'string';
    }

    /**
     * tests that passing custom validation methods work
     */
    public function testCustomMethods(): void
    {
        $data = 'some data';

        $context = ['newRecord' => true];
        $Rule = new ValidationRule(Closure::fromCallable($this->willFail(...)));
        $this->assertFalse($Rule->process($data, $context));

        $Rule = new ValidationRule(Closure::fromCallable($this->willPass(...)), pass: ['key' => 'value']);
        $this->assertTrue($Rule->process($data, $context));

        $Rule = new ValidationRule(Closure::fromCallable($this->willFail3(...)));
        $this->assertSame('string', $Rule->process($data, $context));

        $Rule = new ValidationRule(Closure::fromCallable($this->willFail(...)), message: 'foo');
        $this->assertSame('foo', $Rule->process($data, $context));
    }

    /**
     * Test using a custom validation method with no provider declared.
     */
    public function testCustomMethodNoProvider(): void
    {
        $data = 'some data';
        $context = ['field' => 'custom', 'newRecord' => true];

        $rule = new ValidationRule($this->willFail(...));
        $this->assertFalse($rule->process($data, $context));
    }

    /**
     * Tests that a rule can be skipped
     */
    public function testSkip(): void
    {
        $data = 'some data';

        $Rule = new ValidationRule(Closure::fromCallable($this->willFail(...)), on: 'create');
        $this->assertFalse($Rule->process($data, ['newRecord' => true]));

        $Rule = new ValidationRule(Closure::fromCallable($this->willFail(...)), on: 'update');
        $this->assertTrue($Rule->process($data, ['newRecord' => true]));

        $Rule = new ValidationRule(Closure::fromCallable($this->willFail(...)), on: 'update');
        $this->assertFalse($Rule->process($data, ['newRecord' => false]));
    }

    /**
     * Tests that the 'on' key can be a callable function
     */
    public function testCallableOn(): void
    {
        $data = 'some data';

        $Rule = new ValidationRule(
            callable: Closure::fromCallable(Closure::fromCallable($this->willFail(...))),
            on: function ($context) {
                $expected = ['newRecord' => true, 'data' => []];
                $this->assertEquals($expected, $context);

                return true;
            },
        );
        $this->assertFalse($Rule->process($data, ['newRecord' => true]));

        $Rule = new ValidationRule(
            Closure::fromCallable(Closure::fromCallable($this->willFail(...))),
            on: function ($context) {
                $expected = ['newRecord' => true, 'data' => []];
                $this->assertEquals($expected, $context);

                return false;
            },
        );
        $this->assertTrue($Rule->process($data, ['newRecord' => true]));
    }
}
