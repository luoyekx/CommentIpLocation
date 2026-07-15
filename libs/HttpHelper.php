<?php
namespace TypechoPlugin\CommentIpLocation;

/**
 * HTTP 请求和文件校验工具类
 * Plugin.php 和 download_xdb.php 共享，消除重复代码
 *
 * @package CommentIpLocation
 */
class HttpHelper
{
    /** @var int IPv4 数据库最小合理大小（字节） */
    public const V4_MIN_SIZE = 5000000;

    /** @var int IPv4 数据库最大合理大小（字节） */
    public const V4_MAX_SIZE = 20000000;

    /** @var int IPv6 数据库最小合理大小（字节） */
    public const V6_MIN_SIZE = 20000000;

    /** @var int IPv6 数据库最大合理大小（字节） */
    public const V6_MAX_SIZE = 60000000;

    /**
     * 查找系统 CA 证书包路径
     *
     * @return string|null
     */
    private static function findCaBundle(): ?string
    {
        $candidates = [
            '/etc/ssl/certs/ca-certificates.crt',   // Debian/Ubuntu
            '/etc/pki/tls/certs/ca-bundle.crt',      // RHEL/CentOS/Fedora
            '/usr/local/share/certs/ca-root-nss.crt', // FreeBSD
            '/etc/ssl/cert.pem',                      // macOS/OpenBSD
            '/etc/ssl/certs',                         // 目录形式（Linux 通用）
        ];
        foreach ($candidates as $path) {
            if (is_readable($path)) {
                return $path;
            }
        }
        return null;
    }

    /**
     * HTTP GET 请求（支持 cURL 和 file_get_contents 双模式）
     * 启用 SSL 证书校验，防止中间人攻击
     *
     * @param string $url            请求URL
     * @param int    $timeout        总超时秒数
     * @param int    $connectTimeout 连接超时秒数
     * @return string|null 响应内容，失败返回null
     */
    public static function httpGet(string $url, int $timeout = 120, int $connectTimeout = 10): ?string
    {
        if (function_exists('curl_init')) {
            $ch = curl_init();
            $opts = [
                CURLOPT_URL            => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_TIMEOUT        => $timeout,
                CURLOPT_CONNECTTIMEOUT => $connectTimeout,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_USERAGENT      => 'Typecho-CommentIpLocation/2.1'
            ];

            $caBundle = self::findCaBundle();
            if ($caBundle !== null) {
                $opts[CURLOPT_CAINFO] = $caBundle;
            }

            curl_setopt_array($ch, $opts);

            $data = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($data !== false && $httpCode === 200) {
                return $data;
            }
            return null;
        }

        $sslOpts = [
            'verify_peer'      => true,
            'verify_peer_name' => true
        ];

        $caBundle = self::findCaBundle();
        if ($caBundle !== null && is_file($caBundle)) {
            $sslOpts['cafile'] = $caBundle;
        }

        $context = stream_context_create([
            'http' => [
                'timeout'         => $timeout,
                'user_agent'      => 'Typecho-CommentIpLocation/2.1',
                'follow_location' => true
            ],
            'ssl' => $sslOpts
        ]);

        $data = @file_get_contents($url, false, $context);
        return $data !== false ? $data : null;
    }

    /**
     * 校验 xdb 数据库文件完整性
     * 检查文件大小范围和头部 magic header
     *
     * @param string $filePath 文件路径
     * @param string $type     'v4' 或 'v6'，用于确定大小范围；空字符串则跳过大小范围检查
     * @return bool
     */
    public static function validateXdbFile(string $filePath, string $type = ''): bool
    {
        if (!is_readable($filePath)) {
            return false;
        }

        $size = filesize($filePath);
        if ($size === false || $size < 1024) {
            return false;
        }

        // 文件大小范围校验
        if ($type !== '') {
            if ($type === 'v4') {
                $minSize = self::V4_MIN_SIZE;
                $maxSize = self::V4_MAX_SIZE;
            } else {
                $minSize = self::V6_MIN_SIZE;
                $maxSize = self::V6_MAX_SIZE;
            }
            if ($size < $minSize || $size > $maxSize) {
                return false;
            }
        }

        // 检查文件头部 magic header（前2字节为版本标识，非零值）
        $handle = @fopen($filePath, 'rb');
        if ($handle === false) {
            return false;
        }
        $header = fread($handle, 4);
        fclose($handle);

        if ($header === false || strlen($header) < 4) {
            return false;
        }

        $magic = unpack('n', $header);
        if ($magic === false || !isset($magic[1]) || $magic[1] < 1) {
            return false;
        }

        return true;
    }
}
