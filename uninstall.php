<?php
/**
 * Pin Throttle Plugin Uninstall Script
 * 
 * This file is executed when the plugin is uninstalled via WordPress admin.
 * It handles cleanup of all plugin data if the user has enabled this option.
 * 
 * @package Pin_Throttle
 * @version 1.2.0
 */

// Security checks - prevent direct access and ensure proper uninstall context
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

// Additional security check - ensure we're in WordPress context
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Verify we have the correct plugin being uninstalled
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) || 
     WP_UNINSTALL_PLUGIN !== plugin_basename( dirname( __FILE__ ) . '/pin-throttle.php' ) ) {
    exit;
}

/**
 * Pin Throttle Uninstall Handler Class
 */
final class Pin_Throttle_Uninstaller {
    
    /**
     * Plugin table name
     *
     * @var string
     */
    private $table_name;
    
    /**
     * Plugin options to remove
     *
     * @var array
     */
    private $plugin_options = [
        'pin_throttle_settings',
        'pin_throttle_last_cleanup',
        'pin_throttle_last_notification',
    ];
    
    /**
     * Constructor
     */
    public function __construct() {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'pin_throttle_logs';
    }
    
    /**
     * Run the uninstall process
     *
     * @return void
     */
    public function run() {
        try {
            // Get plugin settings to check if data deletion is enabled
            $settings = $this->get_plugin_settings();
            
            // Only proceed with data deletion if explicitly enabled
            if ( $this->should_delete_data( $settings ) ) {
                $this->cleanup_database();
                $this->cleanup_options();
                $this->cleanup_cron_jobs();
                $this->cleanup_transients();
                
                // Log successful cleanup if logging is available
                $this->log_cleanup_completion();
            }
            
        } catch ( Exception $e ) {
            // Silent fail - we don't want to break uninstall process
            // In production, this could be logged to error_log if needed
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log( 'Pin Throttle Uninstall Error: ' . $e->getMessage() );
            }
        }
    }
    
    /**
     * Get plugin settings safely
     *
     * @return array
     */
    private function get_plugin_settings() {
        $settings = get_option( 'pin_throttle_settings', [] );
        
        // Ensure settings is always an array
        if ( ! is_array( $settings ) ) {
            $settings = [];
        }
        
        return $settings;
    }
    
    /**
     * Check if data should be deleted during uninstall
     *
     * @param array $settings Plugin settings
     * @return bool
     */
    private function should_delete_data( $settings ) {
        return ! empty( $settings['delete_data_on_uninstall'] );
    }
    
    /**
     * Clean up database tables
     *
     * @return void
     */
    private function cleanup_database() {
        global $wpdb;
        
        // Check if table exists before attempting to drop
        if ( ! $this->table_exists() ) {
            return;
        }
        
        // Use wpdb methods for safer database operations
        $result = $wpdb->query( 
            $wpdb->prepare( 
                'DROP TABLE IF EXISTS %1s',
                $this->table_name 
            ) 
        );
        
        // Verify table was actually dropped
        if ( false === $result ) {
            throw new Exception( 'Failed to drop plugin database table' );
        }
    }
    
    /**
     * Check if plugin table exists
     *
     * @return bool
     */
    private function table_exists() {
        global $wpdb;
        
        $table_exists = $wpdb->get_var( 
            $wpdb->prepare( 
                'SHOW TABLES LIKE %s', 
                $this->table_name 
            ) 
        );
        
        return $table_exists === $this->table_name;
    }
    
    /**
     * Clean up plugin options
     *
     * @return void
     */
    private function cleanup_options() {
        foreach ( $this->plugin_options as $option_name ) {
            $deleted = delete_option( $option_name );
            
            // Also try to delete from site options for multisite
            if ( is_multisite() ) {
                delete_site_option( $option_name );
            }
        }
        
        // Clean up any option that might have been created with a prefix
        $this->cleanup_prefixed_options();
    }
    
    /**
     * Clean up options with plugin prefix
     *
     * @return void
     */
    private function cleanup_prefixed_options() {
        global $wpdb;
        
        // Find any options that start with our plugin prefix
        $options_to_delete = $wpdb->get_col( 
            $wpdb->prepare( 
                "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
                'pin_throttle_%'
            ) 
        );
        
        if ( ! empty( $options_to_delete ) && is_array( $options_to_delete ) ) {
            foreach ( $options_to_delete as $option_name ) {
                delete_option( sanitize_text_field( $option_name ) );
            }
        }
    }
    
    /**
     * Clean up cron jobs
     *
     * @return void
     */
    private function cleanup_cron_jobs() {
        // Remove scheduled cleanup events
        $cron_hooks = [
            'pin_throttle_cleanup',
            'pin_throttle_notification',
        ];
        
        foreach ( $cron_hooks as $hook ) {
            $timestamp = wp_next_scheduled( $hook );
            if ( $timestamp ) {
                wp_unschedule_event( $timestamp, $hook );
            }
            
            // Also clear all scheduled instances
            wp_clear_scheduled_hook( $hook );
        }
    }
    
    /**
     * Clean up transients
     *
     * @return void
     */
    private function cleanup_transients() {
        global $wpdb;
        
        // Delete any transients created by the plugin
        $transient_keys = $wpdb->get_col( 
            $wpdb->prepare( 
                "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
                '_transient_pin_throttle_%',
                '_transient_timeout_pin_throttle_%'
            ) 
        );
        
        if ( ! empty( $transient_keys ) && is_array( $transient_keys ) ) {
            foreach ( $transient_keys as $transient_key ) {
                delete_option( sanitize_text_field( $transient_key ) );
            }
        }
        
        // Clean up site transients for multisite
        if ( is_multisite() ) {
            $site_transient_keys = $wpdb->get_col( 
                $wpdb->prepare( 
                    "SELECT meta_key FROM {$wpdb->sitemeta} WHERE meta_key LIKE %s OR meta_key LIKE %s",
                    '_site_transient_pin_throttle_%',
                    '_site_transient_timeout_pin_throttle_%'
                ) 
            );
            
            if ( ! empty( $site_transient_keys ) && is_array( $site_transient_keys ) ) {
                foreach ( $site_transient_keys as $transient_key ) {
                    delete_site_transient( 
                        str_replace( '_site_transient_', '', sanitize_text_field( $transient_key ) )
                    );
                }
            }
        }
    }
    
    /**
     * Log cleanup completion
     *
     * @return void
     */
    private function log_cleanup_completion() {
        // Only log if debug is enabled
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG && defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
            error_log( 
                sprintf( 
                    'Pin Throttle: Successfully cleaned up all plugin data during uninstall at %s',
                    current_time( 'mysql' )
                )
            );
        }
    }
    
    /**
     * Get memory usage for debugging
     *
     * @return string
     */
    private function get_memory_usage() {
        if ( function_exists( 'memory_get_usage' ) ) {
            return number_format( memory_get_usage( true ) / 1024 / 1024, 2 ) . ' MB';
        }
        return 'Unknown';
    }
}

/**
 * Run the uninstaller
 */
function pin_throttle_run_uninstaller() {
    $uninstaller = new Pin_Throttle_Uninstaller();
    $uninstaller->run();
}

// Execute uninstall
pin_throttle_run_uninstaller();