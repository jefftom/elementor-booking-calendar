<?php
/**
 * Plugin Name: Booking Requests
 * Plugin URI: https://example.com/booking-requests
 * Description: Captures booking requests via Gravity Forms with custom dashboard and email approval workflow
 * Version: 1.0.0
 * Author: Your Name
 * Author URI: https://example.com
 * License: GPL-2.0+
 * Text Domain: booking-requests
 * Domain Path: /languages
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('BR_PLUGIN_VERSION', '1.0.0');
define('BR_PLUGIN_URL', plugin_dir_url(__FILE__));
define('BR_PLUGIN_PATH', plugin_dir_path(__FILE__));
define('BR_PLUGIN_BASENAME', plugin_basename(__FILE__));

/**
 * Activation hook - Create database tables
 */
register_activation_hook(__FILE__, 'br_activate_plugin');
function br_activate_plugin() {
    global $wpdb;
    
    $table_name = $wpdb->prefix . 'booking_requests';
    $charset_collate = $wpdb->get_charset_collate();
    
    $sql = "CREATE TABLE IF NOT EXISTS $table_name (
        id INT AUTO_INCREMENT PRIMARY KEY,
        first_name VARCHAR(100) NOT NULL,
        last_name VARCHAR(100) NOT NULL,
        email VARCHAR(255) NOT NULL,
        phone VARCHAR(20),
        details TEXT,
        checkin_date DATE NOT NULL,
        checkout_date DATE NOT NULL,
        weekly_rate DECIMAL(10,2),
        status ENUM('pending', 'approved', 'denied') DEFAULT 'pending',
        form_entry_id INT,
        submitted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        admin_notes TEXT,
        approval_token VARCHAR(64),
        token_expires DATETIME,
        INDEX idx_status (status),
        INDEX idx_dates (checkin_date, checkout_date),
        INDEX idx_token (approval_token)
    ) $charset_collate;";
    
    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);
    
    // Set default options
    add_option('br_email_from', get_option('admin_email'));
    add_option('br_email_from_name', get_bloginfo('name'));
    add_option('br_minimum_days', 7);
    add_option('br_week_start_day', 'saturday');
    add_option('br_min_advance_days', 1);
}

/**
 * Deactivation hook
 */
register_deactivation_hook(__FILE__, 'br_deactivate_plugin');
function br_deactivate_plugin() {
    // Cleanup if needed
}

/**
 * Load plugin textdomain
 */
add_action('plugins_loaded', 'br_load_textdomain');
function br_load_textdomain() {
    load_plugin_textdomain('booking-requests', false, dirname(BR_PLUGIN_BASENAME) . '/languages');
}

/**
 * Include required files
 */
require_once BR_PLUGIN_PATH . 'includes/class-booking-requests.php';
require_once BR_PLUGIN_PATH . 'includes/class-admin-dashboard.php';
require_once BR_PLUGIN_PATH . 'includes/class-email-handler.php';
require_once BR_PLUGIN_PATH . 'includes/class-booking-calendar-widget.php';
require_once BR_PLUGIN_PATH . 'includes/functions.php';

// Only load Gravity Forms addon if GF is active
if (class_exists('GFForms')) {
    require_once BR_PLUGIN_PATH . 'includes/class-gravity-forms-addon.php';
}

// Only load Elementor widgets if Elementor is active
if (did_action('elementor/loaded')) {
    require_once BR_PLUGIN_PATH . 'includes/class-elementor-widget.php';
    require_once BR_PLUGIN_PATH . 'includes/class-elementor-calendar-widget.php';
}

/**
 * Initialize the plugin
 */
add_action('init', 'br_init_plugin');
function br_init_plugin() {
    // Initialize main plugin class
    $booking_requests = new BR_Booking_Requests();
    $booking_requests->init();
    
    // Initialize admin dashboard if in admin
    if (is_admin()) {
        $admin_dashboard = new BR_Admin_Dashboard();
        $admin_dashboard->init();
    }
    
    // Initialize email handler
    $email_handler = new BR_Email_Handler();
    $email_handler->init();
    
    // Initialize calendar widget
    $calendar_widget = new BR_Booking_Calendar_Widget();
    $calendar_widget->init();
}

/**
 * Initialize Gravity Forms addon
 */
