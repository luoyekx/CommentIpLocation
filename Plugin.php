<?php
namespace TypechoPlugin\CommentIpLocation;

use Typecho\Plugin\PluginInterface;
use Typecho\Widget\Helper\Form;
use Typecho\Widget\Helper\Form\Element\Text;
use Typecho\Widget\Helper\Form\Element\Radio;
use Typecho\Widget\Helper\Form\Element\Checkbox;
use Widget\Options;

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

// 加载共享工具类（Typecho 插件无 PSR-4 自动加载，需手动 require）
require_once __DIR__ . '/libs/HttpHelper.php';

/**
 * 评论IP归属地显示插件
 * 基于ip2region本地离线数据库，支持IPv4和IPv6，无需调用外部API
 *
 * @package CommentIpLocation
 * @author 落叶
 * @version 1.0
 * @link https://github.com/lionsoul2014/ip2region
 */
class Plugin implements PluginInterface
{
    /** @var string IPv4 数据库文件名 */
    public const V4_DB_FILE = 'ip2region_v4.xdb';

    /** @var string IPv6 数据库文件名 */
    public const V6_DB_FILE = 'ip2region_v6.xdb';

    /** @var array<string, \ip2region\xdb\Searcher|null> 搜索器实例缓存 */
    private static array $searchers = [];

    /** @var array<string, bool> CDN下载尝试标记（防止同一请求内重复下载） */
    private static array $downloadAttempted = [];

    /** @var array<string, string>|null 国家翻译表缓存（小写键 → 中文名） */
    private static ?array $countryMap = null;

    /** @var array<string, string>|null 地区翻译表缓存（小写键 → 中文名） */
    private static ?array $regionMap = null;

    /** @var bool 翻译表是否已初始化 */
    private static bool $translationLoaded = false;

    /** @var array<string, string> IP查询结果内存缓存（IP → 归属地） */
    private static array $ipCache = [];

    /** @var object|null 插件配置缓存（同一请求内不重复读取） */
    private static ?object $configCache = null;

    /** @var bool 配置是否已尝试加载 */
    private static bool $configFetched = false;

    /**
     * 加载翻译表 JSON 文件
     * 构建全小写键的哈希表，将后续查找从 O(n) 线性扫描优化为 O(1) 哈希查找
     */
    private static function loadTranslations(): void
    {
        if (self::$translationLoaded) {
            return;
        }
        self::$translationLoaded = true;

        $dataDir = __DIR__ . '/data';

        // 加载国家翻译（键统一转为小写）
        $countryFile = $dataDir . '/countries.json';
        if (is_readable($countryFile)) {
            $data = json_decode((string) file_get_contents($countryFile), true);
            if (is_array($data)) {
                $flat = [];
                foreach ($data as $val) {
                    if (is_array($val)) {
                        foreach ($val as $en => $zh) {
                            $flat[strtolower($en)] = $zh;
                        }
                    }
                }
                self::$countryMap = $flat;
            }
        }

        // 加载地区翻译（键统一转为小写）
        $regionFile = $dataDir . '/regions.json';
        if (is_readable($regionFile)) {
            $data = json_decode((string) file_get_contents($regionFile), true);
            if (is_array($data)) {
                $flat = [];
                foreach ($data as $regions) {
                    if (is_array($regions)) {
                        foreach ($regions as $en => $zh) {
                            $flat[strtolower($en)] = $zh;
                        }
                    }
                }
                self::$regionMap = $flat;
            }
        }
    }

    /**
     * 统一获取插件配置对象（同一请求内缓存，避免重复数据库查询）
     *
     * @return object|null
     */
    private static function getConfig(): ?object
    {
        if (!self::$configFetched) {
            self::$configFetched = true;
            try {
                self::$configCache = Options::alloc()->plugin('CommentIpLocation');
            } catch (\Exception $e) {
                self::$configCache = null;
            }
        }
        return self::$configCache;
    }

