<?php

/**
 * 百度贴吧图片上传类
 * 基于抓包数据分析实现
 */
class BaiduImageUploader {
    
    private $tbs;
    private $cookie;
    
    public function __construct($tbs = "221449b3c4fb22b0017608870920125500_1") {
        $this->tbs = $tbs;
        // 注意：实际使用时需要设置有效的百度贴吧cookie
        $this->cookie = "";
    }
    
    /**
     * 设置cookie
     * @param string $cookie 百度贴吧cookie
     */
    public function setCookie($cookie) {
        $this->cookie = $cookie;
    }
    
    /**
     * 上传图片到百度贴吧
     * @param string $imagePath 本地图片路径
     * @param string $filename 上传后的文件名
     * @return array 上传结果
     */
    public function uploadImage($imagePath, $filename = null) {
        // 检查文件是否存在
        if (!file_exists($imagePath)) {
            return [
                'success' => false,
                'error' => '文件不存在'
            ];
        }
        
        // 检查是否为图片文件
        $imageInfo = getimagesize($imagePath);
        if (!$imageInfo) {
            return [
                'success' => false,
                'error' => '不是有效的图片文件'
            ];
        }
        
        // 读取图片内容
        $imageData = file_get_contents($imagePath);
        if ($imageData === false) {
            return [
                'success' => false,
                'error' => '无法读取图片文件'
            ];
        }
        
        // 生成文件名
        if ($filename === null) {
            $filename = basename($imagePath);
        }
        
        // 生成boundary
        $boundary = '----WebKitFormBoundary' . substr(str_shuffle('0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ'), 0, 16);
        
        // 构造multipart/form-data body
        $body = "--{$boundary}\r\n";
        $body .= "Content-Disposition: form-data; name=\"file\"; filename=\"{$filename}\"\r\n";
        $body .= "Content-Type: " . $imageInfo['mime'] . "\r\n\r\n";
        $body .= $imageData;
        $body .= "\r\n--{$boundary}--\r\n";
        
        // 构造请求URL (根据抓包日志中的URL)
        $url = "https://uploadphotos.baidu.com/upload/pic";
        $params = [
            'tbs' => $this->tbs,
            'fid' => '',
            'save_yun_album' => '0',
            'is_new_imgsys' => '1'
        ];
        
        // 构造完整URL
        $url .= '?' . http_build_query($params);
        
        // 设置请求头 (根据抓包日志中的请求头)
        $headers = [
            'Accept: */*',
            'Accept-Encoding: gzip, deflate, br, zstd',
            'Accept-Language: zh-CN,zh;q=0.9,en;q=0.8,en-GB;q=0.7,en-US;q=0.6',
            'Connection: keep-alive',
            'Content-Type: multipart/form-data; boundary=' . $boundary,
            'Content-Length: ' . strlen($body),
            'Host: uploadphotos.baidu.com',
            'Origin: https://tieba.baidu.com',
            'Referer: https://tieba.baidu.com/',
            'Sec-Fetch-Dest: empty',
            'Sec-Fetch-Mode: cors',
            'Sec-Fetch-Site: same-site',
            'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36 Edg/141.0.0.0',
            'sec-ch-ua: "Microsoft Edge";v="141", "Not?A_Brand";v="8", "Chromium";v="141"',
            'sec-ch-ua-mobile: ?0',
            'sec-ch-ua-platform: "Windows"'
        ];
        
        // 如果设置了cookie，添加到请求头
        if (!empty($this->cookie)) {
            $headers[] = 'Cookie: ' . $this->cookie;
        }
        
        // 发送请求
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36 Edg/141.0.0.0'
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        // 检查请求是否成功
        if ($error) {
            return [
                'success' => false,
                'error' => '请求错误: ' . $error
            ];
        }
        
        if ($httpCode !== 200) {
            return [
                'success' => false,
                'error' => 'HTTP错误: ' . $httpCode,
                'response' => $response
            ];
        }
        
        // 解析响应
        $responseData = json_decode($response, true);
        
        if (!$responseData) {
            return [
                'success' => false,
                'error' => '响应解析失败',
                'response' => $response
            ];
        }
        
        // 检查响应中的错误码
        if (isset($responseData['err_no']) && $responseData['err_no'] === 0) {
            // 上传成功
            return [
                'success' => true,
                'data' => $responseData['info']
            ];
        } else {
            // 上传失败
            $errorMsg = isset($responseData['err_msg']) ? $responseData['err_msg'] : '上传失败';
            return [
                'success' => false,
                'error' => $errorMsg,
                'response' => $responseData
            ];
        }
    }
    
    /**
     * 从上传结果中获取图片URL
     * @param array $uploadResult 上传结果
     * @return string|null 图片URL
     */
    public function getImageUrl($uploadResult) {
        if (!$uploadResult['success']) {
            return null;
        }
        
        $info = $uploadResult['data'];
        // 根据用户需求，优先返回带鉴权参数的图片URL
        // 用户指出应提取这一行的图片url: https://tiebapic.baidu.com/tieba/pic/item/5243fbf2b2119313ca236fa723380cd791238d41.jpg?tbpicau=2025-10-21-05_414f96838e928c651f8a05ce998fd03d
        return isset($info['pic_url_auth']) ? $info['pic_url_auth'] : 
               (isset($info['pic_url_no_auth']) ? $info['pic_url_no_auth'] : null);
    }
}