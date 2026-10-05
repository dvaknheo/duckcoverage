<?php declare(strict_types=1);
/**
 * DuckPhp
 * From this time, you never be alone~
 */

namespace DuckCoverage;

use DuckCoverage\TestListerHelper;
use DuckPhp\Core\App;
use DuckPhp\Core\ComponentBase;
use DuckPhp\Core\Console;
use DuckPhp\Core\PhaseContainer;
use DuckPhp\Core\Route;
use DuckPhp\Core\SuperGlobal;
use DuckPhp\Core\SystemWrapper;
use DuckPhp\HttpServer\HttpServer;
use LibCoverage\GroupCoverage;

class DuckCoverage extends ComponentBase
{
    use DuckCoverage_CommandTrait;
    use DuckCoverage_HttpServerTrait;
    use DuckCoverage_HttpClientTrait;

    const VERSION = '1.0.1';
    public $options = [
        'duckcoverage_stop_init' => false,
        'duckcoverage_data_file_json_file' => 'DuckPhpData-duckcoverage.config.json',
        'duckcoverage_reg_console_command' => true,
        'duckcoverage_test_lister' => null,
        // --flag=xxx：随采集请求一路带下去的标记；web 模式下放进 X-DuckCoverage-Flag 请求头
        'duckcoverage_flag' => '',

        'duckcoverage_path' => '',
        'duckcoverage_path_src' => 'src/', // 需要
        'duckcoverage_report_direct' => false,
        'duckcoverage_report_default_dir' => 'AAAAA.report',

        // JSONL 报告：--jsonl[=FILE] 输出一行一个 JSON 对象；--format=jsonl 则写到 stdout
        'duckcoverage_jsonl_enable' => false,
        'duckcoverage_jsonl' => null,                // null = 不写文件；'' = 报告目录下 report.jsonl；其它 = 该路径(相对工程根)
        'duckcoverage_jsonl_detail' => 'uncovered',  // none | uncovered | full
        'duckcoverage_jsonl_no_timestamp' => false,  // 省略 meta.created，便于两次输出逐字节 diff
        'duckcoverage_format' => '',                 // 'jsonl' = JSONL 写到 stdout

        'duckcoverage_web_base_url' => '',
        // 外部服务器(如 nginx)基础 URL,如 http://admin.duckphp-local.com/ ;空则退回内部测试服务器
        'duckcoverage_curl_connecttimeout' => 10,
        'duckcoverage_curl_timeout' => 30,

        'duckcoverage_server_port' => 8017,
        'duckcoverage_server_host' => '',
        'duckcoverage_path_server' => '',
        'duckcoverage_path_document' => 'public',
        'duckcoverage_homepage' => '/',
        'duckcoverage_new_server' => true,
        'duckcoverage_debug_curl_echo_back' => false,

    ];

    protected $current_group;
    protected $current_name;
    protected $current_path_src;
    protected $current_path_dump;
    protected $is_manual = false;
    protected $in_subcmd = false;

    protected $default_cmd = 'cover';
    protected $default_path = 'DuckCoverage/';
    protected $url_base = '';
    public $route_hook_mode = false;
    public static function BeforeRun()
    {
        return static::_()->_OnBeforeRun();
    }
    public static function AfterRun()
    {
        return static::_()->_OnAfterRun();
    }
    public static function Prepare($options = [])
    {
        return static::_()->beforeInit($options);
    }
    public static function InitedThenGoRouteHookMode()
    {
        return static::_()->runWithRouteHookMode();
    }
    public function beforeInit($options = [])
    {
        App::_()->options = array_merge($options, App::_()->options);

        if (!App::_()->isRoot()) {
            return;
        }
        if (App::_()->options['duckcoverage_reg_console_command'] ?? true) {
            PhaseContainer::_()->addSharedClasses([static::class => true, Console::class => true]);
            App::_()->regConsoleCommand(static::class, 'command_');
        }
        if (!App::Setting('duckcoverage_enable', false)) {
            return;
        }

        $argv = SuperGlobal::_()->_SERVER('argv', []);
        $cmd = $argv[1] ?? '';

        if ($cmd === $this->default_cmd) {
            $this->in_subcmd = true;
        }
        $path_runtime = App::_()->getRuntimePath();

        $this->options['duckcoverage_path'] = $path_runtime . $this->default_path;

        $this->current_group = $this->watchingGetName();

        $this->moveDateJsonFile();
        SystemWrapper::header("x-duckcoverage-datafile: {$this->options['duckcoverage_data_file_json_file']}");
        // 诊断用：把本次的 flag 回显在响应头里（与 x-duckcoverage-group 同类）
        SystemWrapper::header("x-duckcoverage-flag: {$this->getFlag()}");
        if ($this->is_cli()) {
            echo "\033[41;30m";
            echo "DuckCoverage running: JSON_FILE: {$this->options['duckcoverage_data_file_json_file']}";
            echo "\033[0m\n";
        }

        App::_()->options['ext'][static::class] = true;
        PhaseContainer::_()->addSharedClasses([static::class => true]);
    }

