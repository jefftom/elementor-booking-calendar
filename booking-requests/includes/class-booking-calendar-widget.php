<?php

class BR_Booking_Calendar_Widget {
    
    public function __construct() {
        // Register shortcode
        add_shortcode('booking_calendar', array($this, 'render_shortcode'));
        
        // AJAX handlers for calendar data
        add_action('wp_ajax_br_get_calendar_data', array($this, 'ajax_get_calendar_data'));
        add_action('wp_ajax_nopriv_br_get_calendar_data', array($this, 'ajax_get_calendar_data'));
        
        // AJAX handler for booking submission (handled by form handler)
        add_action('wp_ajax_br_submit_calendar_booking', array($this, 'ajax_submit_booking'));
        add_action('wp_ajax_nopriv_br_submit_calendar_booking', array($this, 'ajax_submit_booking'));
        
        // AJAX handler for price calculation
        add_action('wp_ajax_br_calculate_price', array($this, 'ajax_calculate_price'));
        add_action('wp_ajax_nopriv_br_calculate_price', array($this, 'ajax_calculate_price'));
    }
    
    /**
     * Render calendar shortcode
     */
    public function render_shortcode($atts) {
        $atts = shortcode_atts(array(
            'start_day' => get_option('br_week_start_day', 'saturday'),
            'months' => get_option('br_calendar_months_to_show', '12'),
            'min_advance' => get_option('br_calendar_min_advance_days', '1'),
            'show_form' => 'yes'
        ), $atts);
        
        ob_start();
        ?>
        <div class="br-calendar-widget" 
             data-start-day="<?php echo esc_attr($atts['start_day']); ?>"
             data-months="<?php echo esc_attr($atts['months']); ?>"
             data-min-advance="<?php echo esc_attr($atts['min_advance']); ?>"
             data-show-form="<?php echo esc_attr($atts['show_form']); ?>">
            <div class="br-calendar-container">
                <div class="br-calendar-loading"><?php _e('Loading calendar...', 'booking-requests'); ?></div>
            </div>
            <?php if ($atts['show_form'] === 'yes'): ?>
            <div class="br-calendar-form-container">
                <!-- Form will be loaded here by JavaScript -->
            </div>
            <?php endif; ?>
        </div>
        <?php
        return ob_get_clean();
    }
    
    /**
     * AJAX handler to get calendar data
     */
    public function ajax_get_calendar_data() {
        // Set timezone to UTC+3
        date_default_timezone_set(BR_TIMEZONE);
        
        // Verify nonce
        if (!wp_verify_nonce($_POST['nonce'], 'br_calendar_nonce')) {
            wp_send_json_error('Invalid nonce');
        }
        
        $start_date = sanitize_text_field($_POST['start_date']);
        $end_date = sanitize_text_field($_POST['end_date']);
        
        // Get start day setting
        $start_day = get_option('br_week_start_day', 'saturday');
        $start_day_number = $start_day === 'sunday' ? 0 : 6;
        
        // Get pricing data
        $pricing_engine = new BR_Pricing_Engine();
        $pricing_data = array();
        
        // Generate dates for pricing periods
        $current = new DateTime($start_date);
        $end = new DateTime($end_date);
        
        // Move to first start day (if needed)
        if ($start_day === 'saturday' && $current->format('w') != 6) {
            $current->modify('next saturday');
        } elseif ($start_day === 'sunday' && $current->format('w') != 0) {
            $current->modify('next sunday');
        }
        
        // Generate pricing for each week
        while ($current <= $end) {
            $week_end = clone $current;
            $week_end->modify('+6 days');
            
            $rate = $pricing_engine->calculate_total_price(
                $current->format('Y-m-d'),
                $week_end->format('Y-m-d')
            );
            
            if ($rate > 0) {
                $pricing_data[$current->format('Y-m-d')] = array(
                    'rate' => $rate,
                    'available' => $this->check_availability($current->format('Y-m-d'), $week_end->format('Y-m-d'))
                );
            }
            
            $current->modify('+7 days');
        }
        
        // Get booked dates
        $booked_dates = BR_Database::get_booked_dates($start_date, $end_date);
        
        wp_send_json_success(array(
            'pricing_data' => $pricing_data,
            'booked_dates' => $booked_dates,
            'start_day' => $start_day_number,
            'debug' => array(
                'configured_start_day' => $start_day,
                'day_number' => $start_day_number,
                'first_week' => key($pricing_data),
                'total_weeks' => count($pricing_data),
                'timezone' => BR_TIMEZONE,
                'current_time' => current_time('mysql')
            )
        ));
    }
    
    /**
     * AJAX handler for price calculation
     */
    public function ajax_calculate_price() {
        // Verify nonce
        if (!wp_verify_nonce($_POST['nonce'], 'br_calendar_nonce')) {
            wp_send_json_error('Invalid nonce');
        }
        
        $checkin = sanitize_text_field($_POST['checkin']);
        $checkout = sanitize_text_field($_POST['checkout']);
        
        if (empty($checkin) || empty($checkout)) {
            wp_send_json_error(array('message' => __('Please select both dates', 'booking-requests')));
        }
        
        // Calculate nights
        $checkin_date = new DateTime($checkin);
        $checkout_date = new DateTime($checkout);
        $nights = $checkin_date->diff($checkout_date)->days;
        
        // Check minimum nights
        $min_nights = get_option('br_min_nights', 3);
        if ($nights < $min_nights) {
            wp_send_json_error(array('message' => sprintf(__('Minimum stay is %d nights', 'booking-requests'), $min_nights)));
        }
        
        // Calculate price
        $pricing_engine = new BR_Pricing_Engine();
        $total_price = $pricing_engine->calculate_total_price($checkin, $checkout);
        
        wp_send_json_success(array(
            'total_price' => $total_price,
            'nights' => $nights,
            'formatted_total' => '€' . number_format($total_price, 0, ',', '.')
        ));
    }
    
    /**
     * AJAX handler for booking submission
     * Note: This is now handled by BR_Form_Handler class
     */
    public function ajax_submit_booking() {
        // This method is here for backwards compatibility
        // The actual handling is done by BR_Form_Handler::handle_calendar_booking()
        
        if (class_exists('BR_Form_Handler')) {
            $form_handler = new BR_Form_Handler();
            $form_handler->handle_calendar_booking();
        } else {
            wp_send_json_error('Form handler not available');
        }
    }
    
    /**
     * Check availability for dates
     */
    private function check_availability($start_date, $end_date) {
        return BR_Database::check_availability($start_date, $end_date);
    }
    
    /**
     * Get calendar configuration
     */
    public function get_calendar_config() {
        return array(
            'start_day' => get_option('br_week_start_day', 'saturday'),
            'months_to_show' => get_option('br_calendar_months_to_show', '12'),
            'min_advance_days' => get_option('br_calendar_min_advance_days', '1'),
            'min_nights' => get_option('br_min_nights', '3'),
            'currency_symbol' => '€',
            'timezone' => BR_TIMEZONE
        );
    }
}