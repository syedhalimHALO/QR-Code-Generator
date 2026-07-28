<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'errormessage' => 'Kaedah request tidak dibenarkan.'
    ]);
    exit;
}

$raw = file_get_contents('php://input');
$input = json_decode($raw ?: '', true);

if (!is_array($input)) {
    http_response_code(400);
    echo json_encode([
        'errormessage' => 'Data request tidak sah.'
    ]);
    exit;
}

$url = trim((string)($input['url'] ?? ''));
$slug = trim((string)($input['slug'] ?? ''));

if ($url === '') {
    http_response_code(400);
    echo json_encode([
        'errormessage' => 'Masukkan URL yang hendak dipendekkan.'
    ]);
    exit;
}

if (!preg_match('~^https?://~i', $url)) {
    $url = 'https://' . $url;
}

if (!filter_var($url, FILTER_VALIDATE_URL)) {
    http_response_code(400);
    echo json_encode([
        'errormessage' => 'URL tidak sah.'
    ]);
    exit;
}

if ($slug !== '' && !preg_match('/^[A-Za-z0-9_]{5,30}$/', $slug)) {
    http_response_code(400);
    echo json_encode([
        'errormessage' => 'Custom link mesti 5–30 aksara dan hanya boleh menggunakan huruf, nombor dan underscore.'
    ]);
    exit;
}

function requestShortener(string $domain, string $url, string $slug): array
{
    $params = [
        'format' => 'json',
        'url' => $url,
    ];

    if ($slug !== '') {
        $params['shorturl'] = $slug;
    }

    $endpoint = 'https://' . $domain . '/create.php?' .
        http_build_query($params, '', '&', PHP_QUERY_RFC3986);

    if (function_exists('curl_init')) {
        $ch = curl_init($endpoint);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 12,
            CURLOPT_USERAGENT => 'HaloGo-QR-Shortener/1.0',
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
        ]);

        $body = curl_exec($ch);
        $curlError = curl_error($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($body === false) {
            return [
                'ok' => false,
                'network_error' => true,
                'message' => $curlError ?: 'Connection error'
            ];
        }
    } else {
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => 12,
                'header' => "Accept: application/json\r\nUser-Agent: HaloGo-QR-Shortener/1.0\r\n",
                'ignore_errors' => true,
            ]
        ]);

        $body = @file_get_contents($endpoint, false, $context);

        if ($body === false) {
            return [
                'ok' => false,
                'network_error' => true,
                'message' => 'Hosting tidak dapat menghubungi ' . $domain
            ];
        }

        $httpCode = 200;
        if (!empty($http_response_header[0]) &&
            preg_match('/\s(\d{3})\s/', $http_response_header[0], $m)) {
            $httpCode = (int)$m[1];
        }
    }

    $data = json_decode((string)$body, true);

    if (!is_array($data)) {
        return [
            'ok' => false,
            'network_error' => true,
            'message' => 'Respons daripada ' . $domain . ' tidak sah.'
        ];
    }

    if (!empty($data['shorturl'])) {
        return [
            'ok' => true,
            'shorturl' => $data['shorturl']
        ];
    }

    return [
        'ok' => false,
        'network_error' => in_array((int)($data['errorcode'] ?? 0), [3, 4], true)
            || $httpCode >= 500,
        'message' => (string)($data['errormessage'] ?? ('Ralat daripada ' . $domain)),
        'errorcode' => (int)($data['errorcode'] ?? 0)
    ];
}

$providers = ['is.gd', 'v.gd'];
$lastError = 'Tidak dapat memendekkan pautan.';

foreach ($providers as $provider) {
    $result = requestShortener($provider, $url, $slug);

    if (!empty($result['ok'])) {
        echo json_encode([
            'shorturl' => $result['shorturl'],
            'provider' => $provider
        ], JSON_UNESCAPED_SLASHES);
        exit;
    }

    $lastError = (string)($result['message'] ?? $lastError);

    // Input/custom-slug errors won't be fixed by trying the second provider.
    if (empty($result['network_error'])) {
        http_response_code(400);
        echo json_encode([
            'errormessage' => $lastError,
            'errorcode' => $result['errorcode'] ?? null
        ], JSON_UNESCAPED_SLASHES);
        exit;
    }
}

http_response_code(502);
echo json_encode([
    'errormessage' => 'Server website tidak dapat menghubungi is.gd atau v.gd. Semak outbound HTTPS/cURL pada hosting. Butiran terakhir: ' . $lastError
], JSON_UNESCAPED_SLASHES);
