<?php declare(strict_types=1);
/**
 * DuckCoverage RUN 子进程的测试夹具。
 *
 * 它不启动任何应用，只做两件可验证的事：
 * 1. 把父进程传下来的身份环境变量写进 DUCKCOVERAGE_RUN_FIXTURE_OUT 指向的文件；
 * 2. 按第一个参数给出的退出码退出（用来验证父进程对"RUN 失败"的处理）。
 */
$out = (string)getenv('DUCKCOVERAGE_RUN_FIXTURE_OUT');
if ($out !== '') {
    file_put_contents($out, json_encode([
        'child' => (string)getenv('DUCKCOVERAGE_RUN_CHILD'),
        'group' => (string)getenv('DUCKCOVERAGE_RUN_GROUP'),
        'name' => (string)getenv('DUCKCOVERAGE_RUN_NAME'),
        'argv' => array_slice((array)$GLOBALS['argv'], 1),
    ]));
}
exit((int)($GLOBALS['argv'][2] ?? 0));
