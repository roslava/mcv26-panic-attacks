<?php
/**
 * Обработчик формы CTA → письмо через SMTP Яндекса.
 * Загрузите рядом config.php (см. config.example.php).
 *
 * На Timeweb часто закрыт исходящий 465 — тогда используйте 587 + STARTTLS
 * (smtp_port => 587, smtp_secure => 'tls').
 */
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!empty($_POST['_gotcha'])) {
    echo json_encode(['ok' => true]);
    exit;
}

$name  = trim((string)($_POST['name'] ?? ''));
$phone = trim((string)($_POST['phone'] ?? ''));
$agree = isset($_POST['agree']);

if ($name === '' || mb_strlen($name) > 120) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'Укажите имя'], JSON_UNESCAPED_UNICODE);
    exit;
}
if ($phone === '' || mb_strlen($phone) > 40) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'Укажите телефон'], JSON_UNESCAPED_UNICODE);
    exit;
}
if (!$agree) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'Нужно согласие на обработку данных'], JSON_UNESCAPED_UNICODE);
    exit;
}

$configPath = __DIR__ . '/config.php';
if (!is_readable($configPath)) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Сервер не настроен (нет config.php)'], JSON_UNESCAPED_UNICODE);
    exit;
}

$config = require $configPath;
if (!is_array($config)) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'config.php должен возвращать массив (проверьте <?php в начале файла)'], JSON_UNESCAPED_UNICODE);
    exit;
}

$user   = trim((string)($config['smtp_user'] ?? ''));
$pass   = trim((string)($config['smtp_pass'] ?? ''));
$to     = trim((string)($config['to'] ?? $user));
$host   = trim((string)($config['smtp_host'] ?? 'smtp.yandex.ru'));
$port   = (int)($config['smtp_port'] ?? 587);
$secure = strtolower((string)($config['smtp_secure'] ?? 'tls')); // ssl | tls
$subject = (string)($config['subject'] ?? 'Заявка с panic-attacks.mcv26.ru (панические атаки)');
$debug  = !empty($config['debug']);

if ($user === '' || $pass === '' || strpos($pass, 'ВСТАВЬТЕ') !== false) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Не задан SMTP-пароль'], JSON_UNESCAPED_UNICODE);
    exit;
}

$ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '';
$ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
$when = date('d.m.Y H:i:s');

$bodyText =
    "Новая заявка с лендинга «Панические атаки»\n" .
    "Сайт: https://panic-attacks.mcv26.ru/\n\n" .
    "Имя: {$name}\n" .
    "Телефон: {$phone}\n" .
    "Согласие на обработку ПДн: да\n\n" .
    "———\n" .
    "Источник: panic-attacks.mcv26.ru\n" .
    "Время: {$when}\n" .
    "IP: {$ip}\n" .
    "UA: {$ua}\n";

$fromName = 'panic-attacks.mcv26.ru';
$encodedSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
$encodedFromName = '=?UTF-8?B?' . base64_encode($fromName) . '?=';

$headers = [
    "From: {$encodedFromName} <{$user}>",
    "Reply-To: {$user}",
    "MIME-Version: 1.0",
    "Content-Type: text/plain; charset=UTF-8",
    "Content-Transfer-Encoding: base64",
];

$payload =
    "Subject: {$encodedSubject}\r\n" .
    implode("\r\n", $headers) . "\r\n\r\n" .
    chunk_split(base64_encode($bodyText));

/**
 * Попытки подключения: сначала из config, затем запасные варианты.
 * Timeweb часто режет ssl://:465 наружу.
 */
$attempts = [
    ['port' => $port, 'secure' => $secure],
];
if (!($port === 587 && $secure === 'tls')) {
    $attempts[] = ['port' => 587, 'secure' => 'tls'];
}
if (!($port === 465 && $secure === 'ssl')) {
    $attempts[] = ['port' => 465, 'secure' => 'ssl'];
}

$lastError = '';
$sent = false;

foreach ($attempts as $attempt) {
    $aPort = (int)$attempt['port'];
    $aSecure = $attempt['secure'];
    try {
        smtp_send($host, $aPort, $aSecure, $user, $pass, $to, $payload);
        $sent = true;
        break;
    } catch (Throwable $e) {
        $lastError = $e->getMessage();
        if ($debug) {
            error_log('SMTP attempt ' . $aSecure . ':' . $aPort . ' failed: ' . $lastError);
        }
    }
}

if (!$sent) {
    http_response_code(502);
    $msg = 'Не удалось отправить письмо';
    if ($debug) {
        $msg .= ': ' . $lastError;
    }
    echo json_encode(['ok' => false, 'error' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode(['ok' => true]);

/**
 * @throws RuntimeException
 */
function smtp_send(
    string $host,
    int $port,
    string $secure,
    string $user,
    string $pass,
    string $to,
    string $payload
): void {
    $errno = 0;
    $errstr = '';

    if ($secure === 'ssl') {
        $remote = "ssl://{$host}:{$port}";
        $socket = @stream_socket_client($remote, $errno, $errstr, 20, STREAM_CLIENT_CONNECT);
        if (!$socket) {
            throw new RuntimeException("connect ssl {$host}:{$port} — {$errstr} ({$errno})");
        }
    } else {
        // STARTTLS: сначала обычный TCP, потом crypto
        $remote = "tcp://{$host}:{$port}";
        $socket = @stream_socket_client($remote, $errno, $errstr, 20, STREAM_CLIENT_CONNECT);
        if (!$socket) {
            throw new RuntimeException("connect tcp {$host}:{$port} — {$errstr} ({$errno})");
        }
    }

    stream_set_timeout($socket, 20);

    $read = static function () use ($socket): string {
        $data = '';
        while ($line = fgets($socket, 515)) {
            $data .= $line;
            if (isset($line[3]) && $line[3] === ' ') {
                break;
            }
        }
        return $data;
    };

    $write = static function (string $cmd) use ($socket): void {
        fwrite($socket, $cmd . "\r\n");
    };

    $expect = static function (string $response, string $code) use ($host, $port): void {
        if (strpos(trim($response), $code) !== 0) {
            throw new RuntimeException("ожидали {$code}, получили: " . trim($response));
        }
    };

    try {
        $banner = $read();
        $expect($banner, '220');

        $write('EHLO panic-attacks.mcv26.ru');
        $ehlo = $read();
        $expect($ehlo, '250');

        if ($secure === 'tls') {
            $write('STARTTLS');
            $expect($read(), '220');
            $crypto = @stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
            if ($crypto !== true) {
                throw new RuntimeException('STARTTLS crypto failed');
            }
            $write('EHLO panic-attacks.mcv26.ru');
            $expect($read(), '250');
        }

        $write('AUTH LOGIN');
        $expect($read(), '334');
        $write(base64_encode($user));
        $expect($read(), '334');
        $write(base64_encode($pass));
        $auth = $read();
        if (strpos(trim($auth), '235') !== 0) {
            throw new RuntimeException('AUTH failed: ' . trim($auth));
        }

        $write("MAIL FROM:<{$user}>");
        $expect($read(), '250');
        $write("RCPT TO:<{$to}>");
        $expect($read(), '250');
        $write('DATA');
        $expect($read(), '354');
        $write($payload . "\r\n.");
        $expect($read(), '250');
        $write('QUIT');
        $read();
    } finally {
        if (is_resource($socket)) {
            fclose($socket);
        }
    }
}
