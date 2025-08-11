<?php
/**
 * Pin Throttle Logger Class
 * 
 * Handles logging of throttle events to database and optionally to file.
 * Provides methods for cleanup and statistics retrieval.
 * 
 * @package Pin_Throttle
 * @version 1.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Pin Throttle Logger Class
 */
final class Pin_Throttle_Logger {

    /**
     * Database table name
     *
     * @var string
     */
    private $table_name;

    /**
     * Valid status values
     *
     * @var array
     */
    private const VALID_STATUSES = [ 'good_bot', 'bad_bot', 'human', 'blocked' ];

    /**
     * Maximum log file size in bytes (10MB)
     *
     * @var int
     */
    private const MAX_LOG_FILE_SIZE = 10485760;

    /**
     * Cache group for wp_cache functions
     *
     * @var string
     */
    private const CACHE_GROUP = 'pin_throttle';

    /**
     * Cache expiration time in seconds
     *
     * @var int
     */
    private const CACHE_EXPIRATION = 60;

    /**
     * Maximum user agent length
     *
     * @var int
     */
    private const MAX_USER_AGENT_LENGTH = 500;

    /**
     * Plugin settings
     *
     * @var array
     */
    private $settings;

    /**
     * Constructor
     */
    public function __construct() {
        global $wpdb;
        
        $this->table_name = $wpdb->prefix . 'pin_throttle_logs';
        $this->settings = $this->load_settings();
        
        // Validate table exists
        $this->validate_table_exists();
    }

    /**
     * Load plugin settings
     *
     * @return array
     */
    private function load_settings() {
        $settings = get_option( 'pin_throttle_settings', [] );
        
        // Ensure it's an array
        return is_array( $settings ) ? $settings : [];
    }

    /**
     * Validate that the database table exists
     *
     * @return bool
     */
    private function validate_table_exists() {
        global $wpdb;
        
        static $table_checked = false;
        
        if ( $table_checked ) {
            return true;
        }
        
        $table_exists = $wpdb->get_var( $wpdb->prepare(
            'SHOW TABLES LIKE %s',
            $this->table_name
        ) );
        
        if ( $table_exists !== $this->table_name ) {
            $this->log_error( 'Database table does not exist: ' . $this->table_name );
            return false;
        }
        
        $table_checked = true;
        return true;
    }

    /**
     * Log request to database
     *
     * @param string $ip IP address
     * @param string $user_agent User agent string
     * @param string $status Request status
     * @param int    $request_count Number of requests
     * @return bool Success status
     */
    public function log_to_db( $ip, $user_agent, $status = 'human', $request_count = 1 ) {
        global $wpdb;

        // Validate and sanitize input
        $validated_data = $this->validate_log_data( $ip, $user_agent, $status, $request_count );
        if ( false === $validated_data ) {
            return false;
        }

        // Check table exists
        if ( ! $this->validate_table_exists() ) {
            return false;
        }

        // Prepare data for insertion
        $data = [
            'ip'            => $validated_data['ip'],
            'user_agent'    => $validated_data['user_agent'],
            'request_time'  => $validated_data['request_time'],
            'request_count' => $validated_data['request_count'],
            'status'        => $validated_data['status'],
        ];

        $format = [ '%s', '%s', '%s', '%d', '%s' ];

        // Insert with error handling
        $result = $wpdb->insert( $this->table_name, $data, $format );

        if ( false === $result ) {
            $this->log_error( 'Database insert failed: ' . $wpdb->last_error );
            return false;
        }

        // Clear related cache
        $this->clear_request_count_cache( $validated_data['ip'] );

        return true;
    }