    protected function moveDateJsonFile()
    {
        if ($this->current_group) {
            $path_runtime = App::_()->options['path_runtime'] ?? 'runtime';
            $path = $this->default_path; //$this->options['duckcoverage_path'];
            $this->options['duckcoverage_data_file_json_file'] = $path . $this->current_group.'.DuckPhpData.config.json';
        }
        App::_()->options['data_file_json_file'] = $this->options['duckcoverage_data_file_json_file'];
        App::_()->options['data_file_enable'] = true;
    }
    public function init(array $options, ?object $context = null)
    {
        parent::init($options, $context);

        if (!App::_()->isRoot()) {
            return $this;
        }
        if ($this->options['duckcoverage_stop_init']) {
            return $this;
        }
        $path_project = App::_()->getProjectPath();
        $path_runtime = App::_()->getRuntimePath();

        $this->options['duckcoverage_path'] = $path_runtime . $this->default_path;
        $this->options['duckcoverage_path_server'] = $this->options['duckcoverage_path_server'] ?
            $this->options['duckcoverage_path_server'] : $path_project;

        $is_abs = preg_match('#^(?:/|[a-zA-Z]:[\\\\/]|\\\\{2})#', $this->options['duckcoverage_path_src'] ?? '') > 0;
        $this->current_path_src = $is_abs ? $this->options['duckcoverage_path_src'] : $path_project . $this->options['duckcoverage_path_src'];
        $this->current_path_dump = $this->options['duckcoverage_path'];

        @mkdir($this->options['duckcoverage_path'], 0777, true);
        @chmod($this->options['duckcoverage_path'], 0777);
        // if ($this->options['duckcoverage_reg_console_command']) {
        //     App::_()->regConsoleCommand(static::class, 'command_');
        // }
        $this->current_group = $this->watchingGetName();
        $this->initAction();

        return $this;
    }
    protected function initAction()
    {
        if ($this->in_subcmd) {
            return;
        }
        if (!$this->current_group) {
            return;
        }
        SystemWrapper::header("x-duckcoverage-group: {$this->current_group}");

        if (App::_()->isCli()) {
            echo "\033[41;30m";
            echo "DuckCoverage GROUP $this->current_group";
            echo "\033[0m\n";
        }
        $this->current_name = $this->make_name();

        //if (PHP_SAPI === 'cli') {
        SystemWrapper::register_shutdown_function(function () {
            if ($this->route_hook_mode) {
                return;                                                     // @codeCoverageIgnore
            }
            $this->call_http_handler('HTTP_X_DUCKCOVERAGE_AFTERRUN');   // @codeCoverageIgnore
            $this->doEnd();                                             // @codeCoverageIgnore
            $this->current_name = '';
            $this->current_group = '';

        });
        $this->doBegin();
        $this->call_http_handler('HTTP_X_DUCKCOVERAGE_BEFORERUN');      // @codeCoverageIgnore
    }
    public function runWithRouteHookMode()
    {
        $this->route_hook_mode = true;
        $this->doEnd();
        $this->current_name = '';
        $this->current_group = '';
        Route::_()->addRouteHook([static::class, 'BeforeRun'], 'prepend-outter');
        Route::_()->addRouteHook([static::class, 'AfterRun'], 'finally-outter');
    }
    public function _OnBeforeRun()
    {
        if (!$this->route_hook_mode) {
            return;
        }
        $this->current_group = $this->watchingGetName();
        if (!$this->current_group) {
            return;
        }
        $this->current_name = $this->make_name_of_http();
        $this->doBegin();
        $this->call_http_handler('HTTP_X_DUCKCOVERAGE_BEFORERUN');// @codeCoverageIgnore
    }

