<?php

class BR_Form_Handler {
    
    public function __construct() {
        // AJAX handlers for calendar widget
        add_action('wp_ajax_br_submit_calendar_booking', array($this, 'handle_calendar_booking'));
        add_action('wp_ajax_nopriv_br_submit_calendar_booking', array($this, 'handle_calendar_booking'));
        
        // AJAX handlers for Gravity Forms
        add_action('wp_ajax_br_submit_booking', array($this, 'handle_booking_submission'));
        add_action('wp_ajax_nopriv_br_submit_booking', array($this, 'handle_booking_submission'));
        
        // Gravity Forms submission handler
        add_action('gform_after_submission', array($this, 'handle_gravity_form_submission'), 10, 2);
        
        // Debug handler
        add_action('wp_ajax_br_test_form_handler', array($this, 'test_form_handler'));
        add_action('wp_ajax_nopriv_br_test_form_handler', array($this, 'test_form_handler'));
    }
    
    /**
     * Handle booking submission from calendar widget
     */
    public function handle_calendar_booking() {
        // Set timezone to UTC+3
        date_default_timezone_set(BR_TIMEZONE);
        
        // Debug - log what we're receiving
        error_log('BR Form Handler - Received POST data: ' . print_r($_POST, true));
        
        // Verify nonce
        if (!wp_verify_nonce($_POST['nonce'], 'br_calendar_nonce')) {
            error_log('BR Form Handler - Invalid nonce: ' . ($_POST['nonce'] ?? 'not set'));
            wp_send_json_error('Invalid nonce');
        }
        
        // Check database table
        if (!$this->check_database_table()) {
            error_log('BR Form Handler - Database table does not exist');
            wp_send_json_error('Database error: Table not found. Please contact administrator.');
        }
        
        // Validate required fields
        $required_fields = array('guest_name', 'email', 'phone', 'checkin_date', 'checkout_date');
        $missing_fields = array();
        foreach ($required_fields as $field) {
            if (empty($_POST[$field])) {
                $missing_fields[] = $field;
            }
        }
        
        if (!empty($missing_fields)) {
            error_log('BR Form Handler - Missing fields: ' . implode(', ', $missing_fields));
            wp_send_json_error('Please fill in all required fields. Missing: ' . implode(', ', $missing_fields));
        }
        
        // Validate email
        if (!is_email($_POST['email'])) {
            wp_send_json_error('Please enter a valid email address');
        }
        
        // Validate dates
        $checkin = DateTime::createFromFormat('Y-m-d', $_POST['checkin_date']);
        $checkout = DateTime::createFromFormat('Y-m-d', $_POST['checkout_date']);
        
        if (!$checkin || !$checkout) {
            error_log('BR Form Handler - Invalid date format. Checkin: ' . $_POST['checkin_date'] . ', Checkout: ' . $_POST['checkout_date']);
            wp_send_json_error('Invalid date format');
        }
        
        // Validate minimum nights (3 nights minimum)
        $nights = $checkin->diff($checkout)->days;
        $min_nights = get_option('br_min_nights', 3);
        
        if ($nights < $min_nights) {
            error_log('BR Form Handler - Invalid number of nights: ' . $nights . ' (minimum: ' . $min_nights . ')');
            wp_send_json_error('Minimum stay is ' . $min_nights . ' nights. You selected ' . $nights . ' nights.');
        }
        
        // Check availability
        if (!BR_Database::check_availability($_POST['checkin_date'], $_POST['checkout_date'])) {
            wp_send_json_error('These dates are not available. Please select different dates.');
        }
        
        // Calculate total price
        $pricing_engine = new BR_Pricing_Engine();
        $total_price = $pricing_engine->calculate_total_price(
            $_POST['checkin_date'],
            $_POST['checkout_date']
        );
        
        // Get additional services
        $additional_services = isset($_POST['additional_services']) && is_array($_POST['additional_services']) 
            ? $_POST['additional_services'] 
            : array();
        
        // Prepare booking data
        $booking_data = array(
            'guest_name' => sanitize_text_field($_POST['guest_name']),
            'email' => sanitize_email($_POST['email']),
            'phone' => sanitize_text_field($_POST['phone']),
            'checkin_date' => $checkin->format('Y-m-d'),
            'checkout_date' => $checkout->format('Y-m-d'),
            'total_price' => $total_price,
            'message' => sanitize_textarea_field($_POST['message'] ?? ''),
            'status' => 'pending',
            'created_at' => current_time('mysql'),
            'additional_services' => maybe_serialize($additional_services),
            'booking_data' => maybe_serialize(array(
                'form_source' => 'calendar_widget',
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
                'ip_address' => $_SERVER['REMOTE_ADDR'] ?? '',
                'selected_services' => $additional_services,
                'submission_time' => current_time('mysql'),
                'timezone' => BR_TIMEZONE
            ))
        );
        
        error_log('BR Form Handler - Attempting to save booking: ' . print_r($booking_data, true));
        
        // Insert booking
        global $wpdb;
        $table_name = $wpdb->prefix . 'br_bookings';
        
        $inserted = $wpdb->insert($table_name, $booking_data);
        
        if (!$inserted) {
            error_log('BR Form Handler - Database error: ' . $wpdb->last_error);
            wp_send_json_error('Failed to save booking. Database error: ' . $wpdb->last_error);
        }
        
        $booking_id = $wpdb->insert_id;
        
        // Send admin notification
        do_action('br_send_admin_notification', $booking_id);
        
        // Send guest confirmation email
        do_action('br_send_guest_confirmation', $booking_id);
        
        // Log the booking
        error_log('BR Form Handler: New booking created - ID: ' . $booking_id . ', Guest: ' . $booking_data['guest_name']);
        
        wp_send_json_success(array(
            'message' => 'Your booking request has been submitted successfully! You will receive a confirmation email shortly.',
            'booking_id' => $booking_id,
            'debug' => array(
                'nights' => $nights,
                'total_price' => $total_price,
                'timezone' => BR_TIMEZONE,
                'current_time' => current_time('mysql')
            )
        ));
    }
    
