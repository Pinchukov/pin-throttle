<?php
/**
 * Plugin Name: Pin Throttle
 * Description: Simple and secure request throttling for WordPress.
 * Version: 1.2.0
 * Author: Pinchukov Sergey
 * Author URI: https://github.com/Pinchukov  
 * Text Domain: pin-throttle
 * Domain Path: /languages
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Requires at least: 5.0
 * Tested up to: 6.7
 * Requires PHP: 7.4
 * Network: false
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Plugin security check
if ( ! function_exists( 'add_action' ) ) {
    exit;
}

/**
 * Pin Throttle Plugin Main Class
 */
final class Pin_Throttle_Plugin {
    
    /**
     * Plugin version
     */
    const VERSION = '1.2.0';
    
    /**
     * Minimum WordPress version
     */
    const MIN_WP_VERSION = '5.0';
    
    /**
     * Minimum PHP version
     */
    const MIN_PHP_VERSION = '7.4';
    
    /**
     * Plugin instance
     *
     * @var Pin_Throttle_Plugin|null
     */
    private static $instance = null;
    
    /**
     * Plugin settings
     *
     * @var array
     */
    private $settings = [];
    
    /**
     * Default allowed bots list
     *
     * @var array
     */
    private const DEFAULT_ALLOWED_BOTS = [
        'Yandex',
        'Mail.ru',
        'Rambler',
        'Googlebot',
        'Bingbot',
        'DuckDuckBot',
        'Baiduspider',
        'Sogou',
        'Exabot',
        'facebot',
        'Twitterbot',
        'Applebot',
        'SemrushBot',
        'AhrefsBot',
        'MJ12bot',
        'PetalBot',
        'OpenAI',
        'ChatGPT',
        'GPT',
        'Lighthouse',
    ];
    
    /**
     * Get plugin instance (Singleton)
     *
     * @return Pin_Throttle_Plugin
     */
    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Constructor
     */
    private function __construct() {
        $this->define_constants();
        $this->check_requirements();
        $this->init_hooks();
    }
    
    /**
     * Prevent cloning
     */
    private function __clone() {}
    
    /**
     * Prevent unserializing
     */
    public function __wakeup() {
        throw new Exception( 'Cannot unserialize singleton' );
    }
    
    /**
     * Define plugin constants
     */
    private function define_constants() {
        $this->define( 'PIN_THROTTLE_VERSION', self::VERSION );
        $this->define( 'PIN_THROTTLE_PATH', plugin_dir_path( __FILE__ ) );
        $this->define( 'PIN_THROTTLE_URL', plugin_dir_url( __FILE__ ) );
        $this->define( 'PIN_THROTTLE_BASENAME', plugin_basename( __FILE__ ) );
        $this->define( 'PIN_THROTTLE_DEFAULT_ALLOWED_BOTS', self::DEFAULT_ALLOWED_BOTS );
    }
    
    /**
     * Define constant if not already defined
     *
     * @param string $name
     * @param mixed  $value
     */
    private function define( $name, $value ) {
        if ( ! defined( $name ) ) {
            define( $name, $value );
        }
    }
    
    /**
     * Check plugin requirements
     */
    private function check_requirements() {
        // Check WordPress version
        if ( version_compare( get_bloginfo( 'version' ), self::MIN_WP_VERSION, '<' ) ) {
            add_action( 'admin_notices', [ $this, 'wp_version_notice' ] );
            return;
        }
        
        // Check PHP version
        if ( version_compare( PHP_VERSION, self::MIN_PHP_VERSION, '<' ) ) {
            add_action( 'admin_notices', [ $this, 'php_version_notice' ] );
            return;
        }
    }
    
    /**
     * Initialize hooks
     */
    private function init_hooks() {
        register_activation_hook( __FILE__, [ $this, 'activate' ] );
        register_deactivation_hook( __FILE__, [ $this, 'deactivate' ] );
        register_uninstall_hook( __FILE__, [ __CLASS__, 'uninstall' ] );
        
        add_action( 'plugins_loaded', [ $this, 'init' ] );
        add_action( 'init', [ $this, 'load_textdomain' ] );
        
        // Setup autoloader
        spl_autoload_register( [ $this, 'autoload' ] );
    }
    
    /**
     * Initialize plugin
     */
    public function init() {
        // Load settings
        $this->load_settings();
        
        // Initialize components
        if ( is_admin() ) {
            $this->init_admin();
        }
        
        $this->init_throttler();
        
        // Setup cleanup cron
        $this->setup_cleanup_cron();
    }
    
    /**
     * Load plugin settings
     */
    private function load_settings() {
        $default_settings = $this->get_default_settings();
        $saved_settings = get_option( 'pin_throttle_settings', [] );
        
        // Merge with defaults and sanitize
        $this->settings = wp_parse_args( $saved_settings, $default_settings );
        $this->settings = $this->sanitize_settings( $this->settings );
    }
    
