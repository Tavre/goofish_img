<?php
const HULUXIA_HOST_API = 'https://floor.huluxia.com';
const APP_VERSION = '4.3.0.9';
const VERSION_CODE = '20141506';
const PLATFORM = '2';
const GKEY = '000000';
const MARKET_ID = 'floor_web';
const PHONE_BRAND_TYPE = 'UN';
const USER_AGENT = 'okhttp/3.8.1';
const SIGN_SECRET = 'dc9ae0b1c8bae7ccf421cd1607bc3b14';
const DEVICE_CODE = '[d]59bd83fb-f941-4230-b1b4-6f81284029fd';

require __DIR__ . '/config.php';

function out_task($data, $code = 200)
{
    if (!headers_sent()) {
        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');
    }
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function http_get_task($url, $headers = array())
{
    $ch = curl_init($url);
    $httpHeaders = array_merge(array('User-Agent: ' . USER_AGENT, 'Accept-Encoding: gzip'), $headers);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $httpHeaders);
    curl_setopt($ch, CURLOPT_ENCODING, 'gzip');
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
    curl_setopt($ch, CURLOPT_TIMEOUT, 20);
    $body = curl_exec($ch);
    $err = curl_error($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($err)
        return array('ok' => false, 'code' => $code, 'error' => $err);
    return array('ok' => true, 'code' => $code, 'body' => $body);
}

function http_post_form_task($url, $fields, $headers = array())
{
    $ch = curl_init($url);
    $httpHeaders = array('User-Agent: ' . USER_AGENT, 'Accept-Encoding: gzip', 'Content-Type: application/x-www-form-urlencoded');
    if (!empty($headers))
        $httpHeaders = array_merge($httpHeaders, $headers);
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($fields));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $httpHeaders);
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

function sign_login_task($account, $deviceCode, $passwordMd5)
{
    return md5('account' . $account . 'device_code' . $deviceCode . 'password' . $passwordMd5 . 'voice_code' . SIGN_SECRET);
}

function save_config_task($cfg, $taskCfg)
{
    // 保存到根目录的 config.php
    $rootConfigFile = __DIR__ . '/../config.php';

    if (!file_exists($rootConfigFile)) {
        error_log("根目录配置文件不存在: $rootConfigFile");
        return false;
    }

    // 读取根目录配置文件内容
    $configContent = file_get_contents($rootConfigFile);

    // 更新 HULUXIA_KEY
    if (isset($cfg['key'])) {
        $key = addslashes($cfg['key']);
        $configContent = preg_replace(
            "/define\s*\(\s*'HULUXIA_KEY'\s*,\s*'[^']*'\s*\)/",
            "define('HULUXIA_KEY', '$key')",
            $configContent
        );
    }

    // 更新 HULUXIA_UID
    if (isset($cfg['uid'])) {
        $uid = $cfg['uid'];
        $configContent = preg_replace(
            "/define\s*\(\s*'HULUXIA_UID'\s*,\s*'[^']*'\s*\)/",
            "define('HULUXIA_UID', '$uid')",
            $configContent
        );
    }

    // 写回根目录配置文件
    file_put_contents($rootConfigFile, $configContent);

    // 同时保存到本地 config.php (保持向后兼容)
    $localContent = "<?php\n/**\n * 葫芦侠接口配置文件\n * 从根目录的 config.php 读取配置信息\n */\n\n";
    $localContent .= "// 引入根目录的配置文件\n";
    $localContent .= "require_once __DIR__ . '/../config.php';\n\n";
    $localContent .= "// 从根目录配置中读取葫芦侠配置\n";
    $localContent .= '$config = ' . var_export($cfg, true) . ';' . "\n\n";
    $localContent .= "// task 配置保持不变,用于 task.php 的密钥验证\n";
    $localContent .= '$task = ' . var_export($taskCfg, true) . ';' . "\n";
    file_put_contents(__DIR__ . '/config.php', $localContent);

    return true;
}

