<?php

class BR_Database {
    
    private static $table_name = 'br_bookings';
    
    public function __construct() {
        // Hook into plugin activation
        register_activation_hook(BR_PLUGIN_BASENAME, array($this, 'create_tables'));
    }
    
    /**
     * Create database tables
     */
    public static function create_tables() {
        global $wpdb;
        
        $table_name = $wpdb->prefix . self::$table_name;
        $charset_collate = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE $table_name (
            id int(11) NOT NULL AUTO_INCREMENT,
            guest_name varchar(255) NOT NULL,
            email varchar(255) NOT NULL,
            phone varchar(50) DEFAULT NULL,
            checkin_date date NOT NULL,
            checkout_date date NOT NULL,
            total_price decimal(10,2) NOT NULL,
            message text DEFAULT NULL,
            status varchar(20) DEFAULT 'pending',
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            booking_data longtext DEFAULT NULL,
            additional_services text DEFAULT NULL,
            admin_notes text DEFAULT NULL,
            PRIMARY KEY (id),
            KEY status (status),
            KEY checkin_date (checkin_date),
            KEY checkout_date (checkout_date),
            KEY email (email)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
        
        // Add version option
        add_option('br_db_version', '1.1');
    }
    
    /**
     * Get all bookings
     */
    public static function get_bookings($args = array()) {
        global $wpdb;
        $table_name = $wpdb->prefix . self::$table_name;
        
        $defaults = array(
            'status' => '',
            'orderby' => 'created_at',
            'order' => 'DESC',
            'limit' => 20,
            'offset' => 0,
            'search' => '',
            'date_from' => '',
            'date_to' => ''
        );
        
        $args = wp_parse_args($args, $defaults);
        
        $where = '1=1';
        $values = array();
        
        if (!empty($args['status'])) {
            $where .= ' AND status = %s';
            $values[] = $args['status'];
        }
        
        if (!empty($args['search'])) {
            $where .= ' AND (guest_name LIKE %s OR email LIKE %s OR phone LIKE %s)';
            $search = '%' . $wpdb->esc_like($args['search']) . '%';
            $values[] = $search;
            $values[] = $search;
            $values[] = $search;
        }
        
        if (!empty($args['date_from'])) {
            $where .= ' AND checkin_date >= %s';
            $values[] = $args['date_from'];
        }
        
        if (!empty($args['date_to'])) {
            $where .= ' AND checkout_date <= %s';
            $values[] = $args['date_to'];
        }
        
        // Prepare query
        $sql = "SELECT * FROM $table_name WHERE $where ORDER BY {$args['orderby']} {$args['order']} LIMIT %d OFFSET %d";
        $values[] = $args['limit'];
        $values[] = $args['offset'];
        
        return $wpdb->get_results($wpdb->prepare($sql, $values));
    }
    
    /**
     * Get booking by ID
     */
    public static function get_booking($id) {
        global $wpdb;
        $table_name = $wpdb->prefix . self::$table_name;
        
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table_name WHERE id = %d",
            $id
        ));
    }
    
    /**
     * Save booking (insert or update)
     */
    public static function save_booking($booking_data, $booking_id = null) {
        global $wpdb;
        $table_name = $wpdb->prefix . self::$table_name;
        
        // Set timezone to UTC+3
        $original_timezone = date_default_timezone_get();
        date_default_timezone_set(BR_TIMEZONE);
        
        if ($booking_id) {
            // Update existing booking
            $result = $wpdb->update(
                $table_name,
                $booking_data,
                array('id' => $booking_id)
            );
            
            if ($result !== false) {
                $return_id = $booking_id;
            } else {
                $return_id = false;
            }
        } else {
            // Insert new booking
            $booking_data['created_at'] = current_time('mysql');
            $result = $wpdb->insert($table_name, $booking_data);
            $return_id = $result ? $wpdb->insert_id : false;
        }
        
        // Restore original timezone
        date_default_timezone_set($original_timezone);
        
        return $return_id;
    }
    
    /**
     * Update booking status
     */
    public static function update_booking_status($id, $status) {
        global $wpdb;
        $table_name = $wpdb->prefix . self::$table_name;
        
        return $wpdb->update(
            $table_name,
            array('status' => $status),
            array('id' => $id)
        );
    }
    
    /**
     * Delete booking
     */
    public static function delete_booking($id) {
        global $wpdb;
        $table_name = $wpdb->prefix . self::$table_name;
        
        return $wpdb->delete(
            $table_name,
            array('id' => $id)
        );
    }
    
    /**
     * Get bookings count
     */
    public static function get_bookings_count($status = '') {
        global $wpdb;
        $table_name = $wpdb->prefix . self::$table_name;
        
        $where = '1=1';
        if (!empty($status)) {
            $where .= $wpdb->prepare(' AND status = %s', $status);
        }
        
        return $wpdb->get_var("SELECT COUNT(*) FROM $table_name WHERE $where");
    }
    
    /**
     * Check availability for dates
     */
    public static function check_availability($checkin_date, $checkout_date, $exclude_id = null) {
        global $wpdb;
        $table_name = $wpdb->prefix . self::$table_name;
        
        $query = "SELECT COUNT(*) FROM $table_name 
                  WHERE status IN ('pending', 'approved')
                  AND (
                      (checkin_date <= %s AND checkout_date > %s) OR
                      (checkin_date < %s AND checkout_date >= %s) OR
                      (checkin_date >= %s AND checkout_date <= %s)
                  )";
        
        $params = array(
            $checkin_date, $checkin_date,
            $checkout_date, $checkout_date,
            $checkin_date, $checkout_date
        );
        
        if ($exclude_id) {
            $query .= " AND id != %d";
            $params[] = $exclude_id;
        }
        
        $conflict = $wpdb->get_var($wpdb->prepare($query, $params));
        
        return $conflict == 0;
    }
    
    /**
     * Get booked dates for calendar
     */
    public static function get_booked_dates($start_date, $end_date) {
        global $wpdb;
        $table_name = $wpdb->prefix . self::$table_name;
        
        $bookings = $wpdb->get_results($wpdb->prepare(
            "SELECT checkin_date, checkout_date, status 
             FROM $table_name 
             WHERE status IN ('approved', 'pending')
             AND checkout_date >= %s 
             AND checkin_date <= %s",
            $start_date,
            $end_date
        ));
        
        $booked_dates = array();
        foreach ($bookings as $booking) {
            $current = new DateTime($booking->checkin_date);
            $end = new DateTime($booking->checkout_date);
            
            while ($current < $end) {
                $date_key = $current->format('Y-m-d');
                // Only show approved bookings as booked on frontend
                if ($booking->status === 'approved') {
                    $booked_dates[$date_key] = 'booked';
                }
                $current->modify('+1 day');
            }
        }
        
        return $booked_dates;
    }
    
    /**
     * Get available services list
     */
    public static function get_additional_services() {
        return array(
            'taxi_service' => __('Taxi service from and to the airport', 'booking-requests'),
            'greeting_service' => __('Greeting service – to welcome you to the Villa and overview the region', 'booking-requests'),
            'welcome_hamper' => __('Welcome hamper on arrival', 'booking-requests'),
            'sailing' => __('Sailing', 'booking-requests'),
            'motorboat_hire' => __('Motorboat hire', 'booking-requests'),
            'flight_bookings' => __('Flight bookings (from the UK)', 'booking-requests'),
            'car_hire' => __('Car hire booking service', 'booking-requests')
        );
    }
}