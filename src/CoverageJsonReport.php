<?php declare(strict_types=1);
/**
 * DuckPhp
 * From this time, you never be alone~
 */

namespace DuckCoverage;

use DuckPhp\Core\SingletonExTrait;
use SebastianBergmann\CodeCoverage\CodeCoverage;
use SebastianBergmann\CodeCoverage\StaticAnalysis\ParsingFileAnalyser;

/**
 * 生成机器可读的覆盖率报告 report.json（与 HTML 报告写在同一目录）。
 *
 * 与 GroupCoverage 的分工：
 * - GroupCoverage 负责采集/合并/HTML，输出"同一份报告"的 JSON 版本交给本类格式化；
 * - 本类只读 CodeCoverage 的数据，不与驱动打交道，也不落任何采集状态。
 * 两个类都直接接触 php-code-coverage，改动底层 API 时请一并检查。
 *
 * 口径与 HTML 报告一致：
 * - executable_lines = 驱动报告过的行 ∪ 静态分析在源码目录里找到的可执行行(被 codeCoverageIgnore 注解忽略的行不算)
 * - line_map: 1 = 已执行, -1 = 可执行但未执行, -2 = dead code
 * - functions = 具名函数 + 方法(含 trait 方法)；covered = 该单元所有可执行行都执行过
 * - loaded = 采集期间驱动报告过这个文件，即运行时真的被加载过
 *
 * 注意：本注释里不要在类级 docblock 写出 codeCoverageIgnore 的注解写法，
 * 否则 php-code-coverage 会把整个类当成被忽略(本类最初就踩过这个坑)。
 */
class CoverageJsonReport
{
    use SingletonExTrait;

    /**
     * 机器可读报告的文件名，写在与 index.html 同一个报告目录里
     */
    protected const JSON_REPORT_FILE = 'report.json';

    /**
     * 组装并写出 report.json
     *
     * @param array<string,mixed> $context 见 render() 的说明
     * @return string 写出的文件路径
     */
    public function report(CodeCoverage $coverage, array $context, string $path_report): string
    {
        return $this->write($this->render($coverage, $context), $path_report);
    }

