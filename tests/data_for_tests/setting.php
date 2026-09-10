<?php
/**
 * 测试专用配置，由 tests/DuckCoverageTest.php 直接 include（不走 DuckPHP 的 setting 机制）。
 *
 * 本目录被 tests/data_for_tests/.gitignore 忽略，属于本地可覆盖配置；
 * 文件不存在或缺项时，测试回落到默认值。
 */
return [
    'port' => 8017,
];
