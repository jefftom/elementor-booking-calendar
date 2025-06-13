<?php

class BR_Admin_Menu {
    
    public function __construct() {
        add_action('admin_menu', array($this, 'add_menu_pages'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_assets'));
        
        // Handle form submissions
        add_action('admin_post_br_save_booking', array($this, 'handle_save_booking'));
    }
    
    /**
     * Add admin menu pages
     */
    public function add_menu_pages() {
        // Main menu
        add_menu_page(
            'Booking Requests',
            'Booking Requests',
            'manage_options',
            'booking-requests',
            array($this, 'render_bookings_page'),
            'dashicons-calendar-alt',
            30
        );
        
        // Submenu pages
        add_submenu_page(
            'booking-requests',
            'All Bookings',
            'All Bookings',
            'manage_options',
            'booking-requests',
            array($this, 'render_bookings_page')
        );
        
        add_submenu_page(
            'booking-requests',
            'Add New Booking',
            'Add New',
            'manage_options',
            'booking-requests-add',
            array($this, 'render_add_booking_page')
        );
        
        add_submenu_page(
            'booking-requests',
            'Settings',
            'Settings',
            'manage_options',
            'booking-requests-settings',
            array($this, 'render_settings_page')
        );
        
        add_submenu_page(
            'booking-requests',
            'Email Test',
            'Email Test',
            'manage_options',
            'booking-requests-email-test',
            array($this, 'render_email_test_page')
        );
    }
    
    /**
     * Enqueue admin assets
     */
    public function enqueue_admin_assets($hook) {
        if (strpos($hook, 'booking-requests') === false) {
            return;
        }
        
        wp_enqueue_style('br-admin-styles', BR_PLUGIN_URL . 'admin/css/admin-styles.css', array(), BR_PLUGIN_VERSION);
        wp_enqueue_script('br-admin-scripts', BR_PLUGIN_URL . 'admin/js/admin-scripts.js', array('jquery'), BR_PLUGIN_VERSION, true);
        
        // Add datepicker for add/edit page
        if (strpos($hook, 'booking-requests-add') !== false) {
            wp_enqueue_script('jquery-ui-datepicker');
            wp_enqueue_style('jquery-ui', 'https://code.jquery.com/ui/1.12.1/themes/base/jquery-ui.css');
        }
        
        wp_localize_script('br-admin-scripts', 'br_admin', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('br_admin_nonce'),
            'min_nights' => get_option('br_min_nights', '3')
        ));
    }
    
    /**
     * Render bookings page
     */
    public function render_bookings_page() {
        // Handle actions
        if (isset($_GET['action'])) {
            switch ($_GET['action']) {
                case 'view':
                    $this->render_view_booking_page();
                    return;
                case 'edit':
                    $this->render_add_booking_page();
                    return;
            }
        }
        
        // Handle bulk actions
        if (isset($_POST['action']) && $_POST['action'] !== '-1') {
            $this->handle_bulk_action();
        }
        
        // Get bookings
        $status = isset($_GET['status']) ? $_GET['status'] : '';
        $paged = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
        $per_page = 20;
        
        $args = array(
            'status' => $status,
            'limit' => $per_page,
            'offset' => ($paged - 1) * $per_page,
            'search' => isset($_GET['s']) ? $_GET['s'] : '',
            'date_from' => isset($_GET['date_from']) ? $_GET['date_from'] : '',
            'date_to' => isset($_GET['date_to']) ? $_GET['date_to'] : ''
        );
        
        $bookings = BR_Database::get_bookings($args);
        $total_bookings = BR_Database::get_bookings_count($status);
        
        // Load view
        include BR_PLUGIN_DIR . 'admin/views/bookings-list.php';
    }
    
    /**
     * Render add/edit booking page
     */
    public function render_add_booking_page() {
        $booking_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
        $booking = null;
        $page_title = 'Add New Booking';
        
        if ($booking_id) {
            $booking = BR_Database::get_booking($booking_id);
            if (!$booking) {
                wp_die(__('Booking not found', 'booking-requests'));
            }
            $page_title = 'Edit Booking';
        }
        
        // Get additional services options
        $additional_services = BR_Database::get_additional_services();
        
        ?>
        <div class="wrap">
            <h1><?php echo esc_html($page_title); ?></h1>
            
            <?php if (isset($_GET['updated'])): ?>
                <div class="notice notice-success is-dismissible">
                    <p><?php _e('Booking saved successfully!', 'booking-requests'); ?></p>
                </div>
            <?php endif; ?>
            
            <form method="post" action="<?php echo admin_url('admin-post.php'); ?>">
                <?php wp_nonce_field('br_save_booking'); ?>
                <input type="hidden" name="action" value="br_save_booking">
                <?php if ($booking_id): ?>
                    <input type="hidden" name="booking_id" value="<?php echo $booking_id; ?>">
                <?php endif; ?>
                
                <table class="form-table">
                    <tr>
                        <th><label for="guest_name"><?php _e('Guest Name', 'booking-requests'); ?> <span class="required">*</span></label></th>
                        <td><input type="text" name="guest_name" id="guest_name" class="regular-text" value="<?php echo esc_attr($booking ? $booking->guest_name : ''); ?>" required></td>
                    </tr>
                    
                    <tr>
                        <th><label for="email"><?php _e('Email', 'booking-requests'); ?> <span class="required">*</span></label></th>
                        <td><input type="email" name="email" id="email" class="regular-text" value="<?php echo esc_attr($booking ? $booking->email : ''); ?>" required></td>
                    </tr>
                    
                    <tr>
                        <th><label for="phone"><?php _e('Phone', 'booking-requests'); ?></label></th>
                        <td><input type="text" name="phone" id="phone" class="regular-text" value="<?php echo esc_attr($booking ? $booking->phone : ''); ?>"></td>
                    </tr>
                    
                    <tr>
                        <th><label for="checkin_date"><?php _e('Check-in Date', 'booking-requests'); ?> <span class="required">*</span></label></th>
                        <td>
                            <input type="date" name="checkin_date" id="checkin_date" value="<?php echo esc_attr($booking ? $booking->checkin_date : ''); ?>" required>
                            <p class="description"><?php _e('Minimum stay is 3 nights', 'booking-requests'); ?></p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th><label for="checkout_date"><?php _e('Check-out Date', 'booking-requests'); ?> <span class="required">*</span></label></th>
                        <td>
                            <input type="date" name="checkout_date" id="checkout_date" value="<?php echo esc_attr($booking ? $booking->checkout_date : ''); ?>" required>
                            <span id="nights-display" style="margin-left: 10px;"></span>
                        </td>
                    </tr>
                    
                    <tr>
                        <th><label for="status"><?php _e('Status', 'booking-requests'); ?></label></th>
                        <td>
                            <select name="status" id="status">
                                <option value="pending" <?php selected($booking ? $booking->status : '', 'pending'); ?>><?php _e('Pending', 'booking-requests'); ?></option>
                                <option value="approved" <?php selected($booking ? $booking->status : '', 'approved'); ?>><?php _e('Approved', 'booking-requests'); ?></option>
                                <option value="denied" <?php selected($booking ? $booking->status : '', 'denied'); ?>><?php _e('Denied', 'booking-requests'); ?></option>
                            </select>
                        </td>
                    </tr>
                    
                    <tr>
                        <th><label><?php _e('Additional Services', 'booking-requests'); ?></label></th>
                        <td>
                            <?php
                            $selected_services = array();
                            if ($booking && $booking->additional_services) {
                                $selected_services = maybe_unserialize($booking->additional_services);
                                if (!is_array($selected_services)) {
                                    $selected_services = array();
                                }
                            }
                            
                            foreach ($additional_services as $key => $label):
                            ?>
                                <label style="display: block; margin-bottom: 8px;">
                                    <input type="checkbox" name="additional_services[]" value="<?php echo esc_attr($key); ?>" 
                                        <?php checked(in_array($key, $selected_services)); ?>>
                                    <?php echo esc_html($label); ?>
                                </label>
                            <?php endforeach; ?>
                        </td>
                    </tr>
                    
                    <tr>
                        <th><label for="message"><?php _e('Guest Message', 'booking-requests'); ?></label></th>
                        <td><textarea name="message" id="message" rows="4" cols="50" class="large-text"><?php echo esc_textarea($booking ? $booking->message : ''); ?></textarea></td>
                    </tr>
                    
                    <tr>
                        <th><label for="admin_notes"><?php _e('Admin Notes', 'booking-requests'); ?></label></th>
                        <td>
                            <textarea name="admin_notes" id="admin_notes" rows="4" cols="50" class="large-text"><?php echo esc_textarea($booking ? $booking->admin_notes : ''); ?></textarea>
                            <p class="description"><?php _e('Internal notes, not visible to guests', 'booking-requests'); ?></p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th><label><?php _e('Price Information', 'booking-requests'); ?></label></th>
                        <td>
                            <div id="price-info">
                                <?php if ($booking): ?>
                                    <strong><?php _e('Total Price:', 'booking-requests'); ?></strong> €<?php echo number_format($booking->total_price, 0, ',', '.'); ?>
                                <?php else: ?>
                                    <em><?php _e('Price will be calculated based on selected dates', 'booking-requests'); ?></em>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                </table>
                
                <p class="submit">
                    <input type="submit" name="submit" id="submit" class="button button-primary" value="<?php _e('Save Booking', 'booking-requests'); ?>">
                    <a href="<?php echo admin_url('admin.php?page=booking-requests'); ?>" class="button"><?php _e('Cancel', 'booking-requests'); ?></a>
                </p>
            </form>
        </div>
        
        <script>
        jQuery(document).ready(function($) {
            // Date validation and nights calculation
            function updateNights() {
                var checkin = $('#checkin_date').val();
                var checkout = $('#checkout_date').val();
                
                if (checkin && checkout) {
                    var checkinDate = new Date(checkin);
                    var checkoutDate = new Date(checkout);
                    var nights = Math.floor((checkoutDate - checkinDate) / (1000 * 60 * 60 * 24));
                    
                    if (nights < <?php echo get_option('br_min_nights', 3); ?>) {
                        $('#nights-display').html('<span style="color: red;"><?php echo sprintf(__('%d nights (minimum %d required)', 'booking-requests'), '__NIGHTS__', get_option('br_min_nights', 3)); ?></span>'.replace('__NIGHTS__', nights));
                        $('#submit').prop('disabled', true);
                    } else {
                        $('#nights-display').html('<span style="color: green;"><?php echo sprintf(__('%d nights', 'booking-requests'), '__NIGHTS__'); ?></span>'.replace('__NIGHTS__', nights));
                        $('#submit').prop('disabled', false);
                        
                        // Calculate price via AJAX
                        $.post(ajaxurl, {
                            action: 'br_calculate_price_admin',
                            checkin: checkin,
                            checkout: checkout,
                            nonce: br_admin.nonce
                        }, function(response) {
                            if (response.success) {
                                $('#price-info').html('<strong><?php _e('Total Price:', 'booking-requests'); ?></strong> ' + response.data.formatted_total);
                            }
                        });
                    }
                }
            }
            
            $('#checkin_date, #checkout_date').on('change', updateNights);
            
            // Initialize on load
            updateNights();
            
            // Set minimum checkout date when checkin changes
            $('#checkin_date').on('change', function() {
                var checkin = new Date($(this).val());
                if (checkin) {
                    checkin.setDate(checkin.getDate() + <?php echo get_option('br_min_nights', 3); ?>);
                    var minCheckout = checkin.toISOString().split('T')[0];
                    $('#checkout_date').attr('min', minCheckout);
                }
            });
        });
        </script>
        <?php
    }
    
    /**
     * Handle save booking form submission
     */
    public function handle_save_booking() {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }
        
        check_admin_referer('br_save_booking');
        
        $booking_id = isset($_POST['booking_id']) ? intval($_POST['booking_id']) : null;
        
        // Calculate total price
        $pricing_engine = new BR_Pricing_Engine();
        $total_price = $pricing_engine->calculate_total_price(
            $_POST['checkin_date'],
            $_POST['checkout_date']
        );
        
        $booking_data = array(
            'guest_name' => sanitize_text_field($_POST['guest_name']),
            'email' => sanitize_email($_POST['email']),
            'phone' => sanitize_text_field($_POST['phone']),
            'checkin_date' => sanitize_text_field($_POST['checkin_date']),
            'checkout_date' => sanitize_text_field($_POST['checkout_date']),
            'total_price' => $total_price,
            'message' => sanitize_textarea_field($_POST['message']),
            'status' => sanitize_text_field($_POST['status']),
            'additional_services' => maybe_serialize($_POST['additional_services'] ?? array()),
            'admin_notes' => sanitize_textarea_field($_POST['admin_notes'])
        );
        
        // Add booking data
        $booking_data['booking_data'] = maybe_serialize(array(
            'source' => 'admin',
            'admin_user' => get_current_user_id(),
            'created_via' => 'dashboard'
        ));
        
        $result = BR_Database::save_booking($booking_data, $booking_id);
        
        if ($result) {
            // If new booking and approved, send confirmation email
            if (!$booking_id && $_POST['status'] === 'approved') {
                do_action('br_send_approval_email', $result);
            }
            
            $redirect_url = add_query_arg(array(
                'page' => 'booking-requests-add',
                'id' => $result,
                'updated' => 'true'
            ), admin_url('admin.php'));
        } else {
            $redirect_url = add_query_arg(array(
                'page' => 'booking-requests',
                'error' => 'save_failed'
            ), admin_url('admin.php'));
        }
        
        wp_redirect($redirect_url);
        exit;
    }
    
