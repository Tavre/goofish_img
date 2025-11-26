<?php
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

require __DIR__ . '/config.php';

function out_api($data, $code = 200)
{
    if (!headers_sent()) {
        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');
    }
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function get_mime_api($filepath)
{
    if (function_exists('mime_content_type')) {
        $m = mime_content_type($filepath);
        if ($m) return $m;
    }
    if (function_exists('finfo_open')) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo) {
            $m = finfo_file($finfo, $filepath);
            finfo_close($finfo);
            if ($m) return $m;
        }
    }
    return 'application/octet-stream';
}

function is_allowed_file_api($type, $mime, $name)
{
    $mime = strtolower((string)$mime);
    $ext = strtolower((string)pathinfo((string)$name, PATHINFO_EXTENSION));
    $imageMime = array('image/jpeg', 'image/jpg', 'image/pjpeg', 'image/png', 'image/x-png', 'image/gif', 'image/webp');
    $videoMime = array('video/mp4', 'video/quicktime', 'video/x-msvideo', 'video/x-matroska');
    if ($type === 'image') {
        if (in_array($mime, $imageMime, true)) return true;
        if (strpos($mime, 'image/') === 0) return true;
        if (in_array($ext, array('jpg', 'jpeg', 'png', 'gif', 'webp'), true)) return true;
        return false;
    }
    if ($type === 'video') {
        if (in_array($mime, $videoMime, true)) return true;
        if (strpos($mime, 'video/') === 0) return true;
        if (in_array($ext, array('mp4', 'mov', 'avi', 'mkv'), true)) return true;
        return false;
    }
    return false;
}

function http_post_multipart_api($url, $fields)
{
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $fields);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, array('User-Agent: ' . USER_AGENT, 'Accept-Encoding: gzip'));
    curl_setopt($ch, CURLOPT_ENCODING, 'gzip');
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    $body = curl_exec($ch);
    $err = curl_error($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($err) return array('ok' => false, 'code' => $code, 'error' => $err);
    return array('ok' => true, 'code' => $code, 'body' => $body);
}

function sign_upload_api($params)
{
    $order = array('_key', 'app_version', 'device_code', 'gkey', 'market_id', 'nonce_str', 'platform', 'timestamp', 'use_type', 'versioncode');
    $raw = '';
    foreach ($order as $k) {
        if (isset($params[$k])) $raw .= $k . $params[$k];
    }
    $raw .= SIGN_SECRET;
    return md5($raw);
}

function do_upload_api($type)
{
    global $config;
    $type = strtolower((string)$type);
    if (!in_array($type, array('image', 'video'), true)) out_api(array('status' => 0, 'msg' => 'type 仅支持 image 或 video'), 400);
    if (!isset($_FILES['file']) || !isset($_FILES['file']['tmp_name']) || !is_uploaded_file($_FILES['file']['tmp_name'])) out_api(array('status' => 0, 'msg' => '未接收到文件或文件无效'), 400);
    $mime = get_mime_api($_FILES['file']['tmp_name']);
    $name = isset($_FILES['file']['name']) ? $_FILES['file']['name'] : '';
    if (!is_allowed_file_api($type, $mime, $name)) out_api(array('status' => 0, 'msg' => '非法的文件类型'), 415);
    $key = isset($config['key']) ? $config['key'] : '';
    if ($key === '') out_api(array('status' => 0, 'msg' => 'config.php 未配置可用的 _key；请先运行 task.php 刷新登录'), 500);
    $nonce = bin2hex(openssl_random_pseudo_bytes(12));
    $ts = (string)(int)(microtime(true) * 1000);
    $query = array(
        'platform' => PLATFORM,
        'gkey' => GKEY,
        'app_version' => APP_VERSION,
        'versioncode' => VERSION_CODE,
        'market_id' => MARKET_ID,
        '_key' => $key,
        'device_code' => DEVICE_CODE,
        'use_type' => USE_TYPE,
        'timestamp' => $ts,
        'nonce_str' => $nonce
    );
    $query['sign'] = sign_upload_api($query);
    $endpoint = HULUXIA_HOST_UPLOAD . '/upload/v4/' . $type . '?' . http_build_query($query);
    if (function_exists('curl_file_create')) {
        $fileField = curl_file_create($_FILES['file']['tmp_name'], $mime, basename($name));
    } else {
        $fileField = '@' . realpath($_FILES['file']['tmp_name']) . ';type=' . $mime . ';filename=' . basename($name);
    }
    $fields = array('_key' => 'key_10', 'file' => $fileField);
    $resp = http_post_multipart_api($endpoint, $fields);
    if (!$resp['ok']) out_api(array('status' => 0, 'msg' => '上传请求失败: ' . $resp['error']), 502);
    $json = json_decode(isset($resp['body']) ? $resp['body'] : '', true);
    if (!is_array($json)) out_api(array('status' => 0, 'msg' => '上传响应解析失败'), 502);
    if (!isset($json['status']) || (int)$json['status'] !== 1 || empty($json['url'])) {
        $msg = isset($json['msg']) ? $json['msg'] : '上传失败';
        $code = isset($json['code']) ? $json['code'] : 0;
        out_api(array('status' => 0, 'msg' => "上传失败: {$msg}", 'code' => $code), 400);
    }
    out_api(array('status' => 1, 'url' => $json['url'], 'size' => isset($json['size']) ? $json['size'] : null, 'fid' => isset($json['fid']) ? $json['fid'] : null, 'ts' => isset($json['ts']) ? $json['ts'] : null));
}

if (strtoupper($_SERVER['REQUEST_METHOD']) === 'POST') {
    $type = isset($_GET['type']) ? $_GET['type'] : '';
    do_upload_api($type);
}
out_api(array('status' => 0, 'msg' => '用法：POST /api.php?type=image|video，表单字段 file=...'), 405);