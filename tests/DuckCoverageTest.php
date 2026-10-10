<?php
namespace tests\DuckCoverage;

use DuckPhp\Core\SuperGlobal;
use DuckPhp\Core\SystemWrapper;
use DuckPhp\DuckPhp;
use DuckCoverage\DuckCoverage;
use DuckPhp\Core\Console;
use LibCoverage\LibCoverage;
use DuckPhp\Core\PhaseContainer;

use Override;

class DuckCoverageTest extends \PHPUnit\Framework\TestCase
{
    public function testAll()
    {
        $__SERVER = $_SERVER;
        $old = LibCoverage::_();
        $path = LibCoverage::_()->getClassTestPath(DuckCoverage::class);
        LibCoverage::Begin(DuckCoverage::class);
        LibCoverage::_()->cleanDirectory($path);
        @mkdir($path);
        $this->makeData($path);
        DuckCoverage::_(DuckCoverageEx::_());

        include $path.'src/MyDuckCoverageApp.php';
        // 测试专用配置：端口等放在 tests/data_for_tests/setting.php，缺文件时用默认值
        $test_config = is_file(__DIR__ . '/data_for_tests/setting.php')
            ? (array)include __DIR__ . '/data_for_tests/setting.php'
            : [];
        $options = [
            'path'=>$path,
            'duckcoverage_test_lister' =>[DuckCoverageTestList::class,'GetTestList'],
            'duckcoverage_path_server' =>$path,
            'duckcoverage_server_port' => $test_config['port'] ?? 8017,
        ];
        //DuckCoverageApp::_(\MyDuckCoverageApp::_())->init($options);
        var_dump($_SERVER['argv']);
        $_SERVER['argv'] = ['-','duckcover'];
        DuckCoverageApp::_()->init($options);

        $this->cmd("cover --help");
        $this->cmd("cover --watch");
        $this->cmd("cover --play");
        $this->cmd("cover --report");
        $this->cmd("cover --stop");

        DuckCoverage::_()->options['duckcoverage_report_direct'] = true;
        $this->cmd("cover --watch group1");
        $this->cmd("cover --play group1");
        $this->cmd("cover --report group1 group2");
        $this->cmd("cover --report group1");
        $this->cmd("cover --go");

        // --go 不得覆盖 duckcoverage_report_direct：默认时应输出 <group>.report，
        // 与 watch + play + report + stop 手工四步得到同一个目录。
        DuckCoverage::_()->options['duckcoverage_report_direct'] = false;
        ob_start();
        $this->cmd("cover --go go_path_group");
        $go_output = (string)ob_get_clean();
        $this->assertStringContainsString('go_path_group.report', $go_output);
        $this->assertStringNotContainsString('AAAAA.report', $go_output);
        // --go 会提示"进程内换不了配置文件"这一已知限制（英文提示）
        $this->assertStringContainsString('cannot be switched between the phases', $go_output);

        // watchingGetName() 是公开方法：测试类不是 DuckCoverage 的子类，能调用就说明可见性正确
        $this->cmd("cover --watch watching_get_name_group");
        $this->assertSame('watching_get_name_group', DuckCoverageEx::_()->watchingGetName());
        $this->cmd("cover --stop");

        // --flag：命令行解析 -> getFlag() -> 请求头
        $this->cmd("cover --flag=cli_flag --help");
        $this->assertSame('cli_flag', DuckCoverage::_()->getFlag());
        $this->assertContains('X-DuckCoverage-Flag: cli_flag', DuckCoverageEx::_()->testPrepareCurlHeaders());
        // web 模式：优先用这次请求带来的头
        $_SERVER['HTTP_X_DUCKCOVERAGE_FLAG'] = 'header_flag';
        $this->assertSame('header_flag', DuckCoverage::_()->getFlag());
        unset($_SERVER['HTTP_X_DUCKCOVERAGE_FLAG']);
        // 头值里的 CR/LF 会被去掉（防头注入）
        DuckCoverage::_()->options['duckcoverage_flag'] = "bad\r\nvalue";
        $this->assertContains('X-DuckCoverage-Flag: badvalue', DuckCoverageEx::_()->testPrepareCurlHeaders());
        DuckCoverage::_()->options['duckcoverage_flag'] = '';
        // 没有 flag 时不发这个头
        $headers = DuckCoverageEx::_()->testPrepareCurlHeaders();
        foreach ($headers as $header) {
            $this->assertStringNotContainsString('X-DuckCoverage-Flag', $header);
        }
        // 裸 --flag（没有值）不改变已有配置
        DuckCoverage::_()->options['duckcoverage_flag'] = 'keep_me';
        $this->cmd("cover --flag --help");
        $this->assertSame('keep_me', DuckCoverage::_()->getFlag());
        DuckCoverage::_()->options['duckcoverage_flag'] = '';

        // 安全需求：--flag 只在 duckcoverage_enable 打开时生效。
        // 本测试进程里总开关一直是开着的（否则 doCommand() 早就 return 了），
        // 关掉它的分支属于环境相关分支，在 getFlag() 里标了 @codeCoverageIgnore。
        DuckCoverage::_()->options['duckcoverage_flag'] = '';

        // explainMarco 现在是 public：应用自己的 GetTestList 里可以先展开一段再返回
        $this->assertSame('', DuckCoverage::_()->explainMarco(''));
        $this->assertSame('#NOPE', DuckCoverage::_()->explainMarco('#NOPE'));

        // exclude()：可多次调用累加、Windows 反斜杠会被归一成 /，并同步给采集/报告侧
        $this->assertSame([], DuckCoverage::_()->options['duckcoverage_exclude']);
        $ret = DuckCoverage::_()->exclude(['src\\ThirdParty', ' src/System/Foo.php ']);
        $this->assertSame(DuckCoverage::_(), $ret);
        $this->assertSame(['src/ThirdParty', 'src/System/Foo.php'], DuckCoverage::_()->options['duckcoverage_exclude']);
        DuckCoverage::_()->exclude('ThirdParty');   // 字符串也接受
        $this->assertSame(['src/ThirdParty', 'src/System/Foo.php', 'ThirdParty'], DuckCoverage::_()->options['duckcoverage_exclude']);
        $this->assertSame(DuckCoverage::_()->options['duckcoverage_exclude'], \DuckCoverage\GroupCoverage::_()->options['exclude']);
        DuckCoverage::_()->exclude([]);             // 空数组是 no-op
        $this->assertCount(3, DuckCoverage::_()->options['duckcoverage_exclude']);

        // RUN 改成在新进程里执行：父进程把"这一轮的身份"用环境变量传下去，子进程用入口脚本跑那条子命令
        $run_out = LibCoverage::_()->getClassTestPath(DuckCoverage::class) . 'run_child.json';
        @unlink($run_out);
        putenv('DUCKCOVERAGE_RUN_FIXTURE_OUT=' . $run_out);
        $saved_entry = DuckCoverage::_()->options['duckcoverage_run_entry'];
        DuckCoverage::_()->options['duckcoverage_run_entry'] = __DIR__ . '/run_child_fixture.php';
        DuckCoverageEx::_()->testSetCurrent('unit_run', 'unit_group');

        ob_start();
        $ran = DuckCoverageEx::_()->testRunInNewProcess('cover:mycmd', ['7']);
        $run_output = (string)ob_get_clean();
        $this->assertTrue($ran);
        $this->assertStringContainsString('RUN failed (7): cover:mycmd', $run_output);
        $child = json_decode((string)file_get_contents($run_out), true);
        $this->assertSame('1', $child['child']);
        $this->assertSame('unit_group', $child['group']);
        $this->assertSame('unit_run', $child['name']);
        $this->assertSame(['cover:mycmd', '7'], $child['argv']);

        // 入口取不到（argv[0] 不是真实文件、也没配 run_entry）→ getRunEntry() 返回空，调用方退回同进程
        DuckCoverage::_()->options['duckcoverage_run_entry'] = '';
        $this->assertSame('', DuckCoverageEx::_()->testGetRunEntry());
        $saved_argv0 = $_SERVER['argv'][0];
        $_SERVER['argv'][0] = __DIR__ . '/run_child_fixture.php';   // 真实文件则直接采用
        $this->assertSame(__DIR__ . '/run_child_fixture.php', DuckCoverageEx::_()->testGetRunEntry());
        $_SERVER['argv'][0] = $saved_argv0;
        DuckCoverage::_()->options['duckcoverage_run_entry'] = $saved_entry;
        @unlink($run_out);
        putenv('DUCKCOVERAGE_RUN_FIXTURE_OUT');

        // RUN 子进程的自我配置：环境变量 → 认领组名/指令名，立刻 doBegin，退出时 doEnd 落盘
        putenv('DUCKCOVERAGE_RUN_CHILD=1');
        putenv('DUCKCOVERAGE_RUN_GROUP=env_group');
        putenv('DUCKCOVERAGE_RUN_NAME=env_name');
        DuckCoverageEx::_()->cleanName();
        DuckCoverageEx::_()->testSetupRunChild();
        $this->assertSame('env_group', DuckCoverageEx::_()->testCurrentGroup());
        $this->assertSame('env_name', DuckCoverageEx::_()->testCurrentName());
        DuckCoverageEx::_()->testDoEnd();
        $this->assertNotEmpty((array)glob(DuckCoverage::_()->options['duckcoverage_path'] . 'env_group/*.php'));
        putenv('DUCKCOVERAGE_RUN_CHILD');
        putenv('DUCKCOVERAGE_RUN_GROUP');
        putenv('DUCKCOVERAGE_RUN_NAME');

        // logException：把异常类名/错误码/位置/信息追加到 <duckcoverage_path>DuckCoverage.exception.log
        $log_path = DuckCoverageEx::_()->logException(new \RuntimeException("boom\nsecond line", 42));
        $this->assertNotSame('', $log_path);
        $log = (string)file_get_contents($log_path);
        $this->assertMatchesRegularExpression('/^\[\d{4}-\d\d-\d\dT[\d:+\-]+\] group=/', $log);
        $this->assertStringContainsString('class=RuntimeException', $log);
        $this->assertStringContainsString('code=42', $log);
        $this->assertStringContainsString('at=', $log);
        $this->assertStringContainsString('message=boom second line', $log);   // 换行被替换成空格
        $this->assertStringNotContainsString("boom\nsecond", $log);
        $lines_before = count(file($log_path, FILE_IGNORE_NEW_LINES));
        DuckCoverageEx::_()->logException(new \LogicException('second', 7));   // 追加，不覆盖
        $this->assertCount($lines_before + 1, file($log_path, FILE_IGNORE_NEW_LINES));

        // current_name 要把 flag 带进去（readCommand 会按当前 flag 重建名字）
        DuckCoverage::_()->options['duckcoverage_flag'] = 'name_flag';
        DuckCoverageEx::_()->readCommand('COMMENT x');
        $this->assertStringContainsString('flag=name_flag', DuckCoverageEx::_()->testCurrentName());
        DuckCoverage::_()->options['duckcoverage_flag'] = '';

        // CALL 出异常：记进 DuckCoverage.exception.log，整轮回放不中断（doEnd 照旧收尾）。
        // 用 :: 走静态调用（@ 在本框架里是"取单例 _() 再调"，夹具是普通类）
        ob_start();
        DuckCoverageEx::_()->testExplainCall([DuckCoverageThrowingCallable::class . '::boom']);
        $call_output = (string)ob_get_clean();
        $this->assertStringContainsString('CALL failed', $call_output);
        $this->assertStringContainsString('boom-in-call', (string)file_get_contents($log_path));
        $this->assertGreaterThan($lines_before + 1, count(file($log_path, FILE_IGNORE_NEW_LINES)));

        // （测试清单辅助 listOfAdminOrUser / TestListBy* 的断言放在 TestListerHelperTest，
        //   因为 LibCoverage 只收集 Begin() 那个类的文件，这里只会收集 DuckCoverage.php）

        DuckCoverage::_()->options['duckcoverage_report_direct'] = true;

        DuckCoverageApp::_()->testMore();

        // 上游 HttpServer 在 Windows 上现在能拿到真实 PID（修复前恒为 0）
        $this->assertGreaterThan(0, DuckCoverageEx::_()->testRunServer());

        // explainWeb() 拼请求地址时跟随 duckcoverage_server_host：
        // 空值/通配绑定地址回落到 127.0.0.1，IPv6 字面量补方括号。
        $this->assertSame('127.0.0.1', DuckCoverageEx::_()->testServerHostForRequest(''));
        $this->assertSame('127.0.0.1', DuckCoverageEx::_()->testServerHostForRequest('0.0.0.0'));
        $this->assertSame('127.0.0.1', DuckCoverageEx::_()->testServerHostForRequest('::'));
        $this->assertSame('localhost', DuckCoverageEx::_()->testServerHostForRequest('localhost'));
        $this->assertSame('[::1]', DuckCoverageEx::_()->testServerHostForRequest('::1'));

        /////////////
        $_SERVER['argv'] = $__SERVER['argv'];
        DuckCoverageApp::_(new DuckCoverageApp);
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        $_SERVER['SERVER_ADDR'] = '127.0.0.1';
        $_SERVER['HTTP_X_DUCKCOVERAGE_GROUP'] = 'group1';
        $_SERVER['HTTP_X_DUCKCOVERAGE_NAME'] = 'name1';
        $_SERVER['HTTP_X_DUCKCOVERAGE_BEFORERUN'] = DuckCoverageApp::class . '::beforerun';
        $_SERVER['HTTP_X_DUCKCOVERAGE_AFTERRUN'] = DuckCoverageApp::class . '::afterrun';
        $_SERVER['REQUEST_URI'] ='/';
        $_SERVER['PATH_INFO'] ='';
        DuckCoverageApp::_()->force_not_cli = true;
        DuckCoverageApp::_()->init($options);
        DuckCoverageEx::_()->cleanName();
        DuckCoverageApp::_()->serve();

        DuckCoverageApp::_()->testLists();
        $_SERVER['argv'] = ['-',''];
        DuckCoverageApp::_(new DuckCoverageApp)->init($options);
        $this->cmd("cover --watch xxx");
        $_SERVER['argv'] = $__SERVER['argv'];

        PhaseContainer::RestAllContainerForTesting();
        DuckCoverageApp2::_(new DuckCoverageApp2);
        DuckCoverageApp2::_()->init($options);
        $this->cmd("foo --go");
        DuckCoverageApp2::exit();

        PhaseContainer::RestAllContainerForTesting();
        DuckCoverageApp2::_(new DuckCoverageApp2);
        DuckCoverageApp2::_()->_is_cli = false;
        $_SERVER['HTTP_X_DUCKCOVERAGE_NAME'] = '';
        //$_SERVER['HTTP_X_DUCKCOVERAGE_NAME'] = 'name1';

        $_SERVER['HTTP_X_DUCKCOVERAGE_BEFORERUN'] = DuckCoverageApp::class . '::beforerun';

        $_SERVER['REQUEST_URI'] ='/';
        $_POST = ['A'=>"b"];
        DuckCoverageApp2::_()->init($options);
        DuckCoverageApp2::_()->serve();
        DuckCoverageApp2::exit();
        
        PhaseContainer::RestAllContainerForTesting();
        DuckCoverageApp2::_(new DuckCoverageApp2);
        DuckCoverageApp2::_()->_is_cli = false;
        $_SERVER['HTTP_X_DUCKCOVERAGE_NAME'] = 'name1';

        $_SERVER['REQUEST_URI'] ='/';
        DuckCoverageApp2::_()->init($options);
        DuckCoverageApp2::_()->serve();
        DuckCoverageApp2::exit();
        $_SERVER['HTTP_X_DUCKCOVERAGE_BEFORERUN'] = DuckCoverageApp::class . '::beforerun';
        DuckCoverage::_()->call_http_handler('HTTP_X_DUCKCOVERAGE_BEFORERUN');
        DuckCoverageEx::_()->watchingEnd();
        DuckCoverageEx::_()->init($options);
        DuckCoverageApp2::_()->duckcoverage_enable=false;
        DuckCoverageEx::_()->doCommand();
        /////////////
        PhaseContainer::RestAllContainerForTesting();

        $_SERVER['REQUEST_URI'] ='/';
        DuckCoverageApp2::_()->init($options);
        DuckCoverageEx::InitedThenGoRouteHookMode();
        DuckCoverageApp2::_()->serve();
        DuckCoverageApp2::exit();
        DuckCoverageEx::_()->watchingBegin("g1");
        DuckCoverageApp2::_()->serve();
        DuckCoverageEx::_()->route_hook_mode = false;
        DuckCoverageApp2::_()->serve();


        ///////////////
        // JSONL：不要求输出时上下文为空(行为与以前一致)，绝对路径不被改写
        DuckCoverageEx::_()->testParseJsonlOptions([]);
        $this->assertFalse(DuckCoverageEx::_()->testIsJsonlRequested());
        $this->assertSame([], DuckCoverageEx::_()->testGetJsonlContext('/tmp/r.report'));
        $this->assertSame('/abs/x.jsonl', DuckCoverageEx::_()->testResolvePath('/abs/x.jsonl'));
        // 裸 --jsonl 写在报告目录；--jsonl-detail 给了非字符串/空字符串时不改 detail
        DuckCoverageEx::_()->testParseJsonlOptions(['jsonl' => true, 'jsonl-detail' => true]);
        $this->assertStringEndsWith(DIRECTORY_SEPARATOR.'report.jsonl', DuckCoverageEx::_()->testGetJsonlContext('/tmp/r.report')['path']);
        DuckCoverageEx::_()->testParseJsonlOptions(['jsonl-detail' => '']);

        // CLI 端到端：--jsonl=FILE + detail=full + no-timestamp
        // 先造一份 dump：JSONL 在"一个 dump 都没合并"时会用退出码 2（那是给命令行/CI 的信号，
        // 在 PHPUnit 隔离进程里会被当成错误），所以这里给一个有数据的组
        DuckCoverageEx::_()->watchingBegin('jsonl_group');
        DuckCoverageEx::_()->doBegin();
        DuckCoverageEx::_()->doEnd();
        $jsonl_file = $path.'cli_report.jsonl';
        $this->cmd("cover --report jsonl_group --jsonl={$jsonl_file} --jsonl-detail=full --jsonl-no-timestamp");
        $this->assertFileExists($jsonl_file);
        $jsonl_text = (string)file_get_contents($jsonl_file);
        $this->assertStringStartsWith('{"t":"meta"', $jsonl_text);
        $this->assertStringContainsString('"t":"total"', $jsonl_text);
        $this->assertStringContainsString('"t":"file_lines"', $jsonl_text);
        $this->assertStringNotContainsString('"created"', $jsonl_text);

        // 相对路径按工程根解析（规格 §9）
        $rel_file = 'cli_report_rel.jsonl';
        $this->cmd("cover --report jsonl_group --jsonl={$rel_file}");
        $this->assertFileExists($path.$rel_file);
        $this->assertStringStartsWith('{"t":"meta"', (string)file_get_contents($path.$rel_file));

        // --format=jsonl：stdout 只有 JSONL，人读信息让路
        ob_start();
        $this->cmd("cover --report jsonl_group --format=jsonl");
        $stdout = (string)ob_get_clean();
        $this->assertTrue(DuckCoverageEx::_()->testIsJsonlStdout());
        $this->assertStringContainsString('"t":"meta"', $stdout);
        $this->assertStringContainsString('"t":"total"', $stdout);
        $this->assertStringNotContainsString('output path', $stdout);

        $_SERVER = $__SERVER;
        LibCoverage::_($old);
        LibCoverage::_()->cleanDirectory($path);
        LibCoverage::End();
    }
    protected function cmd(string $str)
    {
        $my_argv = explode(' ', $str);
        array_unshift($my_argv, '-');
        $_SERVER['argv']=$my_argv;
        DuckCoverageApp::_()->run();
    }
    protected function cmd2(string $str)
    {
        $my_argv = explode(' ', $str);
        array_unshift($my_argv, '-');
        $_SERVER['argv']=$my_argv;
        DuckCoverageApp2::_()->run();
    }

