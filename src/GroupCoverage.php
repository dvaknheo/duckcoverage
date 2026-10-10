<?php declare(strict_types=1);
/**
 * DuckPhp
 * From this time, you never be alone~
 */

namespace DuckCoverage;

use SebastianBergmann\CodeCoverage\CodeCoverage;
use SebastianBergmann\CodeCoverage\Driver\Selector as CodeCoverageSelector;
use SebastianBergmann\CodeCoverage\Filter as CodeCoverageFilter;
use SebastianBergmann\CodeCoverage\Report\Html\Facade as ReportOfHtmlOfFacade;
use SebastianBergmann\CodeCoverage\Report\PHP as ReportOfPHP;

/**
 * 封装 php-code-coverage 的全部直接依赖(创建/采集/dump/合并/报告)。
 * 组件风格:_() 单例 + init() 初始化。
 * 按组(group)驱动覆盖率工作流:begin() 采集 -> end() 停止并 dump 到组目录 -> createReport()/showAllReport() 按组合并出报告。
 * 对外只暴露合并后的方法,避免调用方零散接触底层 API。
 *
 * @phpstan-consistent-constructor  _() 里会用 new static() 实例化子类，
 * 因此要求子类与本类一样可以无参构造(否则请用 __SINGLETONEX_REPALACER 替换实例)。
 */
class GroupCoverage
{
    public $options = [
        // 排除的目录/文件（由 DuckCoverage::exclude() / option duckcoverage_exclude 灌进来）
        'exclude' => [],
    ];
    public $is_inited = false;

    protected $coverage;
    protected $current_path_dump = '';
    protected $current_name = '';
    protected $current_group = '';

    protected $is_end = false;

    protected $driver_class = '';

    /**
     * 排除的目录/文件（原始写法，匹配时才展开成绝对路径）
     *
     * @var array<int, string>
     */
    protected $exclude_paths = [];

    protected static $_instances = [];

    //embed
    /**
     * @return static
     */
    public static function _($object = null)
    {
        if (defined('__SINGLETONEX_REPALACER')) {
            $callback = __SINGLETONEX_REPALACER;
            return ($callback)(static::class, $object);
        }
        if ($object) {
            self::$_instances[static::class] = $object;
            return $object;
        }
        $me = self::$_instances[static::class] ?? null;
        if (null === $me) {
            $me = new static();
            self::$_instances[static::class] = $me;
        }
        return $me;
    }
    public function __construct()
    {
    }

