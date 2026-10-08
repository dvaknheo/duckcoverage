<?php declare(strict_types=1);
/**
 * DuckPhp
 * From this time, you never be alone~
 */

namespace DuckCoverage;

use DuckPhp\Core\App;
use DuckPhp\Foundation\SingletonTrait;

abstract class TestListWithAuthBase
{
    use SingletonTrait;
    public static function GetTestList(): string
    {
        return static::_()->_GetTestList();
    }
    public function _GetTestList(): string
    {
        $cmd = (string)(App::_()->options['duckcoverage_test_lister_parameter'] ?? '');
        unset(App::_()->options['duckcoverage_test_lister_parameter']);
        switch ($cmd) {
            case 'login':
                return $this->_GetTestListForLogin();
            case 'logout':
                return $this->_GetTestListForLogout();
            case 'clean':
                return $this->_GetTestListForClean();
            default:
                return $this->_GetTestListFull();
        }
    }
    public function _GetTestListForLogin(): string
    {
        throw new \Exception('Not implemented');
    }
    public function _GetTestListForLogout(): string
    {
        throw new \Exception('Not implemented');
    }
    public function _GetTestListForClean(): string
    {
        throw new \Exception('Not implemented');
    }
    public function _GetTestListFull(): string
    {
        throw new \Exception('Not implemented');
    }

}
