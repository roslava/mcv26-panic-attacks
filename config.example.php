<?php
/**
 * Скопируйте этот файл на сервер как config.php
 * и заполните пароль приложения Яндекса.
 * config.php НЕ должен попадать в git и не должен быть доступен по HTTP.
 *
 * Важно: файл ОБЯЗАН начинаться с <?php
 *
 * На Timeweb предпочтительно:
 *   smtp_port => 587
 *   smtp_secure => 'tls'
 * Порт 465 (ssl) на shared-хостинге часто закрыт наружу.
 */
return [
    'smtp_user' => 'info@mcv26.ru',
    'smtp_pass' => 'ВСТАВЬТЕ_ПАРОЛЬ_ПРИЛОЖЕНИЯ',
    'to' => 'info@mcv26.ru',
    'subject' => 'Заявка с panic-attacks.mcv26.ru (панические атаки)',
    'smtp_host' => 'smtp.yandex.ru',
    'smtp_port' => 587,
    'smtp_secure' => 'tls', // tls (587) или ssl (465)
    // 'debug' => true, // раскомментируйте, чтобы видеть текст ошибки в ответе формы
];
