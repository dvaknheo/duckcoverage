<?php declare(strict_types=1);
/**
 * DuckPhp
 * From this time, you never be alone~
 */

namespace DuckCoverage;

use DuckPhp\Component\RouteLister;
use DuckPhp\Core\App;
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
        return '';
        //$routes = RouteLister::_()->listAll(true,true);
        //TODO 我们根据路由给出测试语句
    }
    public function genTestListOfCommands()
    {
        return '';
        //TODO 我们根据 command 给出测试语句
    }
    public function genTestListOfComponents()
    {
        return '';
        //TODO 我们给出所有组件列表
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
