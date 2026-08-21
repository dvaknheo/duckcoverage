<?php declare(strict_types=1);
/**
 * DuckPhp
 * From this time, you never be alone~
 */

namespace DuckCoverage;

use DuckPhp\Core\App;
use DuckPhp\Core\Console;
use DuckPhp\Core\ExitException;
use DuckPhp\Core\SystemWrapper;
use DuckPhp\Foundation\Helper;

#CALL
#WEB url post
#SETWEB precall preweb postweb postcall

class DuckCoverage extends CoverageBase
{
    use HttpServerTrait, HttpClientTrait;

    //todo use  global singletonex to replace default singleton function
    public $options = [
        'duckcoverage_enable' => true,
        'duckcoverage_data_file_json_file'=> 'DuckPhpData-duckcoverage.config.json',

        'duckcoverage_path' => '',
        'duckcoverage_path_src' => 'src/',   // 不需要
        'duckcoverage_path_dump' => 'test_coveragedumps',
        'duckcoverage_path_report' => 'test_reports',
        'duckcoverage_report_direct' => true,
        'duckcoverage_group'=>'', 
        'duckcoverage_name'=>'',     // 不需要

        'duckcoverage_web_base_url' => '',      // 外部服务器(如 nginx)基础 URL,如 http://admin.duckphp-local.com/ ;空则退回内部测试服务器
        'duckcoverage_server_port' => 8080,
        'duckcoverage_server_host' => '',
        'duckcoverage_path_server' => '',
        'duckcoverage_path_document' => 'public',
        'duckcoverage_homepage' => '/index_dev.php/',
        'duckcoverage_new_server' => true,
        

        //'duckcoverage_callback_class' => null,
        'duckcoverage_callback' => null,
        'duckcoverage_save_web_request_list' => true,
        'duckcoverage_save_local_call_list' => false,
        'duckcoverage_echo_back' => false,

    ];
    public $session_id = '';   // 兼容保留（不再用于请求）；会话改为连续传递所有 cookie
    public function __construct()
    {
        $this->options = array_replace_recursive($this->options, (new parent())->options); //merge parent's options;
        parent::__construct();
    }
    public function beforeInit()
    {
        if(App::_()->options['duckcoverage_enable']) {
            App::_()->options['data_file_json_file'] = $this->options['duckcoverage_data_file_json_file'];
            App::_()->options['data_file_enable'] = true;
        }
    }
    public function init(array $options, ?object $context = null)
    {
        // 必须先于 parent::init() 赋值:runner 在 CoverageBase::init 时对路径做快照,
        // 否则 getSubPath 会用默认空路径快照,导致 dump 与报告目录错位
        $this->options['duckcoverage_path'] = Helper::PathOfRuntime();
        parent::init($options, $context);

        $this->options['duckcoverage_path_server'] = Helper::PathOfProject();

        if (!$this->options['duckcoverage_enable']) {
            return $this;
        }
        if (!App::_()->isRoot()) {
            return $this;
        }

        $watching_group = $this->watchingGetName();
        if ($watching_group) {
            $this->options['duckcoverage_group'] = $watching_group;
        }

        // 注册 duckcover 命令行命令（不依赖 onInit 全局事件，旧版 duckphp 机制已移除）
        App::_()->regConsoleCommand(static::class, 'command_');

        // web 收集:isInHttpTest() 命中时 _OnBeforeRun(doBegin),
        // 并用 SystemWrapper::register_shutdown_function 在请求结束注册 _OnAfterRun(doEnd)
        ExitException::Init(); //__define(__ExitException);

        if ($this->isInHttpTest()) {
            DuckCoverage::_()->_OnBeforeRun();
            SystemWrapper::register_shutdown_function(function () {
                DuckCoverage::_()->_OnAfterRun();
            });
        }

        // 这里要改成 DuckPhp 里的
        // cli 收集:#CMD 回放时父进程通过 MYCOVERAGE_NAME 环境变量传入组名,
        // 命中时进程启动即 doBegin,shutdown 时 doEnd,实现命令行测试采集
        if (PHP_SAPI === 'cli') {
            $cli_group = (string) getenv('MYCOVERAGE_NAME');
            if ($cli_group !== '') {
                $this->options['duckcoverage_group'] = $cli_group;
                $argv_str = implode(' ', array_slice(Helper::SERVER('argv', []), 1));
                $this->options['duckcoverage_name'] = 'cli ' . date('ymdHis') . '-' . (getmypid() ?: 0) . ' ' . $argv_str;
                $this->doBegin();
                SystemWrapper::register_shutdown_function(function () {
                    DuckCoverage::_()->doEnd();
                });
            }
        }
        return $this;
    }
    public function isInHttpTest()
    {
        $watching_name = $this->watchingGetName();
        $server_name = Helper::SERVER('HTTP_X_MYCOVERAGE_NAME', '');
        //$server_name = $_SERVER['HTTP_X_MYCOVERAGE_NAME']??'';
        if ($watching_name && $watching_name === $server_name) {
            return true;
        }
        return false;
    }
    public function isInCliTest()
    {
        if (PHP_SAPI === 'cli' && App::_()->options['cli_enable']) {
            $argv = Helper::SERVER('argv', []);
            $cmd = $argv[1] ?? 'NULL';
            if ($cmd === 'duckcover') {
                return true;
            }
        }
        return false;
    }
    public function _OnBeforeRun()
    {
        if (!$this->options['duckcoverage_group']) {
            return;
        }

        if ($this->options['duckcoverage_save_web_request_list'] ?? false) {
            $path_dump = $this->getSubPath('duckcoverage_path_dump');
            @mkdir($path_dump);
            file_put_contents($path_dump . $this->options['duckcoverage_group'] . '.list', $this->getHttpStringToLog() . "\n", FILE_APPEND);
        }


        $this->options['duckcoverage_name'] = $this->getTestName(); //???
        //// TODO save list


        $before_run = Helper::SERVER('HTTP_X_MYCOVERAGE_BEFORERUN', '');
        if ($before_run) {
            $this->callHandler($before_run);
        }

        $this->doBegin();
    }