    /**
     * 激活插件：注册评论过滤器、动态属性和内容扩展钩子
     *
     * @return string
     */
    public static function activate(): string
    {
        \Typecho\Plugin::factory('Widget\Base\Comments')->filter = [__CLASS__, 'filterComment'];
        \Typecho\Plugin::factory('Widget\Base\Comments')->___ipLocation = [__CLASS__, 'getIpLocation'];
        \Typecho\Plugin::factory('Widget\Base\Comments')->contentEx = [__CLASS__, 'appendLocation'];
        \Typecho\Plugin::factory('Widget\Archive')->header = [__CLASS__, 'header'];

        $dataDir = __DIR__ . '/data';
        $v4Exists = file_exists($dataDir . '/' . self::V4_DB_FILE);
        $v6Exists = file_exists($dataDir . '/' . self::V6_DB_FILE);

        if (!$v4Exists && !$v6Exists) {
            $msg = '评论IP归属地插件已激活，数据库文件缺失，首次查询时将自动从 CDN 下载';
        } elseif (!$v4Exists) {
            $msg = '评论IP归属地插件已激活，IPv4 数据库缺失，首次查询时将自动从 CDN 下载';
        } elseif (!$v6Exists) {
            $msg = '评论IP归属地插件已激活，IPv6 数据库缺失，首次查询时将自动从 CDN 下载';
        } else {
            $msg = '评论IP归属地插件已激活，数据库文件就绪';
        }

        return _t($msg);
    }

    /**
     * 禁用插件
     *
     * @return string
     */
    public static function deactivate(): string
    {
        return _t('评论IP归属地插件已禁用');
    }

    /**
     * 插件配置面板
     *
     * @param Form $form
     */
    public static function config(Form $form): void
    {
        $customCdnUrl = new Text(
            'customCdnUrl',
            null,
            'https://cdn.jsdelivr.net/gh/lionsoul2014/ip2region@master/data/{file}',
            _t('自定义CDN地址'),
            _t('默认使用 jsDelivr CDN 自动下载数据库。输入 "local" 使用本地文件（需手动运行 download_xdb.php 下载）；也可输入其他 CDN 地址。{file} 为文件名占位符，运行时自动替换为 ip2region_v4.xdb 或 ip2region_v6.xdb')
        );
        $form->addInput($customCdnUrl);

        $dbDir = new Text(
            'dbDir',
            null,
            'data',
            _t('数据库文件目录'),
            _t('相对于插件目录的路径，存放 ip2region_v4.xdb 和 ip2region_v6.xdb 文件')
        );
        $form->addInput($dbDir);

        $cacheStrategy = new Radio(
            'cacheStrategy',
            [
                'vectorIndex' => _t('向量索引缓存（推荐，固定512KB内存）'),
                'buffer'      => _t('全内存缓存（最快，约占用46MB内存）'),
                'fileOnly'    => _t('纯文件查询（最低内存，有磁盘IO）')
            ],
            'vectorIndex',
            _t('缓存策略'),
            _t('控制数据库文件的加载方式，影响查询速度和内存占用')
        );
        $form->addInput($cacheStrategy);

        $displayFormat = new Radio(
            'displayFormat',
            [
                'province_city'    => _t('省份 城市'),
                'full'             => _t('国家 省份 城市'),
                'province_only'    => _t('仅省份'),
                'country_province' => _t('国家 省份'),
                'country_only'     => _t('仅国家')
            ],
            'province_city',
            _t('显示格式')
        );
        $form->addInput($displayFormat);

        $showIsp = new Radio(
            'showIsp',
            ['1' => _t('显示'), '0' => _t('不显示')],
            '0',
            _t('显示运营商')
        );
        $form->addInput($showIsp);

        $autoDisplay = new Checkbox(
            'autoDisplay',
            ['append' => _t('自动在评论内容后追加归属地（无需修改主题模板）')],
            [],
            _t('自动显示'),
            _t('勾选后归属地将自动追加到评论内容末尾，并自动注入CSS样式。如需自定义显示位置，请取消勾选并在主题模板中调用 $comments->ipLocation')
        );
        $form->addInput($autoDisplay);

        $separator = new Text(
            'separator',
            null,
            ' ',
            _t('分隔符'),
            _t('归属地各部分之间的分隔符')
        );
        $form->addInput($separator);

        $prefix = new Text(
            'prefix',
            null,
            'IP属地：',
            _t('显示前缀'),
            _t('自动显示时归属地前面的文字')
        );
        $form->addInput($prefix);

        $ipMasking = new Radio(
            'ipMasking',
            [
                '0' => _t('不脱敏（显示完整归属地）'),
                '1' => _t('省级脱敏（隐藏城市，仅显示省份）'),
                '2' => _t('国家级脱敏（仅显示国家，GDPR推荐）')
            ],
            '0',
            _t('IP归属地脱敏'),
            _t('出于隐私合规考虑（如GDPR），可降低归属地显示粒度。脱敏设置优先于显示格式')
        );
        $form->addInput($ipMasking);
    }

    /**
     * 个人用户配置面板
     *
     * @param Form $form
     */
    public static function personalConfig(Form $form): void
    {
    }