    public function _OnAfterRun()
    {
        if (!$this->route_hook_mode) {
            return;
        }
        $this->call_http_handler('HTTP_X_DUCKCOVERAGE_AFTERRUN');
        $this->doEnd();
    }
    protected function is_cli()
    {
        return PHP_SAPI === 'cli';
    }
    protected function make_name()
    {
        if ($this->is_cli()) {
            return $this->make_name_of_cli();
        } else {
            return $this->make_name_of_http();
        }
    }
    protected function make_name_of_cli()
    {
        $argv = SuperGlobal::_()->_SERVER('argv', []);
        $argsOnly = array_slice($argv, 1);
        $cmd = implode(' ', array_map('escapeshellarg', $argsOnly));
        $request = 'MAN-RUN '.$cmd;
        return "[{$this->current_group} " . (new \DateTime())->format('Y-m-d_H_i_s.v') . "]" . $request;
    }
    protected function make_name_of_http()
    {
        $name = SuperGlobal::_()->_SERVER('HTTP_X_DUCKCOVERAGE_NAME', null);
        if ($name) {
            return $name;
        }
        $request = SuperGlobal::_()->_SERVER('REQUEST_URI', '');
        $post = SuperGlobal::_()->_POST();
        $request .= $post ? ' '.http_build_query($post) : '';
        $request = 'MAN-WEB '.$request;
        return "[{$this->current_group} " . (new \DateTime())->format('Y-m-d_H_i_s.v') . "]" . $request;
    }
    protected function call_http_handler($name)
    {
        $runner = SuperGlobal::_()->_SERVER($name, '');
        if ($runner) {
            $this->callHandler($runner);
        }
    }
    protected function getTestListerText()
    {
        $test_list = '';
        $callback = $this->options['duckcoverage_test_lister'] ?? null;
        if (is_callable($callback)) {
            $test_list = $callback();
            $test_list = $this->explainMarco($test_list);
        }
        return $test_list;
    }
    protected function explainMarco($test_list)
    {
        return TestListerHelper::_()->explainMarco($test_list);
    }
    //////////////////
    public function genTestListOfAll()
    {
        return TestListerHelper::_()->genTestListOfAll();
    }
    //////////////////
    protected function play()
    {
        $this->cleanClientStatus();
        $this->url_base = __url('');
        $test_list = $this->getTestListerText();
        $test_list = \explode("\n", $test_list);
        foreach ($test_list as $line) {
            $this->readCommand($line);
        }
        $this->stopServer();
    }
    /**
     * DuckCoverage commands form dvaknheo/duckcoverage
     */
    public function command_cover()
    {
        return $this->doCommand();
    }
    public function doCommand()
    {
        if (!App::Setting('duckcoverage_enable', false)) {
            echo "\033[41;30m";
            echo "turn on setting to work: 'duckcoverage_enable'";
            echo "\033[0m\n";
            return;
        }
        @mkdir($this->current_path_dump);

        $p = Console::_()->getCliParameters();
        $this->parseJsonlOptions($p);
        $this->parseFlagOption($p);

        if (($p['help'] ?? false) || (count($p) === 1)) {
            $str = <<<EOT
--watch {group}
--play
--stop
--report a
--report a b c
--go {group}
--flag={value}
--jsonl[=FILE] [--jsonl-detail=uncovered|full|none] [--jsonl-no-timestamp]
--format=jsonl
EOT;
            echo $str;
            return;
        }
        if ($p['watch'] ?? false) {
            $watch_name = $p['watch'];
            if ($watch_name === true) {
                $watch_name = 'default_' . DATE('Y_m_d_H_i_s');
            }
            $this->watchingBegin($watch_name);

            echo "watching {$watch_name}\n";
        }
        if ($p['stop'] ?? false) {
            $this->watchingEnd();
        }
        if ($p['play'] ?? false) {
            echo "playing\n";
            $this->play();
            echo "played\n";
        }

        if ($p['report'] ?? false) {
            $this->echoHuman("reporting...\n");
            $groups = $p['report'];
            if ($groups === true) {
                $groups = $this->watchingGetName();
            }
            $this->doReport($groups);
        }
        if ($p['go'] ?? false) {
            $watch_name = $p['go'];
            if ($watch_name === true) {
                $watch_name = 'default_' . DATE('Y_m_d_H_i_s');
            }
            // 不覆盖 duckcoverage_report_direct：--go 必须等价于 watch + play + report + stop，
            // 报告目录由该选项与组数决定（单组 -> <group>.report，多组/直写 -> report_default_dir）。
            $this->watchingBegin($watch_name);
            $this->echoHuman("watching {$watch_name}\n");
            // 已知限制（不在这里解决，只提示 + 文档）：--go 在一个进程里走完 watch+play+report+stop，
            // 启动时加载的配置文件（ExtOptionsLoader 等，以及 DuckPHP 数据文件）无法在阶段之间切换。
            // TODO_go模式下无法切换配置文件的问题
            $this->echoHuman(
                "[--go] Note: --go runs watch + play + report + stop in one process, so everything loaded\n" .
                "       at startup (ExtOptionsLoader config files, the DuckPHP data file, ...) is loaded once\n" .
                "       and cannot be switched between the phases. To change a config file before the play\n" .
                "       phase, use --watch ... , switch the file, then run --play and --report instead of --go.\n"
            );
            $this->play();
            $this->echoHuman("reporting...\n");
            $this->doReport([$watch_name]);
            $this->watchingEnd();
            $this->echoHuman("watched {$watch_name}\n");
        }
    }
    /**
     * 解析 JSONL 相关开关（规格 §9）
     *
     * --jsonl=FILE 写文件（FILE 相对工程根；裸 --jsonl 写在报告目录的 report.jsonl）
     * --jsonl-detail=none|uncovered|full
     * --jsonl-no-timestamp   省略 meta.created，便于两次输出逐字节 diff
     * --format=jsonl         把 JSONL 写到 stdout（只保证 --report 模式下是纯 JSONL）
     *
     * @param array<string, mixed> $p
     */
    protected function parseJsonlOptions(array $p): void
    {
        if (array_key_exists('jsonl', $p)) {
            $this->options['duckcoverage_jsonl_enable'] = true;
            $this->options['duckcoverage_jsonl'] = ($p['jsonl'] === true) ? '' : (string)$p['jsonl'];
        }
        // 注意：Console::parseCliArgs() 会把命令行里的 '-' 归一成 '_'（--jsonl-detail -> jsonl_detail），
        // 所以两种键名都接受：下划线那份来自真实命令行，带横线那份便于直接构造参数。
        $detail = $p['jsonl_detail'] ?? $p['jsonl-detail'] ?? null;
        if (is_string($detail) && $detail !== '') {
            $this->options['duckcoverage_jsonl_detail'] = $detail;
        }
        if ($p['jsonl_no_timestamp'] ?? $p['jsonl-no-timestamp'] ?? false) {
            $this->options['duckcoverage_jsonl_no_timestamp'] = true;
        }
        if (($p['format'] ?? '') === 'jsonl') {
            $this->options['duckcoverage_format'] = 'jsonl';
            $this->options['duckcoverage_jsonl_enable'] = true;
        }
    }
    /**
     * --flag=xxx / --flag xxx：给这次采集带一个标记
     *
     * 应用侧（包括自己的业务代码与测试清单回调）统一用 getFlag() 读它；
     * web 模式下它会随每个采集请求放进 X-DuckCoverage-Flag 头。
     * 裸 --flag（没有值）不改变已有配置。
     *
     * @param array<string, mixed> $p
     */
    protected function parseFlagOption(array $p): void
    {
        $flag = $p['flag'] ?? null;
        if (is_string($flag)) {
            $this->options['duckcoverage_flag'] = $flag;
        }
    }
    /**
     * JSONL 是否写到 stdout
     */
    protected function isJsonlStdout(): bool
    {
        return $this->options['duckcoverage_format'] === 'jsonl';
    }
    /**
     * JSONL 是否要求输出（文件或 stdout）
     */
    protected function isJsonlRequested(): bool
    {
        return (bool)$this->options['duckcoverage_jsonl_enable'] || $this->isJsonlStdout();
    }
    /**
     * 人读信息：JSONL 写到 stdout 时一律让路，避免污染管道
     */
    protected function echoHuman(string $str): void
    {
        if (!$this->isJsonlStdout()) {
            echo $str;
        }
    }
    protected function doReport($groups)
    {
        $groups = is_array($groups) ? $groups : [$groups];
        $time_begin = microtime(true);

        $path_report = $this->current_path_dump;
        if (count($groups) === 1 && !$this->options['duckcoverage_report_direct']) {
            $path_report = $path_report . $groups[0] . '.report';
        } else {
            $path_report = $path_report . $this->options['duckcoverage_report_default_dir'];
        }
        $stats = $this->createReport($groups, $this->current_path_src, $this->current_path_dump, $path_report);
        $time_end = microtime(true);
        $time_cost = $time_end - $time_begin;
        $time_cost = sprintf('%0.3f', $time_cost);
        if ($this->isJsonlStdout()) {
            // stdout 必须是纯 JSONL：人读信息一律让路(只保证 --report 模式)
            echo (string)($stats['jsonl_text'] ?? '');
            $this->exitIfJsonlIncomplete($stats);
            return;
        }
        echo "time_cost   : $time_cost seconds \noutput path : $path_report \n";
        echo "json report : " . ($stats['json_report'] ?? '') . " \n";
        if (!empty($stats['jsonl_report'])) {
            echo "jsonl report: " . $stats['jsonl_report'] . " \n";
        }
        $this->exitIfJsonlIncomplete($stats);
    }
    /**
     * JSONL 模式下用退出码区分故障（规格 §9）：写文件失败 = 1，一个 dump 都没合并 = 2；
     * 没要求 JSONL 时不改变原有退出码。
     *
     * @param array<string, mixed> $stats
     */
    protected function exitIfJsonlIncomplete(array $stats): void
    {
        if (!$this->isJsonlRequested()) {
            return;
        }
        if (empty($stats['jsonl_report']) && empty($stats['jsonl_text'])) {
            fwrite(STDERR, "duckcoverage: jsonl report was not produced\n"); // @codeCoverageIgnore
            exit(1); // @codeCoverageIgnore
        }
        if ((int)($stats['dumps_merged'] ?? 0) === 0) {
            fwrite(STDERR, "duckcoverage: no coverage dump merged, jsonl only has meta and total\n"); // @codeCoverageIgnore
            exit(2); // @codeCoverageIgnore
        }
    }
    ////]]]]
    ////[[[[
    protected function watchingBegin($name)
    {
        $this->current_group = $name;
        file_put_contents($this->current_path_dump . $name . '.watch.lock', DATE(DATE_ATOM));
        file_put_contents($this->current_path_dump . 'DuckCoverage.watching.txt', $name);
    }
    protected function watchingEnd()
    {
        $this->current_group = null;
        $name = $this->watchingGetName();
        @unlink($this->options['duckcoverage_path'] . 'DuckCoverage.watching.txt');
        @unlink($this->options['duckcoverage_path'] . basename((string)$name) . '.watch.lock');
    }
    /**
     * 当前正在监听的组名：优先取进程内的状态，其次读 DuckCoverage.watching.txt（即"最后 watch 的组"）。
     *
     * 公开方法：外部（如测试清单回调、诊断代码）也常需要知道当前组名。
     *
     * @return string|false 组名；没有在监听、或读取失败时为 false
     */
    public function watchingGetName()
    {
        if ($this->current_group) {
            return $this->current_group;
        }
        $group = @file_get_contents($this->options['duckcoverage_path'] . 'DuckCoverage.watching.txt');
        return $group;
    }
    /**
     * 本次运行的 flag（命令行 --flag=xxx，或 options['duckcoverage_flag']）。
     *
     * web 模式下优先取这次请求带来的 X-DuckCoverage-Flag 头，因此应用在 HTTP 请求里
     * 调用 getFlag() 拿到的是"客户端（--play）发过来的那个 flag"；
     * 在命令行进程里没有该头，就回落到配置/命令行给的值。
     *
     * 典型用法：在 GetTestList 回调里按 flag 返回不同的清单，
     * 让同一份代码分别跑「管理员」「普通用户」等不同前提的采集。
     */
    public function getFlag(): string
    {
        // 安全：总开关关着时 flag 一律不生效——既不认 --flag/配置，也不认请求头。
        // 这样外部请求无法靠一个 X-DuckCoverage-Flag 头去影响被测应用的行为。
        if (!App::Setting('duckcoverage_enable', false)) {
            return ''; // @codeCoverageIgnore
        }
        $flag = SuperGlobal::_()->_SERVER('HTTP_X_DUCKCOVERAGE_FLAG', null);
        if (is_string($flag)) {
            return $flag;
        }
        return (string)$this->options['duckcoverage_flag'];
    }
    ////]]]]
    ////[[[[
    protected function getRunner()
    {
        return \DuckCoverage\GroupCoverage::_();
    }
    protected function doBegin()
    {
        $this->getRunner()->doBegin($this->current_name, $this->current_group, $this->current_path_src, $this->current_path_dump);
    }
    protected function doEnd()
    {
        $this->getRunner()->doEnd();  // @codeCoverageIgnore
        if ($this->current_group) {
            $file = $this->options['duckcoverage_path'].$this->current_group.'.list.log';
            @touch($file);
            @chmod($file, 0666);
            file_put_contents($file, $this->current_name."\n", FILE_APPEND);
        }
    }
    /**
     * @param array<string> $groups
     * @return array<string, mixed>
     */
    protected function createReport($groups, $path_src, $path_dump, $path_report)
    {
        // 传工程根：JSON 报告里的路径写成相对工程根，方便跨机器、跨次 diff
        return $this->getRunner()->createReport(
            $groups,
            $path_src,
            $path_dump,
            $path_report,
            (string)App::_()->getProjectPath(),
            $this->getJsonlContext($path_report)
        );
    }
    /**
     * JSONL 报告的上下文；没要求输出时返回空数组，此时行为与以前完全一致
     *
     * @return array<string, mixed>
     */
    protected function getJsonlContext(string $path_report): array
    {
        if (!$this->isJsonlRequested()) {
            return [];
        }
        $jsonl_path = $this->options['duckcoverage_jsonl'];
        $path = '';
        if ($jsonl_path !== null) {
            $path = ($jsonl_path === '')
                ? rtrim($path_report, '/\\') . DIRECTORY_SEPARATOR . 'report.jsonl'
                : $this->resolvePath((string)$jsonl_path);
        }
        return [
            'path' => $path,
            'stdout' => $this->isJsonlStdout(),
            'detail' => (string)$this->options['duckcoverage_jsonl_detail'],
            'timestamp' => !$this->options['duckcoverage_jsonl_no_timestamp'],
        ];
    }
    /**
     * 相对路径按工程根解析（规格 §9）
     */
    protected function resolvePath(string $path): string
    {
        if (preg_match('#^([a-zA-Z]:[\\\\/]|[\\\\/])#', $path)) {
            return $path;
        }
        return rtrim((string)App::_()->getProjectPath(), '/\\') . DIRECTORY_SEPARATOR . $path;
    }
    ////]]]]
}
trait DuckCoverage_CommandTrait
{
    protected function readCommand($request)
    {
        if (empty($request)) {
            return;
        }
        echo "\033[42;30m".$request."\033[0m\n";

        $argv = explode(" ", $request);
        $this->current_name = "[{$this->current_group} " . (new \DateTime())->format('Y-m-d_H_i_s.v') . "]" . $request;
        $cmd = array_shift($argv);
        $call = ucfirst(strtolower($cmd));
        $method = "explain" . $call;
        if (is_callable([$this, $method])) {
            \call_user_func([$this, $method], $argv);
        } else {
            echo "Bad Request: $request\n";
        }
    }
    protected function explainComment(array $argv)
    {
        //do nothing.
    }
    protected function explainPhase(array $argv)
    {
        // 根 phase 的 phase 名就是空字符串(合法),而 explainMarco() 会 rtrim 掉行尾空白,
        // 所以 "#PHASE_END" 回到根 phase 时展开出来的是无参数的 "PHASE" 行。
        // 因此不能直接取 $argv[0](会 Undefined array key 0,触发 E_WARNING);
        // 无参数按 doPhaseEnd() 的语义视为"切回空 phase"。
        $param = $argv[0] ?? '';
        App::Phase($param);
    }
    protected function explainWeb(array $argv)
    {
        @list($uri, $poststr, $method) = $argv;
        $uri = __url($uri);
        // 相对 uri 会被 __url() 拼上 url_base(CLI 下是 /bin/cli.php 这样的脚本路径),
        // 这里把它切掉、留下站点相对路径。
        // 但 __url() 对「以 / 开头的绝对 uri」是原样返回的,那种情况下再切就会把路径啃掉一截:
        // 例如 '/user/register' 被切掉 11 个字符变成 'er',最终请求打到
        // http://<主机名>er 这种诡异的地址上(DNS 直接解析失败),而且不报错、只是静默地少了请求。
        if ($this->url_base !== '' && strpos($uri, $this->url_base) === 0) {
            $uri = substr($uri, strlen($this->url_base) - 1);
        }

        $base_url = (string) ($this->options['duckcoverage_web_base_url'] ?? '');
        if ($base_url === '') {
            $this->startServer();
            //$this->getServerBaseUrl();
            $base_url = 'http://' . $this->getServerHostForRequest()
                . ":{$this->options['duckcoverage_server_port']}" . $this->options['duckcoverage_homepage'];
        }
        $post = [];
        if ($poststr) {
            parse_str($poststr, $post);
        }
        $is_ajax = ($method === 'AJAX') ? true : false;
        $is_options = ($method === 'OPTIONS') ? true : false;

        $url = rtrim($base_url, '/') . $uri;


        echo "explainWeb: ".$url;
        echo ' ';
        echo http_build_query($post);
        echo "\n";
        $data = $this->curl_file_get_contents($url, $post, $is_ajax, $is_options, $method);
        if ($this->options['duckcoverage_debug_curl_echo_back'] ?? false) {
            echo substr($data, 0, 200);
        }
    }
    protected function explainCall(array $argv)
    {
        $this->doBegin();
        $this->callHandler(implode(" ", $argv));
        $this->doEnd();
    }
    protected function explainSetweb(array $argv)
    {
        @list($pre_curl, $pre_webcall, $post_webcall, $post_curl) = $argv;
        $this->pre_curl = ($pre_curl === '_') ? null : $pre_curl;
        $this->pre_webcall = ($pre_webcall === '_') ? null : $pre_webcall;
        $this->post_webcall = ($post_webcall === '_') ? null : $post_webcall;
        $this->post_curl = ($post_curl === '_') ? null : $post_curl;
        return;
    }
    protected function explainRun(array $argv)
    {
        // 空 RUN 行(没有命令名)没有可执行的东西,直接跳过。
        // 注意不能直接 strpos($sub_cmd, ':'):空数组时 array_shift() 返回 null,
        // 而本文件是 declare(strict_types=1),传 null 会直接抛 TypeError
        // (不是 deprecation,也不会被 dev_error_handler 接住),整个 play 就此中断。
        $sub_cmd = (string)array_shift($argv);
        if ($sub_cmd === '') {
            echo "Skip empty RUN\n";
            return;
        }
        $pos = strpos($sub_cmd, ":");
        if (false === $pos) {
            $sub_cmd = App::_()->getThisCommandPrefix() . $sub_cmd;
        } elseif (0 === $pos) {
            $sub_cmd = substr($sub_cmd, 1);
        }
        $str = implode(" ", $argv);

        $new_argv = $this->shell_parse($str);

        array_unshift($new_argv, $sub_cmd);
        array_unshift($new_argv, '-');

        $this->doBegin();
        $__SERVER = $_SERVER;

        $_SERVER['argv'] = $new_argv;
        App::_()->execute();
        $_SERVER = $__SERVER;

        $this->doEnd();
    }
    ////////////////////////////////////////////////////////////////////////////
    public function callHandler($handler, $ext_args = [])
    {
        if (!$handler) {
            return;
        }
        @list($handler, $parameters) = explode(' ', $handler);
        $phase = null;
        if (($pos = strpos($handler, '!')) !== false) {
            $phase = (string)substr($handler, 0, $pos);
            $handler = (string)substr($handler, $pos + 1);
        }

        // 解析调用方式
        if (preg_match('/^(.+?)(::|@|->)(.+)$/', $handler, $m)) {
            // 类方法调用
            $class = $m[1];
            $type = $m[2];
            $method = $m[3];
            $function = null;
        } elseif (preg_match('/^\w+$/', $handler)) {
            // 函数调用
            $class = null;
            $type = null;
            $method = null;
            $function = $handler;
        } else {
            return false;
        }
        if ($phase !== null) {
            $last_phase = App::Phase($phase);
        }
        $ret = $this->callObject($class, $method, $type, $function, $parameters, $ext_args);
        if ($phase !== null) {
            App::Phase($last_phase);
        }
        return $ret;
    }
    /**
     */
    protected function callObject($class, $method, $type, $function, $poststr, $args = [])
    {
        $input = [];

        if ($poststr) {
            parse_str($poststr, $input);
        }
        $reflect = null;
        $object = null;
        if (!$function) {
            if ($type === '@') {
                $object = $class::_();
                $reflect = new \ReflectionMethod($object, $method);
            } elseif ($type === '->') {
                $object = new $class;
                $reflect = new \ReflectionMethod($object, $method);
            } elseif ($type === '::') {
                $reflect = new \ReflectionMethod($class, $method);
            }
        } else {
            $reflect = new \ReflectionFunction($function);
        }

        // @phpstan-ignore-next-line method.nonObject
        $params = $reflect->getParameters();
        foreach ($params as $i => $param) {
            $name = $param->getName();
            if (isset($input[$name])) {
                $args[$i] = $input[$name];
            } elseif ($param->isDefaultValueAvailable() && !isset($args[$i])) {
                $args[$i] = $param->getDefaultValue();
            } elseif (!isset($args[$i])) {
                //throw new \ReflectionException("Command Need Parameter: {$name}\n", -2);
            }
        }
        if ($reflect instanceof \ReflectionMethod) {
            $ret = $reflect->invokeArgs($object, $args);
        } else {
            $ret = $reflect->invokeArgs($args); // @phpstan-ignore-line method.nonObject
        }
        return $ret;
    }
    protected function shell_parse(string $str): array
    {
        $result = [];
        $buffer = '';
        $inSingle = false;
        $inDouble = false;
        $escape = false;

        $len = mb_strlen($str); // 改用 mb_strlen 支持多字节
        for ($i = 0; $i < $len; $i++) {
            $c = mb_substr($str, $i, 1); // 改用 mb_substr

            if ($escape) {
                // 在双引号内，只有特定字符才被转义
                if ($inDouble) {
                    if ($c === '"' || $c === '\\' || $c === '$') {
                        $buffer .= $c;
                    } else {
                        $buffer .= '\\' . $c; // 其他字符保留反斜杠
                    }
                } else {
                    $buffer .= $c;
                }
                $escape = false;
                continue;
            }

            if ($c === '\\' && !$inSingle) {
                $escape = true;
                continue;
            }

            if ($c === "'" && !$inDouble) {
                $inSingle = !$inSingle;
                continue;
            }

            if ($c === '"' && !$inSingle) {
                $inDouble = !$inDouble;
                continue;
            }

            if (!$inSingle && !$inDouble && ctype_space($c)) {
                if ($buffer !== '') {
                    $result[] = $buffer;
                    $buffer = '';
                }
            } else {
                $buffer .= $c;
            }
        }

        if ($buffer !== '') {
            $result[] = $buffer;
        }

        return $result;
    }
}
trait DuckCoverage_HttpServerTrait
{
    protected $is_server_started = false;