    /**
     * Get default settings
     *
     * @return array
     */
    private function get_default_settings() {
        return [
            'limit_per_minute'           => 30,
            'block_time'                 => 30,
            'whitelist'                  => [],
            'enable_notifications'       => false,
            'notification_emails'        => '',
            'log_to_file'               => false,
            'auto_cleanup_days'         => 7,
            'delete_data_on_uninstall'  => false,
            'allowed_bots'              => self::DEFAULT_ALLOWED_BOTS,
            'blocked_bots'              => [],
        ];
    }
    
    /**
     * Sanitize settings
     *
     * @param array $settings
     * @return array
     */
    private function sanitize_settings( $settings ) {
        return [
            'limit_per_minute'          => absint( $settings['limit_per_minute'] ?? 30 ),
            'block_time'                => absint( $settings['block_time'] ?? 30 ),
            'whitelist'                 => array_filter( array_map( 'sanitize_text_field', (array) ( $settings['whitelist'] ?? [] ) ) ),
            'enable_notifications'      => (bool) ( $settings['enable_notifications'] ?? false ),
            'notification_emails'       => sanitize_textarea_field( $settings['notification_emails'] ?? '' ),
            'log_to_file'              => (bool) ( $settings['log_to_file'] ?? false ),
            'auto_cleanup_days'        => max( 1, absint( $settings['auto_cleanup_days'] ?? 7 ) ),
            'delete_data_on_uninstall' => (bool) ( $settings['delete_data_on_uninstall'] ?? false ),
            'allowed_bots'             => array_filter( array_map( 'sanitize_text_field', (array) ( $settings['allowed_bots'] ?? self::DEFAULT_ALLOWED_BOTS ) ) ),
            'blocked_bots'             => array_filter( array_map( 'sanitize_text_field', (array) ( $settings['blocked_bots'] ?? [] ) ) ),
        ];
    }
    
    /**
     * Initialize admin components
     */
    private function init_admin() {
        if ( class_exists( 'Pin_Throttle_Settings' ) ) {
            new Pin_Throttle_Settings();
        }
    }
    
    /**
     * Initialize throttler
     */
    private function init_throttler() {
        if ( class_exists( 'Pin_Throttle_Throttler' ) ) {
            new Pin_Throttle_Throttler();
        }
    }
    
    /**
     * Setup cleanup cron job
     */
    private function setup_cleanup_cron() {
        if ( ! wp_next_scheduled( 'pin_throttle_cleanup' ) ) {
            wp_schedule_event( time(), 'daily', 'pin_throttle_cleanup' );
        }
        
        add_action( 'pin_throttle_cleanup', [ $this, 'cleanup_old_logs' ] );
    }
    
    /**
     * Cleanup old logs
     */
    public function cleanup_old_logs() {
        global $wpdb;
        
        $days = $this->get_setting( 'auto_cleanup_days', 7 );
        $table_name = $wpdb->prefix . 'pin_throttle_logs';
        
        $deleted = $wpdb->query( $wpdb->prepare(
            "DELETE FROM {$table_name} WHERE request_time < DATE_SUB(NOW(), INTERVAL %d DAY)",
            $days
        ) );
        
        if ( false !== $deleted ) {
            update_option( 'pin_throttle_last_cleanup', current_time( 'mysql' ) );
        }
    }
    
    /**
     * Get setting value
     *
     * @param string $key
     * @param mixed  $default
     * @return mixed
     */
    public function get_setting( $key, $default = null ) {
        return $this->settings[ $key ] ?? $default;
    }
    
    /**
     * Get all settings
     *
     * @return array
     */
    public function get_settings() {
        return $this->settings;
    }
    
    /**
     * Update settings
     *
     * @param array $settings
     * @return bool
     */
    public function update_settings( $settings ) {
        $settings = $this->sanitize_settings( $settings );
        $this->settings = $settings;
        
        return update_option( 'pin_throttle_settings', $settings );
    }
    
    /**
     * Load text domain
     */
    public function load_textdomain() {
        load_plugin_textdomain(
            'pin-throttle',
            false,
            dirname( PIN_THROTTLE_BASENAME ) . '/languages'
        );
    }
    
    /**
     * Autoload classes
     *
     * @param string $class
     */
    public function autoload( $class ) {
        if ( strpos( $class, 'Pin_Throttle_' ) !== 0 ) {
            return;
        }
        
        $class_name = substr( $class, strlen( 'Pin_Throttle_' ) );
        $filename = 'class-' . strtolower( str_replace( '_', '-', $class_name ) ) . '.php';
        $file_path = PIN_THROTTLE_PATH . 'includes/' . $filename;
        
        if ( is_readable( $file_path ) ) {
            require_once $file_path;
        }
    }
    
