<?php
/**
 * 闲鱼图床配置文件
 * 
 * 使用说明：
 * 1. 修改 COOKIE2_VALUE 为你的闲鱼 cookie2 值
 * 2. 修改 BAIDU_COOKIE 为你的百度贴吧 cookie 值（可选，用于百度上传功能）
 * 3. 可以根据需要调整其他配置项
 * 
 * 获取闲鱼 cookie2 值的方法：
 * 1. 访问 https://author.goofish.com/#/
 * 2. 按F12打开开发者工具
 * 3. 刷新页面，在网络选项卡中找到请求
 * 4. 查看请求头中的 cookie 字段，复制 cookie2 的值
 * 
 * 获取百度贴吧 cookie 值的方法：
 * 1. 访问 https://tieba.baidu.com/
 * 2. 登录你的百度账号
 * 3. 按F12打开开发者工具
 * 4. 刷新页面，在网络选项卡中找到请求
 * 5. 查看请求头中的 cookie 字段，复制整个 cookie 值
 * 
 * 注意：
 * - 百度上传功能需要有效的百度账号cookie才能使用
 * - cookie可能会过期，如果上传失败请重新获取新的cookie
 * - 请妥善保管你的cookie信息，不要泄露给他人
 */

// 闲鱼API配置
define('GOOFISH_UPLOAD_URL', 'https://stream-upload.goofish.com/api/upload.api');
define('COOKIE2_VALUE', ''); // 请替换为你的cookie2值 通过https://author.goofish.com/#/获取

// 百度贴吧API配置
define('BAIDU_COOKIE', ''); // 请替换为你的百度贴吧cookie值 用于百度上传功能（可选）

// 上传限制配置
define('MAX_FILE_SIZE', 50 * 1024 * 1024); // 最大文件大小 50MB
define('ALLOWED_TYPES', [
    'image/jpeg',
    'image/png', 
    'image/gif',
    'image/webp',
    'image/jpg'
]);

// 安全配置
define('ENABLE_RATE_LIMIT', true); // 是否启用频率限制
define('RATE_LIMIT_REQUESTS', 10); // 每分钟最大请求数
define('ENABLE_IP_WHITELIST', false); // 是否启用IP白名单
define('IP_WHITELIST', [
    '127.0.0.1',
    '::1'
]);

// 日志配置
define('ENABLE_LOGGING', true); // 是否启用日志记录
define('LOG_FILE', __DIR__ . '/logs/upload.log');

// 缓存配置
define('ENABLE_CACHE', false); // 是否启用缓存（避免重复上传相同文件）
define('CACHE_DIR', __DIR__ . '/cache/');

// 错误信息配置
define('ERROR_MESSAGES', [
    'no_file' => '没有文件上传或上传失败',
    'invalid_type' => '不支持的文件类型，只支持 JPG、PNG、GIF、WebP 格式',
    'file_too_large' => '文件大小不能超过 ' . (MAX_FILE_SIZE / 1024 / 1024) . 'MB',
    'rate_limit' => '请求过于频繁，请稍后再试',
    'ip_blocked' => 'IP地址被限制访问',
    'curl_error' => 'cURL错误',
    'http_error' => 'HTTP错误',
    'parse_error' => '响应解析失败',
    'api_error' => '闲鱼API返回错误',
    'format_error' => '响应格式异常'
]);

// 获取配置函数
function getConfig($key, $default = null) {
    return defined($key) ? constant($key) : $default;
}

// 获取错误信息
function getErrorMessage($key) {
    $messages = ERROR_MESSAGES;
    return isset($messages[$key]) ? $messages[$key] : '未知错误';
}

// 检查目录是否存在，不存在则创建
function ensureDirectoryExists($dir) {
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
}

// 初始化必要的目录
if (ENABLE_LOGGING) {
    ensureDirectoryExists(dirname(LOG_FILE));
}

if (ENABLE_CACHE) {
    ensureDirectoryExists(CACHE_DIR);
}
?>