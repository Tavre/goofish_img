<?php

/**
 * 葫芦侠图片上传类
 * 基于葫芦侠接口源代码实现
 */
class HuluxiaImageUploader
{

    // 常量定义
    const HULUXIA_HOST_UPLOAD = 'http://upload.huluxia.com';
    const APP_VERSION = '4.3.0.9';
    const VERSION_CODE = '20141506';
    const PLATFORM = '2';
    const GKEY = '000000';
    const MARKET_ID = 'floor_web';
    const USER_AGENT = 'okhttp/3.8.1';
    const SIGN_SECRET = 'dc9ae0b1c8bae7ccf421cd1607bc3b14';
    const DEVICE_CODE = '[d]59bd83fb-f941-4230-b1b4-6f81284029fd';
    const USE_TYPE = '8';

    private $config;

    public function __construct()
    {
        // 加载配置文件
        $configFile = __DIR__ . '/hulu/config.php';
        if (file_exists($configFile)) {
            require $configFile;
            // $config 变量来自 require 的文件
            $this->config = isset($config) ? $config : [];
        } else {
            $this->config = [];
        }
    }

    /**
     * 上传图片到葫芦侠
     * @param array $file 文件信息数组 (tmp_name, name, type, size)
     * @return array 上传结果
     */
    public function uploadImage($file)
    {
        // 检查配置
        $key = isset($this->config['key']) ? $this->config['key'] : '';
        if (empty($key)) {
            return [
                'success' => false,
                'message' => '未配置葫芦侠 Key，请检查 hulu/config.php'
            ];
        }

        // 检查文件有效性
        if (!isset($file['tmp_name']) || !file_exists($file['tmp_name'])) {
            return [
                'success' => false,
                'message' => '文件不存在或无效'
            ];
        }

        $mime = $this->getMimeType($file['tmp_name']);
        $name = isset($file['name']) ? $file['name'] : 'image.jpg';

        // 检查文件类型
        if (!$this->isAllowedFile('image', $mime, $name)) {
            return [
                'success' => false,
                'message' => '不支持的文件类型'
            ];
        }

        // 准备上传参数
        $nonce = bin2hex(openssl_random_pseudo_bytes(12));
        $ts = (string) (int) (microtime(true) * 1000);

        $query = array(
            'platform' => self::PLATFORM,
            'gkey' => self::GKEY,
            'app_version' => self::APP_VERSION,
            'versioncode' => self::VERSION_CODE,
            'market_id' => self::MARKET_ID,
            '_key' => $key,
            'device_code' => self::DEVICE_CODE,
            'use_type' => self::USE_TYPE,
            'timestamp' => $ts,
            'nonce_str' => $nonce
        );

        $query['sign'] = $this->signUpload($query);
        $endpoint = self::HULUXIA_HOST_UPLOAD . '/upload/v4/image?' . http_build_query($query);

        // 准备文件字段
        if (function_exists('curl_file_create')) {
            $fileField = curl_file_create($file['tmp_name'], $mime, basename($name));
        } else {
            $fileField = '@' . realpath($file['tmp_name']) . ';type=' . $mime . ';filename=' . basename($name);
        }

        $fields = array('_key' => 'key_10', 'file' => $fileField);

        // 发送请求
        $resp = $this->httpPostMultipart($endpoint, $fields);

        if (!$resp['ok']) {
            return [
                'success' => false,
                'message' => '上传请求失败: ' . $resp['error']
            ];
        }

        // 解析响应
        $json = json_decode(isset($resp['body']) ? $resp['body'] : '', true);
        if (!is_array($json)) {
            return [
                'success' => false,
                'message' => '上传响应解析失败',
                'response' => $resp['body']
            ];
        }

        if (!isset($json['status']) || (int) $json['status'] !== 1 || empty($json['url'])) {
            $msg = isset($json['msg']) ? $json['msg'] : '上传失败';
            $code = isset($json['code']) ? $json['code'] : 0;
            return [
                'success' => false,
                'message' => "上传失败: {$msg} (code={$code})",
                'response' => $json
            ];
        }

        // 成功返回
        return [
            'success' => true,
            'message' => '上传成功',
            'data' => [
                'url' => $json['url'],
                'fileName' => pathinfo($name, PATHINFO_FILENAME),
                'size' => isset($json['size']) ? $this->formatFileSize($json['size']) : $this->formatFileSize($file['size']),
                'pix' => '未知', // 葫芦侠不返回尺寸
                'fileId' => isset($json['fid']) ? $json['fid'] : '',
                'quality' => 100
            ]
        ];
    }

    private function getMimeType($filepath)
    {
        if (function_exists('mime_content_type')) {
            $m = mime_content_type($filepath);
            if ($m)
                return $m;
        }
        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo) {
                $m = finfo_file($finfo, $filepath);
                finfo_close($finfo);
                if ($m)
                    return $m;
            }
        }
        return 'application/octet-stream';
    }

    private function isAllowedFile($type, $mime, $name)
    {
        $mime = strtolower((string) $mime);
        $ext = strtolower((string) pathinfo((string) $name, PATHINFO_EXTENSION));
        $imageMime = array('image/jpeg', 'image/jpg', 'image/pjpeg', 'image/png', 'image/x-png', 'image/gif', 'image/webp');

        if ($type === 'image') {
            if (in_array($mime, $imageMime, true))
                return true;
            if (strpos($mime, 'image/') === 0)
                return true;
            if (in_array($ext, array('jpg', 'jpeg', 'png', 'gif', 'webp'), true))
                return true;
            return false;
        }
        return false;
    }

    private function httpPostMultipart($url, $fields)
    {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $fields);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array('User-Agent: ' . self::USER_AGENT, 'Accept-Encoding: gzip'));
        curl_setopt($ch, CURLOPT_ENCODING, 'gzip');
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        $body = curl_exec($ch);
        $err = curl_error($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($err)
            return array('ok' => false, 'code' => $code, 'error' => $err);
        return array('ok' => true, 'code' => $code, 'body' => $body);
    }

    private function signUpload($params)
    {
        $order = array('_key', 'app_version', 'device_code', 'gkey', 'market_id', 'nonce_str', 'platform', 'timestamp', 'use_type', 'versioncode');
        $raw = '';
        foreach ($order as $k) {
            if (isset($params[$k]))
                $raw .= $k . $params[$k];
        }
        $raw .= self::SIGN_SECRET;
        return md5($raw);
    }

    private function formatFileSize($size)
    {
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
}