    /**
     * 组装机器可读的报告数据(不落盘，便于测试)。
     *
     * $context 需要的键：
     * - groups: array<string>          本次报告合并的组名
     * - dumps_merged: int              合并的 dump 个数
     * - path_src: string               源码根
     * - path_root: string              工程根(用于把 path 写成相对工程根；空或不在其下时用 '.' )
     * - loaded_files: array<string>    补坑前驱动报告过的文件路径(说明运行时真的被加载过)
     * - files: array<string>           源码文件列表(绝对路径，必须与 filter 里的一致且已排序)
     * - driver_class: string           驱动的类名(用于 generator.coverage_driver)
     *
     * @param array<string,mixed> $context
     * @return array<string,mixed>
     */
    public function render(CodeCoverage $coverage, array $context): array
    {
        /** @var array<int, string> $source_files */
        $source_files = $context['files'];
        $loaded_files = $this->indexFileKeys((array)$context['loaded_files']);
        $driver_class = (string)$context['driver_class'];

        $analyser = new ParsingFileAnalyser(true, true);
        $index = $this->indexLineCoverage($coverage->getData()->lineCoverage());
        [$base, $root, $src_base] = $this->reportBases((string)$context['path_src'], (string)$context['path_root']);

        $files = [];
        $ignored_files = [];
        $directories = [];
        $totals = [
            'files' => 0,
            'lines' => ['executable' => 0, 'executed' => 0, 'percent' => 0.0],
            'functions' => ['total' => 0, 'covered' => 0, 'percent' => 0.0],
            'classes' => ['total' => 0, 'covered' => 0, 'percent' => 0.0],
            'traits' => ['total' => 0, 'covered' => 0, 'percent' => 0.0],
        ];

        foreach ($source_files as $file) {
            $path = $this->relativePath($file, $base);
            $lines = $index[$this->normalizePath($file)] ?? [];

            $executable = 0;
            $executed = 0;
            $line_map = [];
            $uncovered = [];
            foreach ($lines as $line => $tests) {
                if ($tests === null) {
                    $line_map[(string)$line] = -2;
                    continue;
                }
                $executable++;
                if ($tests === []) {
                    $line_map[(string)$line] = -1;
                    $uncovered[] = (int)$line;
                    continue;
                }
                $line_map[(string)$line] = 1;
                $executed++;
            }

            if ($executable === 0) {
                // 没有可执行行：整文件被忽略，或者就是个空文件
                $ignored_files[] = [
                    'path' => $path,
                    'reason' => $analyser->executableLinesIn($file) === []
                        ? 'no executable lines (@codeCoverageIgnore or empty file)'
                        : 'all executable lines are @codeCoverageIgnore',
                ];
                continue;
            }

            [$functions, $classes, $traits] = $this->units($analyser, $file, $lines);
            $functions_covered = 0;
            foreach ($functions as $item) {
                if ($item['covered']) {
                    $functions_covered++;
                }
            }
            ksort($line_map, SORT_NUMERIC);
            sort($uncovered);

            $dir = $this->dirOf($path);
            // 签名行：GroupCoverage 已经把它们从 lineCoverage 里剔除，这里优先用传进来的那份
            // （直接调用 render() 的单元测试没经过剔除，就自己算）
            $sig = $context['sig_lines'][$file] ?? $this->signatureLines($file, $lines);
            $files[] = [
                'path' => $path,
                'dir' => $dir,
                'app' => $this->appOf($this->relativePath($file, $src_base)),
                'sha1' => is_file($file) ? (string)sha1_file($file) : '',
                'loaded' => isset($loaded_files[$this->normalizePath($file)]),
                'lines' => [
                    'executable' => $executable,
                    'executed' => $executed,
                    'percent' => $this->percent($executed, $executable),
                ],
                'functions' => [
                    'total' => count($functions),
                    'covered' => $functions_covered,
                    'percent' => $this->percent($functions_covered, count($functions)),
                ],
                'classes' => [
                    'total' => $classes['total'],
                    'covered' => $classes['covered'],
                    'percent' => $this->percent($classes['covered'], $classes['total']),
                ],
                'traits' => [
                    'total' => $traits['total'],
                    'covered' => $traits['covered'],
                    'percent' => $this->percent($traits['covered'], $traits['total']),
                ],
                'uncovered_lines' => $uncovered,
                // todo_lines = uncovered_lines - sig：真正还能补的行
                'todo_lines' => array_values(array_diff($uncovered, $sig)),
                // 签名行：被算成可执行、但驱动永远不会标为已执行的行(见 §5.4 与 signatureLines())
                'sig' => $sig,
                'function_items' => $functions,
                'line_map' => $line_map,
            ];

            $totals['files']++;
            $totals['lines']['executable'] += $executable;
            $totals['lines']['executed'] += $executed;
            $totals['functions']['total'] += count($functions);
            $totals['functions']['covered'] += $functions_covered;
            $totals['classes']['total'] += $classes['total'];
            $totals['classes']['covered'] += $classes['covered'];
            $totals['traits']['total'] += $traits['total'];
            $totals['traits']['covered'] += $traits['covered'];

            if (!isset($directories[$dir])) {
                $directories[$dir] = ['path' => $dir, 'files' => 0, 'lines' => ['executable' => 0, 'executed' => 0, 'percent' => 0.0]];
            }
            $directories[$dir]['files']++;
            $directories[$dir]['lines']['executable'] += $executable;
            $directories[$dir]['lines']['executed'] += $executed;
        }

        $totals['lines']['percent'] = $this->percent($totals['lines']['executed'], $totals['lines']['executable']);
        $totals['functions']['percent'] = $this->percent($totals['functions']['covered'], $totals['functions']['total']);
        $totals['classes']['percent'] = $this->percent($totals['classes']['covered'], $totals['classes']['total']);
        $totals['traits']['percent'] = $this->percent($totals['traits']['covered'], $totals['traits']['total']);
        foreach ($directories as $dir => $item) {
            $directories[$dir]['lines']['percent'] = $this->percent($item['lines']['executed'], $item['lines']['executable']);
        }
        $directories = array_values($directories);
        usort($directories, [$this, 'compareByPath']);
        usort($files, [$this, 'compareByPath']);
        usort($ignored_files, [$this, 'compareByPath']);

        $ret = [
            'schema' => 'duckcoverage-report/1',
            'generated_at' => (new \DateTime())->format(\DateTime::ATOM),
            'generator' => [
                'name' => 'duckcoverage',
                'version' => $this->packageVersion(),
                'php' => PHP_VERSION,
                'coverage_driver' => $this->driverName($driver_class),
            ],
            'root' => $root,
            'groups' => array_values($context['groups']),
            'dumps_merged' => (int)$context['dumps_merged'],
            'definitions' => [
                'executable_lines' => 'lines reported by the coverage driver, plus executable lines found by static analysis in the source root (excluding @codeCoverageIgnore lines, and minus the signature lines listed in sig, which the driver never reports)',
                'line_map' => [
                    '1' => 'executed',
                    '-1' => 'executable, not executed',
                    '-2' => 'dead code / not executable',
                ],
                'functions' => 'named functions and methods, including trait methods; covered = every executable line of the unit was executed',
                'loaded' => 'the file was reported by the driver, i.e. it was loaded while collecting',
                'sig' => 'signature lines: executable lines that the driver never marks as executed (for example a default value inside a multi-line function signature); listed only when every executable line outside the signature of that declaration was executed. These lines are dropped from line coverage before rendering, so they are not counted as executable anywhere (they do not appear in executable counts, line_map or unc)',
            ],
            'totals' => $totals,
            'directories' => $directories,
            'files' => $files,
            'ignored_files' => $ignored_files,
        ];
        // 规格 §3.3-B：一份 dump 都没合并到时，顶层给出机器可读的警告（正常时不带该字段）。
        // 注意不要复用 complete：那是"生成器正常收尾"，空报告确实正常收尾了。
        if ((int)$context['dumps_merged'] === 0) {
            $ret['warning'] = 'no dumps merged';
        }
        return $ret;
    }

