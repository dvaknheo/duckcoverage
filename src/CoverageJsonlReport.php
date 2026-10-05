<?php declare(strict_types=1);
/**
 * DuckPhp
 * From this time, you never be alone~
 */

namespace DuckCoverage;

use DuckPhp\Core\SingletonExTrait;

/**
 * 把 CoverageJsonReport 渲染出的数组改写成 JSONL(一行一个 JSON 对象)。
 *
 * 与 CoverageJsonReport 的分工：
 * - CoverageJsonReport 接触 php-code-coverage，产出"同一份报告"的数组；
 * - 本类只做纯变换(数组 -> 文本)，不再碰覆盖率数据，因此可以脱离覆盖扩展单独测试。
 *
 * 规格见 coverage-report-jsonl-proposal.md，硬性规则：
 * - 一行一个 JSON 对象，行终止符只用 LF，不写 CRLF、不写 BOM；
 * - 记录顺序固定：meta(唯一的首行) -> group -> dir -> file/file_func/file_lines -> ignored -> error -> total(唯一的末行，complete:true)；
 * - 空数据也输出 meta + total(files:0)，不会产出空文件；
 * - 只输出计数，不输出百分比；计数为整数，行号 1 起；路径是相对根的完整路径；
 * - total.records 是各类记录的实际条数(含 file_lines 的分片数，也含 meta/total 自身)，
 *   因此 records 各项之和等于文件行数，消费方可以直接用它判断有没有被截断。
 *
 * 本类不理解"组"：$report 是全组合并后的数据，所以 file/dir/file_func/file_lines 记录不带 group 字段
 * (规格 §6.3 允许)，executed 是各组的并集，**不是**各组相加。
 */
class CoverageJsonlReport
{
    use SingletonExTrait;

    /** schema 标识，不兼容变更时改结尾数字 */
    protected const SCHEMA = 'duckcoverage-report-jsonl/1';

    /** detail 的缺省值与合法值(非法值按缺省值处理) */
    protected const DETAIL_DEFAULT = 'uncovered';
    protected const DETAILS = ['none', 'uncovered', 'full'];

    /**
     * 单个 file_lines 记录的最大字节数，超过就按 §5.3 按行号连续分片。
     * 测试里用子类把它改小，用来验证分片逻辑。
     */
    protected const MAX_LINE_BYTES = 1000000;

    /**
     * 渲染整份 JSONL 文本(每行一个 JSON 对象，结尾一定带 "\n")。
     *
     * $report 是 CoverageJsonReport::_()->render() 的输出数组；本方法不再接触覆盖率数据。
     *
     * $context 支持的键：
     * - detail:      'none' | 'uncovered' | 'full'，缺省 'uncovered'，非法值按 'uncovered'
     * - timestamp:   bool，false 时省略 meta.created(对应 --jsonl-no-timestamp)，缺省 true
     * - group_dumps: array<string,int>，每组 dump 数(group 记录用)
     * - errors:      array<int,array{group?:string,msg:string,fatal:bool}>，按传入顺序输出 error 记录
     *
     * @param array<string,mixed> $report
     * @param array<string,mixed> $context
     */
    public function render(array $report, array $context): string
    {
        $detail = $this->detailOf($context);
        $groups = $this->stringList($report['groups'] ?? []);
        $group_dumps = (array)($context['group_dumps'] ?? []);

        $out = [];
        $records = ['meta' => 0, 'group' => 0, 'dir' => 0, 'file' => 0, 'file_func' => 0, 'file_lines' => 0, 'ignored' => 0, 'error' => 0, 'total' => 0];

        $out[] = $this->encode($this->metaRecord($report, $groups, $detail, $this->withTimestamp($context)));
        $records['meta']++;

        foreach ($groups as $name) {
            $out[] = $this->encode(['t' => 'group', 'name' => $name, 'dumps' => (int)($group_dumps[$name] ?? 0)]);
            $records['group']++;
        }

        foreach ($this->sortedByPath((array)($report['directories'] ?? [])) as $directory) {
            $out[] = $this->encode($this->dirRecord($directory));
            $records['dir']++;
        }

        $files = $this->sortedByPath((array)($report['files'] ?? []));
        foreach ($files as $file) {
            $path = (string)($file['path'] ?? '');
            $out[] = $this->encode($this->fileRecord($file, $detail));
            $records['file']++;
            foreach ($this->sortedFunctions($file) as $unit) {
                $out[] = $this->encode($this->fileFuncRecord($path, $unit));
                $records['file_func']++;
            }
            if ($detail === 'full') {
                $chunks = $this->lineMapChunks($path, $this->lineMapOf($file));
                $count = count($chunks);
                foreach ($chunks as $index => $chunk) {
                    $out[] = $this->encode($this->fileLinesRecord($path, $chunk, $index + 1, $count));
                    $records['file_lines']++;
                }
            }
        }

        foreach ($this->sortedByPath((array)($report['ignored_files'] ?? [])) as $ignored) {
            $out[] = $this->encode($this->ignoredRecord($ignored));
            $records['ignored']++;
        }

        foreach ((array)($context['errors'] ?? []) as $error) {
            $out[] = $this->encode($this->errorRecord((array)$error));
            $records['error']++;
        }

        $records['total'] = 1;
        $out[] = $this->encode($this->totalRecord($report, $records, count($files)));

        return implode("\n", $out) . "\n";
    }