    protected function makeData($path)
    {

        @mkdir($path.'runtime/', 0777, true); 
        @mkdir($path.'src/');
        @mkdir($path.'public/');
        $str = <<<'EOT'
use DuckPhp\DuckPhp;
use DuckPhp\Core\Console;

class MyDuckCoverageApp extends tests\DuckCoverage\DuckCoverageApp
{
    public $options = [
        'is_debug' => true,
        'name'=> 'MyDuckCoverageApp',
        'cli_command_with_common' => true,

    ];
    protected function onPrepare(): void
    {
        parent::onPrepare();
        $this->options['cmd'] = array_merge([static::class => true], $this->options['cmd']);
    }

}

EOT;
'EOT';
        file_put_contents($path.'src/MyDuckCoverageApp.php',"<"."?php declare(strict_types=1);\n".$str);
        $str = <<<'EOT'
setcookie('CK'.DATE('His'),DATE('Y-m-d H:i:s'));
if($_GET['sleep']??null){
echo "sleep";
sleep(8);
}else{
echo "OK";
}
var_dump(DATE(DATE_ATOM));
EOT;
'EOT';

        file_put_contents($path.'public/index.php',"<"."?php declare(strict_types=1);\n".$str);
    }

    /**
     * 回归:根 phase 的 phase 名就是空字符串(合法),而 explainMarco() 会 rtrim 掉行尾空白,
     * 所以 "#PHASE_END" 回到根 phase 时展开出来的是**无参数**的 "PHASE" 行。
     * explainPhase() 原来直接取 $argv[0],于是 Undefined array key 0 触发 E_WARNING。
     */
    public function testBarePhaseLine()
    {
        // 1) 先确认这条行确实是无参数的 PHASE(空 phase 名被 rtrim 掉了)。
        //    进程隔离(processIsolation)保证这里是全新的单例,last_phase 为 null。
        $expanded = trim((string)\DuckCoverage\TestListerHelper::_()->explainMarco("#PHASE_END\n"));
        $this->assertSame('PHASE', $expanded);

        // 2) 无参数的 PHASE 行不应触发任何 PHP 警告,且语义等价于「切回空 phase」
        $warnings = [];
        set_error_handler(function ($errno, $errstr) use (&$warnings) {
            $warnings[] = $errstr;
            return true;    // 自己吞掉,不交给 PHPUnit 当失败处理
        });
        try {
            \DuckPhp\Core\App::Phase('some_phase');
            $this->assertSame('some_phase', \DuckPhp\Core\App::Phase());

            DuckCoverageEx::_()->readCommand('PHASE');
        } finally {
            restore_error_handler();
        }

        $this->assertSame([], $warnings, '无参数的 PHASE 行不应触发 PHP 警告');
        $this->assertSame('', \DuckPhp\Core\App::Phase());
    }

