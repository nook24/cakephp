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

use Cake\Core\Exception\CakeException;
use Cake\TestSuite\TestCase;
use Cake\Validation\Validation;
use Cake\Validation\ValidationRule;
use Cake\Validation\ValidationSet;
use Error;

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
        $Rule = new ValidationRule(['callable' => [$this, 'willFail']]);
        $this->assertFalse($Rule->process($data, $context));

        $Rule = new ValidationRule(['callable' => [$this, 'willPass'], 'pass' => ['key' => 'value']]);
        $this->assertTrue($Rule->process($data, $context));

        $Rule = new ValidationRule(['callable' => [$this, 'willFail3']]);
        $this->assertSame('string', $Rule->process($data, $context));

        $Rule = new ValidationRule(['callable' => [$this, 'willFail'], 'message' => 'foo']);
        $this->assertSame('foo', $Rule->process($data, $context));
    }

    /**
     * Test using a custom validation method with no provider declared.
     */
    public function testCustomMethodNoProvider(): void
    {
        $data = 'some data';
        $context = ['field' => 'custom', 'newRecord' => true];

        $rule = new ValidationRule([
            'callable' => $this->willFail(...),
        ]);
        $this->assertFalse($rule->process($data, $context));
    }

    /**
     * Make sure errors are triggered when validation is missing.
     */
    public function testCustomMethodMissingError(): void
    {
        $this->expectException(Error::class);
        $this->expectExceptionMessage('Call to undefined method Cake\Test\TestCase\Validation\ValidationRuleTest::totallyMissing()');
        $def = ['callable' => [$this, 'totallyMissing']];
        $data = 'some data';

        $Rule = new ValidationRule($def);
        $Rule->process($data, ['newRecord' => true, 'field' => 'test']);
    }

    /**
     * Tests that a rule can be skipped
     */
    public function testSkip(): void
    {
        $data = 'some data';

        $Rule = new ValidationRule([
            'callable' => [$this, 'willFail'],
            'on' => 'create',
        ]);
        $this->assertFalse($Rule->process($data, ['newRecord' => true]));

        $Rule = new ValidationRule([
            'callable' => [$this, 'willFail'],
            'on' => 'update',
        ]);
        $this->assertTrue($Rule->process($data, ['newRecord' => true]));

        $Rule = new ValidationRule([
            'callable' => [$this, 'willFail'],
            'on' => 'update',
        ]);
        $this->assertFalse($Rule->process($data, ['newRecord' => false]));
    }

    /**
     * Tests that the 'on' key can be a callable function
     */
    public function testCallableOn(): void
    {
        $data = 'some data';

        $Rule = new ValidationRule([
            'callable' => [$this, 'willFail'],
            'on' => function ($context) {
                $expected = ['newRecord' => true, 'data' => []];
                $this->assertEquals($expected, $context);

                return true;
            },
        ]);
        $this->assertFalse($Rule->process($data, ['newRecord' => true]));

        $Rule = new ValidationRule([
            'callable' => [$this, 'willFail'],
            'on' => function ($context) {
                $expected = ['newRecord' => true, 'data' => []];
                $this->assertEquals($expected, $context);

                return false;
            },
        ]);
        $this->assertTrue($Rule->process($data, ['newRecord' => true]));
    }

    /**
     * testGet
     */
    public function testGet(): void
    {
        $Rule = new ValidationRule(['callable' => [$this, 'willFail'], 'message' => 'foo']);

        $this->assertSame([$this, 'willFail'], $Rule->get('callable'));
        $this->assertSame('foo', $Rule->get('message'));
        $this->assertEquals([], $Rule->get('pass'));
        $this->assertNull($Rule->get('nonexistent'));

        $Rule = new ValidationRule(['callable' => [Validation::class, 'willPass'], 'pass' => ['param'], 'message' => 'bar']);

        $this->assertSame([Validation::class, 'willPass'], $Rule->get('callable'));
        $this->assertSame('bar', $Rule->get('message'));
        $this->assertEquals(['param'], $Rule->get('pass'));
    }

    public function testAddDuplicateName(): void
    {
        $rules = new ValidationSet();
        $rules->add('myUniqueName', ['callable' => fn() => false]);

        $this->expectException(CakeException::class);
        $rules->add('myUniqueName', ['callable' => fn() => true]);
    }

    public function testHasName(): void
    {
        $rules = new ValidationSet();
        $rules->add('myUniqueName', ['callable' => fn() => false]);

        $this->assertTrue($rules->has('myUniqueName'));
        $this->assertFalse($rules->has('myMadeUpName'));
    }
}