function is_key_valid_task($key, $uid)
{
    if (!$key || !$uid)
        return false;
    $url = HULUXIA_HOST_API . "/user/info/ANDROID/4.1.8"
        . "?platform=" . PLATFORM
        . "&gkey=" . GKEY
        . "&app_version=" . APP_VERSION
        . "&versioncode=" . VERSION_CODE
        . "&market_id=" . MARKET_ID
        . "&_key=" . rawurlencode($key)
        . "&device_code=" . rawurlencode(DEVICE_CODE)
        . "&phone_brand_type=" . PHONE_BRAND_TYPE
        . "&user_id=" . (int) $uid;
    $resp = http_get_task($url);
    if (!$resp['ok'])
        return true;
    $json = json_decode(isset($resp['body']) ? $resp['body'] : '', true);
    if (is_array($json)) {
        if (isset($json['status']) && (int) $json['status'] === 1)
            return true;
        if (isset($json['code']) && (int) $json['code'] === 103)
            return false;
        return true;
    }
    return true;
}

function huluxia_login_task($phone, $password)
{
    $pwdMd5 = md5($password);
    $sign = sign_login_task($phone, DEVICE_CODE, $pwdMd5);
    $url = HULUXIA_HOST_API . "/account/login/ANDROID/4.2.4"
        . "?platform=" . PLATFORM
        . "&gkey=" . GKEY
        . "&app_version=" . APP_VERSION
        . "&versioncode=" . VERSION_CODE
        . "&market_id=" . MARKET_ID
        . "&_key="
        . "&device_code=" . rawurlencode(DEVICE_CODE)
        . "&phone_brand_type=" . PHONE_BRAND_TYPE;
    $resp = http_post_form_task($url, array('account' => $phone, 'login_type' => '2', 'password' => $pwdMd5, 'sign' => $sign));
    if (!$resp['ok'])
        return array('ok' => false, 'msg' => '登录请求失败: ' . $resp['error']);
    $json = json_decode(isset($resp['body']) ? $resp['body'] : '', true);
    if (!is_array($json) || !isset($json['status']) || (int) $json['status'] !== 1 || empty($json['_key'])) {
        $code = isset($json['code']) ? $json['code'] : 0;
        $msg = isset($json['msg']) ? $json['msg'] : '登录失败';
        return array('ok' => false, 'msg' => "登录失败: {$msg} (code={$code})");
    }
    $uid = isset($json['user']['userID']) ? (int) $json['user']['userID'] : null;
    return array('ok' => true, 'key' => $json['_key'], 'uid' => $uid);
}

$reqKey = isset($_GET['key']) ? $_GET['key'] : '';
if ($reqKey !== (isset($task['key']) ? $task['key'] : '')) {
    out_task(array('status' => 0, 'msg' => 'Forbidden: 任务密钥不正确'), 403);
}

$phone = isset($config['phone']) ? $config['phone'] : '';
$password = isset($config['password']) ? $config['password'] : '';
$key = isset($config['key']) ? $config['key'] : '';
$uid = isset($config['uid']) ? $config['uid'] : null;

$result = array('status' => 1, 'checked' => false, 'key_valid' => false, 'refreshed' => false, 'msg' => 'OK');

if ($key === '' || $uid === null) {
    $login = huluxia_login_task($phone, $password);
    if ($login['ok']) {
        $config['key'] = $login['key'];
        $config['uid'] = $login['uid'];
        save_config_task($config, $task);
        $result['refreshed'] = true;
        $result['key_valid'] = true;
        $result['msg'] = '已初始化登录并写入 key/uid 到根目录配置';
        out_task($result);
    } else {
        out_task(array('status' => 0, 'msg' => $login['msg']));
    }
}

$result['checked'] = true;
$result['key_valid'] = is_key_valid_task($key, $uid);

if ($result['key_valid'] === false) {
    $login = huluxia_login_task($phone, $password);
    if ($login['ok']) {
        $config['key'] = $login['key'];
        $config['uid'] = $login['uid'];
        save_config_task($config, $task);
        $result['refreshed'] = true;
        $result['key_valid'] = true;
        $result['msg'] = 'Key 已刷新并写入根目录配置';
    } else {
        $result['status'] = 0;
        $result['msg'] = isset($login['msg']) ? $login['msg'] : '刷新失败';
    }
}

out_task($result);