    /**
     * Plugin activation
     */
    public function activate() {
        // Check requirements again during activation
        if ( version_compare( get_bloginfo( 'version' ), self::MIN_WP_VERSION, '<' ) ||
             version_compare( PHP_VERSION, self::MIN_PHP_VERSION, '<' ) ) {
            wp_die( 
                esc_html__( 'Pin Throttle requires WordPress 5.0+ and PHP 7.4+', 'pin-throttle' ),
                esc_html__( 'Plugin Activation Error', 'pin-throttle' ),
                [ 'back_link' => true ]
            );
        }
        
        $this->create_database_table();
        $this->set_default_options();
        
        // Clear any existing cron jobs and reschedule
        wp_clear_scheduled_hook( 'pin_throttle_cleanup' );
        wp_schedule_event( time(), 'daily', 'pin_throttle_cleanup' );
        
        // Flush rewrite rules if needed
        flush_rewrite_rules();
    }
    
    /**
     * Plugin deactivation
     */
    public function deactivate() {
        // Clear cron jobs
        wp_clear_scheduled_hook( 'pin_throttle_cleanup' );
        
        // Flush rewrite rules
        flush_rewrite_rules();
    }
    
    /**
     * Plugin uninstall
     */
    public static function uninstall() {
        $settings = get_option( 'pin_throttle_settings', [] );
        
        if ( ! empty( $settings['delete_data_on_uninstall'] ) ) {
            global $wpdb;
            
            // Remove database table
            $table_name = $wpdb->prefix . 'pin_throttle_logs';
            $wpdb->query( "DROP TABLE IF EXISTS {$table_name}" );
            
            // Remove options
            delete_option( 'pin_throttle_settings' );
            delete_option( 'pin_throttle_last_notification' );
            delete_option( 'pin_throttle_last_cleanup' );
            
            // Clear cron jobs
            wp_clear_scheduled_hook( 'pin_throttle_cleanup' );
        }
    }
    
    /**
     * Create database table
     */
    private function create_database_table() {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'pin_throttle_logs';
        $charset_collate = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE IF NOT EXISTS {$table_name} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            ip CHAR(45) NOT NULL,
            user_agent VARCHAR(500) NOT NULL DEFAULT '',
            request_time DATETIME NOT NULL,
            request_count INT UNSIGNED NOT NULL DEFAULT 1,
            status ENUM('good_bot','bad_bot','human','blocked') NOT NULL DEFAULT 'human',
            PRIMARY KEY (id),
            KEY ip_time (ip, request_time),
            KEY request_time (request_time),
            KEY status_time (status, request_time)
        ) {$charset_collate};";
        
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );
        
        // Check if table was created successfully
        if ( $wpdb->get_var( "SHOW TABLES LIKE '{$table_name}'" ) !== $table_name ) {
            wp_die( 
                esc_html__( 'Failed to create database table for Pin Throttle', 'pin-throttle' ),
                esc_html__( 'Database Error', 'pin-throttle' ),
                [ 'back_link' => true ]
            );
        }
    }
    
    /**
     * Set default options
     */
    private function set_default_options() {
        $default_settings = $this->get_default_settings();
        
        // Only add if option doesn't exist
        if ( false === get_option( 'pin_throttle_settings' ) ) {
            add_option( 'pin_throttle_settings', $default_settings );
        }
        
        if ( false === get_option( 'pin_throttle_last_notification' ) ) {
            add_option( 'pin_throttle_last_notification', 0 );
        }
        
        if ( false === get_option( 'pin_throttle_last_cleanup' ) ) {
            add_option( 'pin_throttle_last_cleanup', current_time( 'mysql' ) );
        }
    }
    
    /**
     * WordPress version notice
     */
    public function wp_version_notice() {
        $message = sprintf(
            /* translators: %1$s: required WordPress version, %2$s: current WordPress version */
            esc_html__( 'Pin Throttle requires WordPress version %1$s or higher. You are running version %2$s. Please upgrade WordPress.', 'pin-throttle' ),
            self::MIN_WP_VERSION,
            get_bloginfo( 'version' )
        );
        
        printf(
            '<div class="notice notice-error"><p>%s</p></div>',
            $message
        );
    }
    
    /**
     * PHP version notice
     */
    public function php_version_notice() {
        $message = sprintf(
            /* translators: %1$s: required PHP version, %2$s: current PHP version */
            esc_html__( 'Pin Throttle requires PHP version %1$s or higher. You are running version %2$s. Please upgrade PHP.', 'pin-throttle' ),
            self::MIN_PHP_VERSION,
            PHP_VERSION
        );
        
        printf(
            '<div class="notice notice-error"><p>%s</p></div>',
            $message
        );
    }
}

/**
 * Initialize the plugin
 */
function pin_throttle() {
    return Pin_Throttle_Plugin::get_instance();
}

// Start the plugin
pin_throttle();

/**
 * Legacy function for backward compatibility
 * @deprecated 1.2.0
 */
function pin_throttle_activate() {
    pin_throttle()->activate();
}