    /**
     * 把组装好的数组写成 report.json
     *
     * @param array<string,mixed> $report
     * @return string 写出的文件路径
     */
    public function write(array $report, string $path_report): string
    {
        $file = rtrim($path_report, '/\\') . DIRECTORY_SEPARATOR . static::JSON_REPORT_FILE;
        // JSON_PRESERVE_ZERO_FRACTION：percent 始终是带小数的数字(0.0 / 50.0)，类型稳定
        $json = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
        if ($json === false) {
            throw new \RuntimeException('DuckCoverage: unable to encode the JSON report'); // @codeCoverageIgnore
        }
        file_put_contents($file, $json . "\n");
        return $file;
    }

    /**
     * 报告排序用：files / directories / ignored_files 都按 path 升序，保证输出确定性
     *
     * @param array<string,mixed> $a
     * @param array<string,mixed> $b
     */
    protected function compareByPath(array $a, array $b): int
    {
        return strcmp((string)$a['path'], (string)$b['path']);
    }

    /**
     * 收集一个文件里的函数/方法/类/性状统计
     *
     * @param array<int, mixed> $lines
     * @return array{0:array<int,array<string,mixed>>, 1:array{total:int,covered:int}, 2:array{total:int,covered:int}}
     */
    protected function units(ParsingFileAnalyser $analyser, string $file, array $lines): array
    {
        $functions = [];
        foreach ($analyser->functionsIn($file) as $function) {
            $functions[] = $this->unitItem($lines, (string)$function['name'], null, (int)$function['startLine'], (int)$function['endLine']);
        }
        $classes = ['total' => 0, 'covered' => 0];
        foreach ($analyser->classesIn($file) as $class) {
            $classes['total']++;
            if ($this->unitCovered($lines, (int)$class['startLine'], (int)$class['endLine'])) {
                $classes['covered']++;
            }
            foreach ($class['methods'] as $method) {
                $functions[] = $this->unitItem($lines, (string)($method['methodName'] ?? $method['name'] ?? ''), (string)$class['name'], (int)$method['startLine'], (int)$method['endLine']);
            }
        }
        $traits = ['total' => 0, 'covered' => 0];
        foreach ($analyser->traitsIn($file) as $trait) {
            $traits['total']++;
            if ($this->unitCovered($lines, (int)$trait['startLine'], (int)$trait['endLine'])) {
                $traits['covered']++;
            }
            foreach ($trait['methods'] as $method) {
                $functions[] = $this->unitItem($lines, (string)($method['methodName'] ?? $method['name'] ?? ''), (string)$trait['name'], (int)$method['startLine'], (int)$method['endLine']);
            }
        }
        return [$functions, $classes, $traits];
    }

