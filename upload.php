<?php
// 开启输出缓冲，确保只输出JSON响应
ob_start();

// 引入百度上传类
require_once 'BaiduImageUploader.php';

// 必须在任何其他代码之前设置PHP上传限制
ini_set('post_max_size', '50M');
ini_set('upload_max_filesize', '50M');
ini_set('max_execution_time', 300);
ini_set('memory_limit', '256M');

// 禁用所有错误输出到浏览器
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
ini_set('html_errors', 0);
ini_set('docref_root', '');
ini_set('docref_ext', '');
ini_set('error_prepend_string', '');
ini_set('error_append_string', '');
ini_set('auto_prepend_file', '');
ini_set('auto_append_file', '');

// 清理任何之前的输出
if (ob_get_level()) {
    ob_clean();
}

// 错误处理设置
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

// 设置错误处理函数
set_error_handler(function($severity, $message, $file, $line) {
    $errorMsg = "PHP Error: [$severity] $message in $file on line $line";
    error_log($errorMsg);
    
    // 如果是致命错误，返回JSON错误响应
    if ($severity === E_ERROR || $severity === E_PARSE || $severity === E_CORE_ERROR) {
        // 清理输出缓冲区
        if (ob_get_level()) {
            ob_clean();
        }
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'success' => false,
                'message' => '服务器内部错误，请稍后重试'
            ], JSON_UNESCAPED_UNICODE);
        }
        exit(1);
    }
});

// 设置异常处理函数
set_exception_handler(function($exception) {
    $errorMsg = "Uncaught Exception: " . $exception->getMessage() . " in " . $exception->getFile() . " on line " . $exception->getLine();
    error_log($errorMsg);
    
    // 清理输出缓冲区
    if (ob_get_level()) {
        ob_clean();
    }
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success' => false,
            'message' => '服务器内部错误，请稍后重试'
        ], JSON_UNESCAPED_UNICODE);
    }
    exit(1);
});

/**
 * 闲鱼图床上传处理脚本
 * 
 * 功能：
 * - 接收前端上传的图片文件
 * - 转发到闲鱼API进行上传
 * - 返回上传结果
 */

try {
    require_once 'config.php';
} catch (Exception $e) {
    error_log("Config file error: " . $e->getMessage());
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'success' => false,
        'message' => '配置文件加载失败'
    ], JSON_UNESCAPED_UNICODE);
    exit(1);
}

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// 处理预检请求
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

// 只允许POST请求
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    respondWithError('只允许POST请求');
}

// IP白名单检查
if (ENABLE_IP_WHITELIST && !in_array($_SERVER['REMOTE_ADDR'], IP_WHITELIST)) {
    respondWithError(getErrorMessage('ip_blocked'));
}

// 频率限制检查
if (ENABLE_RATE_LIMIT && !checkRateLimit()) {
    respondWithError(getErrorMessage('rate_limit'));
}