    /**
     * Render view booking page
     */
    private function render_view_booking_page() {
        $booking_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
        $booking = BR_Database::get_booking($booking_id);
        
        if (!$booking) {
            wp_die(__('Booking not found', 'booking-requests'));
        }
        
        $additional_services = BR_Database::get_additional_services();
        $selected_services = $booking->additional_services ? maybe_unserialize($booking->additional_services) : array();
        
        include BR_PLUGIN_DIR . 'admin/views/booking-details.php';
    }
    
    /**
     * Render settings page
     */
    public function render_settings_page() {
        // Save settings
        if (isset($_POST['submit']) && wp_verify_nonce($_POST['br_settings_nonce'], 'br_settings')) {
            $this->save_settings();
        }
        
        // Load view
        include BR_PLUGIN_DIR . 'admin/views/settings.php';
    }
    
    /**
     * Save settings
     */
    private function save_settings() {
        $options = array(
            'br_admin_email' => sanitize_email($_POST['br_admin_email']),
            'br_week_start_day' => sanitize_text_field($_POST['br_week_start_day']),
            'br_approval_required' => isset($_POST['br_approval_required']) ? '1' : '0',
            'br_calendar_months_to_show' => intval($_POST['br_calendar_months_to_show']),
            'br_calendar_min_advance_days' => intval($_POST['br_calendar_min_advance_days']),
            'br_min_nights' => intval($_POST['br_min_nights'])
        );
        
        foreach ($options as $key => $value) {
            update_option($key, $value);
        }
        
        add_settings_error('br_settings', 'settings_updated', 'Settings saved successfully!', 'updated');
    }
    
