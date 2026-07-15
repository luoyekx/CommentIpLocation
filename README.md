> Typecho 评论 IP 归属地显示插件，基于 ip2region 本地离线数据库，支持 IPv4/IPv6 双栈，零外部 API 依赖。

## 声明：本插件由 AI 开发，功能可用，但不保证没有 Bug，也不保证好用。使用前请自行备份重要数据，作者不承担因使用本插件造成的任何损失。

## 为什么做这个插件

Typecho 社区缺少一个同时满足以下条件的 IP 归属地插件：纯本地查询（不泄露访客 IP 给第三方）、IPv4 和 IPv6 都能用、安装门槛低到「下载 → 启用 → 完事」。本插件用 ip2region 的 xdb 二进制数据库解决了这个问题——10MB 的 IPv4 库覆盖全国城市级精度，35MB 的 IPv6 库覆盖全球主要网络，查询延迟在毫秒级。

## 功能一览

- **纯本地查询**：基于 ip2region xdb 二进制数据库，查询过程不发起任何网络请求，访客 IP 不离开服务器
- **IPv4 + IPv6 双栈**：自动识别 IP 类型并路由到对应数据库，支持 IPv4-mapped IPv6 地址自动转换
- **CDN 自动下载**：首次使用时自动从 CDN 拉取数据库文件，也支持自定义 CDN 地址
- **SSL 安全下载**：CDN 下载启用证书校验，自动探测系统 CA 证书包，防止中间人攻击
- **三种缓存策略**：向量索引（512KB 固定内存）、全内存缓存（46MB，纯内存查询）、纯文件查询（最低内存）
- **英文自动翻译**：国外 IP 返回的英文地区名自动转为中文，覆盖 200+ 国家和 400+ 地区，O(1) 哈希查找
- **IP 脱敏选项**：支持省级脱敏（隐藏城市）和国家级脱敏（仅显示国家），满足 GDPR 等隐私合规需求
- **自动显示 / 手动调用**：勾选自动显示即可零改码接入，也支持 `$comments->ipLocation()` 手动调用
- **文件完整性校验**：下载后自动校验 xdb 文件大小范围和 magic header，损坏文件自动重新下载
- **下载脚本鉴权**：浏览器访问 `download_xdb.php` 需登录 Typecho 后台，CLI 模式不受限
- **请求内缓存**：配置对象、IP 查询结果、CSS 内容在同一请求内缓存，避免重复 I/O

## 环境要求

| 项目 | 要求 |
|------|------|
| Typecho | >= 1.2.0 |
| PHP | >= 7.4 |
| PHP 扩展 | 无额外要求（纯 PHP 实现，cURL 可选） |

## 快速开始

### 1. 安装插件

将 `CommentIpLocation` 文件夹上传至 `usr/plugins/` 目录：

```
usr/plugins/CommentIpLocation/
├── Plugin.php                  # 主插件文件
├── download_xdb.php            # 数据库下载脚本（CLI + 浏览器）
├── libs/
│   ├── Searcher.class.php      # ip2region 搜索引擎
│   └── HttpHelper.php          # HTTP 请求 + 文件校验工具类
├── assets/
│   └── style.css               # 归属地显示样式
├── data/                       # 数据库存放目录
│   ├── countries.json          # 国家英中翻译表（200+）
│   └── regions.json            # 地区英中翻译表（400+）
├── LICENSE
└── README.md
```

### 2. 下载数据库

**命令行方式（推荐）：**

```bash
cd usr/plugins/CommentIpLocation

# 使用默认 jsDelivr CDN 源下载
php download_xdb.php all --url "https://cdn.jsdelivr.net/gh/lionsoul2014/ip2region@master/data/{file}"

# 或使用自定义 CDN 源
php download_xdb.php all --url "https://your-cdn.com/{file}"
```

**浏览器方式（需登录后台）：**

访问 `http://你的域名/usr/plugins/CommentIpLocation/download_xdb.php?type=all&url=https://cdn.jsdelivr.net/gh/lionsoul2014/ip2region@master/data/{file}`

### CDN 自动下载模式

首次查询某个 IP 时，如果对应类型的数据库文件不存在，插件会自动从配置的 CDN 源下载到本地 `data/` 目录，后续查询直接使用本地缓存。

**注意**：CDN 模式是按需下载，不是一次性下载两个文件。例如，首次查询的评论 IP 都是 IPv4 时，只会下载 `ip2region_v4.xdb`；当首次出现 IPv6 查询时，才会下载 `ip2region_v6.xdb`。

### 3. 启用并配置

进入 Typecho 后台 → 控制台 → 插件管理 → 启用 CommentIpLocation → 点击设置。

## 配置项说明