// 主要处理逻辑包装在try-catch中
try {
    // 检查是否有文件上传 - 支持多文件上传
    if (!isset($_FILES['files']) || empty($_FILES['files']['name'][0])) {
        respondWithError(getErrorMessage('no_file'));
    }

    // 获取分类信息
    $category = isset($_POST['category']) ? trim($_POST['category']) : '未分类';
    if (empty($category)) {
        $category = '未分类';
    }

    // 获取格式转换选项
    $format = isset($_POST['format']) ? trim($_POST['format']) : 'original';
    if (!in_array($format, ['original', 'webp', 'avif'])) {
        $format = 'original';
    }
    
    // 获取上传平台选项
    $platform = isset($_POST['platform']) ? trim($_POST['platform']) : 'goofish';
    if (!in_array($platform, ['goofish', 'baidu'])) {
        $platform = 'goofish';
    }

    $files = $_FILES['files'];
    $uploadResults = [];

// 处理多个文件
for ($i = 0; $i < count($files['name']); $i++) {
    if ($files['error'][$i] !== UPLOAD_ERR_OK) {
        $uploadResults[] = [
            'success' => false,
            'fileName' => $files['name'][$i],
            'message' => '文件上传错误'
        ];
        continue;
    }
    
    $file = [
        'name' => $files['name'][$i],
        'type' => $files['type'][$i],
        'tmp_name' => $files['tmp_name'][$i],
        'size' => $files['size'][$i]
    ];
    
    // 验证文件类型
    if (!in_array($file['type'], ALLOWED_TYPES)) {
        $uploadResults[] = [
            'success' => false,
            'fileName' => $file['name'],
            'message' => getErrorMessage('invalid_type')
        ];
        continue;
    }
    
    // 验证文件大小
    if ($file['size'] > MAX_FILE_SIZE) {
        $uploadResults[] = [
            'success' => false,
            'fileName' => $file['name'],
            'message' => getErrorMessage('file_too_large')
        ];
        continue;
    }
    
    // 自动重命名文件
    $file['name'] = generateNewFileName($file['name']);
    
    // 图片压缩处理 - 当文件大小超过8MB时自动压缩
    if ($file['size'] > 8 * 1024 * 1024) {
        $compressResult = compressImage($file, 8 * 1024 * 1024);
        if ($compressResult['success']) {
            $file = $compressResult['file'];
            if (ENABLE_LOGGING) {
                logMessage("图片压缩完成: {$file['name']}, 原大小: " . formatFileSize($compressResult['originalSize']) . ", 压缩后: " . formatFileSize($file['size']));
            }
        } else {
            $uploadResults[] = [
                'success' => false,
                'fileName' => $file['name'],
                'message' => $compressResult['message']
            ];
            continue;
        }
    }
    
    // 格式转换处理
    if ($format !== 'original') {
        $convertResult = convertImageFormat($file, $format);
        if ($convertResult['success']) {
            $file = $convertResult['file'];
        } else {
            $uploadResults[] = [
                'success' => false,
                'fileName' => $file['name'],
                'message' => $convertResult['message']
            ];
            continue;
        }
    }
    
    // 检查缓存
    $fileHash = getFileHash($file['tmp_name']);
    $cachedResult = getCachedResult($fileHash);
    if ($cachedResult) {
        $uploadResults[] = [
            'success' => true,
            'data' => $cachedResult,
            'cached' => true
        ];
        continue;
    }
    
    // 记录上传日志
    if (ENABLE_LOGGING) {
        logMessage("文件上传开始: {$file['name']}, 大小: " . formatFileSize($file['size']) . ", IP: {$_SERVER['REMOTE_ADDR']}");
    }
    
    // 根据选择的平台上传文件
    if ($platform === 'baidu') {
        // 上传到百度贴吧
        $result = uploadToBaidu($file);
    } else {
        // 上传到闲鱼
        $result = uploadToGoofish($file);
    }
    
    if ($result['success']) {
        // 保存到缓存
        saveCachedResult($fileHash, $result['data']);
        
        // 保存到画廊JSON
        saveToGallery($result['data'], $category);
        
        // 记录日志
        if (ENABLE_LOGGING) {
            logMessage("文件上传成功: {$result['data']['fileName']}, 结果: " . json_encode($result, JSON_UNESCAPED_UNICODE));
        }
        
        // 重新读取画廊数据并添加到结果中
        $galleryData = [];
        if (file_exists('gallery.json')) {
            $galleryContent = file_get_contents('gallery.json');
            $galleryData = json_decode($galleryContent, true) ?: [];
        }
        
        // 更新结果中的画廊数据
        $result['gallery'] = $galleryData;
        $uploadResults[] = $result;
    } else {
        if (ENABLE_LOGGING) {
            logMessage("文件上传失败: {$file['name']}, 错误: {$result['message']}");
        }
        
        $uploadResults[] = [
            'success' => false,
            'fileName' => $file['name'],
            'message' => $result['message']
        ];
    }
}

    // 清理输出缓冲区，确保只输出JSON响应
    if (ob_get_level()) {
        ob_clean();
    }
    
    // 获取最新的画廊数据
    $galleryData = [];
    if (file_exists('gallery.json')) {
        $galleryContent = file_get_contents('gallery.json');
        $galleryData = json_decode($galleryContent, true) ?: [];
    }
    
    // 返回上传结果
    if (count($uploadResults) === 1) {
        // 单文件上传，返回简化格式，并附带最新gallery.json内容
        $result = $uploadResults[0];
        if ($result['success']) {
            echo json_encode([
                'success' => true,
                'message' => '上传成功',
                'data' => $result['data'],
                'gallery' => $galleryData
            ], JSON_UNESCAPED_UNICODE);
        } else {
            echo json_encode([
                'success' => false,
                'message' => $result['message'],
                'gallery' => $galleryData
            ], JSON_UNESCAPED_UNICODE);
        }
    } else {
        // 多文件上传，返回详细格式，并附带最新gallery.json内容
        echo json_encode([
            'success' => true,
            'results' => $uploadResults,
            'total' => count($uploadResults),
            'successful' => count(array_filter($uploadResults, function($r) { return $r['success']; })),
            'gallery' => $galleryData
        ], JSON_UNESCAPED_UNICODE);
    }

} catch (Exception $e) {
    // 捕获所有异常并返回标准错误响应
    $errorMsg = "Upload processing error: " . $e->getMessage() . " in " . $e->getFile() . " on line " . $e->getLine();
    error_log($errorMsg);
    
    // 清理输出缓冲区
    if (ob_get_level()) {
        ob_clean();
    }
    
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
    }
    
    echo json_encode([
        'success' => false,
        'message' => '文件处理过程中发生错误，请稍后重试'
    ], JSON_UNESCAPED_UNICODE);
    exit(1);
} catch (Error $e) {
    // 捕获PHP 7+ 的Error类型错误
    $errorMsg = "Upload processing fatal error: " . $e->getMessage() . " in " . $e->getFile() . " on line " . $e->getLine();
    error_log($errorMsg);
    
    // 清理输出缓冲区
    if (ob_get_level()) {
        ob_clean();
    }
    
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
    }
    
    echo json_encode([
        'success' => false,
        'message' => '服务器内部错误，请稍后重试'
    ], JSON_UNESCAPED_UNICODE);
    exit(1);
}

