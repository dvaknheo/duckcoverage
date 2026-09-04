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

    public $options = [
        'duckcoverage_enable' => true,
        'duckcoverage_data_file_json_file' => 'DuckPhpData-duckcoverage.config.json',
        'duckcoverage_reg_console_command' => true,
        'duckcoverage_test_lister' => null,

        'duckcoverage_path' => '',
        'duckcoverage_path_src' => 'src/', // 需要
        'duckcoverage_report_direct' => false,
        'duckcoverage_report_default_dir' => 'AAAAA.report',

        'duckcoverage_web_base_url' => '',
        // 外部服务器(如 nginx)基础 URL,如 http://admin.duckphp-local.com/ ;空则退回内部测试服务器
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

    protected $default_cmd = 'duckcover';
    protected $default_path = 'DuckCoverage/';
    public static function Prepare($options = [])
    {
        return DuckCoverage::_()->beforeInit($options);
    }
    public function beforeInit($options = [])
    {
        App::_()->options = array_merge($options, App::_()->options);

        if (!App::_()->isRoot()) {
            return;
        }
        if (!App::Setting('duckcoverage_enable', false)) {
            return;
        }

        $argv = SuperGlobal::_()->_SERVER('argv', []);
        $cmd = $argv[1] ?? '';
        if ($cmd !== $this->default_cmd) {
            $this->current_group = $this->watchingGetName();
            $this->in_subcmd = true;
        }
        $this->moveDateJsonFile();

        App::_()->options['ext'][static::class] = true;
        PhaseContainer::_()->addPublicClasses([static::class => true]);
    }

    protected function moveDateJsonFile()
    {
        App::_()->options['data_file_json_file'] = $this->options['duckcoverage_data_file_json_file'];
        App::_()->options['data_file_enable'] = true;
    }
    public function init(array $options, ?object $context = null)
    {
        parent::init($options, $context);

        if (!App::_()->isRoot()) {
            return $this;
        }
        if (!$this->options['duckcoverage_enable']) {
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

        @mkdir($this->options['duckcoverage_path']);
        if ($this->options['duckcoverage_reg_console_command']) {
            App::_()->regConsoleCommand(static::class, 'command_');
        }

        $this->initAction();

        return $this;
    }
    protected function initAction()
    {
        if ($this->in_subcmd) {
            return;
        }
        $this->current_group = $this->watchingGetName();
        if (!$this->current_group) {
            return;
        }
        $this->current_name = $this->make_name();
        $this->doBegin();

        //if (PHP_SAPI === 'cli') {
        SystemWrapper::register_shutdown_function(function () {
            $this->doEnd();
        });
        // } else {
        //     Route::_()->addRouteHook([static::class, 'AfterRun'], 'finally-outter');
        // }

    }
    protected function make_name()
    {
        if (PHP_SAPI === 'cli') {
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
        $name = SuperGlobal::_()->_SERVER('HTTP_X_MYCOVERAGE_NAME', null);
        if ($name) {
            return $name;
        }
        $request = SuperGlobal::_()->_SERVER('REQUEST_URI', '');
        $post = SuperGlobal::_()->_POST();
        $request .= $post ? ''.http_build_query($post) : '';
        $request = 'MAN-WEB '.$request;
        return "[{$this->current_group} " . (new \DateTime())->format('Y-m-d_H_i_s.v') . "]" . $request;
    }
    public function _OnBeforeRun()
    {
        // if($this->reuse_mode){
        //     $this->doBegin();
        // }
        $this->call_http_handler('HTTP_X_MYCOVERAGE_BEFORERUN');
    }
    protected function call_http_handler($name)
    {
        $runner = SuperGlobal::_()->_SERVER($name, '');
        if ($runner) {
            $this->callHandler($runner);
        }
    }
    public function _OnAfterRun()
    {
        //@codeCoverageIgnoreStart
        $this->call_http_handler('HTTP_X_MYCOVERAGE_AFTERRUN');
        if (!$this->current_group) {
            return;
        }
        $this->doEnd();
        $this->current_name = '';
        $this->current_group = '';
        //@codeCoverageIgnoreEnd
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
        $test_list = $this->getTestListerText();
        $test_list = \explode("\n", $test_list);
        $this->current_group = $this->watchingGetName();
        foreach ($test_list as $line) {
            $this->readCommand($line);
        }
        $this->stopServer();
    }
    /**
     * tests group. use --help for more.
     */
    public function command_duckcover()
    {
        @mkdir($this->current_path_dump);
        $p = Console::_()->getCliParameters();
        $this->doCommand($p);
    }
    protected function doCommand($p)
    {
        if (($p['help'] ?? false) || (count($p) === 1)) {
            $str = <<<EOT
--watch {group}
--play
--stop
--report a
--report a b c
--go {group}
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
            $this->play();
            echo "playing";
        }

        if ($p['report'] ?? false) {
            echo "reporting...\n";
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
            $this->options['duckcoverage_report_direct'] = true;
            $this->watchingBegin($watch_name);
            echo "watching {$watch_name}\n";
            $this->play();
            $this->watchingEnd();
            echo "watched {$watch_name}\n";
            $this->doReport([$watch_name]);
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
            $path_report = $path_report . $this->default_report_dir;
        }
        $this->createReport($groups, $this->current_path_src, $this->current_path_dump, $path_report);
        $time_end = microtime(true);
        $time_cost = $time_end - $time_begin;
        $time_cost = sprintf('%0.3f', $time_cost);
        echo "time_cost   : $time_cost seconds \noutput path : $path_report \n";
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
        @unlink($this->options['duckcoverage_path'] . basename($name) . '.watch.lock');
    }
    protected function watchingGetName()
    {
        if ($this->current_group) {
            return $this->current_group;
        }
        $group = @file_get_contents($this->options['duckcoverage_path'] . 'DuckCoverage.watching.txt');
        return $group;
    }
    ////]]]]
    ////[[[[
    protected function getRunner()
    {
        return GroupCoverage::_();
    }
    protected function doBegin()
    {
        $this->getRunner()->doBegin($this->current_name, $this->current_group, $this->current_path_src, $this->current_path_dump);
    }
    protected function doEnd()
    {
        $this->getRunner()->doEnd();  // @codeCoverageIgnore
    }
    protected function createReport($groups, $path_src, $path_dump, $path_report)
    {
        return $this->getRunner()->createReport($groups, $path_src, $path_dump, $path_report);
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
        echo $request;
        echo "\n";
    }
    protected function explainComment(array $argv)
    {
        //do nothing.
    }
    protected function explainPhase(array $argv)
    {
        $param = $argv[0];
        $this->doBegin();
        App::Phase($param);
        $this->doEnd();
    }
    protected function explainWeb(array $argv)
    {
        @list($uri, $poststr, $method) = $argv;
        $uri = __url($uri);

        $base_url = (string) ($this->options['duckcoverage_web_base_url'] ?? '');
        if ($base_url === '') {
            $this->startServer();
            //$this->getServerBaseUrl();
            $base_url = "http://127.0.0.1:{$this->options['duckcoverage_server_port']}" . $this->options['duckcoverage_homepage'];
        }
        $post = [];
        if ($poststr) {
            parse_str($poststr, $post);
        }
        $is_ajax = ($method === 'AJAX') ? true : false;
        $is_options = ($method === 'OPTIONS') ? true : false;

        $url = rtrim($base_url, '/') . $uri;

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
        $sub_cmd = array_shift($argv);
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
            $phase = (string)substr($handler, 0, $pos + 1);
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
            'path' => $this->options['duckcoverage_path_server'],
            'path_document' => $this->options['duckcoverage_path_document'],
            'port' => $this->options['duckcoverage_server_port'],
            'background' => true,
            'http_app_class' => get_class(App::Root()),
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
        $this->headers[] = 'X-MyCoverage-Name: ' . $this->current_name;
        if ($this->pre_webcall) {
            $this->headers[] = 'X-MyCoverage-BeforeRun: ' . $this->pre_webcall;
            $this->pre_webcall = null;
        }
        if ($this->post_webcall) {
            $this->headers[] = 'X-MyCoverage-AfterRun: ' . $this->post_webcall;
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

        curl_setopt($ch, CURLOPT_HTTPHEADER, $this->headers);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        $this->prepareCurl($ch);
        $data = curl_exec($ch);
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
                    unset($this->cookies[$name]);
                } else {
                    $this->cookies[$name] = $value;
                }
            }
        }
        $this->postpareCurl($ch);
        echo $url;
        echo ' ';
        echo http_build_query($post);
        echo "\n";
        echo $data;
        curl_close($ch);
        $data = (string) $data;
        return $data;
    }
}