    /**
     * 回归:空 RUN 行(没有命令名)。
     * 本文件是 declare(strict_types=1),原来 array_shift([]) 得到 null,
     * strpos(null, ':') 直接抛 TypeError(不是 deprecation,dev_error_handler 接不住),
     * 会让整个 play 当场中断。
     */
    public function testBareRunLine()
    {
        DuckCoverageEx::_()->stop = true;   // 本用例不该走到 doBegin/doEnd

        ob_start();
        DuckCoverageEx::_()->readCommand('RUN');
        $out = (string)ob_get_clean();

        $this->assertStringContainsString('Skip empty RUN', $out);
    }
}
class DuckCoverageEx extends DuckCoverage
{
    public $stop = false;
    public function checkHttp()
    {
        return ;//parent::checkHttp();
    }
    public function cleanName()
    {
        $this->current_group = "";
        $this->current_name = "";
    }
    public function watchingBegin($name)
    {
        return parent::watchingBegin($name);
    }
    #[Override]
    public function doBegin()
    {
        if($this->stop){return;}
        return parent::doBegin();
    }
    #[Override]
    public function doEnd()
    {
        if($this->stop){return;}
        return parent::doEnd();
    }
    public function readCommand($request)
    {
        $this->current_group = 'nogroup';
        $this->current_name = 'noname';
        return parent::readCommand($request);
    }
    public function testRunServer()
    {
        $this->startServer();
        $pid = \DuckPhp\HttpServer\HttpServer::_()->getPid();
        $this->stopServer();
        return $pid;
    }
    /**
     * @param array<string, mixed> $p
     */
    public function testParseJsonlOptions(array $p): void
    {
        $this->parseJsonlOptions($p);
    }
    /**
     * @return array<string, mixed>
     */
    public function testGetJsonlContext(string $path_report): array
    {
        return $this->getJsonlContext($path_report);
    }
    public function testResolvePath(string $path): string
    {
        return $this->resolvePath($path);
    }
    public function testIsJsonlStdout(): bool
    {
        return $this->isJsonlStdout();
    }
    public function testIsJsonlRequested(): bool
    {
        return $this->isJsonlRequested();
    }
    public function testCurrentName(): string
    {
        return (string)$this->current_name;
    }
    public function testCurrentGroup(): string
    {
        return (string)$this->current_group;
    }
    public function testSetCurrent(string $name, string $group): void
    {
        $this->current_name = $name;
        $this->current_group = $group;
    }
    public function testGetRunEntry(): string
    {
        return $this->getRunEntry();
    }
    /**
     * @param array<int, string> $args
     */
    public function testRunInNewProcess(string $sub_cmd, array $args): bool
    {
        return $this->runInNewProcess($sub_cmd, $args);
    }
    public function testSetupRunChild(): void
    {
        $this->setupRunChild();
    }
    public function testDoEnd(): void
    {
        $this->doEnd();
    }
    /**
     * @param array<int, string> $argv
     */
    public function testExplainCall(array $argv): void
    {
        $this->explainCall($argv);
    }
    /**
     * 立刻走一遍 prepareCurl()，把要发出去的请求头拿出来看
     *
     * @return array<int, string>
     */
    public function testPrepareCurlHeaders(): array
    {
        $old_name = $this->current_name;
        $this->headers = [];
        $this->current_name = 'probe_name';
        $this->prepareCurl(null);
        $headers = $this->headers;
        $this->current_name = $old_name;
        return $headers;
    }
    public function testServerHostForRequest(string $host)
    {
        $old = $this->options['duckcoverage_server_host'];
        $this->options['duckcoverage_server_host'] = $host;
        $ret = $this->getServerHostForRequest();
        $this->options['duckcoverage_server_host'] = $old;
        return $ret;
    }
    public  function cloze_curl()
    {
        $this->curl_file_get_contents(['http://ai.local.com/?sleep=1', "127.0.0.1:{$this->options['duckcoverage_server_port']}"]);
    }
    public $_is_cli = true;
    protected function is_cli()
    {
        var_dump("sssssssssssssssssssssssssss");
        var_dump($this->_is_cli);
        return $this->_is_cli;
    }
    public function call_http_handler($name)
    {
        return parent::call_http_handler($name);
    }
    public function watchingEnd()
    {
        return parent::watchingEnd();
    }

}
class DuckCoverageApp extends DuckPhp
{
    public $force_not_cli =false;
    public function testLists()
    {
        try{
            $str = DuckCoverage::_()->genTestListOfAll();
        }catch(\Exception $e){
            //
        }
    }
    public function _Setting($key = null, $default = null)
    {
        if ($key ==='duckcoverage_enable') {
            return true;
        }
        return parent::_Setting($key, $default);
    }
    public function isCli()
    {
        if ($this->force_not_cli){
            return false;
        }
        return parent::isCli();
    }
    public function command_mycmd()
    {
        //$this->assertTrue(true);
        $data = Console::_()->getCliParameters();
        //var_dump($data);
    }