    public function _OnAfterRun()
    {

        $after_run = Helper::SERVER('HTTP_X_MYCOVERAGE_AFTERRUN', '');
        if ($after_run) {
            $this->callHandler($after_run);

        }
        $this->doEnd();
    }
    protected function getHttpStringToLog()
    {
        $data = '';
        $post = Helper::POST();
        $post = http_build_query($post);

        $uri = Helper::SERVER('REQUEST_URI', '');
        $data .= $uri;

        if ($post) {
            $data .= " {$post}";
        }
        $data .= "\n";
        return $data;
    }
    protected function getTestName()
    {
        $time = date('ymdHis.', $_SERVER['REQUEST_TIME']) . sprintf('%03d', ($_SERVER['REQUEST_TIME_FLOAT'] - (int) $_SERVER['REQUEST_TIME_FLOAT']) * 1000);

        $method = Helper::SERVER('REQUEST_METHOD', 'GET');

        $session_id = Helper::COOKIE('PHPSESSID', '');
        $uri = Helper::SERVER('REQUEST_URI', '');
        $post = Helper::POST();
        $post = http_build_query($post);
        $ajax = Helper::IsAjax() ? 'AJAX' : '';
        $ret = implode(";", [$time, $uri, $post, $session_id, $method, $ajax]);
        return $ret;
    }
    protected function callHandler($handler, $ext_args = [])
    {
        if (!isset($handler)) {
            return;
        }
        $handler = trim($handler);
        //$handler = "DuckAdmin\\Test\\Tester@_justTest?parameter=d";
        $flag = preg_match('/^(([a-zA-Z0-9_\x7f-\xff\\\\]+)(\:\:|\@|\->)([a-zA-Z0-9_\x7f-\xff]+)|([a-zA-Z0-9_\x7f-\xff]+))(\?(\S*))?$/', $handler, $m);
        if (!$flag) {
            return false;
        }
        @list($_0, $_1, $class, $type, $method, $function, $_6, $parameters) = $m;
        return $this->callObject($class, $method, $type, $function, $parameters, $ext_args);
    }
    //////////////////
    protected function replay()
    {
        $this->cleanClientStatus();
        $this->doBegin();

        $this->options['duckcoverage_name'] = 'replay';

        $callback = $this->options['duckcoverage_callback'] ?? null;
        $test_list = $callback();
        $test_list = \explode("\n", $test_list);

        foreach ($test_list as $line) {
            $this->readCommand($line);
        }
        $this->stopServer();

        $this->doEnd();
    }
    protected function readCommand($request)
    {
        $request = trim($request);
        if (!$request) {
            return;
        }
        if (substr($request, 0, 2) === '##') {
            return;
        }
        // 头部指令:#PHASE {phase} 直接切换当前 phase;#URL_PREFIX {prefix} 记录 URL 前缀
        if (substr($request, 0, strlen('#PHASE ')) === '#PHASE ') {
            $phase = trim(substr($request, strlen('#PHASE ')));
            if ($phase === '') {
                return; // 空 phase 忽略,避免 setCurrentContainer('') 切到不存在的 phase
            }
            App::Phase($phase);
            return;
        }
        if (substr($request, 0, strlen('#URL_PREFIX')) === '#URL_PREFIX') {
            $this->current_url_prefix = trim(substr($request, strlen('#URL_PREFIX')));
            return;
        }
        if (substr($request, 0, strlen('#CALL ')) === '#CALL ') {
            $this->explainCall($request);
        }
        if (substr($request, 0, strlen('#WEB ')) === '#WEB ') {
            $this->explainWeb($request);
        }
        if (substr($request, 0, strlen('#SETWEB ')) === '#SETWEB ') {
            $this->explainSetWeb($request);
        }
        if (substr($request, 0, strlen('#CMD ')) === '#CMD ') {
            $this->explainCmd($request);
        }
    }

