<?php
/**
 * Pin Throttle Settings Class
 * 
 * Handles admin settings page for the Pin Throttle plugin.
 * Provides configuration options for throttling, logging, and notifications.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Pin_Throttle_Settings {

    /**
     * Settings option name in WordPress database
     * 
     * @var string
     */
    private $option_name = 'pin_throttle_settings';

    /**
     * Default settings values
     * 
     * @var array
     */
    private $default_settings = [
        'limit_per_minute'         => 30,
        'block_time'              => 30,
        'log_to_file'             => false,
        'auto_cleanup_days'       => 7,
        'whitelist'               => [],
        'allowed_bots'            => [],
        'blocked_bots'            => [],
        'delete_data_on_uninstall' => false,
        'enable_notifications'    => false,
        'notification_emails'     => '',
    ];

    /**
     * Database table name for logs
     * 
     * @var string
     */
    private $log_table;

    /**
     * Initialize the settings class
     */
    public function __construct() {
        global $wpdb;
        $this->log_table = $wpdb->prefix . 'pin_throttle_logs';
        
        $this->init_hooks();
    }

    /**
     * Initialize WordPress hooks
     */
    private function init_hooks() {
        add_action( 'admin_menu', [ $this, 'add_menu' ] );
        add_action( 'admin_init', [ $this, 'register_settings' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_admin_scripts' ] );
    }

    /**
     * Add settings page to WordPress admin menu
     */
    public function add_menu() {
        add_options_page(
            esc_html__( 'Pin Throttle Settings', 'pin-throttle' ),
            esc_html__( 'Pin Throttle', 'pin-throttle' ),
            'manage_options',
            'pin-throttle',
            [ $this, 'settings_page' ]
        );
    }

    /**
     * Register settings and fields with WordPress Settings API
     */
    public function register_settings() {
        register_setting( 
            $this->option_name, 
            $this->option_name, 
            [ $this, 'sanitize_settings' ] 
        );

        $this->add_settings_sections();
        $this->add_settings_fields();
    }

    /**
     * Add settings sections
     */
    private function add_settings_sections() {
        add_settings_section(
            'pin_throttle_main_section',
            esc_html__( 'Main Settings', 'pin-throttle' ),
            function () {
                echo '<p>' . esc_html__( 'Configure throttling settings for your website.', 'pin-throttle' ) . '</p>';
            },
            $this->option_name
        );
    }

    /**
     * Add all settings fields
     */
    private function add_settings_fields() {
        $fields = [
            'limit_per_minute'         => esc_html__( 'Requests per minute limit', 'pin-throttle' ),
            'block_time'              => esc_html__( 'Block time (minutes)', 'pin-throttle' ),
            'log_to_file'             => esc_html__( 'Log to file', 'pin-throttle' ),
            'auto_cleanup_days'       => esc_html__( 'Auto cleanup (days)', 'pin-throttle' ),
            'whitelist'               => esc_html__( 'IP Whitelist', 'pin-throttle' ),
            'allowed_bots'            => esc_html__( 'Allowed Bots (by User-Agent substring)', 'pin-throttle' ),
            'blocked_bots'            => esc_html__( 'Blocked Bots (by User-Agent substring)', 'pin-throttle' ),
            'delete_data_on_uninstall' => esc_html__( 'Delete data on uninstall', 'pin-throttle' ),
            'enable_notifications'    => esc_html__( 'Enable email notifications for attacks', 'pin-throttle' ),
            'notification_emails'     => esc_html__( 'Notification email addresses', 'pin-throttle' ),
        ];

        foreach ( $fields as $field_id => $field_title ) {
            add_settings_field(
                $field_id,
                $field_title,
                [ $this, "field_{$field_id}" ],
                $this->option_name,
                'pin_throttle_main_section'
            );
        }
    }

    /**
     * Sanitize and validate settings input
     *
     * @param array $input Raw input from form
     * @return array Sanitized settings
     */
    public function sanitize_settings( $input ) {
        if ( ! is_array( $input ) ) {
            return $this->get_default_settings();
        }

        $output = [];

        // Sanitize numeric fields
        $output['limit_per_minute'] = $this->sanitize_int_range( 
            $input['limit_per_minute'] ?? 30, 1, 1000 
        );
        $output['block_time'] = $this->sanitize_int_range( 
            $input['block_time'] ?? 30, 1, 1440 
        );
        $output['auto_cleanup_days'] = $this->sanitize_int_range( 
            $input['auto_cleanup_days'] ?? 7, 0, 365 
        );

        // Sanitize boolean fields
        $output['log_to_file'] = ! empty( $input['log_to_file'] );
        $output['delete_data_on_uninstall'] = ! empty( $input['delete_data_on_uninstall'] );
        $output['enable_notifications'] = ! empty( $input['enable_notifications'] );

        // Sanitize IP whitelist
        $output['whitelist'] = $this->sanitize_ip_list( $input['whitelist'] ?? '' );

        // Sanitize email addresses
        $output['notification_emails'] = $this->sanitize_email_list( $input['notification_emails'] ?? '' );

        // Sanitize bot lists
        $output['allowed_bots'] = $this->sanitize_bot_list( $input['allowed_bots'] ?? '' );
        $output['blocked_bots'] = $this->sanitize_bot_list( $input['blocked_bots'] ?? '' );

        return $output;
    }

    /**
     * Sanitize integer within range
     *
     * @param mixed $value Input value
     * @param int $min Minimum allowed value
     * @param int $max Maximum allowed value
     * @return int Sanitized integer
     */
    private function sanitize_int_range( $value, $min, $max ) {
        return max( $min, min( $max, intval( $value ) ) );
    }

    /**
     * Sanitize and validate IP address list
     *
     * @param string $input Raw IP list input
     * @return array Valid IP addresses
     */
    private function sanitize_ip_list( $input ) {
        if ( empty( $input ) ) {
            return [];
        }

        $ips = array_map( 'trim', explode( "\n", $input ) );
        $valid_ips = [];

        foreach ( $ips as $ip ) {
            if ( ! empty( $ip ) && filter_var( $ip, FILTER_VALIDATE_IP ) ) {
                $valid_ips[] = $ip;
            }
        }

        return $valid_ips;
    }

    /**
     * Sanitize email address list
     *
     * @param string $input Raw email list input
     * @return string Comma-separated valid emails
     */
    private function sanitize_email_list( $input ) {
        if ( empty( $input ) ) {
            return '';
        }

        $emails = array_map( 'trim', preg_split( '/[\s,;]+/', $input ) );
        $valid_emails = array_filter( $emails, 'is_email' );

        return implode( ', ', $valid_emails );
    }

    /**
     * Sanitize bot user-agent list
     *
     * @param string $input Raw bot list input
     * @return array Non-empty bot strings
     */
    private function sanitize_bot_list( $input ) {
        if ( empty( $input ) ) {
            return [];
        }

        $bots = array_map( 'trim', explode( "\n", $input ) );
        return array_filter( $bots, function( $bot ) {
            return ! empty( $bot );
        });
    }

    /**
     * Get current settings with defaults
     *
     * @return array Current settings
     */
    private function get_settings() {
        $options = get_option( $this->option_name, [] );
        return wp_parse_args( $options, $this->default_settings );
    }

    /**
     * Get default settings
     *
     * @return array Default settings
     */
    private function get_default_settings() {
        return $this->default_settings;
    }

    /**
     * Enqueue admin scripts and styles
     *
     * @param string $hook Current admin page hook
     */
    public function enqueue_admin_scripts( $hook ) {
        if ( $hook !== 'settings_page_pin-throttle' ) {
            return;
        }

        if ( defined( 'PIN_THROTTLE_URL' ) && defined( 'PIN_THROTTLE_VERSION' ) ) {
            wp_enqueue_style( 
                'pin-throttle-admin', 
                PIN_THROTTLE_URL . 'assets/admin.css', 
                [], 
                PIN_THROTTLE_VERSION 
            );
        }
    }

    /**
     * Display settings page
     */
    public function settings_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Access denied.', 'pin-throttle' ) );
        }
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'Pin Throttle Settings', 'pin-throttle' ); ?></h1>
            <form method="post" action="options.php">
                <?php
                settings_fields( $this->option_name );
                do_settings_sections( $this->option_name );
                submit_button();
                ?>
            </form>
            <h2><?php esc_html_e( 'Quick Statistics', 'pin-throttle' ); ?></h2>
            <?php $this->display_statistics(); ?>
        </div>
        <?php
    }

    /**
     * Render limit per minute field
     */
    public function field_limit_per_minute() {
        $options = $this->get_settings();
        ?>
        <input type="number" 
               name="<?php echo esc_attr( $this->option_name ); ?>[limit_per_minute]"
               value="<?php echo esc_attr( $options['limit_per_minute'] ); ?>"
               min="1" max="1000" required/>
        <p class="description">
            <?php esc_html_e( 'Maximum number of requests allowed per minute from a single IP.', 'pin-throttle' ); ?>
        </p>
        <?php
    }

    /**
     * Render block time field
     */
    public function field_block_time() {
        $options = $this->get_settings();
        ?>
        <input type="number" 
               name="<?php echo esc_attr( $this->option_name ); ?>[block_time]"
               value="<?php echo esc_attr( $options['block_time'] ); ?>" 
               min="1" max="1440" required/>
        <p class="description">
            <?php esc_html_e( 'How long to block after limit is exceeded (in minutes).', 'pin-throttle' ); ?>
        </p>
        <?php
    }

    /**
     * Render log to file field
     */
    public function field_log_to_file() {
        $options = $this->get_settings();
        ?>
        <input type="checkbox" 
               name="<?php echo esc_attr( $this->option_name ); ?>[log_to_file]" 
               value="1"
               <?php checked( $options['log_to_file'], true ); ?> />
        <p class="description">
            <?php esc_html_e( 'Enable logging to a file in addition to database.', 'pin-throttle' ); ?>
        </p>
        <?php
    }

    /**
     * Render auto cleanup days field
     */
    public function field_auto_cleanup_days() {
        $options = $this->get_settings();
        ?>
        <input type="number" 
               name="<?php echo esc_attr( $this->option_name ); ?>[auto_cleanup_days]"
               value="<?php echo esc_attr( $options['auto_cleanup_days'] ); ?>"
               min="0" max="365" required/>
        <p class="description">
            <?php esc_html_e( 'Automatically delete log entries older than this many days. Set to 0 to disable.', 'pin-throttle' ); ?>
        </p>
        <?php
    }

    /**
     * Render IP whitelist field
     */
    public function field_whitelist() {
        $options = $this->get_settings();
        $whitelist = is_array( $options['whitelist'] ) ? implode( "\n", $options['whitelist'] ) : '';
        ?>
        <textarea name="<?php echo esc_attr( $this->option_name ); ?>[whitelist]" 
                  rows="7" cols="50" class="large-text code"
                  placeholder="192.168.1.1&#10;10.0.0.1"><?php echo esc_textarea( $whitelist ); ?></textarea>
        <p class="description">
            <?php esc_html_e( 'IP addresses that are excluded from throttling, one per line.', 'pin-throttle' ); ?>
        </p>
        <?php
    }

    /**
     * Render allowed bots field
     */
    public function field_allowed_bots() {
        $options = $this->get_settings();
        $allowed_bots = is_array( $options['allowed_bots'] ) ? implode( "\n", $options['allowed_bots'] ) : '';
        ?>
        <textarea name="<?php echo esc_attr( $this->option_name ); ?>[allowed_bots]" 
                  rows="7" cols="50" class="large-text code"
                  placeholder="Googlebot&#10;Bingbot&#10;Yandex"><?php echo esc_textarea( $allowed_bots ); ?></textarea>
        <p class="description">
            <?php esc_html_e( 'List of allowed bots by User-Agent substring. One entry per line.', 'pin-throttle' ); ?>
        </p>
        <?php
    }

    /**
     * Render blocked bots field
     */
    public function field_blocked_bots() {
        $options = $this->get_settings();
        $blocked_bots = is_array( $options['blocked_bots'] ) ? implode( "\n", $options['blocked_bots'] ) : '';
        ?>
        <textarea name="<?php echo esc_attr( $this->option_name ); ?>[blocked_bots]" 
                  rows="7" cols="50" class="large-text code"
                  placeholder="BadBot&#10;Scraper&#10;Spam"><?php echo esc_textarea( $blocked_bots ); ?></textarea>
        <p class="description">
            <?php esc_html_e( 'List of blocked bots by User-Agent substring. One entry per line.', 'pin-throttle' ); ?>
        </p>
        <?php
    }

    /**
     * Render delete data on uninstall field
     */
    public function field_delete_data_on_uninstall() {
        $options = $this->get_settings();
        ?>
        <input type="checkbox" 
               name="<?php echo esc_attr( $this->option_name ); ?>[delete_data_on_uninstall]" 
               value="1"
               <?php checked( $options['delete_data_on_uninstall'], true ); ?> />
        <p class="description">
            <?php esc_html_e( 'Delete all plugin data when uninstalling.', 'pin-throttle' ); ?>
        </p>
        <?php
    }

    /**
     * Render enable notifications field
     */
    public function field_enable_notifications() {
        $options = $this->get_settings();
        ?>
        <input type="checkbox" 
               name="<?php echo esc_attr( $this->option_name ); ?>[enable_notifications]" 
               value="1"
               <?php checked( $options['enable_notifications'], true ); ?> />
        <p class="description">
            <?php esc_html_e( 'Enable email notifications on detected mass attacks.', 'pin-throttle' ); ?>
        </p>
        <?php
    }

    /**
     * Render notification emails field
     */
    public function field_notification_emails() {
        $options = $this->get_settings();
        ?>
        <textarea name="<?php echo esc_attr( $this->option_name ); ?>[notification_emails]" 
                  rows="4" cols="50" class="large-text"
                  placeholder="admin@example.com, security@example.com"><?php echo esc_textarea( $options['notification_emails'] ); ?></textarea>
        <p class="description">
            <?php esc_html_e( 'Enter one or more email addresses separated by commas, semicolons, or new lines.', 'pin-throttle' ); ?>
        </p>
        <?php
    }

    /**
     * Check if database table exists
     *
     * @return bool True if table exists
     */
    private function table_exists() {
        global $wpdb;
        
        $table_name = $wpdb->get_var( $wpdb->prepare( 
            "SHOW TABLES LIKE %s", 
            $this->log_table 
        ));
        
        return ! empty( $table_name );
    }

    /**
     * Get statistics data from database
     *
     * @return array|null Statistics data or null if no table
     */
    private function get_statistics_data() {
        global $wpdb;

        if ( ! $this->table_exists() ) {
            return null;
        }

        $stats = [];
        
        // Basic counts
        $stats['total_requests'] = intval( $wpdb->get_var( 
            "SELECT COUNT(*) FROM {$this->log_table}" 
        ));
        
        $stats['blocked_requests'] = intval( $wpdb->get_var( 
            "SELECT COUNT(*) FROM {$this->log_table} WHERE status = 'blocked'" 
        ));
        
        $stats['last_24h_requests'] = intval( $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->log_table} WHERE request_time >= %s",
            gmdate( 'Y-m-d H:i:s', strtotime( '-24 hours' ) )
        )));

        $stats['unique_ips'] = intval( $wpdb->get_var( 
            "SELECT COUNT(DISTINCT ip) FROM {$this->log_table}" 
        ));
        
        $stats['unique_blocked_ips'] = intval( $wpdb->get_var( 
            "SELECT COUNT(DISTINCT ip) FROM {$this->log_table} WHERE status='blocked'" 
        ));

        $stats['good_bot_count'] = intval( $wpdb->get_var( 
            "SELECT COUNT(*) FROM {$this->log_table} WHERE status='good_bot'" 
        ));
        
        $stats['bad_bot_count'] = intval( $wpdb->get_var( 
            "SELECT COUNT(*) FROM {$this->log_table} WHERE status='bad_bot'" 
        ));

        // Calculated values
        $stats['avg_requests_per_ip'] = $stats['unique_ips'] > 0 
            ? round( $stats['total_requests'] / $stats['unique_ips'], 2 ) 
            : 0;
            
        $stats['blocked_percent'] = $stats['total_requests'] > 0 
            ? round( ( $stats['blocked_requests'] / $stats['total_requests'] ) * 100, 2 ) 
            : 0;

        return $stats;
    }

    /**
     * Display statistics section
     */
    private function display_statistics() {
        $stats = $this->get_statistics_data();
        
        if ( $stats === null ) {
            echo '<p>' . esc_html__( 'No data available yet.', 'pin-throttle' ) . '</p>';
            return;
        }

        $this->render_basic_statistics( $stats );
        $this->render_top_user_agents();
        $this->render_top_ips();
        $this->render_hourly_activity();
    }

    /**
     * Render basic statistics table
     *
     * @param array $stats Statistics data
     */
    private function render_basic_statistics( $stats ) {
        $cleanup_time = get_option( 'pin_throttle_last_cleanup' );
        $cleanup_time_display = $cleanup_time 
            ? esc_html( $cleanup_time ) 
            : esc_html__( 'Unknown', 'pin-throttle' );

        echo '<table class="widefat"><tbody>';
        
        $rows = [
            __( 'Total requests logged:', 'pin-throttle' ) => number_format( $stats['total_requests'] ),
            __( 'Blocked requests:', 'pin-throttle' ) => number_format( $stats['blocked_requests'] ),
            __( 'Requests in last 24h:', 'pin-throttle' ) => number_format( $stats['last_24h_requests'] ),
            __( 'Unique IP addresses:', 'pin-throttle' ) => number_format( $stats['unique_ips'] ),
            __( 'Unique blocked IP addresses:', 'pin-throttle' ) => number_format( $stats['unique_blocked_ips'] ),
            __( 'Average requests per IP:', 'pin-throttle' ) => $stats['avg_requests_per_ip'],
            __( 'Blocked requests percentage:', 'pin-throttle' ) => $stats['blocked_percent'] . '%',
            __( 'Good bot requests:', 'pin-throttle' ) => number_format( $stats['good_bot_count'] ),
            __( 'Bad bot requests:', 'pin-throttle' ) => number_format( $stats['bad_bot_count'] ),
            __( 'Last cleanup time:', 'pin-throttle' ) => $cleanup_time_display,
        ];

        foreach ( $rows as $label => $value ) {
            echo '<tr><td>' . esc_html( $label ) . '</td><td>' . esc_html( $value ) . '</td></tr>';
        }
        
        echo '</tbody></table>';
    }

    /**
     * Render top user agents table
     */
    private function render_top_user_agents() {
        global $wpdb;
        
        $top_user_agents = $wpdb->get_results(
            "SELECT user_agent, COUNT(*) AS cnt 
             FROM {$this->log_table} 
             WHERE user_agent IS NOT NULL AND user_agent != ''
             GROUP BY user_agent 
             ORDER BY cnt DESC 
             LIMIT 5"
        );

        echo '<h3>' . esc_html__( 'Top 5 User Agents by Requests', 'pin-throttle' ) . '</h3>';
        
        if ( empty( $top_user_agents ) ) {
            echo '<p>' . esc_html__( 'No data available.', 'pin-throttle' ) . '</p>';
            return;
        }

        echo '<table class="widefat">';
        echo '<thead><tr>';
        echo '<th>' . esc_html__( 'User-Agent', 'pin-throttle' ) . '</th>';
        echo '<th>' . esc_html__( 'Requests', 'pin-throttle' ) . '</th>';
        echo '</tr></thead><tbody>';
        
        foreach ( $top_user_agents as $ua ) {
            echo '<tr>';
            echo '<td>' . esc_html( wp_trim_words( $ua->user_agent, 10 ) ) . '</td>';
            echo '<td>' . esc_html( number_format( $ua->cnt ) ) . '</td>';
            echo '</tr>';
        }
        
        echo '</tbody></table>';
    }

    /**
     * Render top IPs table
     */
    private function render_top_ips() {
        global $wpdb;
        
        $top_ips = $wpdb->get_results(
            "SELECT ip, COUNT(*) AS cnt 
             FROM {$this->log_table} 
             WHERE ip IS NOT NULL AND ip != ''
             GROUP BY ip 
             ORDER BY cnt DESC 
             LIMIT 5"
        );

        echo '<h3>' . esc_html__( 'Top 5 IPs by Requests', 'pin-throttle' ) . '</h3>';
        
        if ( empty( $top_ips ) ) {
            echo '<p>' . esc_html__( 'No data available.', 'pin-throttle' ) . '</p>';
            return;
        }

        echo '<table class="widefat">';
        echo '<thead><tr>';
        echo '<th>' . esc_html__( 'IP Address', 'pin-throttle' ) . '</th>';
        echo '<th>' . esc_html__( 'Requests', 'pin-throttle' ) . '</th>';
        echo '</tr></thead><tbody>';
        
        foreach ( $top_ips as $ip ) {
            echo '<tr>';
            echo '<td>' . esc_html( $ip->ip ) . '</td>';
            echo '<td>' . esc_html( number_format( $ip->cnt ) ) . '</td>';
            echo '</tr>';
        }
        
        echo '</tbody></table>';
    }

    /**
     * Render hourly activity table
     */
    private function render_hourly_activity() {
        global $wpdb;
        
        $hourly_stats = $wpdb->get_results( $wpdb->prepare(
            "SELECT 
                DATE_FORMAT(request_time, '%%Y-%%m-%%d %%H:00:00') as hour,
                COUNT(*) AS total_count,
                SUM(CASE WHEN status = 'blocked' THEN 1 ELSE 0 END) AS blocked_count
            FROM {$this->log_table}
            WHERE request_time >= %s
            GROUP BY hour
            ORDER BY hour ASC",
            gmdate( 'Y-m-d H:i:s', strtotime( '-24 hours' ) )
        ));

        echo '<h3>' . esc_html__( 'Request Activity in Last 24 Hours (per hour)', 'pin-throttle' ) . '</h3>';
        
        if ( empty( $hourly_stats ) ) {
            echo '<p>' . esc_html__( 'No data available.', 'pin-throttle' ) . '</p>';
            return;
        }

        echo '<table class="widefat">';
        echo '<thead><tr>';
        echo '<th>' . esc_html__( 'Hour', 'pin-throttle' ) . '</th>';
        echo '<th>' . esc_html__( 'Total Requests', 'pin-throttle' ) . '</th>';
        echo '<th>' . esc_html__( 'Blocked Requests', 'pin-throttle' ) . '</th>';
        echo '</tr></thead><tbody>';
        
        foreach ( $hourly_stats as $row ) {
            echo '<tr>';
            echo '<td>' . esc_html( $row->hour ) . '</td>';
            echo '<td>' . esc_html( number_format( $row->total_count ) ) . '</td>';
            echo '<td>' . esc_html( number_format( $row->blocked_count ) ) . '</td>';
            echo '</tr>';
        }
        
        echo '</tbody></table>';
    }
}