    public static function beforerun()
    {
        //$this->assertTrue(true);
        
    }
    public static function afterrun()
    {
        //$this->assertTrue(true);
    }
    public static function pre_curl($ch,$name)
    {
        //$this->assertTrue(true);
    }
    public static function post_curl($ch,$name)
    {
        var_dump($name);
    }
    public static function pre_web()
    {
        //$this->assertTrue(true);
    }
    public static function post_web()
    {
        //$this->assertTrue(true);
    }
    public static function Callback()
    {
        //$this->assertTrue(true);
        return;
    }

    public $options =[
        'is_debug' => true,
        'duckcoverage_debug_curl_echo_back' => true,
        'path_namespace' => 'app',
    ];
    public function __construct()
    {
        parent::__construct();
        $path = explode('\\', static::class);
        $short_class = array_pop($path);
        $namespace = implode("\\", $path);
        $ext_options = [
            'namespace_controller' => "\\".$namespace,
            'name' => 'DuckCoverageApp',
            'controller_welcome_class' => $short_class ,
            'controller_class_postfix' => '',
            'controller_method_prefix' => 'action_',
        ];
        $this->options = array_merge($this->options, $ext_options);
        $this->options['cmd'] = array_merge([static::class => true], $this->options['cmd']);

    }

    public function action_index()
    {
        var_dump(static::class);
        
    }
    protected function onPrepare(): void
    {
        parent::onPrepare();
        DuckCoverage::Prepare();
    }
    public function func($name, $default='_')
    {

    }
    public function testMore()
    {
        $this->is_root = false;
        DuckCoverage::_()->init($this->options,null);
        DuckCoverage::_()->beforeInit();
        $this->is_root = true;

        // 覆盖 init() 的提前返回分支：duckcoverage_stop_init = true 时不做任何配置
        $this->options['duckcoverage_stop_init']=true;
        DuckCoverage::_()->init($this->options,null);
        DuckCoverage::_()->beforeInit();
        $this->options['duckcoverage_stop_init']=false;

        // 下面用内置函数 is_string() 覆盖 callHandler 的「函数调用」分支：
        // 它的第一个参数在 PHP 7.4 叫 $var、PHP 8.0 起叫 $value，
        // 而参数是按名字注入的，所以两个名字都传，7.4 与 8.x 上都能命中。
        $str=<<<EOT
COMMENT just a test
BAD 
PHASE 
CALL {static}::Callback
SETWEB OPTIONS _ _ _


RUN mycmd {ARG}
RUN mycmd {ARG}
RUN mycmd {ARG}
RUN mycmd {ARG}
RUN mycmd {ARG}
RUN mycmd {ARG}
RUN mycmd {ARG}
RUN mycmd {ARG}
RUN :mycmd

CALL @bad
CALL !is_string var=ok&value=ok
CALL is_string var=ok&value=ok
CALL {static}->func name=n1
CALL {static}@func name=n2
CALL {static}@func

EOT;

        $str .= <<<EOT
WEB /
SETWEB {static}::pre_curl {static}::pre_web {static}::prost_web {static}::post_curl
WEB /
WEB / a=b POST
SETWEB AJAX _ _ _
WEB /

SETWEB {static}::pre_cloze_curl _ _ _
CALL {static}::cloze_curl


EOT;

$testInputs = <<<'TESTCASES'

a b
'a b'
"a b"
a\ b
"a\"b" "c\d"
\xF0\x9F\x98\x80
TESTCASES;
        $t = explode("\n",$testInputs);
        foreach($t as $v){
            $str = $this->str_replace_first("{ARG}",$v, $str);
        }
        
        $str = str_replace('{static}',static::class,$str);
        $str = str_replace('{cliprefix}',$this->getThisCommandPrefix(),$str);
        $cmds = explode("\n",$str);
        DuckCoverageEx::_()->stop =true;
        foreach($cmds as $cmd){
            DuckCoverageEx::_()->readCommand($cmd);
global $time_start;var_dump(microtime(true)-$time_start);
        }

        DuckCoverageEx::_()->testRunServer();



    }
    private function str_replace_first(string $search, string $replace, string $subject): string
    {
        $pos = strpos($subject, $search);
        if ($pos === false) return $subject;
        return substr_replace($subject, $replace, $pos, strlen($search));
    }
    public static function pre_cloze_curl($ch,$name)
    {
        var_dump(__FUNCTION__);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 1);
        curl_setopt($ch, CURLOPT_TIMEOUT, 1);
        var_dump($name);
    }
    public static function cloze_curl()
    {
        DuckCoverageEx::_()->cloze_curl();
    }
}
class DuckCoverageTestList
{
    public static function Callback()
    {
        $path = LibCoverage::_()->getClassTestPath(DuckCoverage::class);
        return;
    }
    public static function GetTestList()
    {

        $str=<<<EOT
COMMENT just a test

EOT;
        return $str;
    }
}
class DuckCoverageApp2 extends DuckPhp
{
    public $duckcoverage_enable =false;
    public function _Setting($key = null, $default = null)
    {
        if ($key ==='duckcoverage_enable') {
            return $this->duckcoverage_enable;
        }
        return parent::_Setting($key, $default);
    }