/**
 * 上传文件到闲鱼API
 * @param array $file 文件信息
 * @return array 上传结果
 */
function uploadToGoofish($file) {
    // 准备上传数据
    $boundary = '----WebKitFormBoundary' . uniqid();
    $postData = '';
    
    // 添加文件数据
    $postData .= "--{$boundary}\r\n";
    $postData .= 'Content-Disposition: form-data; name="file"; filename="' . basename($file['name']) . '"' . "\r\n";
    $postData .= 'Content-Type: ' . $file['type'] . "\r\n\r\n";
    $postData .= file_get_contents($file['tmp_name']);
    $postData .= "\r\n--{$boundary}--\r\n";
    
    // 准备请求头
    $headers = [
        'Content-Type: multipart/form-data; boundary=' . $boundary,
        'Accept: application/json, text/plain, */*',
        'Accept-Language: zh-CN,zh;q=0.9,en;q=0.8',
        'Origin: https://author.goofish.com',
        'Referer: https://author.goofish.com/',
        'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/139.0.0.0 Safari/537.36',
        'Cookie: cookie2=' . COOKIE2_VALUE,
        'Content-Length: ' . strlen($postData)
    ];
    
    // 发送请求到闲鱼API
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => GOOFISH_UPLOAD_URL . '?_input_charset=utf-8&appkey=fleamarket',
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $postData,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/139.0.0.0 Safari/537.36'
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    
    // 检查请求是否成功
    if ($error) {
        return [
            'success' => false,
            'message' => getErrorMessage('curl_error') . ': ' . $error
        ];
    }
    
    if ($httpCode !== 200) {
        return [
            'success' => false,
            'message' => getErrorMessage('http_error') . ': ' . $httpCode,
            'response' => $response
        ];
    }
    
    // 解析响应
    $responseData = json_decode($response, true);
    
    if (!$responseData) {
        return [
            'success' => false,
            'message' => getErrorMessage('parse_error'),
            'response' => $response
        ];
    }
    
    // 检查闲鱼API响应
    if (!isset($responseData['success']) || $responseData['success'] !== true) {
        return [
            'success' => false,
            'message' => getErrorMessage('api_error'),
            'response' => $responseData
        ];
    }
    
    // 提取上传结果
    if (isset($responseData['object']) && is_array($responseData['object'])) {
        $object = $responseData['object'];
        
        // 确保必要字段存在
        if (!isset($object['url'])) {
            return [
                'success' => false,
                'message' => getErrorMessage('format_error'),
                'response' => $responseData
            ];
        }
        
        // 格式化文件大小
        $size = isset($object['size']) ? intval($object['size']) : $file['size'];
        $sizeFormatted = formatFileSize($size);
        
        return [
            'success' => true,
            'message' => '上传成功',
            'data' => [
                'url' => $object['url'],
                'fileName' => isset($object['fileName']) ? $object['fileName'] : pathinfo($file['name'], PATHINFO_FILENAME),
                'size' => $sizeFormatted,
                'pix' => isset($object['pix']) ? $object['pix'] : '未知',
                'fileId' => isset($object['fileId']) ? $object['fileId'] : '',
                'quality' => isset($object['quality']) ? intval($object['quality']) : 100
            ]
        ];
    } else {
        return [
            'success' => false,
            'message' => getErrorMessage('format_error'),
            'response' => $responseData
        ];
    }
}