add_action('gform_loaded', 'br_load_gravity_forms_addon', 5);
function br_load_gravity_forms_addon() {
    if (!method_exists('GFForms', 'include_addon_framework')) {
        return;
    }
    
    // Load the addon class file
    require_once BR_PLUGIN_PATH . 'includes/class-gravity-forms-addon.php';
    
    GFForms::include_addon_framework();
    GFAddOn::register('BR_Gravity_Forms_Addon');
}

/**
 * Initialize Elementor widgets
 */
add_action('elementor/widgets/widgets_registered', 'br_register_elementor_widgets');
function br_register_elementor_widgets($widgets_manager) {
    // Load widget files only when Elementor is initializing widgets
    require_once BR_PLUGIN_PATH . 'includes/class-elementor-widget.php';
    require_once BR_PLUGIN_PATH . 'includes/class-elementor-calendar-widget.php';
    
    $widgets_manager->register_widget_type(new BR_Elementor_Widget());
    $widgets_manager->register_widget_type(new BR_Elementor_Calendar_Widget());
}

// Also enqueue scripts globally when using Elementor
add_action('elementor/frontend/after_enqueue_scripts', function() {
    if (defined('BR_PLUGIN_URL') && defined('BR_PLUGIN_VERSION')) {
        wp_enqueue_style('br-calendar-styles', BR_PLUGIN_URL . 'public/css/booking-calendar-v2.css', array(), BR_PLUGIN_VERSION);
        wp_enqueue_script('br-calendar-script', BR_PLUGIN_URL . 'public/js/booking-calendar-v2.js', array('jquery'), BR_PLUGIN_VERSION, true);
        
        // Get day number for start day
        $start_day = get_option('br_week_start_day', 'saturday');
        $days = array(
            'sunday' => 0,
            'monday' => 1,
            'tuesday' => 2,
            'wednesday' => 3,
            'thursday' => 4,
            'friday' => 5,
            'saturday' => 6
        );
        $day_number = isset($days[strtolower($start_day)]) ? $days[strtolower($start_day)] : 6;
        
        wp_localize_script('br-calendar-script', 'br_calendar', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('br_calendar_nonce'),
            'start_day' => $day_number,
            'months_to_show' => 12,
            'min_advance_days' => get_option('br_min_advance_days', 1),
            'currency_symbol' => '€',
            'strings' => array(
                'select_week' => __('Select Week', 'booking-requests'),
                'week_unavailable' => __('This week is not available', 'booking-requests'),
                'loading' => __('Loading...', 'booking-requests'),
                'error' => __('An error occurred. Please try again.', 'booking-requests'),
                'booking_submitted' => __('Your booking request has been submitted!', 'booking-requests'),
                'please_fill_all' => __('Please fill in all required fields', 'booking-requests')
            )
        ));
    }
});

/**
 * Enqueue admin scripts and styles
 */
add_action('admin_enqueue_scripts', 'br_admin_enqueue_scripts');
function br_admin_enqueue_scripts($hook) {
    if (strpos($hook, 'booking-requests') !== false) {
        wp_enqueue_style('br-admin-styles', BR_PLUGIN_URL . 'admin/css/admin-styles.css', array(), BR_PLUGIN_VERSION);
        wp_enqueue_script('br-admin-scripts', BR_PLUGIN_URL . 'admin/js/admin-scripts.js', array('jquery'), BR_PLUGIN_VERSION, true);
        wp_localize_script('br-admin-scripts', 'br_ajax', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('br_admin_nonce')
        ));
    }
}

/**
 * Enqueue public scripts and styles
 */
add_action('wp_enqueue_scripts', 'br_public_enqueue_scripts');
function br_public_enqueue_scripts() {
    wp_enqueue_style('br-public-styles', BR_PLUGIN_URL . 'public/css/public-styles.css', array(), BR_PLUGIN_VERSION);
    wp_enqueue_script('br-pricing-calculator', BR_PLUGIN_URL . 'public/js/pricing-calculator.js', array('jquery'), BR_PLUGIN_VERSION, true);
    wp_localize_script('br-pricing-calculator', 'br_pricing', array(
        'ajax_url' => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('br_public_nonce'),
        'minimum_days' => get_option('br_minimum_days', 7)
    ));
}