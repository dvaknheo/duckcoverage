<?php declare(strict_types=1);
/**
 * DuckPhp
 * From this time, you never be alone~
 */

namespace DuckCoverage;

use DuckPhp\Core\App;
use DuckPhp\Core\ComponentBase;
use DuckPhp\Core\Console;
use DuckPhp\Core\ExitException;
use DuckPhp\Core\SystemWrapper;
use DuckPhp\Core\SuperGlobal;
use DuckPhp\Foundation\Helper;
use DuckPhp\HttpServer\HttpServer;
use LibCoverage\GroupCoverageRunner;

class DuckCoverage extends ComponentBase
{
    use CommandTrait;
    use HttpServerTrait;
    use HttpClientTrait;

    //todo use  global singletonex to replace default singleton function
    public $options = [
        'duckcoverage_enable' => true,
        'duckcoverage_data_file_json_file'=> 'DuckPhpData-duckcoverage.config.json',
        'duckcoverage_reg_console_command' => true,
        'duckcoverage_callback' => null,

        'duckcoverage_path' => '',
        'duckcoverage_path_src' => 'src/', // 需要
        'duckcoverage_report_direct' => false,

        'duckcoverage_web_base_url' => '',
        // 外部服务器(如 nginx)基础 URL,如 http://admin.duckphp-local.com/ ;空则退回内部测试服务器
        'duckcoverage_server_port' => 8017,
        'duckcoverage_server_host' => '',
        'duckcoverage_path_server' => '',
        'duckcoverage_path_document' => 'public',
        'duckcoverage_homepage' => '/',
        'duckcoverage_new_server' => true,

        'duckcoverage_echo_back' => false,
        'duckcoverage_save_web_request_list' => true,
        //'duckcoverage_save_local_call_list' => false,

    ];

    protected $current_group;
    protected $current_name;
    protected $current_path_src;
    protected $current_path_dump;


    public function __construct()
    {
        $this->options = array_replace_recursive($this->options, (new parent())->options); //merge parent's options;
        parent::__construct();
    }
    public function beforeInit()
    {
        // 我们还要检查有没有开启调试模式，有没有 drvier 。 不需要在 Ext 里加后续 Init;，自己加
        // 有没有在 root;
        if(App::_()->options['duckcoverage_enable']) {
            App::_()->options['data_file_json_file'] = $this->options['duckcoverage_data_file_json_file'];
            App::_()->options['data_file_enable'] = true;
        }
        App::_()->options['ext'][static::class] = true;
    }
    public function init(array $options, ?object $context = null)
    {
        parent::init($options, $context);
        if (!$options['duckcoverage_enable']) {
            return $this;
        }
        if (!App::_()->isRoot()) {
            return $this;
        }
        $path_project = App::_()->getProjectPath();
        $path_runtime = App::_()->getRuntimePath();

        $this->options['duckcoverage_path'] = $path_runtime .'DuckCoverage/';
        $this->options['duckcoverage_path_server'] =  $this->options['duckcoverage_path_server'] ?
             $this->options['duckcoverage_path_server'] : $path_project;

        $this->current_path_src = $path_project .$this->options['duckcoverage_path_src'];
        $this->current_path_dump = $this->options['duckcoverage_path'];

        @mkdir($this->options['duckcoverage_path']);

        if ($this->options['duckcoverage_reg_console_command']) {
            App::_()->regConsoleCommand(static::class, 'command_');
        }
        $this->prepareForHttp();
        return $this;
    }
    public function prepareForHttp()
    {
        $client_ip = SuperGlobal::_()->_SERVER('REMOTE_ADDR', '');
        $server_ip = SuperGlobal::_()->_SERVER('SERVER_ADDR', '');
        $name = SuperGlobal::_()->_SERVER('HTTP_X_MYCOVERAGE_NAME', '');
        $group = SuperGlobal::_()->_SERVER('HTTP_X_MYCOVERAGE_GROUP', '');
        if($client_ip){
            var_dump("zzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzz");
        }
        if(!$client_ip || !$server_ip || ($client_ip != $server_ip)  || !$name || !$group){
            return;
        }
        $this->current_name = $name;
        $this->current_group = $group;

        ExitException::Init();
        $this->_OnBeforeRun();
        SystemWrapper::register_shutdown_function(function () {
            $this->_OnAfterRun();
        });
    }
    public function _OnBeforeRun()
    {
        $before_run = SuperGlobal::_()->_SERVER('HTTP_X_MYCOVERAGE_BEFORERUN', '');
        if ($before_run) {
            $this->callHandler($before_run);
        }

        $this->doBegin($this->current_name, $this->current_group, $this->current_path_src, $this->current_path_dump);
    }

