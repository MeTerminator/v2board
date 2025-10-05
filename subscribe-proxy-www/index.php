<?php

/*

location / {
    try_files $uri $uri/ /index.php?$query_string;
}

*/

// 获取当前请求的 URI 路径
$requestPath = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

/**
 * 路由：根路径 '/'
 * 当请求路径是 '/' 或 '/index.php' 时，执行重定向。
 */
if ($requestPath === '/' || $requestPath === '/index.php') {
    header('Location: https://dash.metc.uk/');
    exit(); // 确保重定向后脚本终止
}

/**
 * 路由：API 订阅路径 '/api/v1/client/subscribe'
 * 处理订阅请求，获取 token 和 flag 参数，并代理请求。
 */
if ($requestPath === '/api/v1/client/subscribe') {
    // 获取 GET 参数
    $token = $_GET['token'] ?? null;
    $flag = $_GET['flag'] ?? null;

    // 如果 token 参数缺失，则重定向到根路径
    if (!$token) {
        header('Location: /');
        exit();
    }

    // 构建目标 URL
    $targetUrl = 'https://dashdirect.metc.uk/api/v1/client/subscribe';
    $queryParams = ['token' => $token];
    if ($flag !== null) {
        $queryParams['flag'] = $flag;
    }

    // 将参数转换为 URL 查询字符串
    $fullTargetUrl = $targetUrl . '?' . http_build_query($queryParams);

    // 获取客户端的 User-Agent 头，用于转发
    $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
    $headersToForward = [];
    if ($userAgent) {
        $headersToForward[] = 'User-Agent: ' . $userAgent;
    }

    // 定义允许转发的响应头白名单 (不区分大小写)
    $allowedHeaders = [
        "content-type",
        "content-disposition",
        "profile-update-interval",
        "subscription-userinfo",
        "profile-web-page-url",
        "profile-title",
    ];

    // 初始化 cURL 会话
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $fullTargetUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true); // 返回响应内容而不是直接输出
    curl_setopt($ch, CURLOPT_HEADER, true);       // 包含响应头在输出中
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headersToForward); // 设置请求头
    // 目标 URL 更改为 HTTP，因此不再需要 SSL 验证
    // curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true); // 验证 SSL 证书
    // curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);   // 验证 SSL 主机名

    // 执行 cURL 请求
    $response = curl_exec($ch);

    // 检查 cURL 错误
    if (curl_errno($ch)) {
        error_log("cURL Error: " . curl_error($ch)); // 记录错误日志
        header('Location: /'); // 请求失败时重定向
        exit();
    }

    // 获取响应头大小和 HTTP 状态码
    $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $httpStatusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    // 分离响应头和响应体
    $responseHeadersRaw = substr($response, 0, $headerSize);
    $responseBody = substr($response, $headerSize);

    // 关闭 cURL 会话
    curl_close($ch);

    // 处理并转发响应头
    $headerLines = explode("\r\n", trim($responseHeadersRaw));
    foreach ($headerLines as $headerLine) {
        if (empty($headerLine)) {
            continue;
        }

        // 跳过 HTTP 状态行 (例如 "HTTP/1.1 200 OK")
        if (preg_match('/^HTTP\/\d\.\d\s+\d+/', $headerLine)) {
            continue;
        }
        // 跳过 Transfer-Encoding 和 Content-Length，让 PHP 自动处理
        if (stripos($headerLine, 'Transfer-Encoding:') === 0) {
            continue;
        }
        if (stripos($headerLine, 'Content-Length:') === 0) {
            continue;
        }

        // 解析头部的名称和值
        $parts = explode(':', $headerLine, 2);
        if (count($parts) === 2) {
            $headerName = trim($parts[0]);
            // 检查头部名称是否在白名单中
            if (in_array(strtolower($headerName), $allowedHeaders)) {
                // 发送头部，false 参数表示不替换同名头部，允许有多个
                header($headerLine, false);
            }
        }
    }

    // 设置响应的 HTTP 状态码
    http_response_code($httpStatusCode);

    // 输出响应体
    echo $responseBody;
    exit();
}

/**
 * 如果没有匹配到任何路由，则返回 404 Not Found
 */
http_response_code(404);
echo "404 Not Found";
?>
