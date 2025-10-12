<?php
declare(strict_types=1);

namespace TestApp\Command;

use Cake\Command\Command;
use Cake\Console\Arguments;
use Cake\Console\ConsoleIoInterface;

class GroupedCommand extends Command
{
    public static function getGroup(): string
    {
        return 'custom_group';
    }

    public function execute(Arguments $args, ConsoleIoInterface $io)
    {
        $io->out('Grouped Command!');
    }
}
