<?php declare(strict_types=1);
/**
 * DuckPhp
 * From this time, you never be alone~
 */

namespace DuckCoverage;

use DuckPhp\Core\App;
use DuckPhp\Core\Console;
use DuckPhp\Core\SingletonExTrait;
use DuckPhp\Ext\RouteLister;
use DuckPhp\GlobalAdmin\Admin;
use DuckPhp\GlobalUser\User;

class TestListerHelper
{
    use SingletonExTrait;

    protected $is_data_inited = false;
    protected $phase = null;
    protected $last_phase = null;
    protected $cmd_prefix = null;
    protected $url_prefix = null;
    public function getChildList($child)
    {
        $list = '';
        $last_phase = App::Phase();
        App::_()->toThisChild($child);
        $callback = App::_()->options['duckcoverage_test_lister'] ?? null;
        if ($callback) {
            $list = ($callback)();
            $list = $this->explainMarco($list);
        }
        App::Phase($last_phase);
        return $list;
    }
    public function explainMarco($list)
    {
        if (empty($list)) {
            return '';
        }
        $list = explode("\n", $list);
        $ret = [];
        foreach ($list as $line) {
            $line = rtrim($line);
            if ($line === '#PHASE_BEGIN') {
                $ret[] = $this->doPhaseBegin();
            } elseif ($line === '#CURRENT_PHASE') {
                $ret[] = $this->doCurrentPhase();
            } elseif ($line === '#PHASE_END') {
                $ret[] = $this->doPhaseEnd();
            } elseif (substr($line, 0, strlen('#INCLUDE_CALL ')) === '#INCLUDE_CALL ') {
                $ret[] = $this->doIncludeCall($line);
            } elseif (substr($line, 0, strlen('#INCLUDE_CHILD ')) === '#INCLUDE_CHILD ') {
                $ret[] = $this->doIncludeChild($line);
            } elseif (substr($line, 0, strlen('#BUSINESS ')) === '#BUSINESS ') {
                $ret[] = $this->doIncludeComponent($line, '#BUSINESS ', 'Business\\');
            } elseif (substr($line, 0, strlen('#MODEL ')) === '#MODEL ') {
                $ret[] = $this->doIncludeComponent($line, '#MODEL ', 'Model\\');
            } elseif (substr($line, 0, strlen('#ACTION ')) === '#ACTION ') {
                $ret[] = $this->doIncludeComponent($line, '#ACTION ', 'Controller\\');
            } elseif (preg_match('/^#(ADMIN|USER)_(LOGIN|LOGOUT|CLEAN)$/', $line, $matches)) {
                // 见 README「Macro directives」：等价于调用 TestListByAdminLogin() 那几个静态方法
                $ret[] = $this->doTestListOf($matches[1], strtolower($matches[2]));
            } else {
                $ret[] = $line;
            }
        }
        return implode("\n", $ret);
    }
    protected function doPhaseBegin()
    {
        $phase = App::Phase();
        $this->last_phase = App::_()->getLastPhase();
        return "PHASE $phase";
    }
    /**
     * `#CURRENT_PHASE`：等价于指令 `PHASE {App::Phase()}`
     *
     * 只是把当前 phase 显式写进清单（不记录 last_phase，也不改变当前 phase），
     * 适合在清单中间"锁一下当前 phase"。
     */
    protected function doCurrentPhase(): string
    {
        return 'PHASE ' . App::Phase();
    }
    protected function doPhaseEnd()
    {
        $last_phase = $this->last_phase;
        return "PHASE $last_phase";
    }
    protected function doIncludeCall(string $line)
    {
        $str = substr($line, strlen('#INCLUDE_CALL '));
        return DuckCoverage::_()->callHandler($str);
    }
    protected function doIncludeChild(string $line)
    {
        [$_default, $child_app] = explode(" ", $line);
        return "COMMENT APP $child_app\n".$this->getChildList($child_app);
    }
    protected function doIncludeComponent(string $line, string $old_cmd, string $new_prefix = '')
    {
        $prefix = 'CALL '. App::Phase().'!'.App::_()->options['namespace']."\\".$new_prefix;
        return substr_replace($line, $prefix, 0, strlen($old_cmd));
    }

