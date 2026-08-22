<?php
// Включаем отображение ошибок для отладки
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Устанавливаем заголовки для JSON-ответа
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

// Проверяем, что запрос POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

// Honeypot - защита от спама
if (!empty($_POST['_gotcha'])) {
    echo json_encode(['ok' => true]);
    exit;
}

// Получаем данные из формы
$name = trim($_POST['name'] ?? '');
$phone = trim($_POST['phone'] ?? '');
$agree = isset($_POST['agree']);

// Проверяем имя
if ($name === '' || mb_strlen($name) > 120) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'Укажите имя']);
    exit;
}

// Проверяем телефон
if ($phone === '' || mb_strlen($phone) > 40) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'Укажите телефон']);
    exit;
}

// Проверяем согласие
if (!$agree) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'Нужно согласие на обработку данных']);
    exit;
}

// Загружаем конфиг
$configPath = __DIR__ . '/config.php';
if (!is_readable($configPath)) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Сервер не настроен (нет config.php)']);
    exit;
}

$config = require $configPath;

// ============ ВСЕ ДАННЫЕ ИЗ КОНФИГА ============
$to = $config['to'] ?? 'mcv26-feedback@yandex.ru';
$subject = $config['subject'] ?? 'Заявка с сайта';
$fromEmail = $config['email_from'] ?? $config['smtp_user'] ?? 'no-reply@migren.mcv26.ru';

// Новые параметры из конфига
$siteName = $config['site_name'] ?? 'migren.mcv26.ru';
$siteTitle = $config['site_title'] ?? 'Новая заявка';
$footerText = $config['footer_text'] ?? 'С заботой о вас';
// ===============================================

// Получаем IP и другую информацию
$ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? 'Неизвестно';
$ua = $_SERVER['HTTP_USER_AGENT'] ?? 'Неизвестно';
$when = date('d.m.Y H:i:s');

