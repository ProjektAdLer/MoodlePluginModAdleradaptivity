<?php
/**
 * Label module version info
 *
 * @package mod_adleradaptivity
 * @copyright  2023, Markus Heck <markus.heck@hs-kempten.de>
 */

defined('MOODLE_INTERNAL') || die();

$plugin->version = 2024102108;
$plugin->requires = 2024042200;  // Moodle version
$plugin->release = '3.0.0-dev';
$plugin->component = 'mod_adleradaptivity'; // Full name of the plugin (used for diagnostics)
$plugin->maturity = MATURITY_ALPHA;
$plugin->dependencies = array(
    'local_logging' => ANY_VERSION,
);