    protected function startServer()
    {
        if ($this->is_server_started) {
            return;
        }
        $server_options = [
            'host' => $this->options['duckcoverage_server_host'] ?: '127.0.0.1',
            'path' => $this->options['duckcoverage_path_server'],
            'path_document' => $this->options['duckcoverage_path_document'],
            'port' => $this->options['duckcoverage_server_port'],
            'background' => true,
            'workers' => 2,
        ];

        if ($this->options['duckcoverage_new_server']) {
            HttpServer::_(new HttpServer());
        }
        HttpServer::RunQuickly($server_options);

        usleep(500);
        echo static::class . " HTTP SERVER PID = " . HttpServer::_()->getPid() . "\n";
        $this->is_server_started = true;
    }
    protected function stopServer()
    {
        if (!$this->is_server_started) {
            return;
        }
        HttpServer::_()->close();
        $this->is_server_started = false;
    }
    /**
     * 回放 WEB 指令时使用的主机名(内置服务器)。
     *
     * duckcoverage_server_host 描述的是**绑定地址**：0.0.0.0 / :: 这类通配地址不能作为请求
     * 目标(在 Windows 上会直接连不上)，所以这里回落到 127.0.0.1；IPv6 字面量需要加方括号才能
     * 拼进 URL。绑定地址本身仍按该选项原样传给 HttpServer，见 startServer()。
     */
    protected function getServerHostForRequest(): string
    {
        $host = trim((string) ($this->options['duckcoverage_server_host'] ?? ''));
        if ($host === '' || $host === '0.0.0.0' || $host === '::' || $host === '[::]') {
            return '127.0.0.1';
        }
        if (strpos($host, ':') !== false && strpos($host, '[') !== 0) {
            return '[' . $host . ']';
        }
        return $host;
    }
}
trait DuckCoverage_HttpClientTrait
{
    protected $cookies = [];
    protected $post = [];

