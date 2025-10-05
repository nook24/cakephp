<?php
declare(strict_types=1);

namespace TestPlugin\Command\Helper;

use Cake\Console\Helper;

class ExampleHelper extends Helper
{
    public function output(array $args): void
    {
        $this->io->out('Plugins work!');
    }
}
