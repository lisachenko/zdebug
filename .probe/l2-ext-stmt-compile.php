<?php
require dirname(__DIR__) . '/vendor/autoload.php';
use ZEngine\Core;
use ZEngine\System\Compiler;
Core::init();
Core::$compiler->setOptions(Core::$compiler->getOptions() | Compiler::COMPILE_EXTENDED_STMT);
require __DIR__ . '/payload.php';
echo "L2 DONE\n";
