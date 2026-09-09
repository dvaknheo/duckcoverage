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
            $line = rtrim($line);
            if ($line === '#PHASE_BEGIN') {
                $ret[] = $this->doPhaseBegin();
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

        if (!is_string($filename) || $filename === '') {
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