    /**
     * Validate and sanitize log data
     *
     * @param string $ip IP address
     * @param string $user_agent User agent string
     * @param string $status Request status
     * @param int    $request_count Number of requests
     * @return array|false Validated data or false on failure
     */
    private function validate_log_data( $ip, $user_agent, $status, $request_count ) {
        // Validate IP address
        if ( ! $this->is_valid_ip( $ip ) ) {
            $this->log_error( 'Invalid IP address: ' . esc_html( $ip ) );
            return false;
        }

        // Sanitize and validate user agent
        $user_agent = $this->sanitize_user_agent( $user_agent );
        if ( false === $user_agent ) {
            return false;
        }

        // Validate status
        $status = $this->validate_status( $status );

        // Validate request count
        $request_count = $this->validate_request_count( $request_count );

        return [
            'ip'            => $ip,
            'user_agent'    => $user_agent,
            'status'        => $status,
            'request_count' => $request_count,
            'request_time'  => current_time( 'mysql', 1 ), // UTC time
        ];
    }

    /**
     * Validate IP address
     *
     * @param string $ip IP address to validate
     * @return bool
     */
    private function is_valid_ip( $ip ) {
        if ( empty( $ip ) || ! is_string( $ip ) ) {
            return false;
        }

        // Validate both IPv4 and IPv6
        return false !== filter_var( 
            $ip, 
            FILTER_VALIDATE_IP, 
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE 
        ) || false !== filter_var( $ip, FILTER_VALIDATE_IP );
    }

    /**
     * Sanitize user agent string
     *
     * @param string $user_agent User agent to sanitize
     * @return string|false Sanitized user agent or false on failure
     */
    private function sanitize_user_agent( $user_agent ) {
        if ( ! is_string( $user_agent ) ) {
            $user_agent = '';
        }

        // Sanitize and truncate
        $user_agent = sanitize_text_field( $user_agent );
        $user_agent = substr( $user_agent, 0, self::MAX_USER_AGENT_LENGTH );

        return $user_agent;
    }

    /**
     * Validate status value
     *
     * @param string $status Status to validate
     * @return string Valid status
     */
    private function validate_status( $status ) {
        if ( ! is_string( $status ) || ! in_array( $status, self::VALID_STATUSES, true ) ) {
            return 'human';
        }
        
        return $status;
    }

    /**
     * Validate request count
     *
     * @param mixed $request_count Request count to validate
     * @return int Valid request count
     */
    private function validate_request_count( $request_count ) {
        $count = absint( $request_count );
        return max( 1, $count );
    }

    /**
     * Log to file (optional)
     *
     * @param array $data Data to log
     * @return bool Success status
     */
    public function log_to_file( array $data = [] ) {
        // Check if file logging is enabled
        if ( empty( $this->settings['log_to_file'] ) ) {
            return true; // Not an error if disabled
        }

        try {
            $log_file_path = $this->get_log_file_path();
            if ( false === $log_file_path ) {
                return false;
            }

            // Handle log rotation
            $this->rotate_log_file_if_needed( $log_file_path );

            // Ensure directory exists
            if ( ! $this->ensure_log_directory( $log_file_path ) ) {
                return false;
            }

            // Prepare log entry
            $entry = $this->format_log_entry( $data );

            // Write to file with proper locking
            $result = $this->write_log_entry( $log_file_path, $entry );

            return false !== $result;

        } catch ( Exception $e ) {
            $this->log_error( 'File logging exception: ' . $e->getMessage() );
            return false;
        }
    }

    /**
     * Get log file path
     *
     * @return string|false Log file path or false on error
     */
    private function get_log_file_path() {
        $upload_dir = wp_upload_dir();
        
        if ( ! empty( $upload_dir['error'] ) ) {
            $this->log_error( 'Upload directory error: ' . $upload_dir['error'] );
            return false;
        }

        return $upload_dir['basedir'] . '/pin-throttle.log';
    }

    /**
     * Rotate log file if it exceeds size limit
     *
     * @param string $log_file_path Path to log file
     * @return void
     */
    private function rotate_log_file_if_needed( $log_file_path ) {
        if ( ! file_exists( $log_file_path ) ) {
            return;
        }

        $file_size = filesize( $log_file_path );
        if ( false === $file_size || $file_size <= self::MAX_LOG_FILE_SIZE ) {
            return;
        }

        $rotated_file = $log_file_path . '.old.' . time();
        
        if ( ! rename( $log_file_path, $rotated_file ) ) {
            $this->log_error( 'Failed to rotate log file: ' . $log_file_path );
        }
    }