// ============ HTML-ВЕРСИЯ ПИСЬМА ============
$htmlMessage = "
<!DOCTYPE html>
<html>
<head>
    <meta charset='UTF-8'>
    <style>
        body { 
            font-family: Arial, sans-serif; 
            color: #333;
            background: #EFEFDO;
            margin: 0;
            padding: 20px;
        }
        .container { 
            max-width: 600px; 
            margin: 0 auto; 
            background: #ffffff;
            border-radius: 16px;
            overflow: hidden;
            box-shadow: 0 8px 30px rgba(0,78,137,0.15);
        }
        
        .header {
            background: linear-gradient(135deg, #004E89 0%, #1A659E 100%);
            padding: 30px 20px;
            text-align: center;
            border-bottom: 4px solid #FF6B35;
        }
        .header h1 {
            color: #ffffff;
            margin: 0;
            font-size: 24px;
            font-weight: 700;
            letter-spacing: 1px;
        }
        .header p {
            color: rgba(255,255,255,0.9);
            margin: 8px 0 0;
            font-size: 16px;
        }
        
        .content {
            padding: 30px 25px;
            background: #ffffff;
        }
        
        .field {
            margin: 20px 0;
            padding: 16px 18px;
            background: #EFEFDO;
            border-radius: 12px;
            border-left: 5px solid #FF6B35;
        }
        .field-phone {
            border-left-color: #F7C59F;
        }
        .field-agree {
            border-left-color: #1A659E;
            background: #f0f7fd;
        }
        
        .label {
            display: block;
            font-size: 13px;
            font-weight: 700;
            color: #004E89;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 4px;
        }
        .label .icon {
            margin-right: 6px;
        }
        
        .value {
            font-size: 18px;
            color: #1A1A1A;
            font-weight: 600;
        }
        .value a {
            color: #004E89;
            text-decoration: none;
        }
        
        .badge {
            display: inline-block;
            background: #1A659E;
            color: white;
            padding: 4px 16px;
            border-radius: 20px;
            font-size: 13px;
            font-weight: 600;
            letter-spacing: 0.3px;
        }
        
        .meta {
            margin-top: 25px;
            padding: 18px 20px;
            background: #EFEFDO;
            border-radius: 12px;
            border: 1px solid #F7C59F;
        }
        .meta-title {
            font-size: 14px;
            font-weight: 700;
            color: #004E89;
            margin-bottom: 10px;
            display: block;
        }
        .meta-item {
            font-size: 14px;
            color: #333;
            padding: 4px 0;
        }
        .meta-item strong {
            color: #1A659E;
        }
        .meta-icon {
            margin-right: 8px;
        }
        
        .footer {
            padding: 20px 25px;
            background: #EFEFDO;
            text-align: center;
            border-top: 2px solid #F7C59F;
        }
        .footer p {
            margin: 4px 0;
            font-size: 12px;
            color: #666;
        }
        .footer .brand {
            color: #004E89;
            font-weight: 700;
            font-size: 14px;
        }
        .footer .highlight {
            color: #FF6B35;
        }
        
        @media (max-width: 480px) {
            .header h1 { font-size: 20px; }
            .value { font-size: 16px; }
            .content { padding: 20px 15px; }
        }
    </style>
</head>
<body>
    <div class='container'>
        
        <!-- ===== ШАПКА - ДАННЫЕ ИЗ КОНФИГА ===== -->
        <div class='header'>
            <h1>📩 {$siteTitle}</h1>                              ← Из конфига
            <p>с сайта <strong>{$siteName}</strong></p>           ← Из конфига
        </div>
        
        <!-- ОСНОВНОЙ КОНТЕНТ -->
        <div class='content'>
            
            <div class='field'>
                <span class='label'><span class='icon'>👤</span>Имя клиента</span>
                <div class='value'>{$name}</div>
            </div>
            
            <div class='field field-phone'>
                <span class='label'><span class='icon'>📱</span>Контактный телефон</span>
                <div class='value'><a href='tel:{$phone}'>{$phone}</a></div>
            </div>
            
            <div class='field field-agree'>
                <span class='label'><span class='icon'>✅</span>Согласие на обработку ПДн</span>
                <div class='value'>
                    <span class='badge'>✓ Подтверждено</span>
                </div>
            </div>
            
            <div class='meta'>
                <span class='meta-title'>📋 Дополнительная информация</span>
                
                <div class='meta-item'>
                    <span class='meta-icon'>🕐</span>
                    Время отправки: <strong>{$when}</strong>
                </div>
                
                <div class='meta-item'>
                    <span class='meta-icon'>🌐</span>
                    IP-адрес: <strong>{$ip}</strong>
                </div>
                
                <div class='meta-item' style='font-size:12px; color:#888; margin-top:5px; border-top:1px solid #ddd; padding-top:8px;'>
                    <span class='meta-icon'>ℹ️</span>
                    User-Agent: <span style='color:#666;'>{$ua}</span>
                </div>
            </div>
            
        </div>
        
        <!-- ===== ФУТЕР - ДАННЫЕ ИЗ КОНФИГА ===== -->
        <div class='footer'>
            <p class='brand'>{$siteName}</p>
            <p>Письмо отправлено автоматически</p>
            <p style='color:#aaa; font-size:11px;'>
                <span class='highlight'>✦</span> 
                {$footerText}                                    
                <span class='highlight'>✦</span>
            </p>
        </div>
        
    </div>
</body>
</html>
";

// ============ ТЕКСТОВАЯ ВЕРСИЯ ============
$textMessage = "
╔═════════════════════════════════════════╗
║  📩 {$siteTitle}                       ║
║  {$siteName}                           ║
╚═════════════════════════════════════════╝

👤 Имя клиента:     {$name}
📱 Телефон:         {$phone}
✅ Согласие на ПДн: Подтверждено

───────────────────────────────────────────
📋 Дополнительная информация:
🕐 Время:   {$when}
🌐 IP-адрес: {$ip}
───────────────────────────────────────────

{$footerText}
Письмо отправлено автоматически.
";

// Кодируем тему письма
$encodedSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';

// Заголовки для HTML-письма
$boundary = "----=" . md5(uniqid(rand(), true));

$headers = [
    'From: ' . $fromEmail,
    'Reply-To: ' . $fromEmail,
    'MIME-Version: 1.0',
    "Content-Type: multipart/alternative; boundary=\"{$boundary}\""
];

$headersStr = implode("\r\n", $headers);

// Формируем тело письма
$body = "--{$boundary}\r\n";
$body .= "Content-Type: text/plain; charset=utf-8\r\n";
$body .= "Content-Transfer-Encoding: 8bit\r\n\r\n";
$body .= $textMessage . "\r\n\r\n";

$body .= "--{$boundary}\r\n";
$body .= "Content-Type: text/html; charset=utf-8\r\n";
$body .= "Content-Transfer-Encoding: 8bit\r\n\r\n";
$body .= $htmlMessage . "\r\n\r\n";

$body .= "--{$boundary}--";

// Отправляем письмо
$mailResult = mail($to, $encodedSubject, $body, $headersStr);

// Проверяем результат
if ($mailResult) {
    echo json_encode(['ok' => true, 'message' => 'Письмо отправлено']);
} else {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Ошибка при отправке письма']);
}