    /**
     * @param array<int, mixed> $lines
     * @return array<string,mixed>
     */
    protected function unitItem(array $lines, string $name, ?string $class, int $start, int $end): array
    {
        [$executable, $executed] = $this->unitCounts($lines, $start, $end);
        return [
            'name' => $name,
            'class' => $class,
            'start' => $start,
            'end' => $end,
            'executable' => $executable,
            'executed' => $executed,
            'covered' => $executable > 0 && $executed === $executable,
        ];
    }

    /**
     * @param array<int, mixed> $lines
     */
    protected function unitCovered(array $lines, int $start, int $end): bool
    {
        [$executable, $executed] = $this->unitCounts($lines, $start, $end);
        return $executable > 0 && $executed === $executable;
    }

    /**
     * 单元的可执行/已执行行数。isset() 对 null 为 false，因此 dead code 不计入可执行，与文件级口径一致。
     *
     * @param array<int, mixed> $lines
     * @return array{0:int, 1:int}
     */
    protected function unitCounts(array $lines, int $start, int $end): array
    {
        $executable = 0;
        $executed = 0;
        for ($line = $start; $line <= $end; $line++) {
            if (!isset($lines[$line])) {
                continue;
            }
            $executable++;
            if ($lines[$line] !== []) {
                $executed++;
            }
        }
        return [$executable, $executed];
    }

    /**
     * 签名行(规格 §5.4)：被算成可执行、但驱动永远不会标为已执行的"头部行"。
     *
     * 判定规则：
     * - 头部行 = 从 function 关键字所在行，到函数体 "{" 所在行(抽象方法/接口方法等没有函数体的，
     *   取声明结尾 ";" 所在行)，闭区间；
     * - 若该声明范围内(头部行除外)的可执行行全部已执行，则头部行里那些从未执行的可执行行就是签名行；
     * - 同时要求声明范围内至少有一行已执行，否则就是"整个方法从没被调用过"，不能算签名行。
     *
     * 典型例子(驱动侧全命中，报表却少一行)：
     *   public function log(
     *       $a,
     *       array $ext = []     <- 静态分析算它可执行，但驱动永远不会执行它
     *   ) {
     *       return;             <- 只有这一行被执行
     *   }
     *
     * 公开：GroupCoverage 在补坑后用它把这些行从 lineCoverage 里剔除，
     * 让它们不再计入可执行行（否则文件永远到不了 100%）。
     *
     * @param array<int, array<string,string>|null> $lines 该文件的行覆盖(与 line_map 同源)
     * @return array<int,int> 升序的签名行行号
     */
    public function signatureLines(string $file, array $lines): array
    {
        $source = @file_get_contents($file);
        if ($source === false) {
            return []; // @codeCoverageIgnore
        }
        $tokens = token_get_all($source);
        $token_lines = $this->tokenLines($tokens);
        $sig = [];
        foreach ($this->functionDeclarations($tokens, $token_lines) as $declaration) {
            foreach ($this->signatureLinesIn($lines, $declaration) as $line) {
                $sig[$line] = true;
            }
        }
        $ret = array_keys($sig);
        sort($ret, SORT_NUMERIC);
        return $ret;
    }