    public function genTestListOfRoutes()
    {
        $routes = RouteLister::_()->listAll(true, true);
        $list = [];
        foreach ($routes as $route) {
            $uri = $route['url'] ?? '';
            $list[] = "WEB {$uri}";
        }
        return implode("\n", $list)."\n";
    }
    public function genTestListOfCommands()
    {
        $prefix = App::_()->getThisCommandPrefix();
        $classes = Console::_()->options['console_command_classes'][$prefix] ?? [];
        $list = [];
        foreach ($classes as $class => $method_prefix) {
            if (!isset($method_prefix) || $method_prefix === false) {
                continue;
            }
            $method_prefix = ($method_prefix === true) ? 'command_' : $method_prefix;

            $reflect = new \ReflectionClass($class);
            foreach ($reflect->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
                if ($method->isStatic() || $method->isConstructor()) {
                    continue;
                }
                $method_name = $method->getName();
                if (substr($method_name, 0, strlen($method_prefix)) !== $method_prefix) {
                    continue;
                }
                $cmd = substr($method_name, strlen($method_prefix));
                $command = ($prefix === '') ? $cmd : $prefix . ':' . $cmd;
                $list[] = "RUN {$command}";
            }
        }
        return implode("\n", $list)."\n";
    }
    public function genTestListOfComponents()
    {
        $class = App::_()->getThisClassName();
        $reflect = new \ReflectionClass($class);
        $filename = $reflect->getFileName();

        if (!is_string($filename)) {
            throw new \LogicException("Can not locate file for App->getThisClassName() '{$class}'"); //@codeCoverageIgnore
        }

        $namespace = trim((string) App::_()->options['namespace'], '\\');

        $base_path = substr($filename, 0, 0 - (strlen($class) - strlen($namespace) - 1 + strlen('.php')));
        $list = [];
        $list = array_merge($list, $this->getComponentCalls($base_path, $namespace, 'Business'));
        $list = array_merge($list, $this->getComponentCalls($base_path, $namespace, 'Model'));
        return implode("\n", $list)."\n";
    }
    protected function getComponentCalls($base_path, $namespace, $component)
    {
        $ret = [];
        $cmd = '#'.strtoupper($component);

        $dir = $base_path . $component;
        $directory = new \RecursiveDirectoryIterator($dir, \FilesystemIterator::CURRENT_AS_PATHNAME | \FilesystemIterator::SKIP_DOTS);
        $iterator = new \RecursiveIteratorIterator($directory);
        foreach ($iterator as $file) {
            if (substr($file, -strlen($component.'.php')) !== $component.'.php') {
                continue;
            }

            $short_class = str_replace('/', '\\', substr($file, strlen($dir) + 1, -strlen('.php')));
            $class = $namespace . '\\' . $component .'\\'. $short_class;

            // Business 已经格式化好了
            try {
                // @phpstan-ignore-next-line argument.type
                $reflect = new \ReflectionClass($class);
            } catch (\ReflectionException $ex) { //@codeCoverageIgnore
                continue; //@codeCoverageIgnore
            }
            foreach ($reflect->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
                if ($method->isStatic() || $method->isConstructor() || $method->isAbstract()) {
                    continue;
                }
                if ((string)$method->getFileName() !== (string)realpath($file)) {
                    continue; //@codeCoverageIgnore
                }

                $method_name = $method->getName();
                $ret[] = "{$cmd} {$short_class}@{$method_name}".$this->getCallParams($method);
            }
        }
        return $ret;
    }
    protected function getCallParams(\ReflectionMethod $method)
    {
        $ret = [];
        foreach ($method->getParameters() as $param) {
            $default = null;
            if ($param->isDefaultValueAvailable()) {
                $value = $param->getDefaultValue();
                $default = is_scalar($value) ? (string)$value : '';
            } else {
                $default = '';
            }
            $ret[$param->getName()] = $default;
        }
        return empty($ret)?'':' '.http_build_query($ret);
    }