    /**
     * 写出 JSONL 文件并返回写出的路径。
     *
     * 目标目录不存在时自动创建(规格 §9 允许二选一，这里选自动创建)；
     * 创建目录或写文件失败时抛 \RuntimeException，消息里带路径，由调用方负责非零退出。
     * 写入内容一定是 LF 结尾、不含 CRLF、不含 BOM。
     *
     * @return string 写出的文件路径
     */
    public function write(string $jsonl, string $path): string
    {
        $this->makeDirectory($path);
        if (@file_put_contents($path, $this->normalizeContent($jsonl)) === false) {
            throw new \RuntimeException('DuckCoverage: unable to write the JSONL report: ' . $path);
        }
        return $path;
    }

    /**
     * 写文件前建目录：目标目录已存在(或路径里没有目录)就直接返回
     */
    protected function makeDirectory(string $path): void
    {
        $dir = dirname($path);
        if ($dir === '' || $dir === '.' || is_dir($dir)) {
            return;
        }
        if (!@mkdir($dir, 0777, true) && !is_dir($dir)) {
            throw new \RuntimeException('DuckCoverage: unable to create the JSONL report directory: ' . $dir);
        }
    }

    /**
     * 归一化要写出的文本：统一成 LF，并保证结尾有且只有一个换行
     */
    protected function normalizeContent(string $jsonl): string
    {
        return rtrim(str_replace(["\r\n", "\r"], "\n", $jsonl), "\n") . "\n";
    }

