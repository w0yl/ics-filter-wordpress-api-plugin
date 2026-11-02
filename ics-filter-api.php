<?php
/**
 * Plugin Name: ICS Filter REST API
 * Plugin URI:  https://github.com/w0yl/ics-filter-wordpress-api-plugin
 * Description: Adds a secured, cached custom REST API endpoint to fetch and filter an ICS calendar by keywords.
 * Version:     1.1.0
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:      SCARC
 * License:     GPL v2 or later
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly.
}

class ICS_Filter_API {

    public function __construct() {
        add_action( 'rest_api_init', [ $this, 'register_routes' ] );
        add_action( 'admin_init', [ $this, 'register_settings' ] );
        add_action( 'admin_menu', [ $this, 'register_settings_page' ] );
    }

    /**
     * Register the custom REST API route with API key permission checks.
     */
    public function register_routes() {
        register_rest_route( 'icsfilter/v1', '/filter', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'handle_ics_filter' ],
            'permission_callback' => [ $this, 'check_api_permission' ],
            'args'                => [
                'url' => [
                    'required'          => true,
                    'type'              => 'string',
                    'sanitize_callback' => 'esc_url_raw',
                    'description'       => 'The absolute URL of the ICS calendar.',
                ],
                'keywords' => [
                    'required'          => true,
                    'type'              => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                    'description'       => 'Comma-separated list of keywords to filter by.',
                ],
                'api_key' => [
                    'required'          => false,
                    'type'              => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                    'description'       => 'API Key (alternative to X-API-Key header).',
                ],
            ],
        ] );
    }

    /**
     * Verify the API key via HTTP Header (X-API-Key) or Query Parameter (api_key).
     */
    public function check_api_permission( WP_REST_Request $request ) {
        $provided_key = $request->get_header( 'X-API-Key' );
        
        if ( empty( $provided_key ) ) {
            $provided_key = $request->get_param( 'api_key' );
        }

        $stored_key = get_option( 'ics_filter_api_key', 'default_change_me_secret_key' );

        if ( empty( $stored_key ) || ! hash_equals( $stored_key, (string) $provided_key ) ) {
            return new WP_Error(
                'rest_forbidden',
                'Invalid or missing API key.',
                [ 'status' => 401 ]
            );
        }

        return true;
    }

    /**
     * Handle the API request with caching and raw text calendar output.
     */
    public function handle_ics_filter( WP_REST_Request $request ) {
        $ics_url      = $request->get_param( 'url' );
        $keywords_raw = $request->get_param( 'keywords' );

        $keywords = array_filter( array_map( 'trim', explode( ',', $keywords_raw ) ) );

        if ( empty( $keywords ) ) {
            return new WP_Error( 'no_keywords', 'At least one valid keyword must be provided.', [ 'status' => 400 ] );
        }

        $raw_cache_key = 'ics_filter_api_raw_' . md5( $ics_url );
        $lock_key      = 'ics_filter_api_lock_' . md5( $ics_url );
        
        $ics_content = get_transient( $raw_cache_key );

        // If the raw calendar isn't cached yet, handle concurrent requests
        if ( false === $ics_content ) {
            $retry_count = 0;
            
            // If another request is currently fetching it, wait up to 2 seconds for it to finish
            while ( get_transient( $lock_key ) && $retry_count < 5 ) {
                usleep( 400000 ); // Wait 0.4 seconds
                $ics_content = get_transient( $raw_cache_key );
                if ( false !== $ics_content ) {
                    break; // Success! Another request finished downloading it.
                }
                $retry_count++;
            }

            // If it's still missing, this request takes ownership of fetching it
            if ( false === $ics_content ) {
                // Set a lock so other concurrent requests know to wait
                set_transient( $lock_key, true, 15 );

                $response = wp_remote_get( $ics_url, [ 'timeout' => 20 ] );

                if ( is_wp_error( $response ) ) {
                    return new WP_Error( 'fetch_failed', 'Failed to fetch the ICS file: ' . $response->get_error_message(), [ 'status' => 502 ] );
                }

                $ics_content = wp_remote_retrieve_body( $response );
                $status_code = wp_remote_retrieve_response_code( $response );

                if ( 200 !== $status_code || empty( $ics_content ) ) {
                    return new WP_Error( 'invalid_ics_response', 'The remote server returned an invalid response or empty calendar.', [ 'status' => 502 ] );
                }

                // Cache the raw content for 1 hour
                set_transient( $raw_cache_key, $ics_content, HOUR_IN_SECONDS );

                // Clear the lock after the raw content is cached
                delete_transient( $lock_key );
            }
        }

        // Perform local keyword filtering instantly
        $filtered_ics = $this->filter_ics_events( $ics_content, $keywords );

        // Send raw text/calendar headers and output exact string without JSON encoding wrappers
        status_header( 200 );
        header( 'Content-Type: text/calendar; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename="filtered-calendar.ics"' );
        echo $filtered_ics;
        exit;
    }

    /**
     * Parse raw ICS content and filter VEVENT blocks based on keywords in SUMMARY.
     */
    private function filter_ics_events( $ics_content, $keywords ) {
        $ics_content = str_replace( [ "\r\n", "\r" ], "\n", $ics_content );
        $lines       = explode( "\n", $ics_content );

        $output_lines    = [];
        $in_event        = false;
        $current_event   = [];
        $current_summary = '';

        foreach ( $lines as $line ) {
            if ( ! empty( $line ) && ( $line[0] === ' ' || $line[0] === "\t" ) ) {
                if ( $in_event ) {
                    $current_event[] = $line;
                    if ( 0 === strpos( trim( $line ), 'SUMMARY:' ) || ! empty( $current_summary ) ) {
                        $current_summary .= trim( substr( $line, 1 ) );
                    }
                } else {
                    $output_lines[] = $line;
                }
                continue;
            }

            if ( trim( $line ) === 'BEGIN:VEVENT' ) {
                $in_event        = true;
                $current_event   = [ $line ];
                $current_summary = '';
                continue;
            }

            if ( trim( $line ) === 'END:VEVENT' ) {
                if ( $in_event ) {
                    $current_event[] = $line;
                    if ( $this->summary_matches_all_keywords( $current_summary, $keywords ) ) {
                        $output_lines = array_merge( $output_lines, $current_event );
                    }
                }
                $in_event        = false;
                $current_event   = [];
                $current_summary = '';
                continue;
            }

            if ( $in_event ) {
                $current_event[] = $line;
                if ( 0 === strpos( $line, 'SUMMARY:' ) ) {
                    $current_summary = substr( $line, 8 );
                }
            } else {
                $output_lines[] = $line;
            }
        }

        return implode( "\r\n", $output_lines );
    }

    /**
     * Check if summary matches all keywords (case-insensitive).
     */
    private function summary_matches_all_keywords( $summary, $keywords ) {
        $summary_lower = mb_strtolower( $summary );

        foreach ( $keywords as $keyword ) {
            $keyword_lower = mb_strtolower( $keyword );
            if ( false === mb_strpos( $summary_lower, $keyword_lower ) ) {
                return false;
            }
        }

        return true;
    }

    /**
     * Register WordPress settings.
     */
    public function register_settings() {
        register_setting( 'ics_filter_options_group', 'ics_filter_api_key', 'sanitize_text_field' );
    }

    /**
     * Add settings page under Settings menu.
     */
    public function register_settings_page() {
        add_options_page(
            'ICS Filter API Settings',
            'ICS Filter API',
            'manage_options',
            'ics-filter-settings',
            [ $this, 'settings_page_html' ]
        );
    }

    /**
     * Render settings page HTML including cache clearing functionality.
     */
    public function settings_page_html() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        // Handle cache clearing action if button was clicked
        if ( isset( $_POST['ics_clear_cache_nonce'] ) && wp_verify_nonce( $_POST['ics_clear_cache_nonce'], 'ics_clear_cache_action' ) ) {
            global $wpdb;
            // Delete all transients matching our ics_filter_api_ prefix from the database options table
            $wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_ics_filter_api_%' OR option_name LIKE '_transient_timeout_ics_filter_api_%'" );
            echo '<div class="notice notice-success is-dismissible"><p><strong>Calendar cache successfully cleared!</strong></p></div>';
        }
        ?>
        <div class="wrap">
            <h1>ICS Filter API Settings</h1>
            
            <form action="options.php" method="post">
                <?php
                settings_fields( 'ics_filter_options_group' );
                do_settings_sections( 'ics_filter_options_group' );
                ?>
                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="ics_filter_api_key">API Key</label></th>
                        <td>
                            <input type="text" id="ics_filter_api_key" name="ics_filter_api_key" value="<?php echo esc_attr( get_option( 'ics_filter_api_key', 'default_change_me_secret_key' ) ); ?>" class="regular-text" />
                            <p class="description">Provide this key in the <code>X-API-Key</code> request header or via the <code>?api_key=</code> parameter.</p>
                        </td>
                    </tr>
                </table>
                <?php submit_button(); ?>
            </form>

            <hr style="margin: 40px 0 20px 0;">

            <h2>Manage Cache</h2>
            <p>Filtered calendar files are cached in the database for 1 hour to improve speed. If your source calendar has changed and you want to force an immediate refresh, you can clear the cache below.</p>
            
            <form method="post">
                <?php wp_nonce_field( 'ics_clear_cache_action', 'ics_clear_cache_nonce' ); ?>
                <?php submit_button( 'Clear Calendar Cache', 'secondary', 'ics_clear_cache_submit', false ); ?>
            </form>
        </div>
        <?php
    }
}

new ICS_Filter_API();