/**
 * 上传文件到百度贴吧
 * @param array $file 文件信息
 * @return array 上传结果
 */
function uploadToBaidu($file) {
    try {
        // 创建百度上传实例
        $uploader = new BaiduImageUploader();
        
        // 设置cookie（从配置中获取，如果有的话）
        if (defined('BAIDU_COOKIE') && !empty(BAIDU_COOKIE)) {
            $uploader->setCookie(BAIDU_COOKIE);
        }
        
        // 使用临时文件进行上传
        $tempFile = $file['tmp_name'];
        $fileName = $file['name'];
        
        // 执行上传
        $result = $uploader->uploadImage($tempFile, $fileName);
        
        // 检查上传结果
        if ($result['success']) {
            // 获取图片URL
            $imageUrl = $uploader->getImageUrl($result);
            
            if ($imageUrl) {
                // 格式化文件大小
                $sizeFormatted = formatFileSize($file['size']);
                
                return [
                    'success' => true,
                    'message' => '上传成功',
                    'data' => [
                        'url' => $imageUrl,
                        'fileName' => pathinfo($fileName, PATHINFO_FILENAME),
                        'size' => $sizeFormatted,
                        'pix' => '未知', // 百度API不返回图片尺寸信息
                        'fileId' => '', // 百度API不返回文件ID
                        'quality' => 100
                    ]
                ];
            } else {
                return [
                    'success' => false,
                    'message' => '无法从响应中提取图片URL',
                    'response' => $result
                ];
            }
        } else {
            return [
                'success' => false,
                'message' => '上传失败: ' . ($result['error'] ?? '未知错误'),
                'response' => $result
            ];
        }
    } catch (Exception $e) {
        return [
            'success' => false,
            'message' => '上传过程中发生异常: ' . $e->getMessage()
        ];
    }
}

/**
 * 返回错误响应并退出
 * @param string $message 错误消息
 */