| 配置项 | 说明 | 默认值 |
|--------|------|--------|
| 自定义CDN地址 | 数据库文件下载地址，`{file}` 为文件名占位符 | jsDelivr CDN |
| 数据库文件目录 | 相对于插件目录的路径 | `data` |
| 缓存策略 | `vectorIndex` / `buffer` / `fileOnly` | `vectorIndex` |
| 显示格式 | 省份+城市 / 国家+省份+城市 / 仅省份 / 国家+省份 / 仅国家 | 省份+城市 |
| 显示运营商 | 是否在归属地后追加 ISP | 不显示 |
| 自动显示 | 勾选后自动追加到评论内容末尾并注入 CSS | 不勾选 |
| 分隔符 | 归属地各部分之间的分隔符 | 空格 |
| 显示前缀 | 自动显示时的文字前缀 | `IP属地：` |
| IP归属地脱敏 | 不脱敏 / 省级脱敏 / 国家级脱敏 | 不脱敏 |

## 使用方式

### 自动显示（零改码）

在插件设置中勾选「自动在评论内容后追加归属地」。归属地会包裹在 `<span class="comment-ip-location">` 标签中追加到评论末尾，CSS 通过 `header` 钩子自动注入（仅自动显示开启时才注入）。

### 手动模板调用

取消勾选自动显示，在主题的 `comments.php` 中添加：

```php
<span class="comment-ip"><?php $comments->ipLocation(); ?></span>
```

在主题 CSS 中自定义样式：

```css
.comment-ip-location {
    font-size: 12px;
    color: #999;
}
```

## 缓存策略

| 策略 | 内存占用 | 查询速度 | 适用场景 |
|------|----------|----------|----------|
| `vectorIndex` | ~512KB（固定） | 快（1 次磁盘 IO） | 推荐，兼顾性能和内存 |
| `buffer` | ~46MB（IPv4+IPv6） | 最快（纯内存） | 内存充足的高流量站点 |
| `fileOnly` | 极低 | 较慢（2 次磁盘 IO） | 内存受限的服务器 |

此外，插件在单次请求内对相同 IP 的查询结果进行内存缓存，避免同一页面多条评论来自同一 IP 时的重复查询。

## 下载脚本用法

```bash
# 查看数据库状态
php download_xdb.php status

# 下载全部数据库
php download_xdb.php all --url "https://cdn.jsdelivr.net/gh/lionsoul2014/ip2region@master/data/{file}"

# 仅下载 IPv4
php download_xdb.php v4 --url "https://your-cdn.com/{file}"

# 使用自定义 CDN 下载
php download_xdb.php all --url "https://your-cdn.com/ip2region/{file}"
```

浏览器访问（需登录 Typecho 后台管理员账户）：

```
?type=status                    查看状态
?type=all&url=https://...       下载全部
?type=v4&url=https://...        下载 IPv4
```

## 技术架构

### 工作原理

```
评论渲染
  │
  ▼
Widget\Base\Comments::push()
  │
  ├─ filter 钩子 ──→ filterComment()
  │                    ├─ 提取评论 IP
  │                    ├─ 内存缓存命中？→ 直接返回
  │                    ├─ 校验 IP（IPv4/IPv6/私有地址）
  │                    ├─ IPv4-mapped IPv6 → 转换为纯 IPv4
  │                    ├─ getSearcher() 获取搜索器
  │                    │    ├─ 本地文件存在？→ 加载
  │                    │    ├─ CDN 模式 → 自动下载 + 校验
  │                    │    └─ 按缓存策略创建 Searcher
  │                    ├─ search() 查询 xdb
  │                    ├─ formatLocation() 格式化
  │                    │    ├─ 翻译英文→中文（O(1) 哈希查找）
  │                    │    ├─ IP 脱敏处理
  │                    │    └─ 按显示格式拼接
  │                    └─ 写入 $row['ipLocation']
  │
  ├─ ___ipLocation 钩子 ──→ getIpLocation()（模板调用回退）
  │
  └─ contentEx 钩子 ──→ appendLocation()（自动追加到评论内容）
                         └─ header 钩子注入 CSS
```

### 注册的 Typecho 钩子

| 钩子 | 类型 | 作用 |
|------|------|------|
| `Widget\Base\Comments->filter` | filter | 评论入栈时注入 `ipLocation` 字段 |
| `Widget\Base\Comments->___ipLocation` | call | 模板 `$comments->ipLocation()` 的回退查询 |
| `Widget\Base\Comments->contentEx` | filter | 自动追加归属地到评论内容末尾 |
| `Widget\Archive->header` | call | 按需注入 CSS 样式（仅自动显示开启时） |

### 文件完整性校验

下载 xdb 文件后通过 `HttpHelper::validateXdbFile()` 执行两层校验：