    /**
     * 每个 token 的起始行号。token_get_all() 里只有数组形态的 token 自带行号，
     * 单字符 token(如 "{" / ";")没有行号，要用上一个 token 的结束行推出来。
     *
     * @param array<int,mixed> $tokens
     * @return array<int,int>
     */
    protected function tokenLines(array $tokens): array
    {
        $lines = [];
        $line = 1;
        foreach ($tokens as $index => $token) {
            if (is_array($token)) {
                $line = (int)$token[2];
            }
            $lines[$index] = $line;
            if (is_array($token)) {
                $line = (int)$token[2] + substr_count((string)$token[1], "\n");
            }
        }
        return $lines;
    }

    /**
     * 扫描源码里的所有函数/方法声明。T_FUNCTION 同时覆盖具名函数、方法与闭包。
     *
     * @param array<int,mixed> $tokens
     * @param array<int,int> $lines
     * @return array<int,array{0:int,1:int,2:int}>
     */
    protected function functionDeclarations(array $tokens, array $lines): array
    {
        $ret = [];
        foreach ($tokens as $index => $token) {
            if (is_array($token) && $token[0] === T_FUNCTION) {
                $ret[] = $this->functionDeclaration($tokens, $lines, (int)$index);
            }
        }
        return $ret;
    }

    /**
     * 一处声明的 [头部起始行, 头部结束行, 函数体结束行]。
     *
     * 头部 = function 行到函数体的 "{" 行(没有函数体的声明取 ";" 行)；
     * 函数体结束行 = 与 "{" 配对的 "}" 行。括号计数会跳过参数表、闭包的 use (...)、返回类型里的括号；
     * 双引号字符串里的 "{$x}" / "${x}" 也参与配对计数，不会让函数体提前结束。
     *
     * @param array<int,mixed> $tokens
     * @param array<int,int> $lines
     * @return array{0:int,1:int,2:int}
     */
    protected function functionDeclaration(array $tokens, array $lines, int $index): array
    {
        $count = count($tokens);
        $head_start = $lines[$index];
        $depth = 0;
        $body = 0;
        for ($i = $index + 1; $i < $count; $i++) {
            $token = $tokens[$i];
            if (is_array($token)) {
                if ($token[0] === T_CURLY_OPEN || $token[0] === T_DOLLAR_OPEN_CURLY_BRACES) {
                    $depth++; // @codeCoverageIgnore
                }
                continue;
            }
            if ($token === '(') {
                $depth++;
                continue;
            }
            if ($token === ')') {
                $depth--;
                continue;
            }
            if ($depth > 0) {
                continue;
            }
            if ($token === '{') {
                $body = $i;
                break;
            }
            if ($token === ';') {
                return [$head_start, $lines[$i], $lines[$i]];
            }
        }
        if ($body === 0) {
            return [$head_start, $head_start, $head_start]; // @codeCoverageIgnore
        }
        $head_end = $lines[$body];
        $depth = 1;
        for ($i = $body + 1; $i < $count; $i++) {
            $token = $tokens[$i];
            if (is_array($token)) {
                if ($token[0] === T_CURLY_OPEN || $token[0] === T_DOLLAR_OPEN_CURLY_BRACES) {
                    $depth++;
                }
                continue;
            }
            if ($token === '{') {
                $depth++;
                continue;
            }
            if ($token !== '}') {
                continue;
            }
            $depth--;
            if ($depth === 0) {
                return [$head_start, $head_end, $lines[$i]];
            }
        }
        return [$head_start, $head_end, $head_end]; // @codeCoverageIgnore
    }

    /**
     * 一个声明里的签名行(见 signatureLines() 的规则)。isset() 对 null 为 false，dead code 不算可执行行。
     *
     * @param array<int, array<string,string>|null> $lines
     * @param array{0:int,1:int,2:int} $declaration
     * @return array<int,int>
     */
    protected function signatureLinesIn(array $lines, array $declaration): array
    {
        [$head_start, $head_end, $body_end] = $declaration;
        $sig = [];
        $executed = false;
        for ($line = $head_start; $line <= $body_end; $line++) {
            if (!isset($lines[$line])) {
                continue;   // 不存在，或者 dead code
            }
            if ($lines[$line] !== []) {
                $executed = true;
                continue;
            }
            if ($line > $head_end) {
                // 函数体里有没执行的行：这个声明不是"只差签名行"，不能标签名行
                return [];
            }
            $sig[] = $line;
        }
        return $executed ? $sig : [];
    }