    protected static $func;
    public static function register_shutdown_function($func)
    {
        static::$func = $func;
    }
    public static function exit()
    {
        var_dump("exit!");
        if(static::$func){
            $callback = static::$func;
            ($callback)();
        }
    }
    public function command_foo()
    {
        var_dump("foo");
    }
    public $_is_cli = true;
    protected function onPrepare(): void
    {
        parent::onPrepare();
        $this->options['cmd'] = array_merge([static::class => true], $this->options['cmd']);

        SystemWrapper::system_wrapper_replace(['register_shutdown_function'=>[DuckCoverageApp2::class, 'register_shutdown_function']]);
        $this->duckcoverage_enable = false;
        DuckCoverage::_(DuckCoverageEx::_(new DuckCoverageEx));
        DuckCoverage::Prepare([]);

        $this->duckcoverage_enable = true;
        DuckCoverage::_()->_is_cli = $this->_is_cli;
        DuckCoverage::Prepare([]);
        (new JustDuckCoverageCli)->is_cli();
    }

}

class JustDuckCoverageCli extends DuckCoverage
{
    public function is_cli()
    {
        return parent::is_cli();
    }
}
/** CALL 指令的异常夹具：被 CALL 时应抛异常，用来验证 DuckCoverage 会 logException 并继续 */
class DuckCoverageThrowingCallable
{
    public static function boom()
    {
        throw new \RuntimeException('boom-in-call', 123);
    }
}