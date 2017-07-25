<?php

namespace Application\Model\DbTable\Attr;

use Autowp\Commons\Db\Table;

class UserValueAbstract extends Table
{
    protected $_referenceMap = [
        'User' => [
            'columns'       => ['user_id'],
            'refTableClass' => \Autowp\User\Model\DbTable\User::class,
            'refColumns'    => ['id']
        ]
    ];
    protected $_primary = ['attribute_id', 'item_id', 'user_id'];
}
