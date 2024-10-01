<?php

// scoper-autoload.php @generated by PhpScoper

$loader = (static function () {
    // Backup the autoloaded Composer files
    $existingComposerAutoloadFiles = isset($GLOBALS['__composer_autoload_files']) ? $GLOBALS['__composer_autoload_files'] : [];

    $loader = require_once __DIR__.'/autoload.php';
    // Ensure InstalledVersions is available
    $installedVersionsPath = __DIR__.'/composer/InstalledVersions.php';
    if (file_exists($installedVersionsPath)) require_once $installedVersionsPath;

    // Restore the backup and ensure the excluded files are properly marked as loaded
    $GLOBALS['__composer_autoload_files'] = \array_merge(
        $existingComposerAutoloadFiles,
        \array_fill_keys(['7bdb062931f6e7102434c3ad28423eb6'], true)
    );

    return $loader;
})();

// Class aliases. For more information see:
// https://github.com/humbug/php-scoper/blob/master/docs/further-reading.md#class-aliases
if (!function_exists('humbug_phpscoper_expose_class')) {
    function humbug_phpscoper_expose_class($exposed, $prefixed) {
        if (!class_exists($exposed, false) && !interface_exists($exposed, false) && !trait_exists($exposed, false)) {
            spl_autoload_call($prefixed);
        }
    }
}
humbug_phpscoper_expose_class('UnhandledMatchError', 'Syde\Vendor\UnhandledMatchError');
humbug_phpscoper_expose_class('Stringable', 'Syde\Vendor\Stringable');
humbug_phpscoper_expose_class('ValueError', 'Syde\Vendor\ValueError');
humbug_phpscoper_expose_class('PhpToken', 'Syde\Vendor\PhpToken');
humbug_phpscoper_expose_class('Attribute', 'Syde\Vendor\Attribute');

// Function aliases. For more information see:
// https://github.com/humbug/php-scoper/blob/master/docs/further-reading.md#function-aliases
if (!function_exists('apply_filters')) { function apply_filters() { return \Syde\Vendor\apply_filters(...func_get_args()); } }
if (!function_exists('fdiv')) { function fdiv() { return \Syde\Vendor\fdiv(...func_get_args()); } }
if (!function_exists('get_debug_type')) { function get_debug_type() { return \Syde\Vendor\get_debug_type(...func_get_args()); } }
if (!function_exists('get_resource_id')) { function get_resource_id() { return \Syde\Vendor\get_resource_id(...func_get_args()); } }
if (!function_exists('preg_last_error_msg')) { function preg_last_error_msg() { return \Syde\Vendor\preg_last_error_msg(...func_get_args()); } }
if (!function_exists('str_contains')) { function str_contains() { return \Syde\Vendor\str_contains(...func_get_args()); } }
if (!function_exists('str_ends_with')) { function str_ends_with() { return \Syde\Vendor\str_ends_with(...func_get_args()); } }
if (!function_exists('str_starts_with')) { function str_starts_with() { return \Syde\Vendor\str_starts_with(...func_get_args()); } }

return $loader;