    /**
     * 路径基准：[0] = files[].path 的相对基准, [1] = root 字段, [2] = 源码根(带结尾斜杠)
     *
     * @return array{0:string, 1:string, 2:string}
     */
    protected function reportBases(string $path_src, string $path_root): array
    {
        $src_base = $this->slashEndedPath($path_src);
        $root_base = $this->slashEndedPath($path_root);
        if ($root_base !== '' && strpos($src_base, $root_base) === 0) {
            return [$root_base, substr($src_base, strlen($root_base)), $src_base];
        }
        return [$src_base, '.', $src_base];
    }

    protected function slashEndedPath(string $path): string
    {
        $path = $this->normalizePath($path);
        if ($path !== '' && substr($path, -1) !== '/') {
            return $path . '/';
        }
        return $path;
    }

    protected function normalizePath(string $path): string
    {
        return str_replace('\\', '/', $path);
    }

    protected function relativePath(string $file, string $base): string
    {
        $path = $this->normalizePath($file);
        if ($base !== '' && strpos($path, $base) === 0) {
            return substr($path, strlen($base));
        }
        return $path; // @codeCoverageIgnore
    }

    protected function dirOf(string $path): string
    {
        $dir = dirname($path);
        return $dir === '.' ? '.' : str_replace('\\', '/', $dir);
    }

    protected function appOf(string $path): string
    {
        $pos = strpos($path, '/');
        return $pos === false ? '' : substr($path, 0, $pos);
    }

    protected function percent(int $covered, int $total): float
    {
        return $total > 0 ? round($covered / $total * 100, 2) : 0.0;
    }

    /**
     * @param array<int, mixed> $files
     * @return array<string, bool>
     */
    protected function indexFileKeys(array $files): array
    {
        $ret = [];
        foreach ($files as $file) {
            $ret[$this->normalizePath((string)$file)] = true;
        }
        return $ret;
    }

    /**
     * 归一化 lineCoverage：路径分隔符统一成 '/'，并合并同一文件因分隔符不同出现的重复键
     *
     * @param array<string, array<int, array<string,string>|null>> $lineCoverage
     * @return array<string, array<int, array<string,string>|null>>
     */
    protected function indexLineCoverage(array $lineCoverage): array
    {
        $index = [];
        foreach ($lineCoverage as $file => $lines) {
            $key = $this->normalizePath($file);
            foreach ($lines as $line => $tests) {
                if (!isset($index[$key][$line]) || $this->lineDataRank($tests) > $this->lineDataRank($index[$key][$line])) {
                    $index[$key][$line] = $tests;
                }
            }
        }
        return $index;
    }

    /**
     * 行数据优先序：已执行(3) > 可执行未执行(2) > dead code(1)
     * 重复键(路径分隔符不一致)合并时，宁可保守地保留"可能是漏测"的行
     *
     * @param array<string,string>|null $tests
     */
    protected function lineDataRank(?array $tests): int
    {
        if ($tests === null) {
            return 1;
        }
        return $tests === [] ? 2 : 3;
    }

    protected function packageVersion(): string
    {
        if (class_exists(\Composer\InstalledVersions::class)) {
            try {
                return (string)\Composer\InstalledVersions::getPrettyVersion('dvaknheo/duckcoverage');
            } catch (\Throwable $ex) { // @codeCoverageIgnore
                return 'unknown'; // @codeCoverageIgnore
            }
        }
        return 'unknown'; // @codeCoverageIgnore
    }

    protected function driverName(string $driver_class): string
    {
        $extension = '';
        if (stripos($driver_class, 'Pcov') !== false) {
            $extension = 'pcov';
        } elseif (stripos($driver_class, 'Xdebug') !== false) {
            $extension = 'xdebug';
        }
        if ($extension === '') {
            return $driver_class !== '' ? $driver_class : 'unknown'; // @codeCoverageIgnore
        }
        $version = (string)phpversion($extension);
        return $version !== '' ? $extension . '-' . $version : $extension; // @codeCoverageIgnore
    }
}