    /**
     * 评论过滤器回调：注入 ipLocation 字段到评论行数据
     *
     * @param array $row
     * @param mixed $widget
     * @param array $result
     * @return array
     */
    public static function filterComment($row, $widget, $result): array
    {
        $row = $result ?: $row;

        if (!empty($row['ip'])) {
            $row['ipLocation'] = self::search($row['ip']);
        } else {
            $row['ipLocation'] = '';
        }

        return $row;
    }

    /**
     * 动态属性回调：模板中 $comments->ipLocation 触发（回退方案）
     *
     * @param mixed $widget
     * @return string
     */
    public static function getIpLocation($widget): string
    {
        return self::search($widget->ip);
    }

    /**
     * 内容扩展过滤器回调：自动在评论内容后追加归属地
     *
     * @param string $content
     * @param mixed $widget
     * @param string $result
     * @return string
     */
    public static function appendLocation($content, $widget, $result): string
    {
        $content = $result ?: $content;

        $config = self::getConfig();
        if ($config === null) {
            return $content;
        }

        $autoDisplay = $config->autoDisplay;
        if (is_array($autoDisplay) && in_array('append', $autoDisplay)) {
            $ipLocation = $widget->ipLocation;
            if (!empty($ipLocation)) {
                $prefix = htmlspecialchars($config->prefix, ENT_QUOTES, 'UTF-8');
                $locationTag = '<span class="comment-ip-location">' . $prefix
                    . htmlspecialchars($ipLocation, ENT_QUOTES, 'UTF-8') . '</span>';
                $content .= ' ' . $locationTag;
            }
        }

        return $content;
    }

    /**
     * 页面头部回调：注入归属地显示样式
     * 仅在自动显示开启时才注入CSS，CSS内容在进程内缓存
     *
     * @param mixed $widget
     */
    public static function header($widget = null): void
    {
        static $cssInjected = false;

        if ($cssInjected) {
            return;
        }

        $config = self::getConfig();
        if ($config === null) {
            return;
        }

        $autoDisplay = $config->autoDisplay;
        if (!is_array($autoDisplay) || !in_array('append', $autoDisplay)) {
            return;
        }

        // CSS 内容缓存：同一进程内只读一次磁盘
        static $cssContent = null;
        if ($cssContent === null) {
            $cssFile = __DIR__ . '/assets/style.css';
            $cssContent = is_readable($cssFile) ? (string) file_get_contents($cssFile) : '';
        }

        if ($cssContent !== '') {
            echo '<style type="text/css">' . "\n" . $cssContent . "\n" . '</style>' . "\n";
        }

        $cssInjected = true;
    }