    protected function explainWeb($request)
    {
        @list($command, $uri, $poststr, $method) = explode(' ', $request);

        if ($command !== '#WEB') {
            return;
        }

        $base_url = (string) ($this->options['duckcoverage_web_base_url'] ?? '');
        if ($base_url === '') {
            // 未配置外部服务器(如 nginx)时,退回内部 PHP 测试服务器
            $this->startServer();
            $base_url = "http://127.0.0.1:{$this->options['duckcoverage_server_port']}" . $this->options['duckcoverage_homepage'];
        }
        $post = [];
        if ($poststr) {
            parse_str($poststr, $post);
        }
        $is_ajax = ($method === 'AJAX') ? true : false;
        $is_options = ($method === 'OPTIONS') ? true : false;

        // 命令未带 URL 前缀时,按 #URL_PREFIX 指令补前缀
        if ($this->current_url_prefix !== '' && strpos($uri, $this->current_url_prefix) !== 0) {
            $uri = $this->current_url_prefix . $uri;
        }
        $url = $base_url . $uri;
        $data = $this->curl_file_get_contents($url, $post, $is_ajax, $is_options, $method);
        if ($this->options['duckcoverage_echo_back'] ?? false) {
            echo substr($data, 0, 200);
        }
    }
    protected function explainCall($request)
    {
        @list($command, $func) = explode(' ', $request);
        if ($command !== '#CALL') {
            return;
        }

        $this->options['duckcoverage_name'] = $command;

        ////[[[[
        //// save list
        $path_dump = $this->getSubPath('duckcoverage_path_dump');
        @mkdir($path_dump);
        file_put_contents($path_dump . $this->options['duckcoverage_group'] . '.list', $request . "\n", FILE_APPEND);
        ////]]]]

        $this->callHandler($func);

    }
    protected function explainSetweb($request)
    {
        @list($command, $pre_curl, $pre_webcall, $post_webcall, $post_curl) = explode(' ', $request);
        if ($command !== '#SETWEB') {
            return;
        }

        $this->pre_curl = ($pre_curl === '_') ? null : $pre_curl;
        $this->pre_webcall = ($pre_webcall === '_') ? null : $pre_webcall;
        $this->post_webcall = ($post_webcall === '_') ? null : $post_webcall;
        $this->post_curl = ($post_curl === '_') ? null : $post_curl;
        return;
    }
    protected function explainCmd($request)
    {
        @list($command, $cmd) = explode(' ', $request, 2);
        if ($command !== '#CMD') {
            return;
        }
        $cmd = trim($cmd);
        if ($cmd === '') {
            return;
        }
        // 通过 MYCOVERAGE_NAME 环境变量把组名传给子进程,
        // 使子进程的 DuckPHP CLI 入口在 init 时命中 cli 采集分支(见 init)
        // 注意:PHP 8.0 起 putenv() 不再支持无 '=' 的删除用法,统一用 'NAME=' 空值形式
        $group = (string) ($this->options['duckcoverage_group'] ?? '');
        $old_env = getenv('MYCOVERAGE_NAME');
        putenv('MYCOVERAGE_NAME=' . $group);
        try {
            echo $cmd . "\n";
            passthru($cmd, $exit_code);
        } finally {
            // 恢复父进程环境变量(空值即删除)
            putenv('MYCOVERAGE_NAME=' . ($old_env === false ? '' : $old_env));
        }
        if ($exit_code !== 0) {
            echo static::class . " #CMD exit code = {$exit_code}\n";
        }
    }
    ////////////////////////////////////////////////////////////////////////////
    /**
     */
    public function callObject($class, $method, $type, $function, $poststr, $args = [])
    {
        $input = [];

        if ($poststr) {
            parse_str($poststr, $input);
        }
        if (!$function) {
            if ($type === '@') {
                $object = $class::_();
            } else if ($type === '->') {
                $object = new $class;
            } else if ($type === '::') {
                $object = $class;
            }
            $reflect = new \ReflectionMethod($object, $method);
        } else {
            $reflect = new \ReflectionFunction($function);
        }

        $params = $reflect->getParameters();
        foreach ($params as $i => $param) {
            $name = $param->getName();
            if (isset($input[$name])) {
                $args[$i] = $input[$name];
            } elseif ($param->isDefaultValueAvailable() && !isset($args[$i])) {
                $args[$i] = $param->getDefaultValue();
            } elseif (!isset($args[$i])) {
                throw new \ReflectionException("Command Need Parameter: {$name}\n", -2);
            }
        }
        $ret = $reflect->invokeArgs(is_object($object) ? $object : null, $args);
        return $ret;
    }
    /**
     * tests group. use --help for more.
     */
    public function command_duckcover()
    {
        /*
        $handler = "DuckAdmin\\Test\\Tester@_justTest?parameter=d";
        */
        $p = Console::_()->getCliParameters();
        if ($p['help'] ?? false || count($p) === 1) {
            $str = <<<EOT
--replay
--report [a b c]
--call SomeApp/Test/Tester@runX
--watch {name}
--stop
EOT;
//--watch xx --replay --report
// --go {name}
// call 不需要了，在列表里就行了 duckcover
            echo $str;
            return;
        }
        if ($p['watch'] ?? false) {
            if ($p['watch'] === true) {
                $p['watch'] = DATE('Y_m_d_H_i_s');
            }
            $this->watchingBegin($p['watch']);
            $this->options['duckcoverage_group'] = $p['watch'];
            echo "watching {$p['watch']}\n";
        }
        if ($p['stop'] ?? false) {
            $this->watchingEnd();
        }
        if ($p['replay'] ?? false) {
            $this->replay();
        }
        if ($p['call'] ?? false) {
            if (is_string($p['call'])) {
                $command = $p['call'];
                $this->options['duckcoverage_name'] = 'call ' . $command;
                $func = str_replace('/', '\\', $command);
                $this->doBegin();
                try {
                    $this->callHandler($func);
                } catch (\Throwable $ex) {
                    var_dump($ex);
                }
                $this->doEnd();
            }
        }

        if ($p['report'] ?? false) {
            echo "reporting...\n";

            $groups = is_array($p['report']) ? $p['report'] : [$this->options['duckcoverage_group']];

            $time_begin = microtime(true);
            $path_group = $this->getReportPath($groups);
            $this->createReport($groups);
            $time_end = microtime(true);
            $time_cost = $time_end - $time_begin;
            $time_cost = sprintf('%0.3f', $time_cost);
            echo "time_cost   : $time_cost seconds \noutput path : $path_group \n";
        }
        //var_dump(__FILE__,__LINE__,DATE(DATE_ATOM));
    }
    ////]]]]
}
