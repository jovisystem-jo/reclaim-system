<?php
/**
 * Load environment variables from .env file
 */

class EnvLoader
{
    private static $loaded = false;
    private static $variables = [];

    private static function setEnvironmentValue($key, $value)
    {
        $key = trim((string) $key);
        if ($key === '') {
            return;
        }

        if (function_exists('putenv')) {
            @putenv($key . '=' . $value);
        }

        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
    }

    public static function load($path = __DIR__ . '/../.env')
    {
        if (self::$loaded) {
            return;
        }

        if (!file_exists($path)) {
            return;
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            // Skip comments
            if (strpos(trim($line), '#') === 0) {
                continue;
            }

            // Parse key=value
            $parts = explode('=', $line, 2);
            if (count($parts) === 2) {
                $key = trim($parts[0]);
                $value = trim($parts[1]);
                
                // Remove quotes if present
                $value = trim($value, '"\'');
                
                self::$variables[$key] = $value;

                // Shared hosting can disable putenv(), so mirror into superglobals too.
                self::setEnvironmentValue($key, $value);
            }
        }
        
        self::$loaded = true;
    }
    
    public static function get($key, $default = null)
    {
        return self::$variables[$key] ?? getenv($key) ?: $default;
    }
}
?>