    protected function cleanClientStatus()
    {
        $this->cookies = [];
    }

    protected $pre_curl;
    protected $post_curl;
    protected $pre_webcall;
    protected $post_webcall;

    protected function prepareCurl($ch)
    {
        $this->headers[] = 'X-DuckCoverage-Name: ' . $this->current_name;
        // --flag：随请求带给被测应用，应用侧用 getFlag() 读同一个值。
        // 去掉 CR/LF：header 值里不允许换行，避免头注入。
        $flag = str_replace(["\r", "\n"], '', $this->getFlag());
        if ($flag !== '') {
            $this->headers[] = 'X-DuckCoverage-Flag: ' . $flag;
        }
        if ($this->pre_webcall) {
            $this->headers[] = 'X-DuckCoverage-BeforeRun: ' . $this->pre_webcall;
            $this->pre_webcall = null;
        }
        if ($this->post_webcall) {
            $this->headers[] = 'X-DuckCoverage-AfterRun: ' . $this->post_webcall;
            $this->post_webcall = null;
        }
        $pre_curl = $this->pre_curl;
        $this->pre_curl = null;

        //////////////////////////
        if (!$pre_curl || $pre_curl === '_') {
            return $ch;
        }

        if ($pre_curl === 'AJAX') {
            $this->headers[] = 'X-Requested-With: XMLHttpRequest';
            return $ch;
        }
        if ($pre_curl === 'OPTIONS') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'OPTIONS');
            return $ch;
        }
        $this->callHandler($pre_curl, [$ch, 'pre']);
        return $ch;
    }

    protected function postpareCurl($ch)
    {
        $post_curl = $this->post_curl;
        $this->post_curl = null;

        $this->callHandler($post_curl, [$ch, 'post']);
    }

    protected $headers = [];

    protected function curl_file_get_contents($url, $post = [], $is_ajax = false, $is_options = false, $method = '')
    {
        $ch = curl_init();
        if (is_array($url)) {
            list($base_url, $real_host) = $url;
            $url = $base_url;
            $host = parse_url($url, PHP_URL_HOST);
            $port = parse_url($url, PHP_URL_PORT);
            $c = $host . ':' . $port . ':' . $real_host;
            curl_setopt($ch, CURLOPT_CONNECT_TO, [$c]);
        }
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1); // @phpstan-ignore-line argument.type
        //curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);

        // replace all Set-Cookie
        curl_setopt($ch, CURLOPT_HEADER, 1); // @phpstan-ignore-line argument.type

        if (!empty($post)) {
            curl_setopt($ch, CURLOPT_POST, 1); // @phpstan-ignore-line argument.type
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
        }
        /////////
        if (!empty($this->cookies)) {
            $cookie_str = [];
            foreach ($this->cookies as $name => $value) {
                $cookie_str[] = $name . '=' . $value;
            }
            /* @phpstan-ignore-next-line argument.type */
            curl_setopt($ch, CURLOPT_COOKIE, implode('; ', $cookie_str));
        }

        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $this->options['duckcoverage_curl_connecttimeout']);
        curl_setopt($ch, CURLOPT_TIMEOUT, $this->options['duckcoverage_curl_timeout']);
        $this->prepareCurl($ch);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $this->headers);
        $data = curl_exec($ch);
        if ($data === false) {
            echo "curl_file_get_contents failed: " . curl_error($ch) ."\n";
        }
        if (curl_errno($ch) === CURLE_OPERATION_TIMEDOUT) {
            echo "curl_file_get_contents timeout";
            return false;
        }

        $this->headers = [];
        $header_size = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $headers = substr((string) $data, 0, $header_size);
        $data = substr((string) $data, $header_size);
        if (preg_match_all('/Set-Cookie:\s*([^=;\s]+)=([^;]*)/i', $headers, $ms)) {
            foreach ($ms[1] as $i => $name) {
                $value = trim($ms[2][$i]);
                if ($value === '' || strcasecmp($value, 'deleted') === 0) {
                    unset($this->cookies[$name]); //@codeCoverageIgnore
                } else {
                    $this->cookies[$name] = $value;
                }
            }
        }
        $this->postpareCurl($ch);
        curl_close($ch);
        $data = (string) $data;
        return $data;
    }
}
