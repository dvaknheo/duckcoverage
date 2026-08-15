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
    //'namespace' => null,
    //'auto_detect_namespace' => true,

    //'path_src' => 'src',
    //'path_dump' => 'test_coveragedumps',
    //'path_report' => 'test_reports',
    //'path_data' => 'tests/data_for_tests',
];

try {
    LibCoverage\LibCoverage::G()->init($options);
} catch (\Throwable $ex) {
    // 无覆盖率驱动(xdebug/pcov)时降级:测试照常运行,只是不收集覆盖率。
    // 注意:不要向 STDERR 输出,phpunit 会把 bootstrap 阶段的 stderr 当作测试错误。
}

/**
 * 探测覆盖率驱动是否可用(xdebug/pcov)。
 * 注意:不能依赖 LibCoverage::G()->isInited()——libcoverage 的 is_inited 默认就是 true。
 */
function _duckcoverage_has_driver(): bool
{
    try {
        $filter = new \SebastianBergmann\CodeCoverage\Filter();
        (new \SebastianBergmann\CodeCoverage\Driver\Selector())->forLineCoverage($filter);
        return true;
    } catch (\Throwable $ex) {
        return false;
    }
}
