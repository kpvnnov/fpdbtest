<?php
//https://career.habr.com/vacancies/1000136678
ini_set("log_errors", 1);
ini_set("display_errors", 1);
ini_set("error_log", "php-test.log");

use FpDbTest\Database;
use FpDbTest\DatabaseTest;
use FpDbTest\DatabaseEngine;
use FpDbTest\Quotation;

spl_autoload_register(function ($class) {
    $a = array_slice(explode('\\', $class), 1);
    if (!$a) {
        throw new Exception();
    }
    $filename = implode('/', [__DIR__, ...$a]) . '.php';
    require_once $filename;
});

//$mysqli = @new mysqli('localhost', 'root', 'password', 'database', 3306);
$mysqli = @new mysqli();
//if ($mysqli->connect_errno) {
//    throw new Exception($mysqli->connect_error);
//}
$quotation = new Quotation();
$db = new DatabaseEngine($mysqli, $quotation);

$test = new DatabaseTest($db);

$test->testBuildQuery();

exit('OK');
