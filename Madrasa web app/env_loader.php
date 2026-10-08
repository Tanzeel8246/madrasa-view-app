<?php
$GLOBALS['_MADRASA_ENV'] = [];

function madrasa_load_env($path = null) {
    static $loaded = false;
    if ($loaded) return;
    $loaded = true;

    $path = $path ?: __DIR__ . '/.env';
    if (!is_readable($path)) return;

    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!$lines) return;

    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || substr($line, 0, 1) === '#' || strpos($line, '=') === false) {
            continue;
        }

        [$key, $value] = explode('=', $line, 2);
        $key = trim($key);
        $value = trim($value);

        if ($key === '') {
            continue;
        }

        if (
            strlen($value) >= 2 &&
            (($value[0] === '"' && substr($value, -1) === '"') ||
             ($value[0] === "'" && substr($value, -1) === "'"))
        ) {
            $value = substr($value, 1, -1);
        }

        $GLOBALS['_MADRASA_ENV'][$key] = $value;
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
        if (function_exists('putenv')) {
            @putenv($key . '=' . $value);
        }
    }
}

if (!function_exists('get_env_val')) {
    function get_env_val($key, $default = '') {
        if (isset($GLOBALS['_MADRASA_ENV'][$key]) && $GLOBALS['_MADRASA_ENV'][$key] !== '') {
            return $GLOBALS['_MADRASA_ENV'][$key];
        }
        if (isset($_ENV[$key]) && $_ENV[$key] !== '') {
            return $_ENV[$key];
        }
        if (isset($_SERVER[$key]) && $_SERVER[$key] !== '') {
            return $_SERVER[$key];
        }
        if (function_exists('getenv')) {
            $v = @getenv($key);
            if ($v !== false && $v !== '') {
                return $v;
            }
        }
        return $default;
    }
}

madrasa_load_env();
