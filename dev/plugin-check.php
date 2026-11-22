<?php
// Loads Plugin Check's WP-CLI command on FLP without activating the plugin. See TESTING.md.
$pc = dirname( __DIR__, 3 ) . '/flp/app/public/wp-content/plugins/plugin-check/';

define( 'WP_PLUGIN_CHECK_VERSION', '2.1.0' );
define( 'WP_PLUGIN_CHECK_MINIMUM_PHP', '7.4' );
define( 'WP_PLUGIN_CHECK_MAIN_FILE', $pc . 'plugin.php' );
define( 'WP_PLUGIN_CHECK_PLUGIN_DIR_PATH', $pc );
define( 'WP_PLUGIN_CHECK_PLUGIN_DIR_URL', 'https://flp.local/wp-content/plugins/plugin-check/' );

require $pc . 'cli.php';
