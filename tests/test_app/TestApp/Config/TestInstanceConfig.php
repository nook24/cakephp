<?php

namespace TestApp\Config;

use Cake\Core\InstanceConfigTrait;

class TestInstanceConfig
{
    use InstanceConfigTrait;

    /**
     * defaultConfig
     *
     * Some default config
     *
     * @var array
     */
    protected $defaultConfig = [
        'some' => 'string',
        'a' => [
            'nested' => 'value',
            'other' => 'value',
        ],
    ];
}