    /**
     * 一行 JSON(不含行终止符)。JSON_UNESCAPED_SLASHES 让路径保持原样，便于 grep/awk 直接看。
     *
     * @param array<string,mixed> $record
     */
    protected function encode(array $record): string
    {
        $json = json_encode($record, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($json === false) {
            throw new \RuntimeException('DuckCoverage: unable to encode a JSONL record'); // @codeCoverageIgnore
        }
        return $json;
    }

    /**
     * meta(唯一的第一行)：版本、运行环境、本次合并的组与 detail 模式
     *
     * @param array<string,mixed> $report
     * @param array<int,string> $groups
     * @return array<string,mixed>
     */
    protected function metaRecord(array $report, array $groups, string $detail, bool $with_timestamp): array
    {
        $generator = (array)($report['generator'] ?? []);
        $record = [
            't' => 'meta',
            'schema' => static::SCHEMA,
            'generator' => [
                'name' => (string)($generator['name'] ?? 'duckcoverage'),
                'version' => (string)($generator['version'] ?? 'unknown'),
            ],
            'php' => (string)($generator['php'] ?? PHP_VERSION),
            'driver' => (string)($generator['coverage_driver'] ?? 'unknown'),
            'root' => (string)($report['root'] ?? ''),
            'groups' => $groups,
            'dumps' => (int)($report['dumps_merged'] ?? 0),
            'detail' => $detail,
        ];
        if ($with_timestamp) {
            // 时间戳只允许出现在这一行(规格 §6.2)；--jsonl-no-timestamp 时整个字段省略
            $record['created'] = (string)($report['generated_at'] ?? '');
        }
        return $record;
    }

    /**
     * dir：目录级汇总。lines 只有 executable/executed，没有百分比；funcs 我们没有数据就不输出。
     *
     * @param array<string,mixed> $directory
     * @return array<string,mixed>
     */
    protected function dirRecord(array $directory): array
    {
        $lines = (array)($directory['lines'] ?? []);
        return [
            't' => 'dir',
            'path' => (string)($directory['path'] ?? ''),
            'files' => (int)($directory['files'] ?? 0),
            'lines' => [
                'executable' => (int)($lines['executable'] ?? 0),
                'executed' => (int)($lines['executed'] ?? 0),
            ],
        ];
    }

    /**
     * file：文件级覆盖。字段顺序照规格 §4.4，但不带 group(全组合并后的数据)；
     * 被排除的文件不会走到这里(它们只出现在 ignored 记录里)，所以 ignored 恒为 false。
     *
     * @param array<string,mixed> $file
     * @return array<string,mixed>
     */
    protected function fileRecord(array $file, string $detail): array
    {
        $lines = (array)($file['lines'] ?? []);
        $functions = (array)($file['functions'] ?? []);
        $record = [
            't' => 'file',
            'path' => (string)($file['path'] ?? ''),
            'dir' => (string)($file['dir'] ?? ''),
            'app' => (string)($file['app'] ?? ''),
            'sha1' => (string)($file['sha1'] ?? ''),
            'loaded' => (bool)($file['loaded'] ?? false),
            'ignored' => false,
            'lines' => [
                'executable' => (int)($lines['executable'] ?? 0),
                'executed' => (int)($lines['executed'] ?? 0),
            ],
            'funcs' => [
                'total' => (int)($functions['total'] ?? 0),
                'covered' => (int)($functions['covered'] ?? 0),
            ],
        ];
        if ($detail !== 'none') {
            $record['unc'] = $this->intList($file['uncovered_lines'] ?? []);
        }
        $record['sig'] = $this->intList($file['sig'] ?? []);
        return $record;
    }

    /**
     * file_func：方法级定位。name 统一用 Class::method，函数用函数名。
     *
     * @param array<string,mixed> $unit
     * @return array<string,mixed>
     */
    protected function fileFuncRecord(string $path, array $unit): array
    {
        return [
            't' => 'file_func',
            'path' => $path,
            'name' => $this->unitName($unit),
            'start' => (int)($unit['start'] ?? 0),
            'end' => (int)($unit['end'] ?? 0),
            'lines' => [
                'executable' => (int)($unit['executable'] ?? 0),
                'executed' => (int)($unit['executed'] ?? 0),
            ],
        ];
    }

    /**
     * file_lines：整份行号 map(§4.6)。map 为空时也必须输出 {}，所以这里用 stdClass 兜底。
     *
     * @param array<int,int> $map
     * @return array<string,mixed>
     */
    protected function fileLinesRecord(string $path, array $map, int $chunk, int $chunks): array
    {
        return [
            't' => 'file_lines',
            'path' => $path,
            'chunk' => $chunk,
            'chunks' => $chunks,
            'map' => $this->mapObject($map),
        ];
    }

    /**
     * ignored：被排除的文件(整文件注解忽略，或没有可执行行)
     *
     * @param array<string,mixed> $ignored
     * @return array<string,mixed>
     */
    protected function ignoredRecord(array $ignored): array
    {
        return [
            't' => 'ignored',
            'path' => (string)($ignored['path'] ?? ''),
            'reason' => (string)($ignored['reason'] ?? ''),
        ];
    }

    /**
     * error：跳过的组/非致命异常。group 是可选字段，没有就不输出。
     *
     * @param array<string,mixed> $error
     * @return array<string,mixed>
     */
    protected function errorRecord(array $error): array
    {
        $record = ['t' => 'error'];
        if (isset($error['group'])) {
            $record['group'] = (string)$error['group'];
        }
        $record['msg'] = (string)($error['msg'] ?? '');
        $record['fatal'] = (bool)($error['fatal'] ?? false);
        return $record;
    }

    /**
     * total：汇总 + 完整性哨兵(永远最后一行，complete:true)。
     * records 的字段顺序照规格 §4.9，meta/total 两个计数补在末尾，使 records 之和等于文件行数。
     *
     * @param array<string,mixed> $report
     * @param array<string,int> $records
     * @return array<string,mixed>
     */
    protected function totalRecord(array $report, array $records, int $files): array
    {
        $totals = (array)($report['totals'] ?? []);
        $lines = (array)($totals['lines'] ?? []);
        $functions = (array)($totals['functions'] ?? []);
        return [
            't' => 'total',
            'files' => $files,
            'lines' => [
                'executable' => (int)($lines['executable'] ?? 0),
                'executed' => (int)($lines['executed'] ?? 0),
            ],
            'funcs' => [
                'total' => (int)($functions['total'] ?? 0),
                'covered' => (int)($functions['covered'] ?? 0),
            ],
            'records' => [
                'file' => $records['file'],
                'file_func' => $records['file_func'],
                'file_lines' => $records['file_lines'],
                'dir' => $records['dir'],
                'group' => $records['group'],
                'ignored' => $records['ignored'],
                'error' => $records['error'],
                'meta' => $records['meta'],
                'total' => $records['total'],
            ],
            'complete' => true,
        ];
    }

    /**
     * 按 §5.3 把整份行号 map 切成若干片：单条 file_lines 记录超过 MAX_LINE_BYTES 就按行号连续切分。
     *
     * 逐项累加 JSON 长度判断，避免每加一项都重新编码整份 map(O(n^2))。
     * 固定开销用最宽的 chunk/chunks(9 位数)估算，保证真实输出的每一片都不超过上限。
     *
     * @param array<int,int> $map 行号 => 1/-1/-2，已按行号升序
     * @return array<int,array<int,int>> 每片一个 map，顺序即行号顺序
     */
    protected function lineMapChunks(string $path, array $map): array
    {
        $overhead = strlen($this->encode($this->fileLinesRecord($path, [], 999999999, 999999999))) - 2;
        $chunks = [];
        $current = [];
        $length = 0;
        $current_count = 0;
        foreach ($map as $line => $value) {
            // 一项的长度： "行号":值
            $entry = strlen((string)json_encode((string)$line)) + 1 + strlen((string)json_encode($value));
            // 加上这一项之后的 map 长度 = 2(花括号) + 各"项+逗号" - 最后一个逗号
            if ($current_count > 0 && $overhead + 2 + $length + $entry + $current_count > static::MAX_LINE_BYTES) {
                $chunks[] = $current;
                $current = [];
                $length = 0;
                $current_count = 0;
            }
            $length += $entry;
            $current[$line] = $value;
            $current_count++;
        }
        if ($current_count > 0) {
            $chunks[] = $current;
        } else {
            // 没有可执行行时也要给一个空 map(输出 {}，保证字段一直在)
            $chunks[] = [];
        }
        return $chunks;
    }

    /**
     * @param array<string,mixed> $file
     * @return array<int,int>
     */
    protected function lineMapOf(array $file): array
    {
        $map = [];
        foreach ((array)($file['line_map'] ?? []) as $line => $value) {
            $map[(int)$line] = (int)$value;
        }
        ksort($map, SORT_NUMERIC);
        return $map;
    }

    /**
     * @param array<string,mixed> $unit
     */
    protected function unitName(array $unit): string
    {
        $class = (string)($unit['class'] ?? '');
        $name = (string)($unit['name'] ?? '');
        return $class !== '' ? $class . '::' . $name : $name;
    }

    /**
     * detail 只认规格里的三个值，其余(含缺省)按 uncovered
     *
     * @param array<string,mixed> $context
     */
    protected function detailOf(array $context): string
    {
        $detail = isset($context['detail']) ? (string)$context['detail'] : static::DETAIL_DEFAULT;
        return in_array($detail, static::DETAILS, true) ? $detail : static::DETAIL_DEFAULT;
    }

    /**
     * 是否输出 meta.created：只有显式 false 才省略(对应 --jsonl-no-timestamp)
     *
     * @param array<string,mixed> $context
     */
    protected function withTimestamp(array $context): bool
    {
        return !array_key_exists('timestamp', $context) || (bool)$context['timestamp'];
    }

    /**
     * map 为空时输出 {} 而不是 []，保证字段一直在且类型稳定
     *
     * @param array<int,int> $map
     * @return array<int,int>|object
     */
    protected function mapObject(array $map)
    {
        return $map === [] ? new \stdClass() : $map;
    }

    /**
     * 行号数组：整数 + 升序，保证输出确定性
     *
     * @param mixed $values
     * @return array<int,int>
     */
    protected function intList($values): array
    {
        $ret = [];
        foreach ((array)$values as $value) {
            $ret[] = (int)$value;
        }
        return $ret;
    }

    /**
     * @param mixed $values
     * @return array<int,string>
     */
    protected function stringList($values): array
    {
        $ret = [];
        foreach ((array)$values as $value) {
            $ret[] = (string)$value;
        }
        return $ret;
    }

    /**
     * 记录一律按 path 升序(规格 §2.8)，保证同样的数据产出逐字节一样的文件
     *
     * @param mixed $items
     * @return array<int,array<string,mixed>>
     */
    protected function sortedByPath($items): array
    {
        $ret = [];
        foreach ((array)$items as $item) {
            $ret[] = (array)$item;
        }
        usort($ret, static function (array $a, array $b): int {
            return strcmp((string)($a['path'] ?? ''), (string)($b['path'] ?? ''));
        });
        return $ret;
    }

    /**
     * 一个文件的 file_func 按 start 升序(规格 §6.1)；start 相同时按名字，保证确定性
     *
     * @param array<string,mixed> $file
     * @return array<int,array<string,mixed>>
     */
    protected function sortedFunctions(array $file): array
    {
        $ret = [];
        foreach ((array)($file['function_items'] ?? []) as $item) {
            $ret[] = (array)$item;
        }
        usort($ret, static function (array $a, array $b): int {
            $order = ((int)($a['start'] ?? 0)) <=> ((int)($b['start'] ?? 0));
            return $order !== 0 ? $order : strcmp((string)($a['name'] ?? ''), (string)($b['name'] ?? ''));
        });
        return $ret;
    }
}
