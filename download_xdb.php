<?php
/**
 * ip2region xdb 数据库文件下载脚本
 * 通过 --url 参数指定自定义下载地址
 *
 * 使用方法：
 *   命令行：
 *     php download_xdb.php v4                 下载 IPv4 数据库
 *     php download_xdb.php v6                 下载 IPv6 数据库
 *     php download_xdb.php all                下载全部数据库
 *     php download_xdb.php status             查看数据库状态
 *     php download_xdb.php v4 --url "https://your-cdn.com/ip2region_v4.xdb"
 *     php download_xdb.php all --url "https://your-cdn.com/{file}"
 *
 *   浏览器（需登录Typecho后台）：
 *     ?type=all&url=https://your-cdn.com/{file}
 *     ?type=status                            查看数据库状态
 *
 * @package CommentIpLocation
 */

// ========== 鉴权：浏览器访问需登录 Typecho 后台 ==========
$isCli = php_sapi_name() === 'cli';

if (!$isCli) {
    $typechoRoot = dirname(dirname(dirname(__DIR__)));
    $configFile = $typechoRoot . '/config.inc.php';

    if (file_exists($configFile)) {
        if (!defined('__TYPECHO_ROOT_DIR__')) {
            define('__TYPECHO_ROOT_DIR__', $typechoRoot);
        }
        require_once $configFile;

        \Widget\Init::alloc();
        $user = \Widget\User::alloc();
        if (!$user->hasLogin() || !$user->pass('administrator', true)) {
            http_response_code(403);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'error' => '权限不足，请先登录 Typecho 管理后台'
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
    } else {
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'error' => '无法定位 Typecho 配置文件，请使用命令行运行此脚本'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

// ========== 引入共享依赖 ==========
require_once __DIR__ . '/libs/HttpHelper.php';

use TypechoPlugin\CommentIpLocation\HttpHelper;

// 数据库文件信息
$dbFiles = [
    'v4' => [
        'file' => 'ip2region_v4.xdb',
        'desc' => 'IPv4 数据库（约 10.6 MB）',
    ],
    'v6' => [
        'file' => 'ip2region_v6.xdb',
        'desc' => 'IPv6 数据库（约 35.5 MB）',
    ]
];

$dataDir = __DIR__ . '/data';

if (!is_dir($dataDir)) {
    @mkdir($dataDir, 0755, true);
}

// ========== 参数解析 ==========
$customUrl = '';
$type = 'all';

if ($isCli) {
    $positionalArgs = [];
    $argc = $GLOBALS['argc'] ?? 0;
    $argv = $GLOBALS['argv'] ?? [];

    for ($i = 1; $i < $argc; $i++) {
        if (strpos($argv[$i], '--') !== 0) {
            $positionalArgs[] = strtolower($argv[$i]);
            break;
        }
    }
    $type = $positionalArgs[0] ?? 'all';

    $optind = 0;
    $opts = getopt('', ['url:'], $optind);
    if (isset($opts['url'])) {
        $customUrl = $opts['url'];
    }
} else {
    $type = isset($_GET['type']) ? strtolower($_GET['type']) : 'status';
    $customUrl = isset($_GET['url']) ? $_GET['url'] : '';
}

// ========== 状态查询 ==========
if ($type === 'status') {
    $status = [];
    foreach ($dbFiles as $key => $info) {
        $filePath = $dataDir . '/' . $info['file'];
        $exists = file_exists($filePath);
        $valid = $exists ? HttpHelper::validateXdbFile($filePath, $key) : false;
        $status[$key] = [
            'file'        => $info['file'],
            'description' => $info['desc'],
            'exists'      => $exists,
            'valid'       => $valid,
            'size'        => $exists ? filesize($filePath) : 0,
            'size_human'  => $exists ? formatSize(filesize($filePath)) : 'N/A'
        ];
    }

    if ($isCli) {
        echo "=== ip2region 数据库文件状态 ===\n\n";
        foreach ($status as $key => $info) {
            if (!$info['exists']) {
                $mark = '[缺失]';
            } elseif ($info['valid']) {
                $mark = '[OK]';
            } else {
                $mark = '[损坏]';
            }
            echo sprintf("%s %s - %s (%s)\n", $mark, $info['file'], $info['description'], $info['size_human']);
        }
        echo "\n使用 'php download_xdb.php v4 --url \"https://your-cdn.com/{file}\"' 下载数据库\n";
    } else {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['status' => $status], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }
    exit;
}

// 验证类型
if ($type === 'all') {
    $types = ['v4', 'v6'];
} elseif (isset($dbFiles[$type])) {
    $types = [$type];
} else {
    outputError('无效的类型参数，可用值：v4, v6, all, status', $isCli);
}

// 构建 CDN URL 模板
if ($customUrl === '') {
    outputError('请使用 --url 参数指定下载地址，例如：--url "https://your-cdn.com/{file}"', $isCli);
}
$urlTemplate = $customUrl;

// ========== 执行下载 ==========
$results = [];
foreach ($types as $t) {
    $results[$t] = downloadFile($dbFiles[$t], $dataDir, $urlTemplate, $isCli, $t);
}

// 输出结果
if ($isCli) {
    echo "\n=== 下载完成 ===\n";
    foreach ($results as $t => $result) {
        echo sprintf("%s: %s\n", strtoupper($t), $result['message']);
    }
} else {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'results' => $results
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
}


// ========== 函数定义 ==========

/**
 * 下载单个数据库文件（含完整性校验）
 * HTTP 请求和文件校验委托给 HttpHelper，启用 SSL 证书验证
 *
 * @param array  $fileInfo    文件信息
 * @param string $dataDir     数据目录
 * @param string $urlTemplate CDN URL 模板（含 {file} 占位符）
 * @param bool   $isCli       是否命令行环境
 * @param string $type        'v4' 或 'v6'
 * @return array
 */
function downloadFile(array $fileInfo, string $dataDir, string $urlTemplate, bool $isCli, string $type): array
{
    $filePath = $dataDir . '/' . $fileInfo['file'];
    $url = str_replace('{file}', $fileInfo['file'], $urlTemplate);

    if ($isCli) {
        echo sprintf("正在下载 %s ...\n", $fileInfo['desc']);
        echo sprintf("  URL: %s\n", $url);
    }

    if (!is_writable($dataDir)) {
        return ['success' => false, 'message' => '数据目录不可写：' . $dataDir];
    }

    $startTime = microtime(true);

    // 通过 HttpHelper 下载（启用 SSL 校验，超时 300 秒）
    $data = HttpHelper::httpGet($url, 300, 10);
    if ($data === null) {
        return ['success' => false, 'message' => '下载失败：HTTP 请求未返回 200 或 SSL 校验失败'];
    }

    // 写入临时文件
    $tmpFile = $filePath . '.tmp';
    $written = file_put_contents($tmpFile, $data);

    if ($written === false) {
        @unlink($tmpFile);
        return ['success' => false, 'message' => '写入临时文件失败：' . $tmpFile];
    }

    // 校验文件完整性（带大小范围检查）
    if (!HttpHelper::validateXdbFile($tmpFile, $type)) {
        @unlink($tmpFile);
        return ['success' => false, 'message' => '文件完整性校验失败：下载的文件可能已损坏'];
    }

    // 校验通过，重命名到正式文件
    if (!@rename($tmpFile, $filePath)) {
        @unlink($tmpFile);
        return ['success' => false, 'message' => '重命名文件失败：' . $tmpFile . ' → ' . $filePath];
    }

    $elapsed = round(microtime(true) - $startTime, 2);

    return [
        'success'    => true,
        'message'    => sprintf('下载成功 (%s, 耗时 %s 秒，已通过完整性校验)', formatSize($written), $elapsed),
        'file'       => $fileInfo['file'],
        'size'       => $written,
        'size_human' => formatSize($written)
    ];
}

/**
 * 输出错误信息
 *
 * @param string $message
 * @param bool   $isCli
 */
function outputError(string $message, bool $isCli): void
{
    if ($isCli) {
        echo "错误：" . $message . "\n";
        echo "\n用法：php download_xdb.php [v4|v6|all|status] [--url <下载地址>]\n";
        echo "例如：php download_xdb.php all --url \"https://your-cdn.com/{file}\"\n";
    } else {
        header('HTTP/1.1 400 Bad Request');
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => $message], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

/**
 * 格式化文件大小
 *
 * @param int $bytes
 * @return string
 */
function formatSize(int $bytes): string
{
    $units = ['B', 'KB', 'MB', 'GB'];
    $i = 0;
    while ($bytes >= 1024 && $i < count($units) - 1) {
        $bytes /= 1024;
        $i++;
    }
    return round($bytes, 2) . ' ' . $units[$i];
}