1. **文件大小范围**：IPv4 库 5~20MB，IPv6 库 20~60MB，超出范围判定为损坏
2. **Magic header**：读取文件头 4 字节，校验版本标识是否合法（非零值）

校验失败时删除临时文件，CDN 模式下会在下次请求时自动重新下载。

### 翻译机制

ip2region 对国外 IP 返回英文地区名（如 `United States|California|San Francisco|0|US`）。插件通过两个 JSON 文件实现本地翻译：

- `data/countries.json`：200+ 国家和地区的英中映射，按大洲分组
- `data/regions.json`：400+ 省/州/城市英中映射，按国家分组

翻译表在首次使用时懒加载到内存，构建全小写键的哈希表，后续查找为 O(1) 复杂度。无网络请求，未覆盖的地区保持英文原样。

### 安全设计

| 措施 | 说明 |
|------|------|
| **SSL 证书校验** | CDN 下载启用 `VERIFYPEER`/`VERIFYHOST`，自动探测系统 CA 证书包路径 |
| **路径穿越防护** | `dbDir` 配置项检测 `..` 序列，拒绝插件目录外的路径 |
| **XSS 防护** | 输出内容使用 `htmlspecialchars($val, ENT_QUOTES, 'UTF-8')` 转义 |
| **下载脚本鉴权** | 浏览器访问 `download_xdb.php` 需登录 Typecho 管理员账户 |
| **二进制安全** | xdb 文件以 `'rb'` 模式打开，避免 Windows 下换行符转换损坏数据 |

## 常见问题

**启用插件后评论不显示归属地？**

1. 运行 `php download_xdb.php status` 检查数据库文件是否已下载
2. CDN 模式下检查 `data/` 目录是否可写（建议 755）
3. 手动调用模式确认模板中使用了 `$comments->ipLocation()`

**CDN 模式下载失败？**

1. 检查服务器网络：`curl -I https://cdn.jsdelivr.net/gh/lionsoul2014/ip2region@master/data/ip2region_v4.xdb`
2. 命令行手动下载：`php download_xdb.php all --url "https://cdn.jsdelivr.net/gh/lionsoul2014/ip2region@master/data/{file}"`
3. 检查服务器 CA 证书包是否已安装（`apt install ca-certificates` / `yum install ca-certificates`）
4. 设置 `customCdnUrl` 为 `local` 切换到本地模式，手动下载

**显示「本地网络」？**

评论者 IP 为局域网/保留地址（127.0.0.1、192.168.x.x、10.x.x.x 等），非公网 IP。

**国外 IP 显示英文？**

翻译表已覆盖 200+ 国家和 400+ 地区，但仍可能有少数地区未覆盖。可以在 `data/regions.json` 中手动添加翻译条目。

**浏览器访问 download_xdb.php 提示 403？**

这是安全设计，浏览器访问需登录 Typecho 管理后台。CLI 命令行运行不受限制。

## 数据库更新

ip2region 数据库不定期更新。更新方式：

```bash
# 重新运行下载脚本
php download_xdb.php all --url "https://cdn.jsdelivr.net/gh/lionsoul2014/ip2region@master/data/{file}"

# 或删除旧文件，下次访问时自动重新下载
rm data/ip2region_v4.xdb data/ip2region_v6.xdb
```

## 参与贡献

欢迎提交 Issue 和 Pull Request。

### 开发环境

- PHP >= 7.4（使用类型化属性、类型声明等 PHP 7.4 特性）
- Typecho >= 1.2.0
- 无 Composer 依赖

### 代码结构

| 文件 | 职责 |
|------|------|
| `Plugin.php` | 主插件类，注册钩子、配置面板、IP 查询、格式化、CDN 下载 |
| `libs/Searcher.class.php` | ip2region 官方 PHP 搜索引擎，单文件实现（精简版，仅保留所用方法） |
| `libs/HttpHelper.php` | HTTP 请求 + 文件校验工具类，Plugin.php 和 download_xdb.php 共享 |
| `download_xdb.php` | 独立数据库下载脚本，支持 CLI 和浏览器双模式 |
| `data/countries.json` | 国家英中翻译表（200+） |
| `data/regions.json` | 地区英中翻译表（400+） |
| `assets/style.css` | 归属地显示样式，含深色模式适配 |

### 贡献方向

- 补充翻译表中缺失的地区
- 添加更多 CDN 源
- 优化查询性能
- 修复兼容性问题

## 数据来源

IP 数据库来自开源项目 [ip2region](https://github.com/lionsoul2014/ip2region)，数据精度为城市级别，覆盖中国全境及全球主要国家。

## 更新日志

暂无。

## 致谢

- [ip2region](https://github.com/lionsoul2014/ip2region) - 高性能 IP 地址定位库
- [Typecho](https://typecho.org) - 简洁强大的博客平台

