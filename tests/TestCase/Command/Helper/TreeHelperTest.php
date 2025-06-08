<?php
declare(strict_types=1);

/**
 * CakePHP :  Rapid Development Framework (https://cakephp.org)
 * Copyright (c) Cake Software Foundation, Inc. (https://cakefoundation.org)
 *
 * Licensed under The MIT License
 * For full copyright and license information, please see the LICENSE.txt
 * Redistributions of files must retain the above copyright notice.
 *
 * @copyright     Copyright (c) Cake Software Foundation, Inc. (https://cakefoundation.org)
 * @link          https://cakephp.org CakePHP Project
 * @since         5.3.0
 * @license       https://opensource.org/licenses/mit-license.php MIT License
 */
namespace Cake\Test\TestCase\Command\Helper;

use Cake\Command\Helper\TreeHelper;
use Cake\Console\ConsoleIo;
use Cake\Console\TestSuite\StubConsoleOutput;
use Cake\TestSuite\TestCase;

/**
 * TreeHelper test.
 */
class TreeHelperTest extends TestCase
{
    /**
     * @var \Cake\Command\Helper\TreeHelper
     */
    protected TreeHelper $helper;

    /**
     * @var \Cake\Console\TestSuite\StubConsoleOutput
     */
    protected StubConsoleOutput $stub;

    /**
     * @var \Cake\Console\ConsoleIo
     */
    protected ConsoleIo $io;

    /**
     * setUp method
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->stub = new StubConsoleOutput();
        $this->io = new ConsoleIo($this->stub);
        $this->helper = new TreeHelper($this->io);
    }

    public function testEmptyTree(): void
    {
        $this->helper->output([]);
        $this->assertEquals([], $this->stub->messages());
    }

    public function testSingleList(): void
    {
        $this->helper->output(['one', 'two', 'three']);
        $this->assertEquals([
            '├── one',
            '├── two',
            '└── three',
        ], $this->stub->messages());
    }

    public function testNestedTree(): void
    {
        $this->helper->output(['one', 'two' => ['two_1' => ['two_1_1', 'two_1_2'], 'two_2' => ['two_2_1', 'two_2_2']]]);
        $this->assertEquals([
            '├── one',
            '└── two',
            '    ├── two_1',
            '    │   ├── two_1_1',
            '    │   └── two_1_2',
            '    └── two_2',
            '        ├── two_2_1',
            '        └── two_2_2',
        ], $this->stub->messages());
    }

    public function testNestedTreeCustomIndent(): void
    {
        $this->helper->setConfig(['baseIndent' => 1, 'elementIndent' => 2]);
        $this->helper->output(['one', 'two' => ['two_1' => ['two_1_1', 'two_1_2'], 'two_2' => ['two_2_1', 'two_2_2']]]);
        $this->assertEquals([
            ' ├── one',
            ' └── two',
            '       ├── two_1',
            '       │     ├── two_1_1',
            '       │     └── two_1_2',
            '       └── two_2',
            '             ├── two_2_1',
            '             └── two_2_2',
        ], $this->stub->messages());
    }
}