    /**
     * Handle booking submission from Gravity Forms
     */
    public function handle_booking_submission() {
        // Set timezone to UTC+3
        date_default_timezone_set(BR_TIMEZONE);
        
        // Verify nonce if using custom AJAX
        if (!wp_verify_nonce($_POST['nonce'], 'br_booking_nonce')) {
            wp_send_json_error('Invalid nonce');
        }
        
        // Process the booking from Gravity Forms data
        $this->process_gravity_forms_booking($_POST);
    }
    
    /**
     * Handle Gravity Forms submission
     */
    public function handle_gravity_form_submission($entry, $form) {
        // Set timezone to UTC+3
        date_default_timezone_set(BR_TIMEZONE);
        
        // Check if this is a booking form based on form settings
        if (!rgar($form, 'br_booking_enabled')) {
            return;
        }
        
        // Map Gravity Forms fields to booking data
        $booking_data = $this->map_gravity_forms_data($entry, $form);
        
        // Save booking
        $booking_id = $this->save_booking($booking_data);
        
        if ($booking_id) {
            // Send notifications
            do_action('br_send_admin_notification', $booking_id);
            do_action('br_send_guest_confirmation', $booking_id);
            
            // Add note to entry
            GFFormsModel::add_note($entry['id'], 0, 'Booking Request', sprintf(__('Booking request created with ID: %d', 'booking-requests'), $booking_id));
            
            // Update entry meta
            gform_update_meta($entry['id'], 'booking_request_id', $booking_id);
        }
    }
    
    /**
     * Map Gravity Forms data to booking data
     */
    private function map_gravity_forms_data($entry, $form) {
        // Get field mappings from form settings
        $first_name = rgar($entry, rgar($form, 'br_field_first_name'));
        $last_name = rgar($entry, rgar($form, 'br_field_last_name'));
        $guest_name = trim($first_name . ' ' . $last_name);
        
        // Get additional services from checkboxes
        $additional_services = array();
        $services_field_id = rgar($form, 'br_field_additional_services');
        if ($services_field_id) {
            foreach ($form['fields'] as $field) {
                if ($field->id == $services_field_id && $field->type == 'checkbox') {
                    foreach ($field->choices as $index => $choice) {
                        $input_id = $services_field_id . '.' . ($index + 1);
                        if (rgar($entry, $input_id)) {
                            $additional_services[] = sanitize_title($choice['text']);
                        }
                    }
                }
            }
        }
        
        $booking_data = array(
            'guest_name' => $guest_name,
            'email' => rgar($entry, rgar($form, 'br_field_email')),
            'phone' => rgar($entry, rgar($form, 'br_field_phone')),
            'checkin_date' => rgar($entry, rgar($form, 'br_field_checkin_date')),
            'checkout_date' => rgar($entry, rgar($form, 'br_field_checkout_date')),
            'message' => rgar($entry, rgar($form, 'br_field_details')),
            'status' => 'pending',
            'created_at' => current_time('mysql'),
            'additional_services' => maybe_serialize($additional_services),
            'booking_data' => maybe_serialize(array(
                'form_source' => 'gravity_forms',
                'form_id' => $form['id'],
                'entry_id' => $entry['id'],
                'form_title' => $form['title'],
                'timezone' => BR_TIMEZONE
            ))
        );
        
        // Calculate price based on dates
        $pricing_engine = new BR_Pricing_Engine();
        $booking_data['total_price'] = $pricing_engine->calculate_total_price(
            $booking_data['checkin_date'],
            $booking_data['checkout_date']
        );
        
        return $booking_data;
    }
    
    /**
     * Save booking to database
     */
    private function save_booking($booking_data) {
        return BR_Database::save_booking($booking_data);
    }
    
    /**
     * Check if database table exists
     */
    private function check_database_table() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'br_bookings';
        return $wpdb->get_var("SHOW TABLES LIKE '$table_name'") == $table_name;
    }
    
    /**
     * Test form handler for debugging
     */
    public function test_form_handler() {
        // Log all received data
        error_log('BR Form Handler Test - POST data: ' . print_r($_POST, true));
        error_log('BR Form Handler Test - Nonce: ' . ($_POST['nonce'] ?? 'not set'));
        
        $response = array(
            'received_data' => $_POST,
            'nonce_valid' => wp_verify_nonce($_POST['nonce'] ?? '', 'br_calendar_nonce'),
            'required_fields' => array(
                'guest_name' => !empty($_POST['guest_name']),
                'email' => !empty($_POST['email']),
                'phone' => !empty($_POST['phone']),
                'checkin_date' => !empty($_POST['checkin_date']),
                'checkout_date' => !empty($_POST['checkout_date'])
            ),
            'email_valid' => is_email($_POST['email'] ?? ''),
            'database_table_exists' => $this->check_database_table(),
            'timezone' => BR_TIMEZONE,
            'current_time' => current_time('mysql')
        );
        
        wp_send_json_success($response);
    }
}