<?php
/**
 * @package PublishPress
 * @author PublishPress
 *
 * Copyright (c) 2018 PublishPress
 *
 * ------------------------------------------------------------------------------
 * Based on Edit Flow
 * Author: Daniel Bachhuber, Scott Bressler, Mohammad Jangda, Automattic, and
 * others
 * Copyright (c) 2009-2016 Mohammad Jangda, Daniel Bachhuber, et al.
 * ------------------------------------------------------------------------------
 *
 * This file is part of PublishPress
 *
 * PublishPress is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * PublishPress is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with PublishPress.  If not, see <http://www.gnu.org/licenses/>.
 */

if ( ! class_exists( 'PP_Calendar' ) ) {
    /**
     * class PP_Calendar
     * Threaded commenting in the admin for discussion between writers and editors
     *
     * @author batmoo
     */
    class PP_Calendar extends PP_Module {
        /**
         * Settings slug
         */
        const SETTINGS_SLUG = 'pp-calendar';

        /**
         * Usermeta key prefix
         */
        const USERMETA_KEY_PREFIX = 'pp_calendar_';

        /**
         * Default number of weeks to display in the calendar
         */
        const DEFAULT_NUM_WEEKS   = 5;

        /**
         * Name of the transient option to flag the warning for selecting
         * at least one post type
         */
        const TRANSIENT_SHOW_ONE_POST_TYPE_WARNING = 'show_one_post_type_warning';

        /**
         * [$module description]
         *
         * @var [type]
         */
        public $module;

        /**
         * [$start_date description]
         *
         * @var string
         */
        public $start_date = '';

        /**
         * [$current_week description]
         *
         * @var integer
         */
        public $current_week = 1;

        /**
         * Default number of weeks to show per screen
         *
         * @var integer
         */
        public $total_weeks = 6;

        /**
         * Counter of hidden posts per date square
         *
         * @var integer
         */
        public $hidden = 0;

        /**
         * Total number of posts to be shown per square before 'more' link
         *
         * @var integer
         */
        public $max_visible_posts_per_date = 4;

        /**
         * [$post_date_cache description]
         *
         * @var array
         */
        private $post_date_cache = array();

        /**
         * [$post_li_html_cache_key description]
         *
         * @var string
         */
        private static $post_li_html_cache_key = 'pp_calendar_post_li_html';

        /**
         * Construct the PP_Calendar class
         */
        public function __construct()
        {
            $this->module_url = $this->get_module_url(__FILE__);

            // Register the module with PublishPress
            $args = array(
                'title'                => __( 'Calendar', 'publishpress' ),
                'short_description'    => __( 'The calendar lets you see your posts over a customizable date range.', 'publishpress' ),
                'extended_description' => __( 'PublishPress’s calendar lets you see your posts over a customizable date range. Filter by status or click on the post title to see its details. Drag and drop posts between days to change their publication date.', 'publishpress' ),
                'module_url'           => $this->module_url,
                'icon_class'           => 'dashicons dashicons-calendar-alt',
                'slug'                 => 'calendar',
                'post_type_support'    => 'pp_calendar',
                'default_options'      => array(
                    'enabled'                => 'on',
                    'post_types'             => $this->pre_select_all_post_types(),
                    'ics_subscription'       => 'on',
                    'ics_secret_key'         => '',
                ),
                'messages' => array(
                    'post-date-updated'   => __( 'Post date updated.', 'publishpress' ),
                    'update-error'        => __( 'There was an error updating the post. Please try again.', 'publishpress' ),
                    'published-post-ajax' => __( "Updating the post date dynamically doesn't work for published content. Please <a href='%s'>edit the post</a>.", 'publishpress' ),
                    'key-regenerated'     => __( 'iCal secret key regenerated. Please inform all users they will need to resubscribe.', 'publishpress' ),
                ),
                'configure_page_cb'   => 'print_configure_view',
                'settings_help_tab'   => array(
                    'id'      => 'pp-calendar-overview',
                    'title'   => __( 'Overview', 'publishpress' ),
                    'content' => __( '<p>The calendar is a convenient week-by-week or month-by-month view into your content. Quickly see which stories are on track to being published on time, and which will need extra effort.</p>', 'publishpress' ),
                ),
                'settings_help_sidebar' => __( '<p><strong>For more information:</strong></p><p><a href="https://publishpress.com/features/calendar/">Calendar Documentation</a></p><p><a href="https://github.com/ostraining/PublishPress">PublishPress on Github</a></p>', 'publishpress' ),
                'show_configure_btn' => false,
                'options_page'       => true,
                'page_link'          => admin_url( 'admin.php?page=pp-calendar' ),
            );

            $this->module = PublishPress()->register_module( 'calendar', $args );
        }

        /**
         * Initialize all of our methods and such. Only runs if the module is active
         *
         * @uses add_action()
         */
        public function init() {
            // Can view the calendar?
            if ( ! $this->check_capability() ) {
                return false;
            }

            // Menu
            add_action( 'publishpress_admin_menu', array( $this, 'action_admin_menu' ) );

            // .ics calendar subscriptions
            add_action( 'wp_ajax_pp_calendar_ics_subscription', array( $this, 'handle_ics_subscription' ) );
            add_action( 'wp_ajax_nopriv_pp_calendar_ics_subscription', array( $this, 'handle_ics_subscription' ) );

            // Define the create-post capability
            $this->create_post_cap = apply_filters( 'pp_calendar_create_post_cap', 'edit_posts' );

            require_once( PUBLISHPRESS_ROOT . '/common/php/' . 'screen-options.php' );

            add_action( 'admin_init', array( $this, 'register_settings' ) );
            add_action( 'admin_print_styles', array( $this, 'add_admin_styles' ) );
            add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_scripts' ) );

            // Ajax manipulation for the calendar
            add_action( 'wp_ajax_pp_calendar_drag_and_drop', array( $this, 'handle_ajax_drag_and_drop' ) );

            // Ajax insert post placeholder for a specific date
            add_action( 'wp_ajax_pp_insert_post', array( $this, 'handle_ajax_insert_post' ) );

            // Update metadata
            add_action( 'wp_ajax_pp_calendar_update_metadata', array( $this, 'handle_ajax_update_metadata' ) );

            // Clear li cache for a post when post cache is cleared
            add_action( 'clean_post_cache', array( $this, 'action_clean_li_html_cache' ) );

            // Action to regenerate the calendar feed sekret
            add_action( 'admin_init', array( $this, 'handle_regenerate_calendar_feed_secret' ) );

            add_filter( 'post_date_column_status', array( $this, 'filter_post_date_column_status' ), 12, 4 );
        }

        /**
         * Add necessary things to the admin menu
         */
        public function action_admin_menu() {
            // Main Menu
            add_submenu_page(
                'pp-calendar',
                esc_html__( 'Calendar', 'publishpress' ),
                esc_html__( 'Calendar', 'publishpress' ),
                apply_filters( 'pp_view_calendar_cap', 'pp_view_calendar' ),
                'pp-calendar',
                array( $this, 'render_admin_page' )
            );
        }

        /**
         * Load the capabilities onto users the first time the module is run
         *
         * @since 0.7
         */
        public function install() {
            // Add necessary capabilities to allow management of calendar
            // view_calendar - administrator --> contributor
            $calendar_roles = array(
                'administrator' => array( 'pp_view_calendar' ),
                'editor'        => array( 'pp_view_calendar' ),
                'author'        => array( 'pp_view_calendar' ),
                'contributor'   => array( 'pp_view_calendar' ),
            );

            foreach ( $calendar_roles as $role => $caps ) {
                $this->add_caps_to_role( $role, $caps );
            }
        }

        /**
         * Upgrade our data in case we need to
         *
         * @since 0.7
         */
        public function upgrade( $previous_version ) {
            global $publishpress;

            // Upgrade path to v0.7
            if ( version_compare( $previous_version, '0.7', '<' ) ) {
                // Migrate whether the calendar was enabled or not and clean up old option
                if ( $enabled = get_option( 'publishpress_calendar_enabled' ) ) {
                    $enabled = 'on';
                } else {
                    $enabled = 'off';
                }
                $publishpress->update_module_option( $this->module->name, 'enabled', $enabled );
                delete_option( 'publishpress_calendar_enabled' );

                // Technically we've run this code before so we don't want to auto-install new data
                $publishpress->update_module_option( $this->module->name, 'loaded_once', true );
            }
        }

        /**
         * Add any necessary CSS to the WordPress admin
         *
         * @uses wp_enqueue_style()
         */
        public function add_admin_styles() {
            global $pagenow;

            // Only load calendar styles on the calendar page
            if ( 'admin.php' === $pagenow && isset( $_GET['page'] ) && $_GET['page'] === 'pp-calendar' ) {
                wp_enqueue_style( 'publishpress-calendar-css', $this->module_url . 'lib/calendar.css', false, PUBLISHPRESS_VERSION );
            }
        }

        /**
         * Check whether the user should have the ability to view the calendar.
         * Returns true if the user can view.
         *
         * @return bool
         */
        protected function check_capability() {
            $view_calendar_cap = 'pp_view_calendar';
            $view_calendar_cap = apply_filters( 'pp_view_calendar_cap', $view_calendar_cap );

            return current_user_can( $view_calendar_cap );
        }

        /**
         * Add any necessary JS to the WordPress admin
         *
         * @since 0.7
         * @uses wp_enqueue_script()
         */
        public function enqueue_admin_scripts() {
            $this->enqueue_datepicker_resources();

            if ( $this->is_whitelisted_functional_view() ) {
                $js_libraries = array(
                    'jquery',
                    'jquery-ui-core',
                    'jquery-ui-sortable',
                    'jquery-ui-draggable',
                    'jquery-ui-droppable',
                );
                foreach ( $js_libraries as $js_library ) {
                    wp_enqueue_script( $js_library );
                }
                wp_enqueue_script( 'publishpress-calendar-js', $this->module_url . 'lib/calendar.js', $js_libraries, PUBLISHPRESS_VERSION, true );

                $pp_cal_js_params = array(
                    'can_add_posts' => current_user_can( $this->create_post_cap ) ? 'true' : 'false',
                );
                wp_localize_script( 'publishpress-calendar-js', 'pp_calendar_params', $pp_cal_js_params );
            }
        }

        /**
         * Handle an AJAX request from the calendar to update a post's timestamp.
         * Notes:
         * - For Post Time, if the post is unpublished, the change sets the publication timestamp
         * - If the post was published or scheduled for the future, the change will change the timestamp. 'publish' posts
         * will become scheduled if moved past today and 'future' posts will be published if moved before today
         * - Need to respect user permissions. Editors can move all, authors can move their own, and contributors can't move at all
         *
         * @since 0.7
         */
        public function handle_ajax_drag_and_drop() {
            global $wpdb;

            // Nonce check!
            if ( ! wp_verify_nonce( $_POST['nonce'], 'pp-calendar-modify' ) ) {
                $this->print_ajax_response( 'error', $this->module->messages['nonce-failed'] );
            }

            // Check that we got a proper post
            $post_id = (int) $_POST['post_id'];
            $post    = get_post( $post_id );
            if ( ! $post ) {
                $this->print_ajax_response( 'error', $this->module->messages['missing-post'] );
            }

            // Check that the user can modify the post
            if ( ! $this->current_user_can_modify_post( $post ) ) {
                $this->print_ajax_response( 'error', $this->module->messages['invalid-permissions'] );
            }

            // Check that the new date passed is a valid one
            $next_date_full = strtotime( $_POST['next_date'] );
            if ( ! $next_date_full ) {
                $this->print_ajax_response( 'error', __( 'Something is wrong with the format for the new date.', 'publishpress' ) );
            }

            // Persist the old hourstamp because we can't manipulate the exact time on the calendar
            // Bump the last modified timestamps too
            $existing_time     = date( 'H:i:s', strtotime( $post->post_date ) );
            $existing_time_gmt = date( 'H:i:s', strtotime( $post->post_date_gmt ) );
            $new_values        = array(
                'post_date' => date( 'Y-m-d', $next_date_full ) . ' ' . $existing_time,
                'post_modified' => current_time( 'mysql' ),
                'post_modified_gmt' => current_time( 'mysql', 1 ),
            );

            // By default, adding a post to the calendar will set the timestamp.
            // If the user don't desires that to be the behavior, they can set the result of this filter to 'false'
            // With how WordPress works internally, setting 'post_date_gmt' will set the timestamp
            if ( apply_filters( 'pp_calendar_allow_ajax_to_set_timestamp', true ) ) {
                $new_values['post_date_gmt'] = date( 'Y-m-d', $next_date_full ) . ' ' . $existing_time_gmt;
            }

            // Check that it's already published, and adjust the status.
            // If is in the past or today, set as published. If future, as scheduled.
            $new_values['post_status'] = $post->post_status;
            if ( in_array( $post->post_status, $this->published_statuses ) || $post->post_status === 'future' ) {
                if ( $next_date_full <= time() ) {
                    $new_values['post_status'] = 'publish';
                } else {
                    $new_values['post_status'] = 'future';
                }
            }

            // We have to do SQL unfortunately because of core bugginess
            // Note to those reading this: bug Nacin to allow us to finish the custom status API
            // See http://core.trac.wordpress.org/ticket/18362
            $response = $wpdb->update( $wpdb->posts, $new_values, array(
                'ID' => $post->ID,
            ) );
            clean_post_cache( $post->ID );
            if ( ! $response ) {
                $this->print_ajax_response( 'error', $this->module->messages['update-error'] );
            }

            $data = array(
                'post_status' => $new_values['post_status'],
            );
            $this->print_ajax_response( 'success', $this->module->messages['post-date-updated'], $data );
            exit;
        }

        /**
         * After checking that the request is valid, do an .ics file
         *
         * @since 0.8
         */
        public function handle_ics_subscription() {

            // Only do .ics subscriptions when the option is active
            if ( 'on' != $this->module->options->ics_subscription ) {
                die();
            } // End if().

            // Confirm all of the arguments are present
            if ( ! isset( $_GET['user'], $_GET['user_key'] ) ) {
                die();
            } // End if().

            // Confirm this is a valid request
            $user           = sanitize_user( $_GET['user'] );
            $user_key       = sanitize_user( $_GET['user_key'] );
            $ics_secret_key = $this->module->options->ics_secret_key;
            if ( ! $ics_secret_key || md5( $user . $ics_secret_key ) !== $user_key ) {
                die( $this->module->messages['nonce-failed'] );
            }

            // Set up the post data to be printed
            $post_query_args  = array();
            $calendar_filters = $this->calendar_filters();
            foreach ( $calendar_filters as $filter ) {
                if ( isset( $_GET[ $filter ] ) && false !== ($value = $this->sanitize_filter( $filter, $_GET[ $filter ] )) ) {
                    $post_query_args[ $filter ] = $value;
                }
            }

            // Set the start date for the posts_where filter
            $this->start_date = apply_filters( 'pp_calendar_ics_subscription_start_date', $this->get_beginning_of_week( date( 'Y-m-d', current_time( 'timestamp' ) ) ) );

            $this->total_weeks = apply_filters( 'pp_calendar_total_weeks', $this->total_weeks, 'ics_subscription' );

            $formatted_posts = array();
            for ( $current_week = 1; $current_week <= $this->total_weeks; $current_week++ ) {
                // We need to set the object variable for our posts_where filter
                $this->current_week = $current_week;
                $week_posts         = $this->get_calendar_posts_for_week( $post_query_args, 'ics_subscription' );
                foreach ( $week_posts as $date => $day_posts ) {
                    foreach ( $day_posts as $num => $post ) {
                        $start_date    = date( 'Ymd', strtotime( $post->post_date ) ) . 'T' . date( 'His', strtotime( $post->post_date ) ) . 'Z';
                        $end_date      = date( 'Ymd', strtotime( $post->post_date ) + (5 * 60) ) . 'T' . date( 'His', strtotime( $post->post_date ) + (5 * 60) ) . 'Z';
                        $last_modified = date( 'Ymd', strtotime( $post->post_modified_gmt ) ) . 'T' . date( 'His', strtotime( $post->post_modified_gmt ) ) . 'Z';

                        // Remove the convert chars and wptexturize filters from the title
                        remove_filter( 'the_title', 'convert_chars' );
                        remove_filter( 'the_title', 'wptexturize' );

                        $formatted_post = array(
                            'BEGIN'           => 'VEVENT',
                            'UID'             => $post->guid,
                            'SUMMARY'         => $this->do_ics_escaping( apply_filters( 'the_title', $post->post_title ) ) . ' - ' . $this->get_post_status_friendly_name( get_post_status( $post->ID ) ),
                            'DTSTART'         => $start_date,
                            'DTEND'           => $end_date,
                            'LAST-MODIFIED'   => $last_modified,
                            'URL'             => get_post_permalink( $post->ID ),
                        );

                        // Description should include everything visible in the calendar popup
                        $information_fields            = $this->get_post_information_fields( $post );
                        $formatted_post['DESCRIPTION'] = '';
                        if ( ! empty( $information_fields ) ) {
                            foreach ( $information_fields as $key => $values ) {
                                $formatted_post['DESCRIPTION'] .= $values['label'] . ': ' . $values['value'] . '\n';
                            }
                            $formatted_post['DESCRIPTION'] = rtrim( $formatted_post['DESCRIPTION'] );
                        }

                        $formatted_post['END'] = 'VEVENT';

                        // @todo auto format any field longer than 75 bytes
                        $formatted_posts[] = $formatted_post;
                    }
                }// End foreach().
            }// End for().

            // Other template data
            $header = array(
                    'BEGIN'   => 'VCALENDAR',
                    'VERSION' => '2.0',
                    'PRODID'  => '-//PublishPress//PublishPress ' . PUBLISHPRESS_VERSION . '//EN',
                );

            $footer = array(
                    'END'     => 'VCALENDAR',
                );

            // Render the .ics template and set the content type
            header('Content-type: text/calendar; charset=utf-8');
            header('Content-Disposition: inline; filename=calendar.ics');

            foreach ( array( $header, $formatted_posts, $footer ) as $section ) {
                foreach ( $section as $key => $value ) {
                    if ( is_string( $value ) ) {
                        echo $this->do_ics_line_folding( $key . ':' . $value );
                    } else {
                        foreach ( $value as $k => $v ) {
                            echo $this->do_ics_line_folding( $k . ':' . $v );
                        }
                    }
                }
            }

            die();
        }

        /**
         * Perform line folding according to RFC 5545.
         *
         * @param string $line The line without trailing CRLF
         * @return string The line after line-folding with all necessary CRLF.
         */
        public function do_ics_line_folding( $line ) {
            $len = mb_strlen( $line );
            if ( $len <= 75 ) {
                return $line . "\r\n";
            }

            $chunks = array();
            $start  = 0;
            while ( true ) {
                $chunk    = mb_substr( $line, $start, 75 );
                $chunkLen = mb_strlen( $chunk );
                $start += $chunkLen;
                if ( $start < $len ) {
                    $chunks[] = $chunk . "\r\n ";
                } else {
                    $chunks[] = $chunk . "\r\n";
                    return implode( '', $chunks );
                }
            }
        }

        /**
         * Perform the encoding necessary for ICS feed text.
         *
         * @param string $text The string that needs to be escaped
         * @return string The string after escaping for ICS.
         * @since 0.8
         * */

        public function do_ics_escaping( $text ) {
            $text = str_replace( ',', '\,', $text );
            $text = str_replace( ';', '\:', $text );
            $text = str_replace( '\\', '\\\\', $text );
            return $text;
        }

        /**
         * Handle a request to regenerate the calendar feed secret
         *
         * @since 0.8
         */
        public function handle_regenerate_calendar_feed_secret() {
            if ( ! isset( $_GET['action'] ) || 'pp_calendar_regenerate_calendar_feed_secret' != $_GET['action'] ) {
                return;
            }

            if ( ! current_user_can( 'manage_options' ) ) {
                wp_die( $this->module->messages['invalid-permissions'] );
            }

            if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( $_GET['_wpnonce'], 'pp-regenerate-ics-key' ) ) {
                wp_die( $this->module->messages['nonce-failed'] );
            }

            PublishPress()->update_module_option( $this->module->name, 'ics_secret_key', wp_generate_password() );

            $args = array(
                'page'   => PP_Modules_Settings::SETTINGS_SLUG,
                'module' => $this->module->settings_slug,
            );

            wp_safe_redirect(
                add_query_arg(
                    'message',
                    'key-regenerated',
                    add_query_arg( $args, admin_url( 'admin.php' ) )
                )
            );

            exit;
        }

        /**
         * Get the user's filters for calendar, either with $_GET or from saved
         *
         * @uses get_user_meta()
         * @return array $filters All of the set or saved calendar filters
         */
        public function get_filters() {
            $current_user = wp_get_current_user();
            $filters      = array();
            $old_filters  = $this->get_user_meta( $current_user->ID, self::USERMETA_KEY_PREFIX . 'filters', true );

            $default_filters = array(
                    'weeks' => self::DEFAULT_NUM_WEEKS,
                    'post_status' => '',
                    'cpt' => '',
                    'cat' => '',
                    'tag' => '',
                    'author' => '',
                    'start_date' => date( 'Y-m-d', current_time( 'timestamp' ) ),
                );
            $old_filters = array_merge( $default_filters, (array) $old_filters );

            // Sanitize and validate any newly added filters
            foreach ( $old_filters as $key => $old_value ) {
                if ( isset( $_GET[ $key ] ) && false !== ($new_value = $this->sanitize_filter( $key, $_GET[ $key ] )) ) {
                    $filters[ $key ] = $new_value;
                } else {
                    $filters[ $key ] = $old_value;
                }
            }

            // Set the start date as the beginning of the week, according to blog settings
            $filters['start_date'] = $this->get_beginning_of_week( $filters['start_date'] );

            $filters = apply_filters( 'pp_calendar_filter_values', $filters, $old_filters );

            $this->update_user_meta( $current_user->ID, self::USERMETA_KEY_PREFIX . 'filters', $filters );

            return $filters;
        }

        /**
         * Set all post types as selected, to be used as the default option.
         *
         * @return array
         */
        protected function pre_select_all_post_types() {
            $list = get_post_types( null, 'objects' );

            foreach ( $list as $type => $value ) {
                $list[ $type ] = 'on';
            }

            return $list;
        }

        /**
         * Get an array of the selected post types.
         *
         * @return array
         */
        protected function get_selected_post_types() {
            $return = array();

            if ( ! isset( $this->module->options->post_types ) ) {
                $this->module->options->post_types = array();
            }

            if ( ! empty( $this->module->options->post_types ) ) {
                foreach ( $this->module->options->post_types as $type => $value ) {
                    if ( 'on' === $value ) {
                        $return[] = $type;
                    }
                }
            }

            return $return;
        }

        /**
         * Renders the admin page
         */
        public function render_admin_page() {
            global $publishpress;

            $this->dropdown_taxonomies = array();

            $supported_post_types = $this->get_post_types_for_module( $this->module );

            $dotw = array(
                'Sat',
                'Sun',
            );
            $dotw = apply_filters( 'pp_calendar_weekend_days', $dotw );

            // Get filters either from $_GET or from user settings
            $filters = $this->get_filters();

            // Total number of weeks to display on the calendar. Run it through a filter in case we want to override the
            // user's standard
            $this->total_weeks = empty( $filters['weeks'] ) ? self::DEFAULT_NUM_WEEKS : $filters['weeks'];

            // For generating the WP Query objects later on
            $post_query_args = array(
                'post_status' => $filters['post_status'],
                'post_type'   => $filters['cpt'],
                'cat'         => $filters['cat'],
                'tag'         => $filters['tag'],
                'author'      => $filters['author'],
            );
            $this->start_date = $filters['start_date'];

            // We use this later to label posts if they need labeling
            if ( count( $supported_post_types ) > 1 ) {
                $all_post_types = get_post_types( null, 'objects' );
            }
            $dates        = array();
            $heading_date = $filters['start_date'];
            for ( $i = 0; $i < 7; $i++ ) {
                $dates[ $i ]    = $heading_date;
                $heading_date = date( 'Y-m-d', strtotime( '+1 day', strtotime( $heading_date ) ) );
            }

            // we sort by post statuses....... eventually
            $post_statuses = $this->get_post_statuses();

            // Get the custom description for this page
            $description = sprintf( '%s <span class="time-range">%s</span>', __( 'Calendar', 'publishpress' ), $this->calendar_time_range() );

            // Should we display the subscribe button?
            if ( 'on' == $this->module->options->ics_subscription && $this->module->options->ics_secret_key ) {
                $args = array(
                        'action'       => 'pp_calendar_ics_subscription',
                        'user'         => wp_get_current_user()->user_login,
                        'user_key'     => md5( wp_get_current_user()->user_login . $this->module->options->ics_secret_key ),
                    );
                $subscription_link = add_query_arg( $args, admin_url( 'admin-ajax.php' ) );

                 $description .= sprintf(
                     '<a href="%s" class="calendar-subscribe"><span class="dashicons dashicons-external"></span>%s</a>',
                     esc_attr( $subscription_link ),
                     __( 'Subscribe in iCal or Google Calendar', 'publishpress' )
                 );
            }

            $publishpress->settings->print_default_header( $publishpress->modules->calendar, $description );

            ?>
            <div class="wrap">
                <?php
                    // Handle posts that have been trashed or untrashed
                if ( isset( $_GET['trashed'] ) || isset( $_GET['untrashed'] ) ) {
                    echo '<div id="trashed-message" class="updated"><p>';
                    if ( isset( $_GET['trashed'] ) && (int) $_GET['trashed'] ) {
                        printf( _n( 'Post moved to the trash.', '%d posts moved to the trash.', $_GET['trashed'] ), number_format_i18n( $_GET['trashed'] ) );
                        $ids       = isset( $_GET['ids'] ) ? $_GET['ids'] : 0;
                        $pid       = explode( ',', $ids );
                        $post_type = get_post_type( $pid[0] );
                        echo ' <a href="' . esc_url( wp_nonce_url( "edit.php?post_type=$post_type&doaction=undo&action=untrash&ids=$ids", 'bulk-posts' ) ) . '">' . __( 'Undo', 'publishpress' ) . ' <span class="dashicons dashicons-undo"></span></a><br />';
                        unset( $_GET['trashed'] );
                    }
                    if ( isset( $_GET['untrashed'] ) && (int) $_GET['untrashed'] ) {
                        printf( _n( 'Post restored from the Trash.', '%d posts restored from the Trash.', $_GET['untrashed'] ), number_format_i18n( $_GET['untrashed'] ) );
                        unset( $_GET['undeleted'] );
                    }
                    echo '</p></div>';
                }
            ?>

                <div id="pp-calendar-wrap"><!-- Calendar Wrapper -->

                <?php $this->print_top_navigation( $filters, $dates );
            ?>

                <?php
                    $table_classes = array();
                    // CSS don't like our classes to start with numbers
                if ( $this->total_weeks == 1 ) {
                    $table_classes[] = 'one-week-showing';
                } elseif ( $this->total_weeks == 2 ) {
                    $table_classes[] = 'two-weeks-showing';
                } elseif ( $this->total_weeks == 3 ) {
                    $table_classes[] = 'three-weeks-showing';
                }

                $table_classes = apply_filters( 'pp_calendar_table_classes', $table_classes );
            ?>
                <table id="pp-calendar-view" class="<?php echo esc_attr( implode( ' ', $table_classes ) );
            ?>">
                    <thead>
                    <tr class="calendar-heading">
                        <?php echo $this->get_time_period_header( $dates );
            ?>
                    </tr>
                    </thead>
                    <tbody>

                    <?php
                    $current_month = date_i18n( 'F', strtotime( $filters['start_date'] ) );
                    for ( $current_week = 1; $current_week <= $this->total_weeks; $current_week++ ) :
                        // We need to set the object variable for our posts_where filter
                        $this->current_week = $current_week;
                            $week_posts                     = $this->get_calendar_posts_for_week( $post_query_args );
                            $date_format                    = 'Y-m-d';
                            $week_single_date               = $this->get_beginning_of_week( $filters['start_date'], $date_format, $current_week );
                            $week_dates                     = array();
                            $split_month                    = false;
                        for ( $i = 0 ; $i < 7; $i++ ) {
                            $week_dates[ $i ]    = $week_single_date;
                            $single_date_month = date_i18n( 'F', strtotime( $week_single_date ) );
                            if ( $single_date_month != $current_month ) {
                                $split_month   = $single_date_month;
                                $current_month = $single_date_month;
                            }
                            $week_single_date = date( 'Y-m-d', strtotime( '+1 day', strtotime( $week_single_date ) ) );
                        }
                            ?>
                            <?php if ( $split_month ) : ?>
                    <tr class="month-marker">
                        <?php foreach ( $week_dates as $key => $week_single_date ) {
                            if ( date_i18n( 'F', strtotime( $week_single_date ) ) != $split_month && date_i18n( 'F', strtotime( '+1 day', strtotime( $week_single_date ) ) ) == $split_month ) {
                                $previous_month = date_i18n( 'F', strtotime( $week_single_date ) );
                                echo '<td class="month-marker-previous">' . esc_html( $previous_month ) . '</td>';
                            } elseif ( date_i18n( 'F', strtotime( $week_single_date ) ) == $split_month && date_i18n( 'F', strtotime( '-1 day', strtotime( $week_single_date ) ) ) != $split_month ) {
                                echo '<td class="month-marker-current">' . esc_html( $split_month ) . '</td>';
                            } else {
                                echo '<td class="month-marker-empty"></td>';
                            }
}
        ?>
                </tr>
                <?php endif;
        ?>

                <tr class="week-unit">
                <?php foreach ( $week_dates as $day_num => $week_single_date ) : ?>
                <?php
                    // Somewhat ghetto way of sorting all of the day's posts by post status order
                if ( ! empty( $week_posts[ $week_single_date ] ) ) {
                    $week_posts_by_status = array();
                    foreach ( $post_statuses as $post_status ) {
                        $week_posts_by_status[ $post_status->slug ] = array();
                    }
                    // These statuses aren't handled by custom statuses or post statuses
                    $week_posts_by_status['private'] = array();
                    $week_posts_by_status['publish'] = array();
                    $week_posts_by_status['future']  = array();
                    foreach ( $week_posts[ $week_single_date ] as $num => $post ) {
                        $week_posts_by_status[ $post->post_status ][ $num ] = $post;
                    }
                    unset( $week_posts[ $week_single_date ] );
                    foreach ( $week_posts_by_status as $status ) {
                        foreach ( $status as $num => $post ) {
                            $week_posts[ $week_single_date ][] = $post;
                        }
                    }
                }

                $td_classes = array(
                            'day-unit',
                        );
                $day_name = date( 'D', strtotime( $week_single_date ) );

                if ( in_array( $day_name, $dotw ) ) {
                    $td_classes[] = 'weekend-day';
                }

                if ( $week_single_date == date( 'Y-m-d', current_time( 'timestamp' ) ) ) {
                    $td_classes[] = 'today';
                }

                        // Last day of the week
                if ( $day_num == 6 ) {
                    $td_classes[] = 'last-day';
                }

                $td_classes = apply_filters( 'pp_calendar_table_td_classes', $td_classes, $week_single_date );
            ?>
                    <td class="<?php echo esc_attr( implode( ' ', $td_classes ) );
            ?>" id="<?php echo esc_attr( $week_single_date );
            ?>">
                        <div class="day-wrapper">
                            <div class='schedule-new-post-label'>
                                <?php echo __( 'Click to create', 'publishpress' ); ?>
                            </div>
                            <?php $class = ($week_single_date == date( 'Y-m-d', current_time( 'timestamp' ) )) ? 'calendar-today' : ''; ?>
                            <div class="day-unit-label <?php echo $class; ?>"><?php echo esc_html( date( 'j', strtotime( $week_single_date ) ) );
                ?></div>
                            <ul class="post-list">
                                <?php
                                $this->hidden = 0;
                                if ( ! empty( $week_posts[ $week_single_date ] ) ) {
                                    $week_posts[ $week_single_date ] = apply_filters( 'pp_calendar_posts_for_week', $week_posts[ $week_single_date ] );

                                    foreach ( $week_posts[ $week_single_date ] as $num => $post ) {
                                        echo $this->generate_post_li_html( $post, $week_single_date, $num );
                                    }
                                }
                ?>
                            </ul>
                            <?php if ( $this->hidden ) : ?>
                                <a class="show-more" href="#"><?php printf( __( 'Show %d more', 'publishpress' ), $this->hidden ); ?></a>
                            <?php endif; ?>

                            <?php if ( current_user_can( $this->create_post_cap ) ) :
                                $date_formatted = date( 'D, M jS, Y', strtotime( $week_single_date ) ); ?>

                                <form method="POST" class="post-insert-dialog">
                                    <?php /* translators: %1$s = post type name, %2$s = date */ ?>
                                    <?php $post_types = $this->get_selected_post_types(); ?>
                                    <?php if ( count( $post_types ) === 1 ) : ?>
                                        <h1>
                                            <?php echo sprintf( __( 'Schedule a %1$s for %2$s', 'publishpress' ), $this->get_quick_create_post_type_name( $post_types ), $date_formatted ); ?>
                                            <input type="hidden" id="post-insert-dialog-post-type" name="post-insert-dialog-post-type" class="post-insert-dialog-post-type" value="<?php echo $post_types[0]; ?>" />
                                        </h1>
                                    <?php else : ?>
                                        <h1>
                                            <?php echo sprintf( __( 'Schedule content for %1$s', 'publishpress' ), $date_formatted );?>
                                        </h1>
                                        <label for="post-insert-dialog-post-type">
                                            <?php echo __( 'Type', 'publishpress' ); ?>
                                            <select id="post-insert-dialog-post-type" name="post-insert-dialog-post-type" class="post-insert-dialog-post-type">
                                                <?php foreach ( $post_types as $type ) : ?>
                                                    <option value="<?php echo $type; ?>">
                                                        <?php echo $this->get_quick_create_post_type_name( $type ); ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </label>
                                        <br />
                                    <?php endif; ?>

                                    <?php /* translators: %s = post type name */ ?>
                                    <input type="text" class="post-insert-dialog-post-title" name="post-insert-dialog-post-title" placeholder="<?php echo esc_attr( __( 'Title', 'publishpress' ) ); ?>" />
                                    <textarea class="post-insert-dialog-post-content" name="post-insert-dialog-post-content" placeholder="<?php echo esc_attr( __( 'Content', 'publishpress' ) ); ?>"></textarea>

                                    <input type="hidden" class="post-insert-dialog-post-date" name="post-insert-dialog-post-title" value="<?php echo esc_attr( $week_single_date ); ?>" />

                                    <div class="post-insert-dialog-controls">
                                        <input type="submit" class="button left" value="<?php echo esc_html( __( 'Create', 'publishpress' ) ); ?>" />
                                        &nbsp;
                                        <a class="post-insert-dialog-edit-post-link" href="#"><?php echo esc_html( __( 'Edit', 'publishpress' ) ); ?>&nbsp;&raquo;</a>
                                    </div>

                                    <div class="spinner">&nbsp;</div>
                                </form>
                            <?php endif; ?>

                            </div>
                        </td>
                        <?php endforeach; ?>
                                        </tr>

                                        <?php endfor; ?>

                        </tbody>
                        </table><!-- /Week Wrapper -->
                        <?php
                        // Nonce field for AJAX actions
                        wp_nonce_field( 'pp-calendar-modify', 'pp-calendar-modify' );

                        ?>

                        <div class="clear"></div>
                    </div><!-- /Calendar Wrapper -->

                  </div>

            <?php
            $publishpress->settings->print_default_footer( $publishpress->modules->calendar );
        }

        /**
         * Generates the HTML for a single post item in the calendar
         *
         * @param  obj $post The WordPress post in question
         * @param  str $post_date The date of the post
         * @param  int $num The index of the post
         *
         * @return str HTML for a single post item
         */
        public function generate_post_li_html( $post, $post_date, $num = 0 ) {
            $can_modify = ($this->current_user_can_modify_post( $post )) ? 'can_modify' : 'read_only';
            $cache_key  = $post->ID . $can_modify;
            $cache_val  = wp_cache_get( $cache_key, self::$post_li_html_cache_key );
            // Because $num is pertinent to the display of the post LI, need to make sure that's what's in cache
            if ( is_array( $cache_val ) && $cache_val['num'] == $num ) {
                $this->hidden = $cache_val['hidden'];
                return $cache_val['post_li_html'];
            }

            ob_start();

            $post_id        = $post->ID;
            $edit_post_link = get_edit_post_link( $post_id );

            $post_classes = array(
                'day-item',
                'custom-status-' . $post->post_status,
            );

            $post_type_options = $this->get_post_status_options( $post->post_status );

            // Only allow the user to drag the post if they have permissions to
            // or if it's in an approved post status
            // This is checked on the ajax request too.
            if ( $this->current_user_can_modify_post( $post ) ) {
                $post_classes[] = 'sortable';
            }

            if ( in_array( $post->post_status, $this->published_statuses ) ) {
                $post_classes[] = 'is-published';
            }

            // Hide posts over a certain number to prevent clutter, unless user is only viewing 1 or 2 weeks
            $max_visible_posts = apply_filters( 'pp_calendar_max_visible_posts_per_date', $this->max_visible_posts_per_date );

            if ( $num >= $max_visible_posts && $this->total_weeks > 2 ) {
                $post_classes[] = 'hidden';
                $this->hidden++;
            }
            $post_classes = apply_filters( 'pp_calendar_table_td_li_classes', $post_classes, $post_date, $post->ID );

            ?>

            <li
                class="<?php echo esc_attr( implode( ' ', $post_classes ) ); ?>"
                id="post-<?php echo esc_attr( $post->ID );?>">

                <div style="clear:right;"></div>
                <div class="item-static">
                    <div class="item-default-visible" style="background:<?php echo $post_type_options[ 'color' ]; ?>;">
                        <div class="inner">
                            <span class="dashicons <?php echo $post_type_options[ 'icon' ]; ?>"></span>
                            <?php $title = esc_html( _draft_or_post_title( $post->ID ) ); ?>

                            <span class="item-headline post-title" title="<?php echo esc_attr( $title ); ?>">
                                <strong><?php echo $title; ?></strong>
                            </span>
                        </div>
                    </div>
                    <div class="item-inner">
                        <?php $this->get_inner_information( $this->get_post_information_fields( $post ), $post ); ?>
                    </div>
                </div>
            </li>
            <?php

            $post_li_html = ob_get_contents();
            ob_end_clean();

            $post_li_cache = array(
                'num' => $num,
                'post_li_html' => $post_li_html,
                'hidden' => $this->hidden,
                );
            wp_cache_set( $cache_key, $post_li_cache, self::$post_li_html_cache_key );

            return $post_li_html;
        } // generate_post_li_html()

        /**
         * Returns the CSS class name and color for the given custom status.
         * It reutrns an array with the following keys:
         *     - icon
         *     - color
         *
         * @param string $post_status
         *
         * @return array
         */
        protected function get_post_status_options( $post_status ) {
            global $publishpress;

            // Check if we have a custom icon for this post_status
            $term = $publishpress->custom_status->get_custom_status_by( 'slug', $post_status );

            // Icon
            $icon = null;
            if ( ! empty( $term->icon ) ) {
                $icon = $term->icon;
            } else {
                // Add an icon for the items
                $default_icons = array(
                    'publish'    => 'dashicons-yes',
                    'future'     => 'dashicons-calendar-alt',
                    'private'    => 'dashicons-lock',
                    'draft'      => 'dashicons-edit',
                    'pending'    => 'dashicons-edit',
                    'auto-draft' => 'dashicons-edit',
                );

                $icon = isset( $default_icons[ $post_status ] ) ? $default_icons[ $post_status ] : 'dashicons-edit';
            }

            // Color
            $color = '#655997';
            if ( ! empty( $term->color ) ) {
                $color = $term->color;
            }

            return array(
                'color' => $color,
                'icon'  => $icon,
            );
        }

        /**
         * get_inner_information description
         * Functionality for generating the inner html elements on the calendar
         * has been separated out so various ajax functions can reload certain
         * parts of an inner html element.
         *
         * @param  array   $pp_calendar_item_information_fields
         * @param  WP_Post $post
         * @param  array   $published_statuses
         *
         * @since 0.8
         */
        public function get_inner_information( $pp_calendar_item_information_fields, $post ) {
            ?>
                <table class="item-information">
                    <?php foreach ( $this->get_post_information_fields( $post ) as $field => $values ) : ?>
                        <tr class="item-field item-information-<?php echo esc_attr( $field ); ?>">
                            <th class="label"><?php echo esc_html( $values['label'] ); ?>:</th>
                            <?php if ( $values['value'] && isset( $values['type'] ) ) : ?>
                                <?php if ( isset( $values['editable'] ) && $this->current_user_can_modify_post( $post ) ) : ?>
                                    <td class="value<?php if ( $values['editable'] ) { ?> editable-value<?php } ?>">
                                        <?php echo esc_html( $values['value'] ); ?>

                                    </td>
                                    <?php if ( $values['editable'] ) : ?>
                                        <td class="editable-html hidden" data-type="<?php echo $values['type']; ?>" data-metadataterm="<?php echo str_replace( 'editorial-metadata-', '', str_replace( 'tax_', '', $field ) ); ?>">

                                            <?php echo $this->get_editable_html( $values['type'], $values['value'] ); ?>
                                        </td>
                                    <?php endif; ?>
                                <?php else : ?>
                                    <td class="value"><?php echo esc_html( $values['value'] ); ?></td>
                                <?php endif; ?>
                            <?php elseif ( $values['value'] ) : ?>
                                <td class="value"><?php echo esc_html( $values['value'] ); ?></td>
                            <?php else : ?>
                            <td class="value">
                                <em class="none"><?php echo _e( 'None', 'publishpress' );?></em>
                            </td>
                            <?php endif; ?>
                        </tr>
                    <?php endforeach; ?>
                    <?php do_action( 'pp_calendar_item_additional_html', $post->ID ); ?>
                </table>
                <?php
                    $post_type_object = get_post_type_object( $post->post_type );
                    $item_actions     = array();
                if ( $this->current_user_can_modify_post( $post ) ) {
                    // Edit this post
                    $item_actions['edit'] = '<a href="' . get_edit_post_link( $post->ID, true ) . '" title="' . esc_attr( __( 'Edit this item', 'publishpress' ) ) . '">' . __( 'Edit', 'publishpress' ) . '</a>';
                    // Trash this post
                    $item_actions['trash'] = '<a href="' . get_delete_post_link( $post->ID ) . '" title="' . esc_attr( __( 'Trash this item' ), 'publishpress' ) . '">' . __( 'Trash', 'publishpress' ) . '</a>';
                    // Preview/view this post
                    if ( ! in_array( $post->post_status, $this->published_statuses ) ) {
                        $item_actions['view'] = '<a href="' . esc_url( apply_filters( 'preview_post_link', add_query_arg( 'preview', 'true', get_permalink( $post->ID ) ), $post ) ) . '" title="' . esc_attr( sprintf( __( 'Preview &#8220;%s&#8221;', 'publishpress' ), $post->post_title ) ) . '" rel="permalink">' . __( 'Preview', 'publishpress' ) . '</a>';
                    } elseif ( 'trash' != $post->post_status ) {
                        $item_actions['view'] = '<a href="' . get_permalink( $post->ID ) . '" title="' . esc_attr( sprintf( __( 'View &#8220;%s&#8221;', 'publishpress' ), $post->post_title ) ) . '" rel="permalink">' . __( 'View', 'publishpress' ) . '</a>';
                    }
                    // Save metadata
                    $item_actions['save hidden'] = '<a href="#savemetadata" id="save-editorial-metadata" class="post-' . $post->ID . '" title="' . esc_attr( sprintf( __( 'Save &#8220;%s&#8221;', 'publishpress' ), $post->post_title ) ) . '" >' . __( 'Save', 'publishpress' ) . '</a>';
                }

                    // Allow other plugins to add actions
                    $item_actions = apply_filters( 'pp_calendar_item_actions', $item_actions, $post->ID );

                if ( count( $item_actions ) ) {
                    echo '<div class="item-actions">';
                    $html = '';
                    foreach ( $item_actions as $class => $item_action ) {
                        $html .= '<span class="' . esc_attr( $class ) . '">' . $item_action . ' | </span> ';
                    }
                    echo rtrim( $html, '| ' );
                    echo '</div>';
                }
                    ?>
                <div style="clear:right;"></div>
                <?php

        } // generate_post_li_html()

        public function get_editable_html( $type, $value ) {
            switch ( $type ) {
                case 'text':
                case 'location':
                case 'number':
                    return '<input type="text" class="metadata-edit-' . $type . '" value="' . $value . '"/>';
                break;
                case 'paragraph':
                    return '<textarea type="text" class="metadata-edit-' . $type . '">' . $value . '</textarea>';
                break;
                case 'date':
                    return '<input type="text" value="' . $value . '" class="date-time-pick metadata-edit-' . $type . '"/>';
                break;
                case 'checkbox':
                    $output = '<select class="metadata-edit">';

                    if ( $value == 'No' ) {
                        $output .= '<option value="0">No</option><option value="1">Yes</option>';
                    } else {
                        $output .= '<option value="1">Yes</option><option value="0">No</option>';
                    }

                    $output .= '</select>';

                    return $output;
                break;
                case 'user':
                    return wp_dropdown_users( array(
                        'echo' => false,
                    ) );
                break;
                case 'taxonomy':
                    return '<input type="text" class="metadata-edit-' . $type . '" value="' . $value . '" />';
                break;
                case 'taxonomy hierarchical':
                    return wp_dropdown_categories( array(
                        'echo' => 0,
                        'hide_empty' => 0,
                    ) );
                break;
            }// End switch().
        }

        /**
         * Get the information fields to be presented with each post popup
         *
         * @since 0.8
         *
         * @param obj $post Post to gather information fields for
         * @return array $information_fields All of the information fields to be presented
         */
        public function get_post_information_fields( $post ) {
            $information_fields = array();
            // Post author
            $information_fields['author'] = array(
                'label'        => __( 'Author', 'publishpress' ),
                'value'        => get_the_author_meta( 'display_name', $post->post_author ),
                'type'         => 'author',
            );

            // If the calendar supports more than one post type, show the post type label
            if ( count( $this->get_post_types_for_module( $this->module ) ) > 1 ) {
                $information_fields['post_type'] = array(
                    'label' => __( 'Post Type', 'publishpress' ),
                    'value' => get_post_type_object( $post->post_type )->labels->singular_name,
                );
            }
            // Publication time for published statuses
            if ( in_array( $post->post_status, $this->published_statuses ) ) {
                if ( $post->post_status == 'future' ) {
                    $information_fields['post_date'] = array(
                        'label' => __( 'Scheduled', 'publishpress' ),
                        'value' => get_the_time( null, $post->ID ),
                    );
                } else {
                    $information_fields['post_date'] = array(
                        'label' => __( 'Published', 'publishpress' ),
                        'value' => get_the_time( null, $post->ID ),
                    );
                }
            }
            // Taxonomies and their values
            $args = array(
                'post_type' => $post->post_type,
            );
            $taxonomies = get_object_taxonomies( $args, 'object' );
            foreach ( (array) $taxonomies as $taxonomy ) {
                // Sometimes taxonomies skip by, so let's make sure it has a label too
                if ( ! $taxonomy->public || ! $taxonomy->label ) {
                    continue;
                }

                $terms = get_the_terms( $post->ID, $taxonomy->name );
                if ( ! $terms || is_wp_error( $terms ) ) {
                    continue;
                }

                $key = 'tax_' . $taxonomy->name;
                if ( count( $terms ) ) {
                    $value = '';
                    foreach ( (array) $terms as $term ) {
                        $value .= $term->name . ', ';
                    }
                    $value = rtrim( $value, ', ' );
                } else {
                    $value = '';
                }
                 // Used when editing editorial metadata and post meta
                if ( is_taxonomy_hierarchical( $taxonomy->name ) ) {
                    $type = 'taxonomy hierarchical';
                } else {
                    $type = 'taxonomy';
                }

                $information_fields[ $key ] = array(
                    'label' => $taxonomy->label,
                    'value' => $value,
                    'type' => $type,
                );

                if ( $post->post_type == 'page' ) {
                    $ed_cap = 'edit_page';
                } else {
                    $ed_cap = 'edit_post';
                }

                if ( current_user_can( $ed_cap, $post->ID ) ) {
                    $information_fields[ $key ]['editable'] = true;
                }
            }// End foreach().

            $information_fields = apply_filters( 'pp_calendar_item_information_fields', $information_fields, $post->ID );
            foreach ( $information_fields as $field => $values ) {
                // Allow filters to hide empty fields or to hide any given individual field. Hide empty fields by default.
                if ( (apply_filters( 'pp_calendar_hide_empty_item_information_fields', true, $post->ID ) && empty( $values['value'] ))
                        || apply_filters( "pp_calendar_hide_{$field}_item_information_field", false, $post->ID ) ) {
                    unset( $information_fields[ $field ] );
                }
            }

            return $information_fields;
        }

        /**
         * Generates the filtering and navigation options for the top of the calendar
         *
         * @param array $filters Any set filters
         * @param array $dates All of the days of the week. Used for generating navigation links
         */
        public function print_top_navigation( $filters, $dates ) {
            ?>
            <ul class="pp-calendar-navigation">
                <li id="calendar-filter">
                    <form method="GET" id="pp-calendar-filters">
                        <input type="hidden" name="page" value="pp-calendar" />
                        <input type="hidden" name="start_date" value="<?php echo esc_attr( $filters['start_date'] );
            ?>"/>
                        <!-- Filter by status -->
                        <?php
                        foreach ( $this->calendar_filters() as $select_id => $select_name ) {
                            echo $this->calendar_filter_options( $select_id, $select_name, $filters );
                        }
            ?>
                    </form>
                </li>
                <!-- Clear filters functionality (all of the fields, but empty) -->
                <li>
                    <form method="GET">
                        <input type="hidden" name="page" value="pp-calendar" />
                        <input type="hidden" name="start_date" value="<?php echo esc_attr( $filters['start_date'] );
            ?>"/>
                        <?php
                        foreach ( $this->calendar_filters() as $select_id => $select_name ) {
                            echo '<input type="hidden" name="' . $select_name . '" value="" />';
                        }
            ?>
                        <input type="submit" id="post-query-clear" class="button-secondary button" value="<?php _e( 'Reset', 'publishpress' );
            ?>"/>
                    </form>
                </li>

                <?php /** Previous and next navigation items (translatable so they can be increased if needed)**/ ?>
                <li class="date-change next-week">
                    <a title="<?php printf( __( 'Forward 1 week', 'publishpress' ) );
            ?>" href="<?php echo esc_url( $this->get_pagination_link( 'next', $filters, 1 ) );
            ?>"><?php _e( '&rsaquo;', 'publishpress' );
            ?></a>
                    <?php if ( $this->total_weeks > 1 ) : ?>
                        <a title="<?php printf( __( 'Forward %d weeks', 'publishpress' ), $this->total_weeks );
            ?>" href="<?php echo esc_url( $this->get_pagination_link( 'next', $filters ) );
            ?>"><?php _e( '&raquo;', 'publishpress' );
            ?></a>
                    <?php endif; ?>
                </li>
                <li class="date-change today">
                    <a title="<?php printf( __( 'Today is %s', 'publishpress' ), date( get_option( 'date_format' ), current_time( 'timestamp' ) ) );
            ?>" href="<?php echo esc_url( $this->get_pagination_link( 'next', $filters, 0 ) );
            ?>"><?php _e( 'Today', 'publishpress' );
            ?></a>
                </li>
                <li class="date-change previous-week">
                    <?php if ( $this->total_weeks > 1 ) : ?>
                    <a title="<?php printf( __( 'Back %d weeks', 'publishpress' ), $this->total_weeks );
            ?>"  href="<?php echo esc_url( $this->get_pagination_link( 'previous', $filters ) );
            ?>"><?php _e( '&laquo;', 'publishpress' );
            ?></a>
                    <?php endif; ?>
                    <a title="<?php printf( __( 'Back 1 week', 'publishpress' ) );
            ?>" href="<?php echo esc_url( $this->get_pagination_link( 'previous', $filters, 1 ) );
            ?>"><?php _e( '&lsaquo;', 'publishpress' );
            ?></a>
                </li>
                <li class="ajax-actions">
                    <img class="waiting" style="display:none;" src="<?php echo esc_url( admin_url( 'images/wpspin_light.gif' ) );
            ?>" alt="" />
                </li>
            </ul>
        <?php

        }

        /**
         * Generate the calendar header for a given range of dates
         *
         * @param array $dates Date range for the header
         * @return string $html Generated HTML for the header
         */
        public function get_time_period_header( $dates ) {
            $html = '';
            foreach ( $dates as $date ) {
                $html .= '<th class="column-heading" >';
                $html .= esc_html( date_i18n( 'l', strtotime( $date ) ) );
                $html .= '</th>';
            }

            return $html;
        }

        /**
         * Query to get all of the calendar posts for a given day
         *
         * @param array  $args Any filter arguments we want to pass
         * @param string $request_context Where the query is coming from, to distinguish dashboard and subscriptions
         * @return array $posts All of the posts as an array sorted by date
         */
        public function get_calendar_posts_for_week( $args = array(), $context = 'dashboard' ) {
            global $wpdb;

            $supported_post_types = $this->get_post_types_for_module( $this->module );
            $defaults             = array(
                'post_status'      => null,
                'cat'              => null,
                'tag'              => null,
                'author'           => null,
                'post_type'    => $supported_post_types,
                'posts_per_page'   => -1,
            );

            $args = array_merge( $defaults, $args );

            // Unpublished as a status is just an array of everything but 'publish'
            if ( $args['post_status'] == 'unpublish' ) {
                $args['post_status'] = '';
                $post_statuses       = $this->get_post_statuses();
                foreach ( $post_statuses as $post_status ) {
                    $args['post_status'] .= $post_status->slug . ', ';
                }
                $args['post_status'] = rtrim( $args['post_status'], ', ' );
                // Optional filter to include scheduled content as unpublished
                if ( apply_filters( 'pp_show_scheduled_as_unpublished', false ) ) {
                    $args['post_status'] .= ', future';
                }
            }
            // The WP functions for printing the category and author assign a value of 0 to the default
            // options, but passing this to the query is bad (trashed and auto-draft posts appear!), so
            // unset those arguments.
            if ( $args['cat'] === '0' ) {
                unset( $args['cat'] );
            }
            if ( $args['tag'] === '0' ) {
                unset( $args['tag'] );
            } else {
                $args['tag_id'] = $args['tag'];
                unset( $args['tag'] );
            }

            if ( $args['author'] === '0' ) {
                unset( $args['author'] );
            }

            if ( empty( $args['post_type'] ) || ! in_array( $args['post_type'], $supported_post_types ) ) {
                $args['post_type'] = $supported_post_types;
            }

            // Filter for an end user to implement any of their own query args
            $args = apply_filters( 'pp_calendar_posts_query_args', $args, $context );

            add_filter( 'posts_where', array( $this, 'posts_where_week_range' ) );
            $post_results = new WP_Query( $args );
            remove_filter( 'posts_where', array( $this, 'posts_where_week_range' ) );

            $posts = array();
            while ( $post_results->have_posts() ) {
                $post_results->the_post();
                global $post;
                $key_date           = date( 'Y-m-d', strtotime( $post->post_date ) );
                $posts[ $key_date ][] = $post;
            }

            return $posts;
        }

        /**
         * Filter the WP_Query so we can get a week range of posts
         *
         * @param string $where The original WHERE SQL query string
         * @return string $where Our modified WHERE query string
         */
        public function posts_where_week_range( $where = '' ) {
            global $wpdb;

            $beginning_date = $this->get_beginning_of_week( $this->start_date, 'Y-m-d', $this->current_week );
            $ending_date    = $this->get_ending_of_week( $this->start_date, 'Y-m-d', $this->current_week );
            // Adjust the ending date to account for the entire day of the last day of the week
            $ending_date = date( 'Y-m-d', strtotime( '+1 day', strtotime( $ending_date ) ) );
            $where       = $where . $wpdb->prepare( " AND ($wpdb->posts.post_date >= %s AND $wpdb->posts.post_date < %s)", $beginning_date, $ending_date );

            return $where;
        }

        /**
         * Gets the link for the next time period
         *
         * @param string $direction 'previous' or 'next', direction to go in time
         * @param array  $filters Any filters that need to be applied
         * @param int    $weeks_offset Number of weeks we're offsetting the range
         * @return string $url The URL for the next page
         */
        public function get_pagination_link( $direction = 'next', $filters = array(), $weeks_offset = null ) {
            $supported_post_types = $this->get_post_types_for_module( $this->module );

            if ( ! isset( $weeks_offset ) ) {
                $weeks_offset = $this->total_weeks;
            } elseif ( $weeks_offset == 0 ) {
                $filters['start_date'] = $this->get_beginning_of_week( date( 'Y-m-d', current_time( 'timestamp' ) ) );
            }

            if ( $direction === 'previous' ) {
                $weeks_offset = '-' . $weeks_offset;
            }

            $filters['start_date'] = date( 'Y-m-d', strtotime( $weeks_offset . ' weeks', strtotime( $filters['start_date'] ) ) );
            $url                   = add_query_arg( $filters, menu_page_url( 'pp-calendar', false ) );

            if ( count( $supported_post_types ) > 1 ) {
                $url = add_query_arg( 'cpt', $filters['cpt'], $url );
            }

            return $url;
        }

        /**
         * Given a day in string format, returns the day at the beginning of that week, which can be the given date.
         * The end of the week is determined by the blog option, 'start_of_week'.
         *
         * @see http://www.php.net/manual/en/datetime.formats.date.php for valid date formats
         *
         * @param string $date String representing a date
         * @param string $format Date format in which the end of the week should be returned
         * @param int    $week Number of weeks we're offsetting the range
         * @return string $formatted_start_of_week End of the week
         */
        public function get_beginning_of_week( $date, $format = 'Y-m-d', $week = 1 ) {
            $date          = strtotime( $date );
            $start_of_week = get_option( 'start_of_week' );
            $day_of_week   = date( 'w', $date );
            $date += (($start_of_week - $day_of_week - 7) % 7) * 60 * 60 * 24 * $week;
            $additional              = 3600 * 24 * 7 * ($week - 1);
            $formatted_start_of_week = date( $format, $date + $additional );
            return $formatted_start_of_week;
        }

        /**
         * Given a day in string format, returns the day at the end of that week, which can be the given date.
         * The end of the week is determined by the blog option, 'start_of_week'.
         *
         * @see http://www.php.net/manual/en/datetime.formats.date.php for valid date formats
         *
         * @param string $date String representing a date
         * @param string $format Date format in which the end of the week should be returned
         * @param int    $week Number of weeks we're offsetting the range
         * @return string $formatted_end_of_week End of the week
         */
        public function get_ending_of_week( $date, $format = 'Y-m-d', $week = 1 ) {
            $date        = strtotime( $date );
            $end_of_week = get_option( 'start_of_week' ) - 1;
            $day_of_week = date( 'w', $date );
            $date += (($end_of_week - $day_of_week + 7) % 7) * 60 * 60 * 24;
            $additional            = 3600 * 24 * 7 * ($week - 1);
            $formatted_end_of_week = date( $format, $date + $additional );
            return $formatted_end_of_week;
        }

        /**
         * Human-readable time range for the calendar
         * Shows something like "for October 30th through November 26th" for a four-week period
         *
         * @since 0.7
         */
        public function calendar_time_range() {
            $first_datetime = strtotime( $this->start_date );
            $first_date     = date_i18n( get_option( 'date_format' ), $first_datetime );
            $total_days     = ($this->total_weeks * 7) - 1;
            $last_datetime  = strtotime( '+' . $total_days . ' days', date( 'U', strtotime( $this->start_date ) ) );
            $last_date      = date_i18n( get_option( 'date_format' ), $last_datetime );

            return sprintf( __( 'for %1$s through %2$s', 'publishpress' ), $first_date, $last_date );
        }

        /**
         * Check whether the current user should have the ability to modify the post
         *
         * @since 0.7
         *
         * @param object $post The post object we're checking
         * @return bool $can Whether or not the current user can modify the post
         */
        public function current_user_can_modify_post( $post ) {
            if ( ! $post ) {
                return false;
            }

            $post_type_object = get_post_type_object( $post->post_type );

            // Editors and admins are fine
            if ( current_user_can( $post_type_object->cap->edit_others_posts, $post->ID ) ) {
                return true;
            }
            // Authors and contributors can move their own stuff if it's not published
            if ( current_user_can( $post_type_object->cap->edit_post, $post->ID ) && wp_get_current_user()->ID == $post->post_author && ! in_array( $post->post_status, $this->published_statuses ) ) {
                return true;
            }
            // Those who can publish posts can move any of their own stuff
            if ( current_user_can( $post_type_object->cap->publish_posts, $post->ID ) && wp_get_current_user()->ID == $post->post_author ) {
                return true;
            }

            return false;
        }

        /**
         * Register settings for notifications so we can partially use the Settings API
         * We use the Settings API for form generation, but not saving because we have our
         * own way of handling the data.
         *
         * @since 0.7
         */
        public function register_settings() {
            add_settings_section( $this->module->options_group_name . '_general', false, '__return_false', $this->module->options_group_name );
            add_settings_field( 'post_types', __( 'Post types to show', 'publishpress' ), array( $this, 'settings_post_types_option' ), $this->module->options_group_name, $this->module->options_group_name . '_general' );
            add_settings_field( 'ics_subscription', __( 'Subscription in iCal or Google Calendar', 'publishpress' ), array( $this, 'settings_ics_subscription_option' ), $this->module->options_group_name, $this->module->options_group_name . '_general' );
        }

        /**
         * Choose the post types that should be displayed on the calendar
         *
         * @since 0.7
         */
        public function settings_post_types_option() {
            global $publishpress;
            $publishpress->settings->helper_option_custom_post_type( $this->module );

            // Check if we need to display the message about selecting at lest one post type
            if ( get_transient( static::TRANSIENT_SHOW_ONE_POST_TYPE_WARNING ) ) {
                echo '<p class="psppca_field_warning">' . __( 'At least one post type must be selected', 'publishpress' ) . '</p>';

                delete_transient( static::TRANSIENT_SHOW_ONE_POST_TYPE_WARNING );
            }
        }

        /**
         * Give a bit of helper text to indicate the user can change
         * number of weeks in the screen options
         *
         * @since 0.7
         */
        public function settings_number_weeks_option() {
            echo '<span class="description">' . __( 'The number of weeks shown on the calendar can be changed on a user-by-user basis using the calendar\'s screen options.', 'publishpress' ) . '</span>';
        }

        /**
         * Enable calendar subscriptions via .ics in iCal or Google Calendar
         *
         * @since 0.8
         */
        public function settings_ics_subscription_option() {
            $options = array(
                'off'       => __( 'Disabled', 'publishpress' ),
                'on'        => __( 'Enabled', 'publishpress' ),
            );
            echo '<select id="ics_subscription" name="' . $this->module->options_group_name . '[ics_subscription]">';
            foreach ( $options as $value => $label ) {
                echo '<option value="' . esc_attr( $value ) . '"';
                echo selected( $this->module->options->ics_subscription, $value );
                echo '>' . esc_html( $label ) . '</option>';
            }
            echo '</select>';

            $regenerate_url = add_query_arg( 'action', 'pp_calendar_regenerate_calendar_feed_secret', admin_url( 'admin.php?page=pp-calendar' ) );
            $regenerate_url = wp_nonce_url( $regenerate_url, 'pp-regenerate-ics-key' );
            echo '&nbsp;&nbsp;&nbsp;<a href="' . esc_url( $regenerate_url ) . '">' . __( 'Regenerate calendar feed secret', 'publishpress' ) . '</a>';

            // If our secret key doesn't exist, create a new one
            if ( empty( $this->module->options->ics_secret_key ) ) {
                PublishPress()->update_module_option( $this->module->name, 'ics_secret_key', wp_generate_password() );
            }
        }

        /**
         * Validate the data submitted by the user in calendar settings
         *
         * @since 0.7
         */
        public function settings_validate( $new_options ) {
            $options = (array) $this->module->options;

            if ( isset( $new_options['post_types'] ) ) {
                // Set post as default
                $empty = true;
                foreach ( $options['post_types'] as $value ) {
                    if ( 'on' === $value ) {
                        $empty = false;
                        break;
                    }
                }

                if ( $empty) {
                    // Check post by default
                    $options['post_types'] = array( 'post' => 'on' );

                    // Add flag to display a warning to the user
                    set_transient( static::TRANSIENT_SHOW_ONE_POST_TYPE_WARNING, 1, 300 );
                } else {
                    $options['post_types'] = $this->clean_post_type_options( $new_options['post_types'], $this->module->post_type_support );
                }
            } else {
                // Check post by default
                $options['post_types'] = array( 'post' => 'on' );

                // Add flag to display a warning to the user
                set_transient( static::TRANSIENT_SHOW_ONE_POST_TYPE_WARNING, 1, 300 );
            }

            if ( 'on' != $new_options['ics_subscription'] ) {
                $options['ics_subscription'] = 'off';
            } else {
                $options['ics_subscription'] = 'on';
            }

            return $options;
        }

        /**
         * Settings page for calendar
         */
        public function print_configure_view() {
            global $publishpress;

            ?>
            <form class="basic-settings" action="<?php echo esc_url( menu_page_url( $this->module->settings_slug, false ) );
            ?>" method="post">
                <?php settings_fields( $this->module->options_group_name );
            ?>
                <?php do_settings_sections( $this->module->options_group_name );
            ?>
                <?php
                    echo '<input id="publishpress_module_name" name="publishpress_module_name[]" type="hidden" value="' . esc_attr( $this->module->name ) . '" />';
            ?>
                <p class="submit"><?php submit_button( null, 'primary', 'submit', false );
            ?></p>
            <?php echo '<input name="publishpress_module_name[]" type="hidden" value="' . esc_attr( $this->module->name ) . '" />'; ?>
            <?php wp_nonce_field( 'edit-publishpress-settings' ); ?>
            </form>
            <?php
        }

        /**
         * Ajax callback to insert a post placeholder for a particular date
         *
         * @since 0.8
         */
        public function handle_ajax_insert_post() {

            // Nonce check!
            if ( ! wp_verify_nonce( $_POST['nonce'], 'pp-calendar-modify' ) ) {
                $this->print_ajax_response( 'error', $this->module->messages['nonce-failed'] );
            }

            // Check that the user has the right capabilities to add posts to the calendar (defaults to 'edit_posts')
            if ( ! current_user_can( $this->create_post_cap ) ) {
                $this->print_ajax_response( 'error', $this->module->messages['invalid-permissions'] );
            }

            if ( empty( $_POST['pp_insert_date'] ) ) {
                $this->print_ajax_response( 'error', __( 'No date supplied.', 'publishpress' ) );
            }

            // Post type has to be visible on the calendar to create a placeholder
            $post_type = sanitize_text_field( $_POST['pp_insert_type'] );
            if ( empty( $post_type ) ) {
                $post_type = 'post';
            }

            if ( ! in_array( $post_type, $this->get_post_types_for_module( $this->module ) ) ) {
                $this->print_ajax_response( 'error', __( 'Please change Quick Create to use a post type viewable on the calendar.', 'publishpress' ) );
            }

            // Sanitize post values
            $post_title = sanitize_text_field( $_POST['pp_insert_title'] );
            $post_content = sanitize_text_field( $_POST['pp_insert_content'] );

            if ( ! $post_title ) {
                $post_title = __( 'Untitled', 'publishpress' );
            }
            if ( ! $post_content ) {
                $post_content = '';
            }

            $post_date = sanitize_text_field( $_POST['pp_insert_date'] );

            $post_status = $this->get_default_post_status();

            // Set new post parameters
            $post_placeholder = array(
                'post_title'        => $post_title,
                'post_content'      => $post_content,
                'post_type'         => $post_type,
                'post_status'       => $post_status,
                'post_date'         => date( 'Y-m-d H:i:s', strtotime( $post_date ) ),
                'post_modified'     => current_time( 'mysql' ),
                'post_modified_gmt' => current_time( 'mysql', 1 ),
            );

            // By default, adding a post to the calendar will set the timestamp.
            // If the user don't desires that to be the behavior, they can set the result of this filter to 'false'
            // With how WordPress works internally, setting 'post_date_gmt' will set the timestamp
            if ( apply_filters( 'pp_calendar_allow_ajax_to_set_timestamp', true ) ) {
                $post_placeholder['post_date_gmt'] = date( 'Y-m-d H:i:s', strtotime( $post_date ) );
            }

            // Create the post
            add_filter( 'wp_insert_post_data', [$this, 'alter_post_modification_time'], 99, 2 );
            $post_id = wp_insert_post( $post_placeholder );
            remove_filter( 'wp_insert_post_data', [$this, 'alter_post_modification_time'], 99, 2 );



            if ( $post_id ) { // success!

                $post = get_post( $post_id );

                // Generate the HTML for the post item so it can be injected
                $post_li_html = $this->generate_post_li_html( $post, $post_date );

                // announce success and send back the html to inject
                $this->print_ajax_response( 'success', $post_li_html );
            } else {
                $this->print_ajax_response( 'error', __( 'Post could not be created', 'publishpress' ) );
            }
        }

        /**
         * Allow to alter modified date when creating posts. WordPress by default
         * doesn't allow that. We need it to fix an issue where the post_modified
         * field is saved with the post_date value. That is a ptoblem when you save
         * a post with post_date to the future. For scheduled posts.
         *
         * @param  array $data
         * @param  array $postarr
         *
         * @return array
         */
        public function alter_post_modification_time( $data , $postarr ) {
            if (!empty($postarr['post_modified']) && !empty($postarr['post_modified_gmt'])) {
                $data['post_modified'] = $postarr['post_modified'];
                $data['post_modified_gmt'] = $postarr['post_modified_gmt'];
            }

            return $data;
        }

        /**
         * Returns the singular label for the posts that are
         * quick-created on the calendar
         *
         * @param  mix $post_type_slug
         *
         * @return str Singular label for a post-type
         */
        public function get_quick_create_post_type_name( $post_type_slug ) {
            if ( is_array( $post_type_slug ) ) {
                $post_type_slug = $post_type_slug[0];
            }

            $post_type_obj  = get_post_type_object( $post_type_slug );

            return $post_type_obj->labels->singular_name ? $post_type_obj->labels->singular_name : $post_type_slug;
        }

        /**
         * ajax_pp_calendar_update_metadata
         * Update the metadata from the calendar.
         *
         * @return string representing the overlay
         *
         * @since 0.8
         */
        public function handle_ajax_update_metadata() {
            global $wpdb;

            if ( ! wp_verify_nonce( $_POST['nonce'], 'pp-calendar-modify' ) ) {
                $this->print_ajax_response( 'error', $this->module->messages['nonce-failed'] );
            }

            // Check that we got a proper post
            $post_id = (int) $_POST['post_id'];
            $post    = get_post( $post_id );

            if ( ! $post ) {
                $this->print_ajax_response( 'error', $this->module->messages['missing-post'] );
            }

            if ( $post->post_type == 'page' ) {
                $edit_check = 'edit_page';
            } else {
                $edit_check = 'edit_post';
            }

            if ( ! current_user_can( $edit_check, $post->ID ) ) {
                $this->print_ajax_response( 'error', $this->module->messages['invalid-permissions'] );
            }

            // Check that the user can modify the post
            if ( ! $this->current_user_can_modify_post( $post ) ) {
                $this->print_ajax_response( 'error', $this->module->messages['invalid-permissions'] );
            }

            $default_types = array(
                'author',
                'taxonomy',
            );

            $metadata_types = array();

            if ( ! $this->module_enabled( 'editorial_metadata' ) ) {
                $this->print_ajax_response( 'error', $this->module->messages['update-error'] );
            }

            $metadata_types = array_keys( PublishPress()->editorial_metadata->get_supported_metadata_types() );

            // Update an editorial metadata field
            if ( isset( $_POST['metadata_type'] ) && in_array( $_POST['metadata_type'], $metadata_types ) ) {
                $post_meta_key = sanitize_text_field( '_pp_editorial_meta_' . $_POST['metadata_type'] . '_' . $_POST['metadata_term'] );

                // Javascript date parsing is terrible, so use strtotime in php
                if ( $_POST['metadata_type'] == 'date' ) {
                    $metadata_value = strtotime( sanitize_text_field( $_POST['metadata_value'] ) );
                } else {
                    $metadata_value = sanitize_text_field( $_POST['metadata_value'] );
                }

                update_post_meta( $post->ID, $post_meta_key, $metadata_value );
                $response = 'success';
            } else {
                switch ( $_POST['metadata_type'] ) {
                    case 'taxonomy':
                    case 'taxonomy hierarchical':
                        $response = wp_set_post_terms( $post->ID, $_POST['metadata_value'], $_POST['metadata_term'] );
                    break;
                    default:
                        $response = new WP_Error( 'invalid-type', __( 'Invalid metadata type', 'publishpress' ) );
                    break;
                }
            }

            // Assuming we've got to this point, just regurgitate the value
            if ( ! is_wp_error( $response ) ) {
                $this->print_ajax_response( 'success', $_POST['metadata_value'] );
            } else {
                $this->print_ajax_response( 'error', __( 'Metadata could not be updated.', 'publishpress' ) );
            }
        }

        public function calendar_filters() {
            $select_filter_names = array();

            $select_filter_names['post_status'] = 'post_status';
            $select_filter_names['cat']         = 'cat';
            $select_filter_names['tag']         = 'tag';
            $select_filter_names['author']      = 'author';
            $select_filter_names['type']        = 'cpt';
            $select_filter_names['weeks']       = 'weeks';

            return apply_filters( 'pp_calendar_filter_names', $select_filter_names );
        }

        /**
         * Sanitize a $_GET or similar filter being used on the calendar
         *
         * @since 0.8
         *
         * @param string $key Filter being sanitized
         * @param string $dirty_value Value to be sanitized
         * @return string $sanitized_value Safe to use value
         */
        public function sanitize_filter( $key, $dirty_value ) {
            switch ( $key ) {
                case 'post_status':
                    // Whitelist-based validation for this parameter
                    $valid_statuses   = wp_list_pluck( $this->get_post_statuses(), 'slug' );
                    $valid_statuses[] = 'future';
                    $valid_statuses[] = 'unpublish';
                    $valid_statuses[] = 'publish';
                    $valid_statuses[] = 'trash';
                    if ( in_array( $dirty_value, $valid_statuses ) ) {
                        return $dirty_value;
                    } else {
                        return '';
                    }
                    break;
                case 'cpt':
                    $cpt                  = sanitize_key( $dirty_value );
                    $supported_post_types = $this->get_post_types_for_module( $this->module );
                    if ( $cpt && in_array( $cpt, $supported_post_types ) ) {
                        return $cpt;
                    } else {
                        return '';
                    }
                    break;
                case 'start_date':
                    return date( 'Y-m-d', strtotime( $dirty_value ) );
                    break;
                case 'cat':
                case 'tag':
                case 'author':
                    return intval( $dirty_value );
                    break;
                case 'weeks':
                    $weeks = intval( $dirty_value );
                    return empty( $weeks ) ? self::DEFAULT_NUM_WEEKS : $weeks;
                    break;
                default:
                    return false;
                    break;
            }// End switch().
        }

        public function calendar_filter_options( $select_id, $select_name, $filters ) {
            switch ( $select_id ) {
                case 'post_status':
                    $post_statuses = $this->get_post_statuses();
                ?>
                    <select id="<?php echo $select_id; ?>" name="<?php echo $select_name; ?>" >
                        <option value=""><?php _e( 'All statuses', 'publishpress' ); ?></option>
                        <?php
                        foreach ( $post_statuses as $post_status ) {
                            echo "<option value='" . esc_attr( $post_status->slug ) . "' " . selected( $post_status->slug, $filters['post_status'] ) . '>' . esc_html( $post_status->name ) . '</option>';
                        }
                        ?>
                    </select>
                    <?php
                break;
                case 'cat':
                    // Filter by categories, borrowed from wp-admin/edit.php
                    if ( taxonomy_exists( 'category' ) ) {
                        $category_dropdown_args = array(
                            'show_option_all' => __( 'All categories', 'publishpress' ),
                            'hide_empty' => 0,
                            'hierarchical' => 1,
                            'show_count' => 0,
                            'orderby' => 'name',
                            'selected' => $filters['cat'],
                            );
                        wp_dropdown_categories( $category_dropdown_args );
                    }
                break;
                case 'tag':
                    if ( taxonomy_exists( 'post_tag' ) ) {
                        $tag_dropdown_args = array(
                            'show_option_all' => __( 'All tags', 'publishpress' ),
                            'hide_empty' => 0,
                            'hierarchical' => 0,
                            'show_count' => 0,
                            'orderby' => 'name',
                            'selected' => $filters['tag'],
                            'taxonomy' => 'post_tag',
                            'name' => 'tag',
                            );
                        wp_dropdown_categories( $tag_dropdown_args );
                    }
                break;
                case 'author':
                    $users_dropdown_args = array(
                        'show_option_all'   => __( 'All users', 'publishpress' ),
                        'name'              => 'author',
                        'selected'          => $filters['author'],
                        'who'               => 'authors',
                        );
                    $users_dropdown_args = apply_filters( 'pp_calendar_users_dropdown_args', $users_dropdown_args );
                    wp_dropdown_users( $users_dropdown_args );
                break;
                case 'type':
                    $supported_post_types = $this->get_post_types_for_module( $this->module );
                    if ( count( $supported_post_types ) > 1 ) {
                        ?>
                        <select id="type" name="cpt">
                            <option value=""><?php _e( 'All types', 'publishpress' );
                        ?></option>
                        <?php
                        foreach ( $supported_post_types as $key => $post_type_name ) {
                            $all_post_types = get_post_types( null, 'objects' );
                            echo '<option value="' . esc_attr( $post_type_name ) . '"' . selected( $post_type_name, $filters['cpt'] ) . '>' . esc_html( $all_post_types[ $post_type_name ]->labels->name ) . '</option>';
                        }
                        ?>
                        </select>
                    <?php

                    }
                break;
                case 'weeks':
                    if ( ! isset( $filters['weeks'] ) ) {
                        $filters['weeks'] = self::DEFAULT_NUM_WEEKS;
                    }

                    $output = '<select name="weeks">';
                    for ( $i = 1; $i <= 12; $i++ ) {
                        $output .= '<option value="' . esc_attr( $i ) . '" ' . selected( $i, $filters['weeks'], false ) . '>' . sprintf( _n( '%s week', '%s weeks', $i, 'publishpress' ), esc_attr( $i ) ) . '</option>';
                    }
                    $output .= '</select>';
                    echo $output;
                break;
                default:
                    do_action( 'pp_calendar_filter_display', $select_id, $select_name, $filters );
                break;
            }// End switch().
        }

        /**
         * When a post is updated, clean the <li> html post cache for it
         */
        public function action_clean_li_html_cache( $post_id ) {
            wp_cache_delete( $post_id . 'can_modify', self::$post_li_html_cache_key );
            wp_cache_delete( $post_id . 'read_only', self::$post_li_html_cache_key );
        }

        /**
         * Filters the status text of the post. Fixing the text for future and past dates.
         *
         * @param string  $status      The status text.
         * @param WP_Post $post        Post object.
         * @param string  $column_name The column name.
         * @param string  $mode        The list display mode ('excerpt' or 'list').
         */
        public function filter_post_date_column_status($status, $post, $column_name, $mode) {
            if ( 'date' === $column_name ) {
                if ( '0000-00-00 00:00:00' === $post->post_date ) {
                    $time_diff = 0;
                } else {
                    $time = get_post_time( 'G', true, $post );

                    $time_diff = time() - $time;
                }

                if ( 'future' === $post->post_status ) {
                    if ( $time_diff > 0 ) {
                        return '<strong class="error-message">' . __( 'Missed schedule' ) . '</strong>';
                    } else {
                        return __( 'Scheduled' );
                    }
                }

                if ( 'publish' === $post->post_status ) {
                    return __( 'Published' );
                }

                return __( 'Publish on' );
            }

            return $status;
        }
    }
}
