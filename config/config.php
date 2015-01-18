<?php

ini_set('default_charset', 'utf-8');
mb_internal_encoding("UTF-8");
date_default_timezone_set('Europe/Moscow');

define('DEBUG_MODE', false);
define('DEBUG_ALLOWED_IP',
  isset($_SERVER['REMOTE_ADDR']) && in_array($_SERVER['REMOTE_ADDR'], [
    '127.0.0.1',
  ])
);
define('IS_LOCAL_DEVEL', !isset($_SERVER['REMOTE_ADDR']) || $_SERVER['REMOTE_ADDR'] === '127.0.0.1');  // Опасное определение. Не стоит на это слишком полагаться!

define('DOMAIN_NAME', isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'localhost');
define('ROOT_URL', DOMAIN_NAME . '/');

define('ROOT_DIR', realpath(__DIR__ . '/..') . '/');
define('CLASSES_DIR', ROOT_DIR . 'core/');
define('TEMPLATES_DIR', ROOT_DIR . 'templates/');
define('LIB_DIR', ROOT_DIR . 'lib/');
define('CONFIGS_DIR', ROOT_DIR . 'config/');
define('DATA_DIR', ROOT_DIR . 'runtime/data/');
define('LOG_DIR', ROOT_DIR . 'runtime/log/');
define('SESSIONS_DIR', ROOT_DIR . 'runtime/sessions/');
define('WEB_DIR', ROOT_DIR . 'www/');
define('LOCAL_DIR', ROOT_DIR . 'classes/');

define('YYID', 'YYID');
define('COOKIE_NAME', 'YY');
define('DEFAULT_SESSION_IP_CHECKING', TRUE);
define('DEFAULT_SESSION_LIFETIME', 3600 * 24 * 90); // ~ три месяца

ini_set('session.gc_maxlifetime', DEFAULT_SESSION_LIFETIME);
ini_set('session.cookie_lifetime', DEFAULT_SESSION_LIFETIME);
ini_set('session.save_path', SESSIONS_DIR);
ini_set('session.cookie_domain', DOMAIN_NAME);
ini_set('session.gc_probability', '5');

require_once CLASSES_DIR . "class-data.php";
require_once LOCAL_DIR . "class-main.php";

// Данные, специфичные для конкретной реализации бизнес-логики

require_once CLASSES_DIR . "class-utils.php";

const DEFAULT_PROJECT_TITLE = 'YY';
const PROJECT_SUBTITLE = '';

// Для интерграцией с phpStorm
// TODO: Это песец, зачем столько бэкслэшей?
define('EDITOR_PATH', 'C:\\\\Program Files (x86)\\\\JetBrains\\\\PhpStorm 8.0.1\\\\bin\\\\PhpStorm.exe');
define('EDITOR_CONFIG_ROOT', 'W:\\home\\my-project.ru\\config\\.current\\');