    public function _OnAfterRun()
    {
        $after_run = SuperGlobal::_()->_SERVER('HTTP_X_MYCOVERAGE_AFTERRUN', '');
        if ($after_run) {
            $this->callHandler($after_run);
        }
        $this->doEnd();
    }
    //////////////////
    protected function replay()
    {
        $this->cleanClientStatus();
        $callback = $this->options['duckcoverage_callback'] ?? null;
        $test_list = $callback();
        $test_list = \explode("\n", $test_list);

        $this->current_group = $this->watchingGetName();
        foreach ($test_list as $line) {
            $str = (new \DateTime())->format('Y-m-d H:i:s.v');
            $name = "[{$this->current_group} $str]".$line;
            $this->current_name = $name;
            $this->doBegin(
                $this->current_name,
                $this->current_group,
                $this->current_path_src,
                $this->current_path_dump
            );
            $this->readCommand($line);   // @codeCoverageIgnore
            $this->doEnd();              // @codeCoverageIgnore
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
        if ($p['help'] ?? false || count($p) === 1) {
            $str = <<<EOT
--watch {group}
--replay
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
                $watch_name = 'default_'. DATE('Y_m_d_H_i_s');
            }
            $this->watchingBegin($watch_name);
            
            echo "watching {$watch_name}\n";
        }
        if ($p['stop'] ?? false) {
            $this->watchingEnd();
        }
        if ($p['replay'] ?? false) {
            $this->replay();
            echo "replaying";
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
                $watch_name = 'default_'. DATE('Y_m_d_H_i_s');
            }
            $this->options['duckcoverage_report_direct'] = true;
            $this->watchingBegin($watch_name);
            echo "watching {$watch_name}\n";
            $this->replay();
            $this->watchingEnd();
            $this->doReport([$watch_name]);
        }
    }
    protected function doReport($groups)
    {
        $groups = is_array($groups)?$groups:[$groups];
        $time_begin = microtime(true);

        $path_report = $this->current_path_dump;
        if (count($groups)===1 && !$this->options['duckcoverage_report_direct']) {
            $path_report = $path_report. $groups[0].'.report';
        } else {
            $path_report = $path_report.'AAAAA.report';
        }
        $this->createReport($groups,$this->current_path_src,$this->current_path_dump,$path_report);
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
        file_put_contents($this->current_path_dump. $name.'.watch.lock',DATE(DATE_ATOM));
        file_put_contents($this->current_path_dump.'DuckCoverage.watching.txt',$name);
    }
    protected function watchingEnd()
    {
        $this->current_group = null;
        $name = $this->watchingGetName();
        @unlink($this->options['duckcoverage_path'].'DuckCoverage.watching.txt');
        @unlink($this->options['duckcoverage_path']. basename($name).'.watch.lock');
    }
    protected function watchingGetName()
    {
        if ($this->current_group) {
            return $this->current_group;
        }
        $group = @file_get_contents($this->options['duckcoverage_path'].'DuckCoverage.watching.txt');
        return $group;    
    }
    ////]]]]
    ////[[[[
    protected function getRunner()
    {
        return GroupCoverageRunner::_();
    }
    public function doBegin($name, $group, $path_src, $path_dump)
    {
        $this->getRunner()->doBegin($name, $group, $path_src, $path_dump);
    }
    public function doEnd()
    {
        $this->getRunner()->doEnd();  // @codeCoverageIgnore
    }
    public function createReport($groups, $path_src, $path_dump, $path_report)
    {
        return $this->getRunner()->createReport( $groups, $path_src, $path_dump, $path_report);
    }
    ////]]]]
}
trait CommandTrait
{
    protected function readCommand($request)
    {
        file_put_contents($this->current_path_dump.'readCommand.log',DATE(DATE_ATOM).' '.$request."\n",FILE_APPEND);
        $request = ltrim($request);
        $map =[
            '#PHASE' => 'explainPhase',
            '#CALL' => 'explainCall',
            '#WEB' => 'explainWeb',
            '#SETWEB' => 'explainSetWeb',
            '#CMD' => 'explainCmd',
        ];
        $flag = preg_match('/^(\S+)\s+(.*)/',$request,$m);
        if($flag){
            $call = ucfirst(substr(strtolower($m[1]),1));
            $method = "explain".$call;
            call_user_func([$this, $method],$request);
        }
    }
    public function explainPhase($request)
    {
        if (substr($request, 0, strlen('#PHASE ')) === '#PHASE ') {
            $phase = trim(substr($request, strlen('#PHASE ')));
            App::Phase($phase);
            return;
        }
    }
    protected function explainWeb($request)
    {
        @list($command, $uri, $poststr, $method) = explode(' ', $request);

        $base_url = (string) ($this->options['duckcoverage_web_base_url'] ?? '');
        if ($base_url === '') {
            // 未配置外部服务器(如 nginx)时,退回内部 PHP 测试服务器
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

        $url = rtrim($base_url,'/') . $uri;
        $data = $this->curl_file_get_contents($url, $post, $is_ajax, $is_options, $method);
        if ($this->options['duckcoverage_echo_back'] ?? false) {
            echo substr($data, 0, 200);
        }
    }
    protected function explainCall($request)
    {
        @list($command, $func) = explode(' ', $request);
        $this->callHandler($func);
    }
    protected function explainSetweb($request)
    {
        @list($command, $pre_curl, $pre_webcall, $post_webcall, $post_curl) = explode(' ', trim($request));
        $this->pre_curl = ($pre_curl === '_') ? null : $pre_curl;
        $this->pre_webcall = ($pre_webcall === '_') ? null : $pre_webcall;
        $this->post_webcall = ($post_webcall === '_') ? null : $post_webcall;
        $this->post_curl = ($post_curl === '_') ? null : $post_curl;
        return;
    }
    protected function explainCmd($request)
    {
        $__SERVER = $_SERVER;
        $_SERVER['argv'] =['-','cmdback'];
        App::_()->execute();
        $_SERVER = $__SERVER;
    }
    ////////////////////////////////////////////////////////////////////////////
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
}
trait HttpServerTrait
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

