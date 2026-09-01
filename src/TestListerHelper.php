<?php declare(strict_types=1);
/**
 * DuckPhp
 * From this time, you never be alone~
 */

namespace DuckCoverage;

use DuckPhp\Component\RouteLister;
use DuckPhp\Core\App;
use DuckPhp\Core\Console;
use DuckPhp\Core\SingletonExTrait;

class TestListerHelper
{
    use SingletonExTrait;

    protected $is_data_inited = false;
    protected $phase = null;
    protected $cmd_prefix = null;
    protected $url_prefix = null;
    public function getChildList($child)
    {
        $list = '';
        $last_phase = App::Phase();
        App::_()->toThisChild($child);
        $callback = App::_()->options['duckcoverage_test_lister'];
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
            if ($line === '#PHASE_BEGIN') {
                $ret[] = $this->doPhaseBegin();
            } elseif ($line === '#PHASE_END') {
                $ret[] = $this->doPhaseEnd();
            } elseif (substr($line, 0, strlen('#INCLUDE_CALL ')) === '#INCLUDE_CALL ') {
                $ret[] = $this->doIncludeCall($line);
            } elseif (substr($line, 0, strlen('#INCLUDE_CHILD ')) === '#INCLUDE_CHILD ') {
                $ret[] = $this->doIncludeChild($line);
            } else {
                $ret[] = $line;
            }
        }
        return implode("\n", $ret);
    }
    protected function doPhaseBegin()
    {
        $phase = App::Phase();
        return "PHASE $phase";
    }
    protected function doPhaseEnd()
    {
        $last_phase = App::_()->getLastPhase();
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
        return $this->getChildList($child_app);
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



    public function genTestListOfRoutes()
    {
        $routes = RouteLister::_()->listAll(true, true);
        $list = [];
        foreach ($routes as $route) {
            $uri = $route['url'] ?? '';
            if ($uri === '') {
                continue;
            }
            $list[] = "WEB {$uri}";
        }
        return implode("\n", $list);
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
        return implode("\n", $list);
    }
    public function genTestListOfComponents($components = ['Business', 'Model'])
    {
        $base_dir = App::_()->options['path_namespace'] ?? null;
        if (empty($base_dir)) {
            throw new \LogicException('options[path_namespace] is required for genTestListOfComponents()');
        }
        $is_abs = (substr($base_dir, 0, 1) === '/') || preg_match('#^[a-zA-Z]:[\\\\/]#', $base_dir) === 1;
        if (!$is_abs) {
            $base_dir = App::_()->getProjectPath().$base_dir;
        }
        $base_dir = rtrim($base_dir, '/\\').DIRECTORY_SEPARATOR;

        $namespace = trim((string)App::_()->options['namespace'], '\\');
        $prefix = $namespace === '' ? '' : $namespace.'\\';

        $list = [];
        foreach ($components as $component) {
            $component_dir = $base_dir.$component;
            if (!is_dir($component_dir)) {
                continue;
            }
            $list = array_merge($list, $this->getComponentCalls($component_dir, $prefix.$component, $component_dir));
        }
        return implode("\n", $list);
    }
    protected function getComponentCalls($dir, $namespace_prefix, $base_dir)
    {
        $directory = new \RecursiveDirectoryIterator($dir, \FilesystemIterator::CURRENT_AS_PATHNAME | \FilesystemIterator::SKIP_DOTS);
        $iterator = new \RecursiveIteratorIterator($directory);
        $ret = [];
        foreach ($iterator as $file) {
            if (substr($file, -strlen('.php')) !== '.php') {
                continue;
            }
            $rel = ltrim(substr($file, strlen($base_dir), -strlen('.php')), '/\\');
            $class = $namespace_prefix.'\\'.str_replace('/', '\\', $rel);
            try {
                // @phpstan-ignore-next-line argument.type
                $reflect = new \ReflectionClass($class);
            } catch (\ReflectionException $ex) {
                continue;
            }
            if ($reflect->isAbstract() || $reflect->isInterface() || $reflect->isTrait() || $reflect->isEnum()) {
                continue;
            }
            if (!$reflect->hasMethod('_')) {
                continue;
            }
            foreach ($reflect->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
                if ($method->isStatic() || $method->isConstructor() || $method->isAbstract()) {
                    continue;
                }
                // 只收录声明在本类文件内的动态方法，排除从父类/trait 继承的方法
                if ((string)$method->getFileName() !== (string)realpath($file)) {
                    continue;
                }
                $ret[] = "CALL {$class}@{$method->getName()}".$this->getCallParams($method);
            }
        }
        return $ret;
    }
    protected function getCallParams(\ReflectionMethod $method)
    {
        $str = '';
        foreach ($method->getParameters() as $param) {
            $default = null;
            if ($param->isDefaultValueAvailable()) {
                $value = $param->getDefaultValue();
                $default = is_scalar($value) ? (string)$value : '';
            } else {
                $default = '';
            }
            $str .= ' '.$param->getName().'='.$default;
        }
        return $str;
    }

    public function genTestListOfAll()
    {
        $list = '';
        $list .= $this->genTestListOfCommands();
        $list .= $this->genTestListOfRoutes();
        $list .= $this->genTestListOfComponents();
        $list .= "\n";
        return;
    }
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
