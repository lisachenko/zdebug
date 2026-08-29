<?php
require dirname(__DIR__) . '/vendor/autoload.php';
use ZEngine\Core;
use ZEngine\System\Compiler;
use ZEngine\System\OpCode;
Core::init();
Core::$compiler->setOptions(Core::$compiler->getOptions() | Compiler::COMPILE_EXTENDED_STMT);
$fires = 0;
OpCode::setHandler(OpCode::EXT_STMT, function ($scope) use (&$fires): int {
    $fires++;
    return Core::ZEND_USER_OPCODE_DISPATCH;
});
require __DIR__ . '/payload.php';
echo "EXT_STMT fires: {$fires}\n";
echo "L3 DONE\n";
