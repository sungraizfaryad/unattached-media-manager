<?php

/**
 * Plugin Name: Unattached Media Manager
 * Plugin URI: https://wordpress.org/plugins/unattached-media-manager/
 * Description: Fix the WordPress Unattached media filter. Automatically attach used media files to their posts so you can safely clean up your library.
 * Version: 1.0.6
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * Author: Sungraiz Faryad
 * Author URI:
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: unattached-media-manager
 * Domain Path: /languages
 *
 * @package UnattachedMediaManager
 */

// Prevent direct access
if (! defined('ABSPATH')) {
    exit;
}

// Plugin constants
define('UNMAM_VERSION', '1.0.2');
define('UNMAM_PLUGIN_FILE', __FILE__);
define('UNMAM_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('UNMAM_PLUGIN_URL', plugin_dir_url(__FILE__));
define('UNMAM_PLUGIN_BASENAME', plugin_basename(__FILE__));

// Register activation hook early (before class instantiation)
register_activation_hook(__FILE__, 'unmam_activate_plugin');
register_deactivation_hook(__FILE__, 'unmam_deactivate_plugin');

/**
 * Plugin activation callback
 */
function unmam_activate_plugin()
{
    require_once UNMAM_PLUGIN_DIR . 'includes/class-unmam-history.php';
    require_once UNMAM_PLUGIN_DIR . 'includes/class-unmam-database.php';
    UNMAM_Database::create_tables();

    // Schedule background scan
    if (! wp_next_scheduled('unmam_background_scan')) {
        wp_schedule_event(time(), 'hourly', 'unmam_background_scan');
    }

    // Set default options
    add_option('unmam_settings', array(
        'batch_size'           => 50,
        'auto_attach'          => false, // Disabled by default - use manual "Attach All" button instead
        'scan_post_content'    => true,
        'scan_featured_images' => true,
        'scan_acf_fields'      => true,
        'scan_gutenberg'       => true,
        'scan_widgets'         => true,
        'scan_options'         => true,
        'excluded_post_types'  => array('revision', 'nav_menu_item'),
        'resource_mode'        => 'auto', // auto, low, high
        'processing_mode'      => null,   // null = not set (show first-time popup), 'frontend' or 'background'
    ));

    // Migrate old settings if they exist (from previous plugin versions)
    $old_settings = get_option('aioms_settings');
    if ($old_settings && ! get_option('unmam_settings')) {
        update_option('unmam_settings', $old_settings);
        delete_option('aioms_settings');
    }
    // Also check for even older settings
    $older_settings = get_option('mui_settings');
    if ($older_settings && ! get_option('unmam_settings')) {
        update_option('unmam_settings', $older_settings);
        delete_option('mui_settings');
    }

    flush_rewrite_rules();
}

/**
 * Plugin deactivation callback
 */
function unmam_deactivate_plugin()
{
    wp_clear_scheduled_hook('unmam_background_scan');
    wp_clear_scheduled_hook('unmam_process_batch');
    wp_clear_scheduled_hook('unmam_process_job');
    // Also clear legacy hooks from previous plugin versions
    wp_clear_scheduled_hook('aioms_background_scan');
    wp_clear_scheduled_hook('mui_process_batch');
    wp_clear_scheduled_hook('mui_process_job');
}

/**
 * Main plugin class
 */
final class Unattached_Media_Manager
{

    /**
     * Single instance
     *
     * @var Unattached_Media_Manager|null
     */
    private static $instance = null;

    /**
     * Get singleton instance
     *
     * @return Unattached_Media_Manager
     */
    public static function instance()
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor
     */
    private function __construct()
    {
        $this->load_dependencies();
        $this->init_hooks();
    }

    /**
     * Load required files
     */
    private function load_dependencies()
    {
        // Core classes
        require_once UNMAM_PLUGIN_DIR . 'includes/class-unmam-history.php';
        require_once UNMAM_PLUGIN_DIR . 'includes/class-unmam-database.php';
        require_once UNMAM_PLUGIN_DIR . 'includes/class-unmam-resource-monitor.php';
        require_once UNMAM_PLUGIN_DIR . 'includes/class-unmam-scanner.php';
        require_once UNMAM_PLUGIN_DIR . 'includes/class-unmam-attachment-manager.php';
        require_once UNMAM_PLUGIN_DIR . 'includes/class-unmam-background-processor.php';
        require_once UNMAM_PLUGIN_DIR . 'includes/class-unmam-job-queue.php';

        // Parsers
        require_once UNMAM_PLUGIN_DIR . 'includes/parsers/class-unmam-parser-interface.php';
        require_once UNMAM_PLUGIN_DIR . 'includes/parsers/class-unmam-content-parser.php';
        require_once UNMAM_PLUGIN_DIR . 'includes/parsers/class-unmam-block-parser.php';
        require_once UNMAM_PLUGIN_DIR . 'includes/parsers/class-unmam-acf-parser.php';
        require_once UNMAM_PLUGIN_DIR . 'includes/parsers/class-unmam-meta-parser.php';
        require_once UNMAM_PLUGIN_DIR . 'includes/parsers/class-unmam-options-parser.php';
        require_once UNMAM_PLUGIN_DIR . 'includes/parsers/class-unmam-widget-parser.php';
        require_once UNMAM_PLUGIN_DIR . 'includes/parsers/class-unmam-elementor-parser.php';
        require_once UNMAM_PLUGIN_DIR . 'includes/parsers/class-unmam-metabox-parser.php';
        require_once UNMAM_PLUGIN_DIR . 'includes/parsers/class-unmam-woocommerce-parser.php';
        require_once UNMAM_PLUGIN_DIR . 'includes/parsers/class-unmam-seo-parser.php';

        // Admin
        if (is_admin()) {
            require_once UNMAM_PLUGIN_DIR . 'includes/admin/class-unmam-admin.php';
            require_once UNMAM_PLUGIN_DIR . 'includes/admin/class-unmam-media-modal.php';
            require_once UNMAM_PLUGIN_DIR . 'includes/admin/class-unmam-bulk-actions.php';
        }

        // REST API
        require_once UNMAM_PLUGIN_DIR . 'includes/api/class-unmam-rest-controller.php';

        // WP-CLI
        if (defined('WP_CLI') && WP_CLI) {
            require_once UNMAM_PLUGIN_DIR . 'includes/cli/class-unmam-cli-commands.php';
        }
    }

    /**
     * Initialize hooks
     */
    private function init_hooks()
    {
        // Initialize components
        add_action('init', array($this, 'init'));
        add_action('rest_api_init', array($this, 'init_rest_api'));

        // Background processing
        add_action('unmam_background_scan', array($this, 'run_background_scan'));
        add_action('unmam_index_single_post', array($this, 'index_single_post'), 10, 1);

        // Real-time indexing hooks
        add_action('save_post', array($this, 'on_post_save'), 20, 2);
        add_action('delete_post', array($this, 'on_post_delete'));
        add_action('add_attachment', array($this, 'on_attachment_add'));
        add_action('delete_attachment', array($this, 'on_attachment_delete'));
        add_action('updated_post_meta', array($this, 'on_meta_update'), 10, 4);
        add_action('added_post_meta', array($this, 'on_meta_add'), 10, 4);
        add_action('deleted_post_meta', array($this, 'on_meta_delete'), 10, 4);

        // ACF specific hooks
        add_action('acf/save_post', array($this, 'on_acf_save'), 20);
    }

    /**
     * Initialize plugin
     */
    public function init()
    {
        // Note: Translations are automatically loaded by WordPress.org for hosted plugins (since WP 4.6).

        // Initialize admin
        if (is_admin()) {
            UNMAM_Admin::instance();
            UNMAM_Media_Modal::instance();
            UNMAM_Bulk_Actions::instance();
        }

        // Initialize background processor (always, for cron support)
        UNMAM_Background_Processor::instance();

        // Initialize job queue (for background bulk operations)
        UNMAM_Job_Queue::instance();

        // Register WP-CLI commands
        if (defined('WP_CLI') && WP_CLI) {
            WP_CLI::add_command('unmam', 'UNMAM_CLI_Commands');
        }
    }

    /**
     * Initialize REST API
     */
    public function init_rest_api()
    {
        $controller = new UNMAM_REST_Controller();
        $controller->register_routes();
    }

    /**
     * Run background scan
     */
    public function run_background_scan()
    {
        $scanner = UNMAM_Scanner::instance();
        $scanner->run_batch();
    }

    /**
     * Index a single post
     *
     * @param int $post_id Post ID.
     */
    public function index_single_post($post_id)
    {
        $scanner = UNMAM_Scanner::instance();
        $scanner->index_post($post_id);
    }

    /**
     * Handle post save
     *
     * @param int     $post_id Post ID.
     * @param WP_Post $post    Post object.
     */
    public function on_post_save($post_id, $post)
    {
        // Skip autosaves and revisions
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }

        if (wp_is_post_revision($post_id)) {
            return;
        }

        // Schedule reindex for this post
        wp_schedule_single_event(time() + 5, 'unmam_index_single_post', array($post_id));
    }

    /**
     * Handle post deletion
     *
     * @param int $post_id Post ID.
     */
    public function on_post_delete($post_id)
    {
        UNMAM_Database::delete_references_by_source($post_id);
    }

    /**
     * Handle attachment creation
     *
     * @param int $attachment_id Attachment ID.
     */
    public function on_attachment_add($attachment_id)
    {
        // New attachments don't have references yet, nothing to do
    }

    /**
     * Handle attachment deletion
     *
     * @param int $attachment_id Attachment ID.
     */
    public function on_attachment_delete($attachment_id)
    {
        UNMAM_Database::delete_references_by_attachment($attachment_id);
    }

    /**
     * Handle meta update
     *
     * @param int    $meta_id    Meta ID.
     * @param int    $object_id  Object ID.
     * @param string $meta_key   Meta key.
     * @param mixed  $meta_value Meta value.
     */
    public function on_meta_update($meta_id, $object_id, $meta_key, $meta_value)
    {
        // Skip internal meta
        if (strpos($meta_key, '_unmam_') === 0) {
            return;
        }

        // Schedule reindex
        wp_schedule_single_event(time() + 5, 'unmam_index_single_post', array($object_id));
    }

    /**
     * Handle meta add
     *
     * @param int    $meta_id    Meta ID.
     * @param int    $object_id  Object ID.
     * @param string $meta_key   Meta key.
     * @param mixed  $meta_value Meta value.
     */
    public function on_meta_add($meta_id, $object_id, $meta_key, $meta_value)
    {
        $this->on_meta_update($meta_id, $object_id, $meta_key, $meta_value);
    }

    /**
     * Handle meta delete
     *
     * @param array  $meta_ids   Meta IDs.
     * @param int    $object_id  Object ID.
     * @param string $meta_key   Meta key.
     * @param mixed  $meta_value Meta value.
     */
    public function on_meta_delete($meta_ids, $object_id, $meta_key, $meta_value)
    {
        wp_schedule_single_event(time() + 5, 'unmam_index_single_post', array($object_id));
    }

    /**
     * Handle ACF save
     *
     * @param int $post_id Post ID.
     */
    public function on_acf_save($post_id)
    {
        // ACF uses options for some fields
        if ($post_id === 'options' || strpos((string) $post_id, 'options') !== false) {
            $scanner = UNMAM_Scanner::instance();
            $scanner->index_options();
            return;
        }

        // Schedule reindex
        wp_schedule_single_event(time() + 5, 'unmam_index_single_post', array($post_id));
    }

    /**
     * Get plugin settings
     *
     * @param string|null $key Optional specific setting key.
     * @return mixed
     */
    public static function get_setting($key = null)
    {
        // Try new option first, fall back to legacy
        $settings = get_option('unmam_settings');
        if (! $settings) {
            $settings = get_option('unmam_settings', array());
        }

        if (null === $key) {
            return $settings;
        }

        return isset($settings[$key]) ? $settings[$key] : null;
    }

    /**
     * Update plugin setting
     *
     * @param string $key   Setting key.
     * @param mixed  $value Setting value.
     */
    public static function update_setting($key, $value)
    {
        $settings = self::get_setting();
        $settings[$key] = $value;
        update_option('unmam_settings', $settings);
    }
}

/**
 * Initialize the plugin
 *
 * @return Unattached_Media_Manager
 */
function unmam()
{
    return Unattached_Media_Manager::instance();
}

// Start the plugin
unmam();