function respondWithError($message) {
    // 清理输出缓冲区
    if (ob_get_level()) {
        ob_clean();
    }
    
    echo json_encode([
        'success' => false,
        'message' => $message
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * 检查频率限制
 * @return bool 是否通过频率限制检查
 */
function checkRateLimit() {
    if (!ENABLE_RATE_LIMIT) {
        return true;
    }
    
    $ip = $_SERVER['REMOTE_ADDR'];
    $cacheFile = sys_get_temp_dir() . '/goofish_rate_limit_' . md5($ip);
    
    $now = time();
    $requests = [];
    
    // 读取现有请求记录
    if (file_exists($cacheFile)) {
        $data = file_get_contents($cacheFile);
        $requests = json_decode($data, true) ?: [];
    }
    
    // 清理过期记录（超过1分钟）
    $requests = array_filter($requests, function($timestamp) use ($now) {
        return ($now - $timestamp) < 60;
    });
    
    // 检查是否超过限制
    if (count($requests) >= RATE_LIMIT_REQUESTS) {
        return false;
    }
    
    // 添加当前请求
    $requests[] = $now;
    
    // 保存到缓存文件
    file_put_contents($cacheFile, json_encode($requests), LOCK_EX);
    
    return true;
}

/**
 * 格式化文件大小
 * @param int $size 文件大小（字节）
 * @return string 格式化后的大小
 */
function formatFileSize($size) {
    $size = intval($size);
    
    if ($size < 1024) {
        return $size . ' B';
    } elseif ($size < 1024 * 1024) {
        return round($size / 1024, 2) . ' KB';
    } elseif ($size < 1024 * 1024 * 1024) {
        return round($size / (1024 * 1024), 2) . ' MB';
    } else {
        return round($size / (1024 * 1024 * 1024), 2) . ' GB';
    }
}

/**
 * 记录日志
 * @param string $message 日志消息
 */
function logMessage($message) {
    if (!ENABLE_LOGGING) {
        return;
    }
    
    $logFile = LOG_FILE;
    $timestamp = date('Y-m-d H:i:s');
    $logEntry = "[{$timestamp}] {$message}" . PHP_EOL;
    
    // 确保日志目录存在
    $logDir = dirname($logFile);
    if (!is_dir($logDir)) {
        mkdir($logDir, 0755, true);
    }
    
    file_put_contents($logFile, $logEntry, FILE_APPEND | LOCK_EX);
}

/**
 * 获取文件MD5哈希值（用于缓存）
 * @param string $filePath 文件路径
 * @return string MD5哈希值
 */
function getFileHash($filePath) {
    return md5_file($filePath);
}

/**
 * 检查文件是否已经上传过（缓存功能）
 * @param string $fileHash 文件哈希值
 * @return array|false 如果找到缓存则返回结果，否则返回false
 */
function getCachedResult($fileHash) {
    if (!ENABLE_CACHE) {
        return false;
    }
    
    $cacheFile = CACHE_DIR . $fileHash . '.json';
    
    if (file_exists($cacheFile)) {
        $data = file_get_contents($cacheFile);
        $result = json_decode($data, true);
        
        if ($result && isset($result['timestamp'])) {
            // 检查缓存是否过期（24小时）
            if ((time() - $result['timestamp']) < 86400) {
                return $result['data'];
            } else {
                // 删除过期缓存
                unlink($cacheFile);
            }
        }
    }
    
    return false;
}

/**
 * 保存上传结果到缓存
 * @param string $fileHash 文件哈希值
 * @param array $result 上传结果
 */
function saveCachedResult($fileHash, $result) {
    if (!ENABLE_CACHE) {
        return;
    }
    
    $cacheFile = CACHE_DIR . $fileHash . '.json';
    $cacheData = [
        'timestamp' => time(),
        'data' => $result
    ];
    
    file_put_contents($cacheFile, json_encode($cacheData), LOCK_EX);
}

/**
 * 生成新的文件名（自动重命名）
 * @param string $originalName 原始文件名
 * @return string 新文件名
 */
function generateNewFileName($originalName) {
    $extension = pathinfo($originalName, PATHINFO_EXTENSION);
    $timestamp = date('YmdHis');
    $random = substr(md5(uniqid()), 0, 6);
    return "img_{$timestamp}_{$random}.{$extension}";
}

/**
 * 保存上传记录到画廊JSON文件
 * @param array $data 上传数据
 * @param string $category 分类信息
 */
function saveToGallery($data, $category = '未分类') {
    $galleryFile = 'gallery.json';
    $gallery = [];
    
    // 读取现有画廊数据
    if (file_exists($galleryFile)) {
        $content = file_get_contents($galleryFile);
        $gallery = json_decode($content, true) ?: [];
    }
    
    // 添加新记录
    $record = [
        'id' => uniqid(),
        'fileName' => $data['fileName'],
        'url' => $data['url'],
        'size' => $data['size'],
        'uploadTime' => date('Y-m-d H:i:s'),
        'category' => $category
    ];
    
    array_unshift($gallery, $record);
    
    // 限制记录数量（最多保存1000条）
    if (count($gallery) > 1000) {
        $gallery = array_slice($gallery, 0, 1000);
    }
    
    // 保存到文件
    $result = file_put_contents($galleryFile, json_encode($gallery, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX);
    
    // 检查是否成功写入
    if ($result === false) {
        error_log("无法写入画廊文件: $galleryFile");
    }
}

/**
 * 根据文件名获取分类
 * @param string $fileName 文件名
 * @return string 分类名称
 */
function getCategoryByFileName($fileName) {
    $extension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
    
    switch ($extension) {
        case 'jpg':
        case 'jpeg':
            return 'JPEG图片';
        case 'png':
            return 'PNG图片';
        case 'gif':
            return 'GIF动图';
        case 'webp':
            return 'WebP图片';
        case 'bmp':
            return 'BMP图片';
        default:
            return '其他图片';
    }
}

/**
 * 转换图片格式
 * @param array $file 文件信息
 * @param string $targetFormat 目标格式 (webp|avif)
 * @return array 转换结果
 */
function convertImageFormat($file, $targetFormat) {
    // 检查GD扩展
    if (!extension_loaded('gd')) {
        return [
            'success' => false,
            'message' => 'GD扩展未安装，无法进行格式转换'
        ];
    }
    
    // 检查目标格式支持
    if ($targetFormat === 'webp' && !function_exists('imagewebp')) {
        return [
            'success' => false,
            'message' => '当前PHP版本不支持WebP格式转换'
        ];
    }
    
    if ($targetFormat === 'avif' && !function_exists('imageavif')) {
        return [
            'success' => false,
            'message' => '当前PHP版本不支持AVIF格式转换'
        ];
    }
    
    try {
        // 根据原始格式创建图像资源
        $sourceImage = null;
        $imageInfo = getimagesize($file['tmp_name']);
        
        if (!$imageInfo) {
            return [
                'success' => false,
                'message' => '无法读取图片信息'
            ];
        }
        
        switch ($imageInfo[2]) {
            case IMAGETYPE_JPEG:
                $sourceImage = imagecreatefromjpeg($file['tmp_name']);
                break;
            case IMAGETYPE_PNG:
                $sourceImage = imagecreatefrompng($file['tmp_name']);
                // 保持PNG透明度
                imagealphablending($sourceImage, false);
                imagesavealpha($sourceImage, true);
                break;
            case IMAGETYPE_GIF:
                $sourceImage = imagecreatefromgif($file['tmp_name']);
                break;
            case IMAGETYPE_WEBP:
                $sourceImage = imagecreatefromwebp($file['tmp_name']);
                break;
            default:
                return [
                    'success' => false,
                    'message' => '不支持的图片格式'
                ];
        }
        
        if (!$sourceImage) {
            return [
                'success' => false,
                'message' => '无法创建图像资源'
            ];
        }
        
        // 创建临时文件
        $tempFile = tempnam(sys_get_temp_dir(), 'converted_');
        $success = false;
        
        // 转换格式
        switch ($targetFormat) {
            case 'webp':
                $success = imagewebp($sourceImage, $tempFile, 85); // 85%质量
                $newExtension = 'webp';
                $newMimeType = 'image/webp';
                break;
            case 'avif':
                $success = imageavif($sourceImage, $tempFile, 85); // 85%质量
                $newExtension = 'avif';
                $newMimeType = 'image/avif';
                break;
        }
        
        // 释放内存
        imagedestroy($sourceImage);
        
        if (!$success) {
            if (file_exists($tempFile)) {
                unlink($tempFile);
            }
            return [
                'success' => false,
                'message' => '格式转换失败'
            ];
        }
        
        // 更新文件信息
        $pathInfo = pathinfo($file['name']);
        $newFileName = $pathInfo['filename'] . '.' . $newExtension;
        
        return [
            'success' => true,
            'file' => [
                'name' => $newFileName,
                'type' => $newMimeType,
                'tmp_name' => $tempFile,
                'size' => filesize($tempFile)
            ]
        ];
        
    } catch (Exception $e) {
        return [
            'success' => false,
            'message' => '格式转换异常: ' . $e->getMessage()
        ];
    }
}

/**
 * 压缩图片到指定大小
 * @param array $file 文件信息
 * @param int $maxSize 最大文件大小（字节）
 * @return array 压缩结果
 */
function compressImage($file, $maxSize) {
    // 检查GD扩展
    if (!extension_loaded('gd')) {
        return [
            'success' => false,
            'message' => 'GD扩展未安装，无法进行图片压缩'
        ];
    }
    
    try {
        // 获取图片信息
        $imageInfo = getimagesize($file['tmp_name']);
        if (!$imageInfo) {
            return [
                'success' => false,
                'message' => '无法读取图片信息'
            ];
        }
        
        // 创建图像资源
        $sourceImage = null;
        switch ($imageInfo[2]) {
            case IMAGETYPE_JPEG:
                $sourceImage = imagecreatefromjpeg($file['tmp_name']);
                break;
            case IMAGETYPE_PNG:
                $sourceImage = imagecreatefrompng($file['tmp_name']);
                // 保持PNG透明度
                imagealphablending($sourceImage, false);
                imagesavealpha($sourceImage, true);
                break;
            case IMAGETYPE_GIF:
                $sourceImage = imagecreatefromgif($file['tmp_name']);
                break;
            case IMAGETYPE_WEBP:
                $sourceImage = imagecreatefromwebp($file['tmp_name']);
                break;
            default:
                return [
                    'success' => false,
                    'message' => '不支持的图片格式，无法压缩'
                ];
        }
        
        if (!$sourceImage) {
            return [
                'success' => false,
                'message' => '无法创建图像资源'
            ];
        }
        
        $originalSize = $file['size'];
        $width = imagesx($sourceImage);
        $height = imagesy($sourceImage);
        
        // 如果文件已经小于等于目标大小，直接返回
        if ($originalSize <= $maxSize) {
            imagedestroy($sourceImage);
            return [
                'success' => true,
                'file' => $file,
                'originalSize' => $originalSize
            ];
        }
        
        // 压缩策略：先尝试降低质量，如果还是太大则缩小尺寸
        $quality = 85;
        $scaleFactor = 1.0;
        $attempts = 0;
        $maxAttempts = 10;
        
        do {
            $attempts++;
            
            // 计算新尺寸
            $newWidth = (int)($width * $scaleFactor);
            $newHeight = (int)($height * $scaleFactor);
            
            // 创建新图像
            $compressedImage = imagecreatetruecolor($newWidth, $newHeight);
            
            // 处理PNG透明度
            if ($imageInfo[2] === IMAGETYPE_PNG) {
                imagealphablending($compressedImage, false);
                imagesavealpha($compressedImage, true);
                $transparent = imagecolorallocatealpha($compressedImage, 255, 255, 255, 127);
                imagefill($compressedImage, 0, 0, $transparent);
            }
            
            // 缩放图像
            imagecopyresampled($compressedImage, $sourceImage, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);
            
            // 创建临时文件
            $tempFile = tempnam(sys_get_temp_dir(), 'compressed_');
            $success = false;
            
            // 根据原始格式保存
            switch ($imageInfo[2]) {
                case IMAGETYPE_JPEG:
                    $success = imagejpeg($compressedImage, $tempFile, $quality);
                    break;
                case IMAGETYPE_PNG:
                    // PNG压缩级别 0-9，9为最高压缩
                    $pngQuality = (int)(9 - ($quality / 100) * 9);
                    $success = imagepng($compressedImage, $tempFile, $pngQuality);
                    break;
                case IMAGETYPE_GIF:
                    $success = imagegif($compressedImage, $tempFile);
                    break;
                case IMAGETYPE_WEBP:
                    $success = imagewebp($compressedImage, $tempFile, $quality);
                    break;
            }
            
            imagedestroy($compressedImage);
            
            if (!$success) {
                if (file_exists($tempFile)) {
                    unlink($tempFile);
                }
                imagedestroy($sourceImage);
                return [
                    'success' => false,
                    'message' => '图片压缩失败'
                ];
            }
            
            $compressedSize = filesize($tempFile);
            
            // 如果压缩后大小满足要求，返回结果
            if ($compressedSize <= $maxSize) {
                imagedestroy($sourceImage);
                return [
                    'success' => true,
                    'file' => [
                        'name' => $file['name'],
                        'type' => $file['type'],
                        'tmp_name' => $tempFile,
                        'size' => $compressedSize
                    ],
                    'originalSize' => $originalSize
                ];
            }
            
            // 删除临时文件，准备下一次尝试
            unlink($tempFile);
            
            // 调整压缩参数
            if ($quality > 30) {
                $quality -= 10; // 降低质量
            } else {
                $scaleFactor *= 0.9; // 缩小尺寸
                $quality = 85; // 重置质量
            }
            
        } while ($attempts < $maxAttempts && $scaleFactor > 0.3);
        
        // 如果所有尝试都失败了，返回错误
        imagedestroy($sourceImage);
        return [
            'success' => false,
            'message' => '无法将图片压缩到指定大小，请尝试上传更小的图片'
        ];
        
    } catch (Exception $e) {
        return [
            'success' => false,
            'message' => '图片压缩异常: ' . $e->getMessage()
        ];
    }
}
?>