<?php
require dirname(__DIR__) . '/vendor/autoload.php';
use ZEngine\Core;
use ZEngine\System\Compiler;
use ZEngine\System\OpCode;
Core::init();
Core::$compiler->setOptions(Core::$compiler->getOptions() | Compiler::COMPILE_EXTENDED_STMT);
$counts = ['stmt' => 0, 'ret' => 0, 'throw' => 0];
OpCode::setHandler(OpCode::EXT_STMT, function ($s) use (&$counts): int { $counts['stmt']++; return Core::ZEND_USER_OPCODE_DISPATCH; });
OpCode::setHandler(OpCode::RETURN, function ($s) use (&$counts): int { $counts['ret']++; return Core::ZEND_USER_OPCODE_DISPATCH; });
OpCode::setHandler(OpCode::THROW, function ($s) use (&$counts): int { $counts['throw']++; return Core::ZEND_USER_OPCODE_DISPATCH; });
require __DIR__ . '/payload.php';
echo "fires: stmt={$counts['stmt']} ret={$counts['ret']} throw={$counts['throw']}\n";
echo "L4 DONE\n";