        //sleep(1);// ugly
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
trait HttpClientTrait
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

    public function prepareCurl($ch)
    {
        $this->headers[] = 'X-MyCoverage-Name: ' . $this->current_name;
        $this->headers[] = 'X-MyCoverage-Group: ' . $this->current_group;
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

        // 始终抓取响应头，以收集/更新所有 Set-Cookie
        curl_setopt($ch, CURLOPT_HEADER, 1);

        if (!empty($post)) {
            curl_setopt($ch, CURLOPT_POST, 1);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
        }
        /////////
        // 连续传递所有已收集的 cookie（不再只传 PHPSESSID）
        if (!empty($this->cookies)) {
            $cookie_str = [];
            foreach ($this->cookies as $name => $value) {
                $cookie_str[] = $name . '=' . $value;
            }
            curl_setopt($ch, CURLOPT_COOKIE, implode('; ', $cookie_str));
        }

        $this->prepareCurl($ch);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $this->headers);
        $data = curl_exec($ch);
        $this->headers = [];
        // 收集响应中的所有 Set-Cookie，同名覆盖（空值/deleted 移除）
        $header_size = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $headers = substr((string)$data, 0, $header_size);
        $data = substr((string)$data, $header_size);
        if (preg_match_all('/Set-Cookie:\s*([^=;\s]+)=([^;]*)/i', $headers, $ms)) {
            foreach ($ms[1] as $i => $name) {
                $value = trim($ms[2][$i]);
                if ($value === '' || strcasecmp($value, 'deleted') === 0) {
                    //unset($this->cookies[$name]);
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
        $data = ($data !== false) ? $data : '';
        return $data;
    }
}