    /**
     * Render email test page
     */
    public function render_email_test_page() {
        include BR_PLUGIN_DIR . 'admin/views/email-test.php';
    }
    
    /**
     * Handle bulk actions
     */
    private function handle_bulk_action() {
        if (!isset($_POST['booking_ids']) || empty($_POST['booking_ids'])) {
            return;
        }
        
        $action = $_POST['action'];
        $booking_ids = array_map('intval', $_POST['booking_ids']);
        
        foreach ($booking_ids as $booking_id) {
            switch ($action) {
                case 'approve':
                    BR_Database::update_booking_status($booking_id, 'approved');
                    do_action('br_send_approval_email', $booking_id);
                    break;
                case 'deny':
                    BR_Database::update_booking_status($booking_id, 'denied');
                    do_action('br_send_denial_email', $booking_id);
                    break;
                case 'delete':
                    BR_Database::delete_booking($booking_id);
                    break;
            }
        }
    }
}

// Add AJAX handler for price calculation
add_action('wp_ajax_br_calculate_price_admin', 'br_calculate_price_admin');
function br_calculate_price_admin() {
    check_ajax_referer('br_admin_nonce', 'nonce');
    
    $checkin = sanitize_text_field($_POST['checkin']);
    $checkout = sanitize_text_field($_POST['checkout']);
    
    $pricing_engine = new BR_Pricing_Engine();
    $total_price = $pricing_engine->calculate_total_price($checkin, $checkout);
    
    wp_send_json_success(array(
        'total_price' => $total_price,
        'formatted_total' => '€' . number_format($total_price, 0, ',', '.')
    ));
}