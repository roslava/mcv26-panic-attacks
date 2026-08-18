<?php
/**
 * Скопируйте этот файл на сервер как config.php
 * и заполните пароль приложения Яндекса.
 * config.php НЕ должен попадать в git и не должен быть доступен по HTTP.
 */
return [
    // Ящик, от имени которого идёт отправка (SMTP-логин)
    'smtp_user' => 'rostislav.nen@yandex.ru',

    // Пароль приложения Яндекса (не основной пароль от почты!)
    'smtp_pass' => 'ВСТАВЬТЕ_ПАРОЛЬ_ПРИЛОЖЕНИЯ',

    // Куда приходят заявки
    'to' => 'rostislav.nen@yandex.ru',

    // Тема по умолчанию
    'subject' => 'Заявка с сайта artrit-artroz.mcv26.ru',

    // SMTP Яндекса
    'smtp_host' => 'smtp.yandex.ru',
    'smtp_port' => 465, // SSL
];
