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
use DuckPhp\HttpServer\HttpServer;
use SebastianBergmann\CodeCoverage\CodeCoverage;

#CALL
#WEB url post
#SETWEB precall preweb postweb postcall

class DuckCoverage extends CoverageBase
{
    //todo use  global singletonex to replace default singleton function
    public $options = [
        'duckcoverage_enable' => true,
        'duckcoverage_data_file_json_file'=> 'DuckPhpData-duckcoverage.config.json',
        'duckcoverage_save_web_request_list' => true,
        'duckcoverage_save_local_call_list' => false,

        'duckcoverage_server_port' => 8080,
        'duckcoverage_server_host' => '',
        'duckcoverage_path_server' => '',
        'duckcoverage_path_document' => 'public',
        'duckcoverage_homepage' => '/index_dev.php/',
        'duckcoverage_new_server' => true,

        'duckcoverage_web_base_url' => '',      // 外部服务器(如 nginx)基础 URL,如 http://admin.duckphp-local.com/ ;空则退回内部测试服务器

        'duckcoverage_callback_class' => null,
        'duckcoverage_callback' => null,

        'duckcoverage_report_direct' => true,
        'duckcoverage_echo_back' => false,

    ];
    public $session_id = '';
    protected $is_save_session = false;
    protected $post = [];
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
        parent::init($options, $context);

        $this->options['duckcoverage_path'] = Helper::PathOfRuntime();
        $this->options['duckcoverage_path_server'] = Helper::PathOfProject();
        $this->options['duckcoverage_path_src'] ??= realpath(__DIR__ . '/../../') . '/src'; //??

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
    protected function cleanClientStatus()
    {
        $this->is_save_session = true;
        $this->session_id = '';
    }
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
            App::Phase($phase);
            return;
        }
        if (substr($request, 0, strlen('#URL_PREFIX ')) === '#URL_PREFIX ') {
            $this->current_url_prefix = trim(substr($request, strlen('#URL_PREFIX ')));
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
    }
    protected $current_url_prefix = '';

    protected $pre_curl;
    protected $post_curl;
    protected $pre_webcall;
    protected $post_webcall;
    public function prepareCurl($ch)
    {
        $this->headers[] = 'X-MyCoverage-Name: ' . $this->watchingGetName();
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
    public function postpareCurl($ch)
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
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        //curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1); 

        if ($this->is_save_session) {
            curl_setopt($ch, CURLOPT_HEADER, 1);
        }

        if (!empty($post)) {
            curl_setopt($ch, CURLOPT_POST, 1);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
        }
        /////////
        if ($this->session_id) {
            curl_setopt($ch, CURLOPT_COOKIE, "PHPSESSID={$this->session_id}");
        }

        $this->prepareCurl($ch);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $this->headers);
        $data = curl_exec($ch);
        $this->headers = [];
        if ($this->is_save_session) {
            $header_size = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
            $headers = substr($data, 0, $header_size);
            $data = substr($data, $header_size);
            $flag = preg_match('/PHPSESSID=(\w+)/', $headers, $m);
            if ($flag) {
                $this->session_id = $m[1];
                $this->is_save_session = false;
            }
        }
        $this->postpareCurl($ch);
        echo $url;
        echo ' ';
        echo http_build_query($post);
        echo "\n";
        //echo $data;
        curl_close($ch);
        $data = ($data !== false) ? $data : '';
        return $data;
    }
    ////[[[[
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
        ];

        if ($this->options['duckcoverage_new_server']) {
            HttpServer::_(new HttpServer());
        }
        HttpServer::RunQuickly($server_options);

        sleep(1);// ugly
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
    ////]]]]
    //////////
    protected function explainWeb($request)
    {
        @list($command, $uri, $poststr, $method, $session_id) = explode(' ', $request);

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