<?php
/**
 * Обработчик формы CTA → письмо на Yandex через SMTP.
 * Загрузите рядом config.php (см. config.example.php).
 */
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

// Honeypot: если бот заполнил скрытое поле — притворяемся успехом
if (!empty($_POST['_gotcha'])) {
    echo json_encode(['ok' => true]);
    exit;
}

$name  = trim((string)($_POST['name'] ?? ''));
$phone = trim((string)($_POST['phone'] ?? ''));
$agree = isset($_POST['agree']);

if ($name === '' || mb_strlen($name) > 120) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'Укажите имя']);
    exit;
}
if ($phone === '' || mb_strlen($phone) > 40) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'Укажите телефон']);
    exit;
}
if (!$agree) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'Нужно согласие на обработку данных']);
    exit;
}

$configPath = __DIR__ . '/config.php';
if (!is_readable($configPath)) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Сервер не настроен (нет config.php)']);
    exit;
}

$config = require $configPath;
$user = $config['smtp_user'] ?? '';
$pass = $config['smtp_pass'] ?? '';
$to   = $config['to'] ?? $user;
$host = $config['smtp_host'] ?? 'smtp.yandex.ru';
$port = (int)($config['smtp_port'] ?? 465);
$subject = $config['subject'] ?? 'Заявка с сайта artrit-artroz.mcv26.ru';

if ($user === '' || $pass === '' || strpos($pass, 'ВСТАВЬТЕ') !== false) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Не задан SMTP-пароль']);
    exit;
}

$ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '';
$ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
$when = date('d.m.Y H:i:s');

$bodyText =
    "Новая заявка с artrit-artroz.mcv26.ru\n\n" .
    "Имя: {$name}\n" .
    "Телефон: {$phone}\n" .
    "Согласие на обработку ПДн: да\n\n" .
    "———\n" .
    "Время: {$when}\n" .
    "IP: {$ip}\n" .
    "UA: {$ua}\n";

$fromName = 'Сайт artrit-artroz.mcv26.ru';
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

$errno = 0;
$errstr = '';
$socket = @stream_socket_client(
    "ssl://{$host}:{$port}",
    $errno,
    $errstr,
    20,
    STREAM_CLIENT_CONNECT
);

if (!$socket) {
    http_response_code(502);
    echo json_encode(['ok' => false, 'error' => 'Не удалось подключиться к почте']);
    exit;
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

$expect = static function (string $response, string $code) : bool {
    return strpos(trim($response), $code) === 0;
};

try {
    $banner = $read();
    if (!$expect($banner, '220')) {
        throw new RuntimeException('SMTP banner');
    }

    $write('EHLO artrit-artroz.mcv26.ru');
    $ehlo = $read();
    if (!$expect($ehlo, '250')) {
        throw new RuntimeException('EHLO');
    }

    $write('AUTH LOGIN');
    if (!$expect($read(), '334')) {
        throw new RuntimeException('AUTH');
    }
    $write(base64_encode($user));
    if (!$expect($read(), '334')) {
        throw new RuntimeException('USER');
    }
    $write(base64_encode($pass));
    if (!$expect($read(), '235')) {
        throw new RuntimeException('PASS');
    }

    $write("MAIL FROM:<{$user}>");
    if (!$expect($read(), '250')) {
        throw new RuntimeException('MAIL FROM');
    }
    $write("RCPT TO:<{$to}>");
    if (!$expect($read(), '250')) {
        throw new RuntimeException('RCPT TO');
    }
    $write('DATA');
    if (!$expect($read(), '354')) {
        throw new RuntimeException('DATA');
    }
    $write($payload . "\r\n.");
    if (!$expect($read(), '250')) {
        throw new RuntimeException('DATA body');
    }
    $write('QUIT');
    $read();
} catch (Throwable $e) {
    fclose($socket);
    http_response_code(502);
    echo json_encode(['ok' => false, 'error' => 'Ошибка отправки письма']);
    exit;
}

fclose($socket);
echo json_encode(['ok' => true]);