    /**
     * Ensure log directory exists
     *
     * @param string $log_file_path Path to log file
     * @return bool Success status
     */
    private function ensure_log_directory( $log_file_path ) {
        $log_dir = dirname( $log_file_path );
        
        if ( ! wp_mkdir_p( $log_dir ) ) {
            $this->log_error( 'Cannot create log directory: ' . $log_dir );
            return false;
        }
        
        return true;
    }

    /**
     * Format log entry
     *
     * @param array $data Data to format
     * @return string Formatted log entry
     */
    private function format_log_entry( array $data ) {
        // Add timestamp if not present
        if ( empty( $data['timestamp'] ) ) {
            $data['timestamp'] = current_time( 'Y-m-d H:i:s' );
        }

        // Sanitize data for JSON
        $sanitized_data = $this->sanitize_log_data_for_json( $data );

        return sprintf(
            "[%s] %s%s",
            $sanitized_data['timestamp'],
            wp_json_encode( $sanitized_data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
            PHP_EOL
        );
    }

    /**
     * Sanitize log data for JSON output
     *
     * @param array $data Data to sanitize
     * @return array Sanitized data
     */
    private function sanitize_log_data_for_json( array $data ) {
        $sanitized = [];
        
        foreach ( $data as $key => $value ) {
            $key = sanitize_key( $key );
            
            if ( is_string( $value ) ) {
                $value = sanitize_text_field( $value );
            } elseif ( is_numeric( $value ) ) {
                $value = is_int( $value ) ? intval( $value ) : floatval( $value );
            }
            
            $sanitized[ $key ] = $value;
        }
        
        return $sanitized;
    }

    /**
     * Write log entry to file
     *
     * @param string $log_file_path Path to log file
     * @param string $entry Log entry to write
     * @return int|false Number of bytes written or false on failure
     */
    private function write_log_entry( $log_file_path, $entry ) {
        return file_put_contents( 
            $log_file_path, 
            $entry, 
            FILE_APPEND | LOCK_EX 
        );
    }

    /**
     * Main logging method
     *
     * @param string $ip IP address
     * @param string $user_agent User agent string
     * @param string $status Request status
     * @param int    $request_count Number of requests
     * @return bool Overall success status
     */
    public function log( $ip, $user_agent, $status = 'human', $request_count = 1 ) {
        $data = [
            'ip'            => $ip,
            'user_agent'    => $user_agent,
            'status'        => $status,
            'request_count' => $request_count,
            'timestamp'     => current_time( 'Y-m-d H:i:s' ),
        ];

        $success = true;

        // Log to file if enabled
        if ( ! empty( $this->settings['log_to_file'] ) ) {
            $file_success = $this->log_to_file( $data );
            $success = $success && $file_success;
        }

        // Always log to database
        $db_success = $this->log_to_db( $ip, $user_agent, $status, $request_count );
        $success = $success && $db_success;

        return $success;
    }

    /**
     * Clean old log entries
     *
     * @param int $days Number of days to keep logs
     * @return int Number of deleted records
     */
    public function cleanup_old_logs( $days = 7 ) {
        global $wpdb;

        // Validate input
        $days = $this->validate_cleanup_days( $days );
        
        // Check table exists
        if ( ! $this->validate_table_exists() ) {
            return 0;
        }

        // Calculate cutoff date
        $cutoff_date = $this->get_cutoff_date( $days );

        // Execute cleanup query
        $deleted = $wpdb->query( $wpdb->prepare(
            "DELETE FROM {$this->table_name} WHERE request_time < %s",
            $cutoff_date
        ) );

        if ( false === $deleted ) {
            $this->log_error( 'Cleanup query failed: ' . $wpdb->last_error );
            return 0;
        }

        // Clear all request count cache after cleanup
        $this->clear_all_request_count_cache();

        return absint( $deleted );
    }

    /**
     * Validate cleanup days parameter
     *
     * @param mixed $days Days to validate
     * @return int Valid days value
     */
    private function validate_cleanup_days( $days ) {
        $days = absint( $days );
        return max( 1, $days );
    }

    /**
     * Get cutoff date for cleanup
     *
     * @param int $days Number of days
     * @return string Cutoff date in MySQL format
     */
    private function get_cutoff_date( $days ) {
        return gmdate( 'Y-m-d H:i:s', strtotime( "-{$days} days" ) );
    }

    /**
     * Get request count for IP in time period
     *
     * @param string $ip IP address
     * @param int    $minutes Time period in minutes
     * @return int Request count
     */
    public function get_request_count( $ip, $minutes = 1 ) {
        global $wpdb;

        // Validate IP
        if ( ! $this->is_valid_ip( $ip ) ) {
            return 0;
        }

        // Validate minutes
        $minutes = $this->validate_minutes( $minutes );

        // Check cache first
        $cache_key = $this->get_cache_key( $ip, $minutes );
        $count = wp_cache_get( $cache_key, self::CACHE_GROUP );
        
        if ( false !== $count ) {
            return absint( $count );
        }

        // Check table exists
        if ( ! $this->validate_table_exists() ) {
            return 0;
        }

        // Query database
        $cutoff_time = $this->get_cutoff_time( $minutes );
        
        $count = $wpdb->get_var( $wpdb->prepare(
            "SELECT SUM(request_count) FROM {$this->table_name} WHERE ip = %s AND request_time >= %s",
            $ip,
            $cutoff_time
        ) );

        // Sanitize result
        $count = absint( $count );

        // Cache result
        wp_cache_set( $cache_key, $count, self::CACHE_GROUP, self::CACHE_EXPIRATION );

        return $count;
    }

    /**
     * Validate minutes parameter
     *
     * @param mixed $minutes Minutes to validate
     * @return int Valid minutes value
     */
    private function validate_minutes( $minutes ) {
        $minutes = absint( $minutes );
        return max( 1, $minutes );
    }

    /**
     * Get cache key for request count
     *
     * @param string $ip IP address
     * @param int    $minutes Minutes
     * @return string Cache key
     */
    private function get_cache_key( $ip, $minutes ) {
        return sprintf( 'pin_throttle_count_%s_%d', md5( $ip ), $minutes );
    }

    /**
     * Get cutoff time for request count query
     *
     * @param int $minutes Minutes to subtract
     * @return string Cutoff time in MySQL format
     */
    private function get_cutoff_time( $minutes ) {
        return gmdate( 'Y-m-d H:i:s', strtotime( "-{$minutes} minutes" ) );
    }

    /**
     * Clear request count cache for specific IP
     *
     * @param string $ip IP address
     * @return void
     */
    private function clear_request_count_cache( $ip ) {
        // Clear cache for common minute values
        $common_minutes = [ 1, 5, 10, 15, 30, 60 ];
        
        foreach ( $common_minutes as $minutes ) {
            $cache_key = $this->get_cache_key( $ip, $minutes );
            wp_cache_delete( $cache_key, self::CACHE_GROUP );
        }
    }

    /**
     * Clear all request count cache
     *
     * @return void
     */
    private function clear_all_request_count_cache() {
        wp_cache_flush_group( self::CACHE_GROUP );
    }

    /**
     * Log error message
     *
     * @param string $message Error message
     * @return void
     */
    private function log_error( $message ) {
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log( 'Pin Throttle Logger: ' . $message );
        }
    }

    /**
     * Get table name
     *
     * @return string Table name
     */
    public function get_table_name() {
        return $this->table_name;
    }

    /**
     * Get valid statuses
     *
     * @return array Valid status values
     */
    public static function get_valid_statuses() {
        return self::VALID_STATUSES;
    }
}