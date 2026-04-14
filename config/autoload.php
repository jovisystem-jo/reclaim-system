<?php
spl_autoload_register(function ($class_name) {
    // Define possible paths for classes
    $paths = [
        __DIR__ . '/../classes/',
        __DIR__ . '/../includes/'
    ];
    
    foreach ($paths as $path) {
        $file = $path . $class_name . '.php';
        if (file_exists($file)) {
            require_once $file;
            return true;
        }
    }
    return false;
});
?>