    public function genTestListOfAll()
    {
        $list = '';
        $list .= $this->genTestListOfCommands();
        $list .= $this->genTestListOfRoutes();
        $list .= $this->genTestListOfComponents();
        $list .= "\n";
        return $list;
    }
    /**
     * `#ADMIN_LOGIN` / `#ADMIN_LOGOUT` / `#ADMIN_CLEAN` / `#USER_LOGIN` / `#USER_LOGOUT` / `#USER_CLEAN`
     *
     * 在对应提供者的 phase 下取测试清单并嵌进当前清单，等价于调用
     * `TestListByAdminLogin()` / `TestListByUserLogout()` 这类静态方法。
     *
     * @param string $which     ADMIN | USER
     * @param string $parameter login | logout | clean
     */
    protected function doTestListOf(string $which, string $parameter): string
    {
        $service = ($which === 'ADMIN') ? Admin::_() : User::_();
        return $this->listOfAdminOrUser($service, $parameter);
    }
    /**
     * 在指定的"管理员/用户提供者"的 phase 下取测试清单。
     *
     * DuckAdmin/DuckUser 这类应用同一时刻只有一个提供者生效，所以清单要在对应 phase 下生成：
     * 先切到 `$service` 自己的 phase，把 `$parameter`（login|logout|clean|null）放进
     * `options['duckcoverage_test_lister_parameter']` 供回调读取，取完清单再切回原来的 phase
     * （App::Phase() 返回切换前的 phase）。$service->phase() 为何可用见方法内的英文注释。
     *
     * @param object $service
     * @param string|null $parameter null | 'login' | 'logout' | 'clean'
     * @return string
     */
    public function listOfAdminOrUser($service, ?string $parameter): string
    {
        /*
         * Why can we call $service->phase()?
         *
         * During DuckPHP's initialization, when an Admin or User provider is configured, the framework
         * REPLACES the Admin/User class with a PhaseProxy instance (see DuckPhp\Component\PhaseProxy and
         * the CreatePhaseProxy() call inside DuckPhp\GlobalAdmin\Admin). That proxy is bound to the
         * provider's phase and exposes phase() for it, which is exactly the question we need answered
         * here: "which phase is this provider active in?".
         *
         * So $service->phase() is the normal, supported call. A plain object that has no provider behind
         * it (nothing configured, or a stub in tests) has no phase(), so fall back to the current phase
         * instead of fataling.
         */
        $phase = method_exists($service, 'phase') ? (string)$service->phase() : (string)App::Phase();
        $last = App::Phase($phase);

        App::_()->options['duckcoverage_test_lister_parameter'] = $parameter;

        $lister = App::_()->options['duckcoverage_test_lister'] ?? null;
        $body = $lister ? (string)($lister)() : '';
        $out = $this->explainMarco($body);
        App::_()->options['duckcoverage_test_lister_parameter'] = null;
        App::Phase($last);

        return $out;
    }
    /** 管理员提供者：登录态的测试清单（可直接当 options['duckcoverage_test_lister'] 用） */
    public static function TestListByAdminLogin()
    {
        return static::_()->listOfAdminOrUser(Admin::_(), 'login');
    }
    /** 管理员提供者：登出态 */
    public static function TestListByAdminLogout()
    {
        return static::_()->listOfAdminOrUser(Admin::_(), 'logout');
    }
    /** 管理员提供者：清理态 */
    public static function TestListByAdminClean()
    {
        return static::_()->listOfAdminOrUser(Admin::_(), 'clean');
    }
    /** 用户提供者：登录态 */
    public static function TestListByUserLogin()
    {
        return static::_()->listOfAdminOrUser(User::_(), 'login');
    }
    /** 用户提供者：登出态 */
    public static function TestListByUserLogout()
    {
        return static::_()->listOfAdminOrUser(User::_(), 'logout');
    }
    /** 用户提供者：清理态 */
    public static function TestListByUserClean()
    {
        return static::_()->listOfAdminOrUser(User::_(), 'clean');
    }

    // public function replaceLineStart(string $content, array $rules): string
    // {
    //     foreach ($rules as $prefix => $append) {
    //         if (strncmp($content, $prefix, strlen($prefix)) === 0) {
    //             return substr_replace($content, $append, strlen($prefix), 0);
    //         }
    //     }
    //     return $content;
    // }
    // public function replaceMarco($str,$args)
    // {
    //     if (empty($args)) {
    //         return $str;
    //     }
    //     if (false === strpos($str,'{')) {
    //         return $str;
    //     }
    //     $a = [];
    //     foreach ($args as $k => $v) {
    //         $a["{".$k."}"] = $v;
    //     }

    //     $ret = str_replace(array_keys($a), array_values($a), $str);

    //     return $ret;
    // }
    // protected function get_component_path($component,$base_file = 'Base')
    // {
    //         // \\DuckAdmin\\   xxxx\\DuckAdmin\\system\\AA.php $file DuckAdmin
    //     $base_class = App::Current()->options['namespace']."\\{$component}\\{$base_file}";
    //     $ref = new \ReflectionClass($base_class);
    //     $path = dirname($ref->getFilename()).'/';
    //     return $path;
    // }

    // protected function get_all_component_classes_files($path, $component)
    // {
    //     $directory = new \RecursiveDirectoryIterator($path, \FilesystemIterator::CURRENT_AS_PATHNAME | \FilesystemIterator::SKIP_DOTS);
    //     $iterator = new \RecursiveIteratorIterator($directory);
    //     $files = \iterator_to_array($iterator, false);

    //     $ret = [];
    //     foreach ($files as $file) {
    //         if(substr($file,-strlen($component.'.php'))!==$component.'.php'){continue;};
    //         $ret[] = $file;
    //     }
    //     return $ret;
    // }
    // public function getComponentTestString($path, $file, $namespace)
    // {
    //     $data = file_get_contents($file);
    //     preg_match_all('/public\s+function (([^\(]+)\([^\)]*\))/', (string)$data, $m);
    //     $funcs = $m[1];

    //     $ret = '';
    //     $class = substr($file,strlen($path),-strlen('.php'));
    //     $class = $namespace .str_replace('/','\\',$class);
    //     foreach ($funcs as $v) {
    //         $v = str_replace(['&','callable '], ['',''], $v);
    //         $ret .= "        \\{$class}::_()->$v;\n";
    //     }
    //     return $ret;
    // }
}