    /**
     *
     * @param array<string,mixed> $options
     * @param ?object $context
     * @return static
     */
    public function init(array $options, ?object $context = null)
    {
        //$this->options = array_intersect_key(array_replace_recursive($this->options, $options) ?? [], $this->options);
        $this->coverage = $this->createCoverage();
        $this->is_inited = true;
        return $this;
    }
    public function getCoverage()
    {
        return $this->coverage;
    }
    /**
     * 开始采集：懒创建 coverage + 收录源码目录(options['path_src']) + start（内部防重入）。
     * 测试名与组名由参数传入（组名为空时回落到 options['group']），供 doEnd() 无参 dump 使用。
     */
    public function doBegin(string $name, string $group, string $path_src, string $path_dump): void
    {
        $this->is_end = false;
        $this->pre_begin($name, $group, $path_src, $path_dump);    // @codeCoverageIgnore
        if (class_exists(\LibCoverage\LibCoverage::class)) {
            \LibCoverage\LibCoverage::_()->doPause();   // @codeCoverageIgnore
        }
        $this->coverage->start($name);      // @codeCoverageIgnore
    }
    protected function pre_begin(string $name, string $group, string $path_src, string $path_dump): void
    {
        if (!$this->coverage) {
            $this->coverage = $this->createCoverage(); // @codeCoverageIgnore
        }
        $this->current_path_dump = $path_dump;
        $this->current_name = $name;
        $this->current_group = $group;

        $this->includePath($this->coverage, $path_src);
    }
    /**
     * 结束采集并 dump：stop + Report\PHP 序列化到 {path_dump}/{group}/{md5(name)}.php。
     * 路径/组/名来自 doBegin() 捕获的状态与 options['path_dump']。
     */
    public function doEnd(): void
    {
        if ($this->is_end) {
            return;
        }
        $this->coverage->stop();        // @codeCoverageIgnore
        if (class_exists(\LibCoverage\LibCoverage::class)) { // @codeCoverageIgnore
            \LibCoverage\LibCoverage::_()->doResume();   // @codeCoverageIgnore
        }
        $this->post_end();
    }
    protected function post_end()
    {
        $path_dump = $this->current_path_dump. $this->current_group;
        @mkdir($path_dump, 0777, true);
        @chmod($path_dump, 0777);
        $file = (string)  $path_dump. DIRECTORY_SEPARATOR . $this->make_filename($this->current_name);
        (new ReportOfPHP)->process($this->coverage, $file);
        $ext = "\n// ".$this->current_name ."\n";
        file_put_contents($file, $ext, FILE_APPEND);
        $perms = fileperms($file);
        chmod($file, $perms | 0002);

        $this->is_end = true;
    }
    protected function make_filename($name)
    {
        return (new \DateTime())->format('Y-m-d_H_i_s_v').'-'.\sha1($name).'.php';
    }
    ////////////////////////////////////////////////////////////////////////////
    /**
     * 生成报告：新建 coverage 收录源码 -> 按组合并 dump -> 补全部分覆盖文件 -> 渲染 HTML、输出 JSON 并统计。
     *
     * $path_root 是工程根，用来把 JSON 里的路径写成"相对工程根"的形式；为空或源码不在其下时，
     * JSON 路径相对源码根，root 记为 '.'。
     *
     * $jsonl 非空时额外输出 JSONL 报告（键：path/stdout/detail/timestamp，见 CoverageJsonlReport）：
     * path 为空表示不写文件，stdout=true 表示由调用方把文本写到 stdout。
     *
     * @param array<string>  $groups
     * @param array<string,mixed> $jsonl
     * @return array{lines_tested:int, lines_total:int, lines_percent:string, json_report:string, dumps_merged:int}
     */
    public function createReport(array $groups, string $path_src, string $path_dump, string $path_report, string $path_root = '', array $jsonl = []): array
    {
        $coverage = $this->createCoverage();
        $this->includePath($coverage, $path_src);
        $coverage->setTests([
          'T' => [
            'size' => 'unknown',
            'status' => -1,
          ],
        ]);
        $dumps_merged = 0;
        $group_dumps = [];
        $errors = [];
        foreach ($groups as $group) {
            $dir = $path_dump.$group;
            $merged = $this->mergeFromDir($coverage, $dir);
            $group_dumps[$group] = $merged;
            $dumps_merged += $merged;
            if ($merged === 0) {
                // 规格 §4.8：组没有 dump、或组目录不存在，都不要静默吞掉
                $errors[] = [
                    'group' => $group,
                    'msg' => is_dir($dir) ? "group skipped: no dump in {$group}" : "group skipped: dump dir not found: {$group}",
                    'fatal' => false,
                ];
            }
        }
        // 合并后的数据里可能带着"现在被排除"的文件（排除是后配的，或 dump 来自别的配置）。
        // 报告的三个出口(HTML / report.json / report.jsonl)必须同一口径：这里直接把它们从数据里剔除，
        // 而不是只靠 filter —— php-code-coverage 的 HTML 报告是拿合并后的数据出节点的，filter 拦不住旧数据。
        $this->pruneExcludedFiles($coverage, $path_src);

        // 补坑之前先记下"驱动报告过的文件"＝运行时真的被加载过(JSON 的 loaded 字段)。
        // 必须取原始数据：getData() 默认会把"只在 filter 里、从未加载"的文件也补进来。
        $loaded_files = array_keys($coverage->getData(true)->lineCoverage());

        // 出报告前先清空输出目录：HTML 报告只写不删，旧文件残留会让人误以为"排除没生效"
        $this->cleanReportDir($path_report, $path_dump);

        // 补全部分覆盖文件：未执行的可执行行加入 lineCoverage（空数组），
        // 否则报告只统计已执行行，部分覆盖文件会错误显示为 100%
        $sig_lines = $this->fillPartialCoveredFiles($coverage);
        $stats = $this->renderReport($coverage, $path_report);
        $report = CoverageJsonReport::_()->render($coverage, [
            'groups' => $groups,
            'dumps_merged' => $dumps_merged,
            'path_src' => $path_src,
            'path_root' => $path_root,
            'loaded_files' => $loaded_files,
            'files' => $this->sourceFiles($path_src),
            'driver_class' => $this->driver_class,
            'sig_lines' => $sig_lines,
        ]);
        $stats['json_report'] = CoverageJsonReport::_()->write($report, $path_report);
        $stats['dumps_merged'] = $dumps_merged;
        if ($jsonl) {
            // JSONL 是同一份数据的行导向输出：只做变换，不再碰 php-code-coverage
            $jsonl_report = CoverageJsonlReport::_();
            $text = $jsonl_report->render($report, $jsonl + ['group_dumps' => $group_dumps, 'errors' => $errors]);
            if (!empty($jsonl['path'])) {
                $stats['jsonl_report'] = $jsonl_report->write($text, (string)$jsonl['path']);
            }
            if (!empty($jsonl['stdout'])) {
                $stats['jsonl_text'] = $text;
            }
        }
        return $stats;
    }
    /**
     * 创建 CodeCoverage。php-code-coverage 9.x 起必须显式传入 Driver + Filter
     */
    protected function createCoverage(): CodeCoverage
    {
        $filter = new CodeCoverageFilter();
        $driver = (new CodeCoverageSelector())->forLineCoverage($filter);
        $this->driver_class = get_class($driver);
        return new CodeCoverage($driver, $filter);
    }
    /**
     * 把目录或文件加入 filter。9.x 用 includeDirectory;11.x 已移除,需展开目录(只收录 .php)
     */
    protected function includePath(CodeCoverage $coverage, string $path): void
    {
        $coverage->filter()->includeFiles($this->sourceFiles($path));
    }
    /**
     * 源码目录下所有 .php 文件(绝对路径)。排序后再用，保证 HTML/JSON 报告都是确定性的。
     *
     * @return array<int, string>
     */
    protected function sourceFiles(string $path): array
    {
        $directory = new \RecursiveDirectoryIterator($path, \FilesystemIterator::CURRENT_AS_PATHNAME | \FilesystemIterator::SKIP_DOTS);
        $iterator = new \RecursiveIteratorIterator($directory);
        $files = [];
        foreach ($iterator as $file) {
            if (is_file($file) && substr($file, -4) === '.php') {
                if ($this->isExcludedPath($file, $path)) {
                    continue;
                }
                $files[] = $file;
            }
        }
        sort($files);
        return $files;
    }
    /**
     * 设置要排除的目录/文件（覆盖式）。匹配规则见 isExcludedPath()。
     *
     * @param array<int, string> $paths
     */
    public function setExcludePaths(array $paths): void
    {
        $this->exclude_paths = array_values(array_filter(array_map('strval', $paths), static function ($path) {
            return $path !== '';
        }));
        $this->options['exclude'] = $this->exclude_paths;
    }
    /**
     * 把目录下的 .php 逐个拉黑（Filter::excludeDirectory() 只对单层通配生效，不会递归）。
     */
    protected function blacklistDir(CodeCoverage $coverage, string $dir): void
    {
        if (!is_dir($dir)) {
            return;   // @codeCoverageIgnore
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            $file = (string)$file;
            if (is_file($file) && substr($file, -4) === '.php') {
                $coverage->filter()->excludeFile($file);
            }
        }
    }
    /**
     * 清空报告输出目录（HTML 报告只写不删，旧文件会一直留着）。
     *
     * 安全护栏：输出目录等于 dump 目录、或"包含" dump 目录时，一律不动手，免得误删采集数据。
     */
    protected function cleanReportDir(string $path_report, string $path_dump): void
    {
        $report = rtrim(str_replace('\\', '/', $path_report), '/');
        $dump = rtrim(str_replace('\\', '/', $path_dump), '/');
        if ($report === '' || $report === $dump || strpos($dump . '/', $report . '/') === 0) {
            return;   // @codeCoverageIgnore
        }
        if (!is_dir($report)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($report, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $file) {
            if ($file->isDir()) {
                @rmdir((string)$file);
            } else {
                @unlink((string)$file);
            }
        }
    }
    /**
     * 把被排除的文件从"合并后的覆盖数据"里剔掉。
     *
     * 采集侧靠 filter 就能不收录，但已存在的 dump 里可能已经带着这些文件（规则是后配的、
     * 或 dump 来自别的配置）；而 HTML 报告是直接拿合并数据出节点的，filter 管不到旧数据，
     * 所以要在渲染之前从数据里删掉，保证三个出口口径一致。
     */
    protected function pruneExcludedFiles(CodeCoverage $coverage, string $path_src): void
    {
        if (empty($this->exclude_paths)) {
            return;
        }
        // 先按"规则本身"把文件拉黑：HTML 报告的文件树来自 Filter，而 filter 里可能有
        // 从未被加载过的文件（lang/view 之类），它们根本不在 dump 里。
        // 注意 Filter::excludeDirectory() 不递归，所以这里自己走目录。
        foreach ($this->exclude_paths as $pattern) {
            foreach ($this->expandExcludePattern($pattern, $path_src) as $candidate) {
                if (strpbrk($candidate, '*?') !== false) {
                    continue;
                }
                if (is_dir($candidate)) {
                    $this->blacklistDir($coverage, $candidate);
                } elseif (is_file($candidate)) {
                    $coverage->filter()->excludeFile($candidate);
                }
            }
        }

        $data = $coverage->getData(true);
        $lines = $data->lineCoverage();
        $kept = [];
        foreach ($lines as $file => $file_lines) {
            if ($this->isExcludedPath($file, $path_src)) {
                // HTML 报告的文件列表来自 Filter::isFile()，所以除了从数据里删，还必须把文件拉黑
                $coverage->filter()->excludeFile($file);
                continue;
            }
            $kept[$file] = $file_lines;
        }
        if (count($kept) === count($lines)) {
            return;
        }
        $data->setLineCoverage($kept);
        $data->setFunctionCoverage(array_intersect_key($data->functionCoverage(), $kept));
    }
    /**
     * 该文件是否被排除命中。
     *
     * 一条排除项可以是：
     * - **相对源码目录**（`duckcoverage_path_src`）的路径，如 `Admin/config`、`System/Foo.php`；
     *   排除目录时**建议在结尾写 `/`**（`Admin/config/`），意思更明确；
     * - 绝对路径（目录或文件）；
     * - 含 `*` / `?` 的通配表达式（如 `Admin/*\/Generated`，按 fnmatch 匹配）。
     * 目录按"前缀 + /"匹配（其下所有文件都被排除），文件按全等匹配；比较前一律把 `\` 归一成 `/`。
     * 注意：相对写法**只**相对源码目录解析，不再看应用的 projectPath。
     */
    protected function isExcludedPath(string $file, string $path_src): bool
    {
        if (empty($this->exclude_paths)) {
            return false;
        }
        $file = str_replace('\\', '/', $file);
        foreach ($this->exclude_paths as $pattern) {
            foreach ($this->expandExcludePattern($pattern, $path_src) as $candidate) {
                if ($candidate === '') {
                    continue;
                }
                if (strpbrk($candidate, '*?') !== false) {
                    // 通配：整个路径匹配，或把它当目录匹配其下所有文件
                    if (fnmatch($candidate, $file) || fnmatch(rtrim($candidate, '/') . '/*', $file)) {
                        return true;
                    }
                    continue;
                }
                $candidate = rtrim($candidate, '/');
                if ($file === $candidate || strpos($file, $candidate . '/') === 0) {
                    return true;
                }
            }
        }
        return false;
    }
    /**
     * 把一条排除项展开成候选绝对路径：绝对路径按字面，相对路径一律相对源码目录
     * （`duckcoverage_path_src`）解析 —— 不依赖应用是否 init、在哪个 phase。
     *
     * @return array<int, string>
     */
    protected function expandExcludePattern(string $pattern, string $path_src): array
    {
        $pattern = trim(str_replace('\\', '/', $pattern));
        if ($pattern === '') {
            return [];
        }
        if ($pattern[0] === '/') {
            return [rtrim($pattern, '/')];
        }
        $src = rtrim(str_replace('\\', '/', $path_src), '/');
        $candidates = [$src . '/' . ltrim($pattern, '/')];
        if (strpbrk($pattern, '*?') !== false) {
            // 通配写法再按字面试一次：可以写成绝对路径通配，或跨目录的 * 形式
            $candidates[] = $pattern;
        }
        return $candidates;
    }
    /**
     * 合并一个组目录下的所有 dump
     *
     * @return int 合并的 dump 个数
     */
    protected function mergeFromDir(CodeCoverage $coverage, string $dir): int
    {
        if (!is_dir($dir)) {
            return 0; // @codeCoverageIgnore
        }
        $directory = new \RecursiveDirectoryIterator($dir, \FilesystemIterator::CURRENT_AS_PATHNAME | \FilesystemIterator::SKIP_DOTS);
        $iterator = new \RecursiveIteratorIterator($directory);
        $files = \iterator_to_array($iterator, false);
        $count = 0;
        foreach ($files as $file) {
            $t = include $file;
            $coverage->merge($t);
            $count++;
        }
        return $count;
    }
    /**
     * 补全部分覆盖文件：把 filter 内已有覆盖数据的文件的可执行行补进 lineCoverage（未执行的为空数组）。
     * php-code-coverage 9.x 只对"完全未覆盖"文件补未执行行（addUncoveredFilesFromFilter），
     * 部分覆盖文件若缺失未执行行，报告会把该文件错误统计为 100%。
     * 修复：使用 ParsingFileAnalyser(true, true) 来正确处理 @codeCoverageIgnore 注解
     *       对于被忽略的行，不填充空数组，让它们保持未填充状态
     */
    protected function fillPartialCoveredFiles(CodeCoverage $coverage): array
    {
        $analyser = new \SebastianBergmann\CodeCoverage\StaticAnalysis\ParsingFileAnalyser(true, true);
        $lineCoverage = $coverage->getData()->lineCoverage();
        $filter = $coverage->filter();

        foreach ($filter->files() as $file) {
            $executableLines = array_keys($analyser->executableLinesIn($file));
            $ignoredLines = $analyser->ignoredLinesFor($file);

            // 如果没有可执行行，跳过
            if (empty($executableLines)) {
                continue;
            }

            // 确保 lineCoverage 中有该文件的条目
            if (!isset($lineCoverage[$file])) {
                $lineCoverage[$file] = [];
            }

            // 只为非忽略的行填充空数组。
            // 这里用 array_key_exists 而不是 isset：值为 null 表示驱动判定为 dead code，
            // 不能覆盖成"可执行未执行"，否则 dead code 会被算进分母(文件永远到不了 100%)
            foreach ($executableLines as $line) {
                if (in_array($line, $ignoredLines, true)) {
                    continue;   // 跳过被忽略的行
                }
                if (!array_key_exists($line, $lineCoverage[$file])) {
                    $lineCoverage[$file][$line] = [];
                }
            }
        }

        // 补坑之后才算签名行（这时"函数体其余行是否都执行了"才判得准），
        // 然后把它们从 lineCoverage 里整个剔除：签名行被静态分析算可执行，
        // 但驱动永远不会把它标为执行，留着会让文件永远到不了 100%。
        // 剔除后 HTML / report.json / report.jsonl 三处口径一致。
        $sig_lines = [];
        foreach ($filter->files() as $file) {
            $lines = $lineCoverage[$file] ?? [];
            if (!$lines) {
                continue;
            }
            $sig = CoverageJsonReport::_()->signatureLines($file, $lines);
            if (!$sig) {
                continue;
            }
            foreach ($sig as $line) {
                unset($lineCoverage[$file][$line]);
            }
            $sig_lines[$file] = $sig;
        }

        $coverage->getData()->setLineCoverage($lineCoverage);
        return $sig_lines;
    }
    /**
     * 渲染 HTML 报告并返回行统计
     *
     * @return array{lines_tested:int, lines_total:int, lines_percent:string}
     */
    protected function renderReport(CodeCoverage $coverage, string $path_report): array
    {
        (new ReportOfHtmlOfFacade)->process($coverage, $path_report);
        $report = $coverage->getReport();
        $lines_tested = $report->numberOfExecutedLines();
        $lines_total = $report->numberOfExecutableLines();
        $lines_percent = $lines_total > 0 ? sprintf('%0.2f%%', $lines_tested / $lines_total * 100) : '0.00%';
        return [
            'lines_tested' => $lines_tested,
            'lines_total' => $lines_total,
            'lines_percent' => $lines_percent,
        ];
    }
}
