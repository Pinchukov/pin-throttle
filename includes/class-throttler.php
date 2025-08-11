<?php
/**
 * Pin Throttle Throttler Class
 * 
 * Handles request throttling, rate limiting, and bot detection.
 * Main class responsible for analyzing incoming requests and applying throttling rules.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Pin_Throttle_Throttler {

    /**
     * Logger instance for recording requests
     * 
     * @var Pin_Throttle_Logger
     */
    private $logger;

    /**
     * Plugin settings
     * 
     * @var array
     */
    private $settings;

    /**
     * Default settings values
     * 
     * @var array
     */
    private $default_settings = [
        'limit_per_minute'     => 30,
        'block_time'          => 30,
        'auto_cleanup_days'   => 7,
        'whitelist'           => [],
        'allowed_bots'        => [],
        'blocked_bots'        => [],
        'enable_notifications' => false,
        'notification_emails' => '',
    ];

    /**
     * Headers to check for client IP (in order of priority)
     * 
     * @var array
     */
    private $ip_headers = [
        'HTTP_CF_CONNECTING_IP',     // Cloudflare
        'HTTP_CLIENT_IP',            // Proxy
        'HTTP_X_FORWARDED_FOR',      // Load balancer/proxy
        'HTTP_X_FORWARDED',          // Proxy
        'HTTP_X_CLUSTER_CLIENT_IP',  // Cluster
        'HTTP_FORWARDED_FOR',        // Proxy
        'HTTP_FORWARDED',            // Proxy
        'REMOTE_ADDR',               // Standard
    ];

    /**
     * Notification cooldown period in seconds
     * 
     * @var int
     */
    private $notification_cooldown = 900; // 15 minutes

    /**
     * Initialize the throttler
     */
    public function __construct() {
        $this->init_dependencies();
        $this->init_hooks();
    }

    /**
     * Initialize dependencies
     */
    private function init_dependencies() {
        if ( class_exists( 'Pin_Throttle_Logger' ) ) {
            $this->logger = new Pin_Throttle_Logger();
        } else {
            // Fallback if logger class doesn't exist yet
            add_action( 'plugins_loaded', [ $this, 'init_logger' ] );
        }
        
        $this->load_settings();
    }

    /**
     * Initialize logger (fallback method)
     */
    public function init_logger() {
        if ( class_exists( 'Pin_Throttle_Logger' ) && ! $this->logger ) {
            $this->logger = new Pin_Throttle_Logger();
        }
    }

    /**
     * Load plugin settings with defaults
     */
    private function load_settings() {
        $saved_settings = get_option( 'pin_throttle_settings', [] );
        $this->settings = wp_parse_args( $saved_settings, $this->default_settings );
    }

    /**
     * Initialize WordPress hooks
     */
    private function init_hooks() {
        add_action( 'init', [ $this, 'check_requests' ], 1 );
        add_action( 'shutdown', [ $this, 'cleanup_logs' ] );
    }

    /**
     * Check if request should skip throttling
     * 
     * @return bool True if throttling should be skipped
     */
    private function should_skip_throttling() {
        // Skip for admin area
        if ( is_admin() ) {
            return true;
        }

        // Skip for various WordPress operations
        $skip_conditions = [
            'DOING_AJAX',
            'REST_REQUEST', 
            'DOING_CRON',
            'WP_CLI',
            'XMLRPC_REQUEST',
            'DOING_AUTOSAVE',
        ];

        foreach ( $skip_conditions as $condition ) {
            if ( defined( $condition ) && constant( $condition ) ) {
                return true;
            }
        }

        // Skip for login/register pages to avoid blocking legitimate users
        if ( $this->is_login_page() ) {
            return true;
        }

        return false;
    }

    /**
     * Check if current request is for login/register page
     * 
     * @return bool True if login/register page
     */
    private function is_login_page() {
        global $pagenow;
        
        $login_pages = [ 'wp-login.php', 'wp-register.php' ];
        return in_array( $pagenow, $login_pages, true );
    }

    /**
     * Get client IP address with enhanced detection
     * 
     * @return string|null Client IP or null if not found
     */
    private function get_client_ip() {
        foreach ( $this->ip_headers as $header ) {
            if ( empty( $_SERVER[ $header ] ) ) {
                continue;
            }

            $ip_list = sanitize_text_field( $_SERVER[ $header ] );
            
            // Handle comma-separated IPs (common with proxies)
            $ips = array_map( 'trim', explode( ',', $ip_list ) );
            
            foreach ( $ips as $ip ) {
                // Remove port number if present
                $ip = preg_replace( '/:\d+$/', '', $ip );
                
                // Validate IP and exclude private ranges for forwarded headers
                if ( $this->is_valid_public_ip( $ip, $header ) ) {
                    return $ip;
                }
            }
        }

        return null;
    }

    /**
     * Validate IP address and check if it's suitable for throttling
     * 
     * @param string $ip IP address to validate
     * @param string $header Header name the IP came from
     * @return bool True if IP is valid and suitable
     */
    private function is_valid_public_ip( $ip, $header ) {
        if ( ! filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
            // For REMOTE_ADDR, allow private IPs (local development)
            if ( $header === 'REMOTE_ADDR' ) {
                return filter_var( $ip, FILTER_VALIDATE_IP ) !== false;
            }
            return false;
        }

        return true;
    }

    /**
     * Get and sanitize user agent
     * 
     * @return string Sanitized user agent
     */
    private function get_user_agent() {
        if ( empty( $_SERVER['HTTP_USER_AGENT'] ) ) {
            return 'unknown';
        }

        $user_agent = sanitize_text_field( $_SERVER['HTTP_USER_AGENT'] );
        
        // Limit length to prevent database issues
        return mb_substr( $user_agent, 0, 500 );
    }

    /**
     * Check if IP is whitelisted
     * 
     * @param string $ip IP address to check
     * @return bool True if whitelisted
     */
    private function is_whitelisted( $ip ) {
        $whitelist = $this->settings['whitelist'];
        
        if ( ! is_array( $whitelist ) || empty( $whitelist ) ) {
            return false;
        }

        return in_array( $ip, $whitelist, true );
    }

    /**
     * Check if user agent belongs to allowed bot
     * 
     * @param string $user_agent User agent string
     * @return bool True if allowed bot
     */
    private function is_allowed_bot( $user_agent ) {
        $allowed_bots = $this->settings['allowed_bots'];
        
        if ( ! is_array( $allowed_bots ) || empty( $allowed_bots ) ) {
            return false;
        }

        $user_agent_lower = strtolower( $user_agent );
        
        foreach ( $allowed_bots as $bot_name ) {
            if ( empty( $bot_name ) ) {
                continue;
            }
            
            if ( stripos( $user_agent_lower, strtolower( $bot_name ) ) !== false ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if user agent belongs to blocked bot
     * 
     * @param string $user_agent User agent string
     * @return bool True if blocked bot
     */
    private function is_blocked_bot( $user_agent ) {
        $blocked_bots = $this->settings['blocked_bots'];
        
        if ( ! is_array( $blocked_bots ) || empty( $blocked_bots ) ) {
            return false;
        }

        $user_agent_lower = strtolower( $user_agent );
        
        foreach ( $blocked_bots as $bot_name ) {
            if ( empty( $bot_name ) ) {
                continue;
            }
            
            if ( stripos( $user_agent_lower, strtolower( $bot_name ) ) !== false ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if IP has exceeded rate limit
     * 
     * @param string $ip IP address to check
     * @return bool True if rate limited
     */
    private function is_rate_limited( $ip ) {
        if ( ! $this->logger ) {
            return false; // Can't check without logger
        }

        $limit = max( 1, intval( $this->settings['limit_per_minute'] ) );
        $count = $this->logger->get_request_count( $ip, 1 );
        
        return $count >= $limit;
    }

    /**
     * Send email notification about potential attack
     * 
     * @param string $ip Attacking IP address
     * @param int $request_count Number of requests
     */
    private function send_attack_notification( $ip, $request_count ) {
        if ( ! $this->should_send_notification() ) {
            return;
        }

        $emails = $this->get_notification_emails();
        if ( empty( $emails ) ) {
            return;
        }

        if ( $this->is_notification_on_cooldown() ) {
            return;
        }

        $this->send_notification_email( $ip, $request_count, $emails );
        $this->update_notification_timestamp();
    }

    /**
     * Check if notifications should be sent
     * 
     * @return bool True if notifications enabled
     */
    private function should_send_notification() {
        return ! empty( $this->settings['enable_notifications'] );
    }

    /**
     * Get valid notification email addresses
     * 
     * @return array Valid email addresses
     */
    private function get_notification_emails() {
        $emails_raw = $this->settings['notification_emails'];
        
        if ( empty( $emails_raw ) ) {
            return [];
        }

        $emails = array_map( 'trim', preg_split( '/[\s,;]+/', $emails_raw ) );
        return array_filter( $emails, 'is_email' );
    }

    /**
     * Check if notification is on cooldown
     * 
     * @return bool True if on cooldown
     */
    private function is_notification_on_cooldown() {
        $last_notification = get_option( 'pin_throttle_last_notification', 0 );
        return ( time() - intval( $last_notification ) ) < $this->notification_cooldown;
    }

    /**
     * Send notification email
     * 
     * @param string $ip Attacking IP
     * @param int $request_count Request count
     * @param array $emails Email addresses
     */
    private function send_notification_email( $ip, $request_count, $emails ) {
        $subject = sprintf(
            /* translators: %s: IP address */
            __( 'Pin Throttle: Mass attack detected from IP %s', 'pin-throttle' ),
            $ip
        );

        $message = sprintf(
            /* translators: 1: IP address, 2: request count, 3: timestamp */
            __( "Massive number of requests detected from IP: %s\nRequests in last minute: %d\nTime: %s\nUser Agent: %s\n\nPlease check your website security.", 'pin-throttle' ),
            $ip,
            $request_count,
            wp_date( 'Y-m-d H:i:s' ),
            $this->get_user_agent()
        );

        // Use WordPress mail function with proper headers
        $headers = [
            'Content-Type: text/plain; charset=UTF-8',
            'From: ' . get_option( 'blogname' ) . ' <' . get_option( 'admin_email' ) . '>',
        ];

        wp_mail( $emails, $subject, $message, $headers );
    }

    /**
     * Update last notification timestamp
     */
    private function update_notification_timestamp() {
        update_option( 'pin_throttle_last_notification', time(), false );
    }

    /**
     * Block request and terminate
     * 
     * @param string $ip IP address
     * @param string $user_agent User agent
     * @param string $status Block status (default: 'blocked')
     */
    private function block_request( $ip, $user_agent, $status = 'blocked' ) {
        // Log the blocked request
        if ( $this->logger ) {
            $this->logger->log( $ip, $user_agent, $status, 1 );
        }

        // Set appropriate headers
        $this->set_block_headers();

        // Generate user-friendly error message
        $this->send_block_response();
    }

    /**
     * Set HTTP headers for blocked request
     */
    private function set_block_headers() {
        if ( headers_sent() ) {
            return;
        }

        $block_time_seconds = max( 60, intval( $this->settings['block_time'] ) * 60 );
        
        status_header( 429 );
        header( 'Retry-After: ' . $block_time_seconds );
        header( 'X-RateLimit-Limit: ' . intval( $this->settings['limit_per_minute'] ) );
        header( 'X-RateLimit-Window: 60' );
        header( 'Cache-Control: no-cache, no-store, must-revalidate' );
    }

    /**
     * Send block response and terminate
     */
    private function send_block_response() {
        $block_time = max( 1, intval( $this->settings['block_time'] ) );
        
        $message = sprintf(
            /* translators: %d: block time in minutes */
            __( 'Too many requests. Please try again in %d minutes.', 'pin-throttle' ),
            $block_time
        );

        wp_die(
            esc_html( $message ),
            esc_html__( 'Too Many Requests', 'pin-throttle' ),
            [
                'response' => 429,
                'back_link' => true,
            ]
        );
    }

    /**
     * Main request checking logic
     */
    public function check_requests() {
        // Early exit conditions
        if ( $this->should_skip_throttling() ) {
            return;
        }

        if ( ! $this->logger ) {
            return; // Can't function without logger
        }

        // Get client information
        $ip = $this->get_client_ip();
        if ( ! $ip ) {
            return; // Can't throttle without IP
        }

        $user_agent = $this->get_user_agent();

        // Process request through throttling pipeline
        $this->process_request( $ip, $user_agent );
    }

    /**
     * Process request through throttling pipeline
     * 
     * @param string $ip Client IP
     * @param string $user_agent User agent
     */
    private function process_request( $ip, $user_agent ) {
        // 1. Check for allowed bots (highest priority)
        if ( $this->is_allowed_bot( $user_agent ) ) {
            $this->logger->log( $ip, $user_agent, 'good_bot', 1 );
            return;
        }

        // 2. Check for blocked bots (immediate block)
        if ( $this->is_blocked_bot( $user_agent ) ) {
            $this->block_request( $ip, $user_agent, 'bad_bot' );
            exit;
        }

        // 3. Check IP whitelist
        if ( $this->is_whitelisted( $ip ) ) {
            $this->logger->log( $ip, $user_agent, 'whitelisted', 1 );
            return;
        }

        // 4. Check rate limiting
        if ( $this->is_rate_limited( $ip ) ) {
            $request_count = $this->logger->get_request_count( $ip, 1 );
            $this->send_attack_notification( $ip, $request_count );
            $this->block_request( $ip, $user_agent, 'blocked' );
            exit;
        }

        // 5. Log normal request
        $this->logger->log( $ip, $user_agent, 'allowed', 1 );
    }

    /**
     * Clean up old log entries
     */
    public function cleanup_logs() {
        if ( ! $this->logger ) {
            return;
        }

        $cleanup_days = max( 0, intval( $this->settings['auto_cleanup_days'] ) );
        
        if ( $cleanup_days === 0 ) {
            return; // Cleanup disabled
        }

        // Only run cleanup occasionally to avoid performance impact
        if ( ! $this->should_run_cleanup() ) {
            return;
        }

        $deleted = $this->logger->cleanup_old_logs( $cleanup_days );
        
        if ( $deleted > 0 ) {
            $this->log_cleanup_result( $deleted );
            $this->update_cleanup_timestamp();
        }
    }

    /**
     * Check if cleanup should run
     * 
     * @return bool True if cleanup should run
     */
    private function should_run_cleanup() {
        $last_cleanup = get_option( 'pin_throttle_last_cleanup_check', 0 );
        $cleanup_interval = 6 * HOUR_IN_SECONDS; // Run every 6 hours max
        
        return ( time() - intval( $last_cleanup ) ) > $cleanup_interval;
    }

    /**
     * Log cleanup results
     * 
     * @param int $deleted Number of deleted entries
     */
    private function log_cleanup_result( $deleted ) {
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log( sprintf( 
                'Pin Throttle: Cleaned up %d old log entries', 
                $deleted 
            ));
        }
    }

    /**
     * Update cleanup timestamp
     */
    private function update_cleanup_timestamp() {
        update_option( 'pin_throttle_last_cleanup', wp_date( 'Y-m-d H:i:s' ), false );
        update_option( 'pin_throttle_last_cleanup_check', time(), false );
    }

    /**
     * Get current settings (for external access)
     * 
     * @return array Current settings
     */
    public function get_settings() {
        return $this->settings;
    }

    /**
     * Refresh settings from database
     */
    public function refresh_settings() {
        $this->load_settings();
    }
}