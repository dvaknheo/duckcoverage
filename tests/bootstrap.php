<?php
foreach ([__DIR__ . '/../vendor/autoload.php', __DIR__ . '/../autoload.php'] as $file) {
    if (file_exists($file)) {
        require $file;
        break;
    }
}

////////
$options=[
    //'path' => null,
    //'namespace' => "DuckCoverage",
    //'auto_detect_namespace' => true,

    //'path_src' => 'src',
    //'path_dump' => 'test_coveragedumps',
    //'path_report' => 'test_reports',
    //'path_data' => 'tests/data_for_tests',
];

try {
    LibCoverage\LibCoverage::_()->init($options);
} catch (\Throwable $ex) {

}

/**
 * 探测覆盖率驱动是否可用(xdebug/pcov)。
 * 注意:不能依赖 LibCoverage::G()->isInited()——libcoverage 的 is_inited 默认就是 true。
 */