    /**
     * 查询 IP 归属地（带内存缓存）
     *
     * @param string $ip
     * @return string
     */
    public static function search(string $ip): string
    {
        if ($ip === '') {
            return '';
        }

        // 内存缓存：同一请求内相同IP直接返回
        if (isset(self::$ipCache[$ip])) {
            return self::$ipCache[$ip];
        }

        // 验证 IP 地址（短路：IPv4 验证通过后不再检查 IPv6）
        $isIPv4 = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4);
        $isIPv6 = $isIPv4 ? false : (bool) filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6);

        if (!$isIPv4 && !$isIPv6) {
            self::$ipCache[$ip] = '';
            return '';
        }

        // IPv4-mapped IPv6 地址（::ffff:x.x.x.x）转换为纯 IPv4
        // 这类地址本质是 IPv4，应查询 IPv4 数据库
        if ($isIPv6) {
            $packed = @inet_pton($ip);
            // IPv4-mapped IPv6 前缀: 00*10 + ff*2
            if ($packed !== false
                && strlen($packed) === 16
                && substr($packed, 0, 12) === "\0\0\0\0\0\0\0\0\0\0\xff\xff"
            ) {
                $ip = inet_ntop(substr($packed, 12, 4));
                $isIPv4 = true;
                $isIPv6 = false;
            }
        }

        // 跳过本地/私有 IP
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            self::$ipCache[$ip] = '本地网络';
            return '本地网络';
        }

        // 获取搜索器
        $type = $isIPv4 ? 'v4' : 'v6';
        $searcher = self::getSearcher($type);

        if ($searcher === null) {
            self::$ipCache[$ip] = '';
            return '';
        }

        try {
            $result = self::formatLocation($searcher->search($ip));
            self::$ipCache[$ip] = $result;
            return $result;
        } catch (\Exception $e) {
            self::$ipCache[$ip] = '';
            return '';
        }
    }

    /**
     * 获取或创建搜索器实例（带缓存）
     * 支持 CDN 自动下载模式：当本地文件不存在时从CDN下载
     *
     * @param string $type 'v4' 或 'v6'
     * @return \ip2region\xdb\Searcher|null
     */
    private static function getSearcher(string $type): ?\ip2region\xdb\Searcher
    {
        if (isset(self::$searchers[$type])) {
            return self::$searchers[$type];
        }

        // 加载 ip2region 搜索器类（class_exists 避免 require_once 重复 include 开销）
        if (!class_exists(\ip2region\xdb\Searcher::class, false)) {
            require_once __DIR__ . '/libs/Searcher.class.php';
        }

        $config = self::getConfig();
        if ($config === null) {
            self::$searchers[$type] = null;
            return null;
        }

        $dbDir      = $config->dbDir ?? 'data';
        $strategy   = $config->cacheStrategy ?? 'vectorIndex';
        $customCdn  = $config->customCdnUrl ?? 'https://cdn.jsdelivr.net/gh/lionsoul2014/ip2region@master/data/{file}';

        $dbDir      = $dbDir ?: 'data';
        $strategy   = $strategy ?: 'vectorIndex';
        $customCdn  = $customCdn ?: 'https://cdn.jsdelivr.net/gh/lionsoul2014/ip2region@master/data/{file}';

        // 路径穿越防护：拒绝包含 .. 的路径
        if (strpos(str_replace('\\', '/', $dbDir), '..') !== false) {
            $dbDir = 'data';
        }

        $dataDir = __DIR__ . '/' . $dbDir;
        $dbFile = $dataDir . '/' . ($type === 'v4' ? self::V4_DB_FILE : self::V6_DB_FILE);

        // 本地文件不存在时处理
        if (!file_exists($dbFile)) {
            if ($customCdn !== 'local' && $customCdn !== '') {
                if (!self::downloadFromCdn($type, $dataDir, $dbFile)) {
                    self::$searchers[$type] = null;
                    return null;
                }
            } else {
                self::$searchers[$type] = null;
                return null;
            }
        } elseif (!HttpHelper::validateXdbFile($dbFile, $type)) {
            // 文件存在但损坏，尝试重新下载
            if ($customCdn !== 'local' && $customCdn !== '') {
                @unlink($dbFile);
                if (!self::downloadFromCdn($type, $dataDir, $dbFile)) {
                    self::$searchers[$type] = null;
                    return null;
                }
            } else {
                self::$searchers[$type] = null;
                return null;
            }
        }

        $version = $type === 'v4'
            ? \ip2region\xdb\IPv4::default()
            : \ip2region\xdb\IPv6::default();

        try {
            switch ($strategy) {
                case 'buffer':
                    $cBuff = \ip2region\xdb\Util::loadContentFromFile($dbFile);
                    $searcher = \ip2region\xdb\Searcher::newWithBuffer($version, $cBuff);
                    break;
                case 'fileOnly':
                    $searcher = \ip2region\xdb\Searcher::newWithFileOnly($version, $dbFile);
                    break;
                case 'vectorIndex':
                default:
                    $vIndex = \ip2region\xdb\Util::loadVectorIndexFromFile($dbFile);
                    $searcher = \ip2region\xdb\Searcher::newWithVectorIndex($version, $dbFile, $vIndex);
                    break;
            }

            self::$searchers[$type] = $searcher;
            return $searcher;
        } catch (\Exception $e) {
            self::$searchers[$type] = null;
            return null;
        }
    }

    /**
     * 从 CDN 下载数据库文件到本地
     * 下载后自动校验文件完整性
     *
     * @param string $type    'v4' 或 'v6'
     * @param string $dataDir 数据目录绝对路径
     * @param string $dbFile  数据库文件绝对路径
     * @return bool 下载是否成功
     */
    private static function downloadFromCdn(string $type, string $dataDir, string $dbFile): bool
    {
        if (isset(self::$downloadAttempted[$type])) {
            return false;
        }
        self::$downloadAttempted[$type] = true;

        $url = self::getCdnUrl($type);
        if ($url === '') {
            return false;
        }

        if (!is_dir($dataDir)) {
            @mkdir($dataDir, 0755, true);
        }

        if (!is_writable($dataDir)) {
            return false;
        }

        $data = HttpHelper::httpGet($url);
        if ($data === null) {
            return false;
        }

        // 写入临时文件，校验通过后再重命名
        $tmpFile = $dbFile . '.tmp';
        $written = file_put_contents($tmpFile, $data);
        if ($written === false) {
            @unlink($tmpFile);
            return false;
        }

        // 校验下载的文件完整性（带大小范围检查）
        if (!HttpHelper::validateXdbFile($tmpFile, $type)) {
            @unlink($tmpFile);
            return false;
        }

        // 校验通过，重命名到正式文件
        if (!@rename($tmpFile, $dbFile)) {
            @unlink($tmpFile);
            return false;
        }

        return true;
    }

    /**
     * 获取指定类型的 CDN 下载 URL
     *
     * @param string $type 'v4' 或 'v6'
     * @return string 完整下载URL，空字符串表示无效
     */
    private static function getCdnUrl(string $type): string
    {
        $fileName = $type === 'v4' ? self::V4_DB_FILE : self::V6_DB_FILE;

        $config = self::getConfig();
        if ($config === null) {
            return '';
        }

        $customUrl = $config->customCdnUrl ?? 'https://cdn.jsdelivr.net/gh/lionsoul2014/ip2region@master/data/{file}';

        if ($customUrl === 'local' || $customUrl === '') {
            return '';
        }

        return str_replace('{file}', $fileName, $customUrl);
    }

    /**
     * 格式化归属地字符串
     * ip2region 返回格式：国家|省份|城市|ISP|国家代码
     * 例如：中国|广东省|深圳市|移动|CN
     *
     * @param string $region
     * @return string
     */
    private static function formatLocation(string $region): string
    {
        if ($region === '') {
            return '';
        }

        $parts = explode('|', $region);

        $country  = $parts[0] ?? '';
        $province = $parts[1] ?? '';
        $city     = $parts[2] ?? '';
        $isp      = $parts[3] ?? '';

        // 处理 0 或空值
        $country  = ($country === '0' || $country === '') ? '' : $country;
        $province = ($province === '0' || $province === '') ? '' : $province;
        $city     = ($city === '0' || $city === '') ? '' : $city;
        $isp      = ($isp === '0' || $isp === '') ? '' : $isp;

        // 将英文地区名翻译为中文（国外IP返回的是英文）
        $country  = self::translateCountry($country);
        $province = self::translateRegion($province);
        $city     = self::translateRegion($city);

        $config = self::getConfig();
        if ($config === null) {
            // 配置未加载时使用默认值：省份城市格式，不显示运营商，空格分隔，不脱敏
            $result = [];
            if ($province) {
                $result[] = $province;
            }
            if ($city) {
                $result[] = $city;
            }
            if (!$province && !$city && $country) {
                $result[] = $country;
            }
            return implode(' ', $result);
        }

        $format    = $config->displayFormat ?? 'province_city';
        $showIsp   = ($config->showIsp ?? '0') === '1';
        $separator = $config->separator ?? ' ';
        $ipMasking = $config->ipMasking ?? '0';

        $format    = $format ?: 'province_city';
        $separator = $separator ?: ' ';

        // IP脱敏处理（优先于显示格式）
        if ($ipMasking === '2') {
            return $country;
        }
        if ($ipMasking === '1') {
            $result = [];
            if ($province) {
                $result[] = $province;
            }
            if (!$province && $country) {
                $result[] = $country;
            }
            return implode($separator, $result);
        }

        // 正常显示格式
        $result = [];

        switch ($format) {
            case 'full':
                if ($country)  $result[] = $country;
                if ($province) $result[] = $province;
                if ($city)     $result[] = $city;
                if ($showIsp && $isp) $result[] = $isp;
                break;

            case 'province_only':
                if ($province) {
                    $result[] = $province;
                } elseif ($country) {
                    $result[] = $country;
                }
                break;

            case 'country_province':
                if ($country)  $result[] = $country;
                if ($province) $result[] = $province;
                break;

            case 'country_only':
                if ($country) $result[] = $country;
                break;

            case 'province_city':
            default:
                if ($province) $result[] = $province;
                if ($city)     $result[] = $city;
                if (!$province && !$city && $country) $result[] = $country;
                if ($showIsp && $isp) $result[] = $isp;
                break;
        }

        return implode($separator, $result);
    }

    /**
     * 将英文国家名翻译为中文（O(1) 哈希查找）
     *
     * @param string $name 英文国家名
     * @return string 中文国家名，无匹配时返回原值
     */
    private static function translateCountry(string $name): string
    {
        if ($name === '') {
            return $name;
        }

        self::loadTranslations();

        $key = strtolower($name);
        return self::$countryMap[$key] ?? $name;
    }

    /**
     * 将英文省份/城市名翻译为中文（O(1) 哈希查找）
     *
     * @param string $name 英文地区名
     * @return string 中文地区名，无匹配时返回原值
     */
    private static function translateRegion(string $name): string
    {
        if ($name === '') {
            return $name;
        }

        self::loadTranslations();

        $key = strtolower($name);
        return self::$regionMap[$key] ?? $name;
    }
}
