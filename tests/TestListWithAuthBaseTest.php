<?php
namespace tests\DuckCoverage;

use DuckCoverage\TestListWithAuthBase;
use DuckPhp\Core\App;
use LibCoverage\LibCoverage;

/**
 * TestListWithAuthBase：按 options['duckcoverage_test_lister_parameter'] 分流的测试清单基类。
 *
 * 本类用两个子类覆盖：
 * - TestListWithAuthSubclass 实现了四个分派目标 -> 验证 login/logout/clean/默认 四条分支；
 * - TestListWithAuthStub 不实现 -> 验证基类的 "Not implemented" 占位实现。
 */
class TestListWithAuthBaseTest extends \PHPUnit\Framework\TestCase
{
    public function testAll()
    {
        $__SERVER = $_SERVER;
        LibCoverage::Begin(TestListWithAuthBase::class);

        $options = &App::_()->options;
        // 静态入口 + login
        $options['duckcoverage_test_lister_parameter'] = 'login';
        $this->assertSame('LOGIN', TestListWithAuthSubclass::GetTestList());
        // 参数是"消费掉"的：取完清单就 unset，不会漏给下一次
        $this->assertArrayNotHasKey('duckcoverage_test_lister_parameter', $options);

        // logout / clean
        $options['duckcoverage_test_lister_parameter'] = 'logout';
        $this->assertSame('LOGOUT', TestListWithAuthSubclass::_()->_GetTestList());
        $this->assertArrayNotHasKey('duckcoverage_test_lister_parameter', $options);
        $options['duckcoverage_test_lister_parameter'] = 'clean';
        $this->assertSame('CLEAN', TestListWithAuthSubclass::_()->_GetTestList());

        // 没有参数、以及未知参数 -> 完整清单
        $this->assertSame('FULL', TestListWithAuthSubclass::_()->_GetTestList());
        $options['duckcoverage_test_lister_parameter'] = 'nonsense';
        $this->assertSame('FULL', TestListWithAuthSubclass::_()->_GetTestList());
        $this->assertArrayNotHasKey('duckcoverage_test_lister_parameter', $options);

        // 基类的四个占位实现都会抛 "Not implemented"
        foreach (['_GetTestListForLogin', '_GetTestListForLogout', '_GetTestListForClean', '_GetTestListFull'] as $method) {
            try {
                TestListWithAuthStub::_()->{$method}();
                $this->fail("$method 应该抛异常");
            } catch (\Exception $ex) {
                $this->assertSame('Not implemented', $ex->getMessage());
            }
        }

        $_SERVER = $__SERVER;
        LibCoverage::End();
    }
}

class TestListWithAuthSubclass extends TestListWithAuthBase
{
    public function _GetTestListForLogin(): string
    {
        return 'LOGIN';
    }
    public function _GetTestListForLogout(): string
    {
        return 'LOGOUT';
    }
    public function _GetTestListForClean(): string
    {
        return 'CLEAN';
    }
    public function _GetTestListFull(): string
    {
        return 'FULL';
    }
}

class TestListWithAuthStub extends TestListWithAuthBase
{
}
