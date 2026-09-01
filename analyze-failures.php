<?php

// Analyze the pest output: group failures by test class.
$c = file_get_contents('D:/laragon/www/laravel/cartbecart-cardpay/pest-all.txt');
$c = preg_replace('/\e\[[0-9;]*m/', '', $c);
preg_match_all('/FAILED\s+(Tests[^\s]+)\s*>\s*([^\n]{0,80})/', $c, $m);
$groups = [];
foreach ($m[1] as $i => $class) {
    $groups[$class][] = $m[2][$i];
}
foreach ($groups as $class => $tests) {
    echo count($tests)."  $class\n";
    foreach (array_slice($tests, 0, 2) as $t) {
        echo "     - $t\n";
    }
}
