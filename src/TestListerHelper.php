<?php declare(strict_types=1);
/**
 * DuckPhp
 * From this time, you never be alone~
 */

namespace DuckCoverage;

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
        $callback  = App::_()->option['duckcoverage_test_lister'];
        if ($callback) {
            $list = ($callback)();
            $list =  $this->explainMarco($list);
        }
        App::Phase($last_phase);
        return $list;
    }
    public function explainMarco($list)
    {
        if (empty($list)){ return '';}
        $list = explode("\n", $list);
        $ret = [];
        foreach($list as $line){
            if ($line === '#PHASE_BEGIN'){
                $ret[] = $this->doPhaseBegin();
            } elseif ($line === '#PHASE_END'){
                $ret[] = $this->doPhaseEnd();
            } else if(substr($line,0,strlen('#INCLUDE_CALL '))){
                $ret[] = $this->doIncludeCall($line);
            } else if(substr($line,0,strlen('#INCLUDE_APP '))){
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
        [$_default, $child_app] = explode(" ",$line);
        return $this->getChildList($child_app);
    }
    protected function replaceLineStart(string $content, array $rules): string
    {
        foreach ($rules as $prefix => $append) {
            if (strncmp($content, $prefix, strlen($prefix)) === 0) {
                return substr_replace($content, $append, strlen($prefix), 0);
            }
        }
        return $content;
    }
    protected function replaceMarco($str,$args)
    {
        if (empty($args)) {
            return $str;
        }
        if (false === strpos($str,'{')) {
            return $str;
        }
        $a = [];
        foreach ($args as $k => $v) {
            $a["{".$k."}"] = $v;
        }
        
        $ret = str_replace(array_keys($a), array_values($a), $str);
        
        return $ret;
    }

    public function listForAllRoute()
    {
        //
    }
    public function listForAllCommand()
    {
        //
    }
    public function listForAllBusiness()
    {
        //
    }
    public function listForAllModel()
    {
        //
    }
}
