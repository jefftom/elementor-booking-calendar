<?php
/**
 * Custom Booking Calendar Widget
 */
class BR_Booking_Calendar_Widget {
    
    /**
     * Initialize the calendar widget
     */
    public function init() {
        // Add shortcode
        add_shortcode('booking_calendar', array($this, 'render_calendar_shortcode'));
        
        // AJAX handlers
        add_action('wp_ajax_br_get_calendar_data', array($this, 'ajax_get_calendar_data'));
        add_action('wp_ajax_nopriv_br_get_calendar_data', array($this, 'ajax_get_calendar_data'));
        
        add_action('wp_ajax_br_submit_calendar_booking', array($this, 'ajax_submit_booking'));
        add_action('wp_ajax_nopriv_br_submit_calendar_booking', array($this, 'ajax_submit_booking'));
        
        // Enqueue scripts
        add_action('wp_enqueue_scripts', array($this, 'enqueue_scripts'));
    }
    
    /**
     * Enqueue calendar scripts and styles
     */
    public function enqueue_scripts() {
        // Always load if we're in Elementor editor
        if (isset($_GET['elementor-preview']) || 
            (is_singular() && has_shortcode(get_post()->post_content, 'booking_calendar')) ||
            $this->should_load_assets()) {
            
            // For now, just use v1 until v2 files are uploaded
            wp_enqueue_style('br-calendar-styles', BR_PLUGIN_URL . 'public/css/booking-calendar.css', array(), BR_PLUGIN_VERSION);
            wp_enqueue_script('br-calendar-script', BR_PLUGIN_URL . 'public/js/booking-calendar.js', array('jquery'), BR_PLUGIN_VERSION, true);
            
            wp_localize_script('br-calendar-script', 'br_calendar', array(
                'ajax_url' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('br_calendar_nonce'),
                'start_day' => $this->get_day_number(get_option('br_week_start_day', 'saturday')), // Send day number, not name
                'months_to_show' => 12, // Show 12 months
                'min_advance_days' => get_option('br_min_advance_days', 1), // Book at least 1 day in advance
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
    }
    
    /**
     * Check if we should load assets (for widgets, etc)
     */
    private function should_load_assets() {
        // Always load on these pages
        if (is_admin() || is_customize_preview()) {
            return true;
        }
        
        // Check if used in widgets
        if (is_active_widget(false, false, 'booking_calendar', true)) {
            return true;
        }
        
        // For now, let's always load on frontend to ensure it works
        return true;
    }
    
    /**
     * Render calendar shortcode
     */
    public function render_calendar_shortcode($atts) {
        $atts = shortcode_atts(array(
            'show_form' => 'yes',
            'months' => 12,
            'start_day' => 'saturday'
        ), $atts);
        
        // Store the start day for this instance
        $this->current_start_day = $atts['start_day'];
        
        ob_start();
        ?>
        <div class="br-calendar-widget" data-months="<?php echo esc_attr($atts['months']); ?>" data-start-day="<?php echo esc_attr($atts['start_day']); ?>">
            <div class="br-calendar-header">
                <h3><?php _e('Select Your Week', 'booking-requests'); ?></h3>
                <p><?php _e('Click on any available Saturday to select a full week (Saturday to Saturday)', 'booking-requests'); ?></p>
            </div>
            
            <div class="br-calendar-legend">
                <span class="br-legend-item">
                    <span class="br-legend-color br-available"></span>
                    <?php _e('Available', 'booking-requests'); ?>
                </span>
                <span class="br-legend-item">
                    <span class="br-legend-color br-booked"></span>
                    <?php _e('Booked', 'booking-requests'); ?>
                </span>
                <span class="br-legend-item">
                    <span class="br-legend-color br-selected"></span>
                    <?php _e('Selected', 'booking-requests'); ?>
                </span>
            </div>
            
            <div class="br-calendar-container">
                <div class="br-calendar-loading">
                    <span class="spinner"></span>
                    <?php _e('Loading calendar...', 'booking-requests'); ?>
                </div>
                <div class="br-calendar-months"></div>
            </div>
            
            <?php if ($atts['show_form'] === 'yes'): ?>
                <div class="br-calendar-booking-form" style="display: none;">
                    <div class="br-booking-summary">
                        <h4><?php _e('Your Selection', 'booking-requests'); ?></h4>
                        <div class="br-booking-dates">
                            <div class="br-date-display">
                                <label><?php _e('Check-in:', 'booking-requests'); ?></label>
                                <span id="br-checkin-display">-</span>
                            </div>
                            <div class="br-date-display">
                                <label><?php _e('Check-out:', 'booking-requests'); ?></label>
                                <span id="br-checkout-display">-</span>
                            </div>
                        </div>
                        <div class="br-booking-price">
                            <label><?php _e('Weekly Rate:', 'booking-requests'); ?></label>
                            <span id="br-price-display">-</span>
                        </div>
                    </div>
                    
                    <form id="br-calendar-form">
                        <input type="hidden" id="br-checkin-date" name="checkin_date" />
                        <input type="hidden" id="br-checkout-date" name="checkout_date" />
                        <input type="hidden" id="br-weekly-rate" name="weekly_rate" />
                        
                        <div class="br-form-row">
                            <div class="br-form-col">
                                <label for="br-first-name"><?php _e('First Name', 'booking-requests'); ?> *</label>
                                <input type="text" id="br-first-name" name="first_name" required />
                            </div>
                            <div class="br-form-col">
                                <label for="br-last-name"><?php _e('Last Name', 'booking-requests'); ?> *</label>
                                <input type="text" id="br-last-name" name="last_name" required />
                            </div>
                        </div>
                        
                        <div class="br-form-row">
                            <div class="br-form-col">
                                <label for="br-email"><?php _e('Email', 'booking-requests'); ?> *</label>
                                <input type="email" id="br-email" name="email" required />
                            </div>
                            <div class="br-form-col">
                                <label for="br-phone"><?php _e('Phone', 'booking-requests'); ?></label>
                                <input type="tel" id="br-phone" name="phone" />
                            </div>
                        </div>
                        
                        <div class="br-form-row">
                            <label for="br-details"><?php _e('Additional Details or Special Requests', 'booking-requests'); ?></label>
                            <textarea id="br-details" name="details" rows="4"></textarea>
                        </div>
                        
                        <div class="br-form-submit">
                            <button type="submit" class="br-submit-btn">
                                <?php _e('Submit Booking Request', 'booking-requests'); ?>
                            </button>
                        </div>
                    </form>
                    
                    <div class="br-form-message"></div>
                </div>
            <?php endif; ?>
        </div>
        
        <script type="text/javascript">
        // Override the start day for this specific instance
        jQuery(document).ready(function($) {
            var widget = $('.br-calendar-widget[data-start-day="<?php echo esc_js($atts['start_day']); ?>"]');
            if (widget.length && typeof br_calendar !== 'undefined') {
                // Override the global start day with widget-specific setting
                var dayMap = {
                    'sunday': 0,
                    'monday': 1,
                    'saturday': 6
                };
                var startDay = '<?php echo esc_js($atts['start_day']); ?>';
                if (dayMap.hasOwnProperty(startDay)) {
                    // Store original value
                    var originalStartDay = br_calendar.start_day;
                    // Set widget-specific value
                    br_calendar.start_day = dayMap[startDay];
                    
                    // Initialize calendar for this widget
                    widget.data('start-day-number', dayMap[startDay]);
                }
            }
        });
        </script>
        <?php
        return ob_get_clean();
    }
    
    /**
     * AJAX handler to get calendar data
     */
    public function ajax_get_calendar_data() {
        check_ajax_referer('br_calendar_nonce', 'nonce');
        
        $start_date = isset($_POST['start_date']) ? sanitize_text_field($_POST['start_date']) : date('Y-m-d');
        $end_date = isset($_POST['end_date']) ? sanitize_text_field($_POST['end_date']) : date('Y-m-d', strtotime('+1 year'));
        
        // Get all bookings in date range
        global $wpdb;
        $table_name = $wpdb->prefix . 'booking_requests';
        
        $bookings = $wpdb->get_results($wpdb->prepare(
            "SELECT checkin_date, checkout_date 
             FROM $table_name 
             WHERE status = 'approved' 
             AND checkout_date >= %s 
             AND checkin_date <= %s",
            $start_date,
            $end_date
        ));
        
        // Convert to booked weeks array
        $booked_weeks = array();
        foreach ($bookings as $booking) {
            $booked_weeks[] = array(
                'start' => $booking->checkin_date,
                'end' => $booking->checkout_date
            );
        }
        
        // Get pricing for each week
        $current = new DateTime($start_date);
        $end = new DateTime($end_date);
        $pricing_data = array();
        
        // Get the configured start day
        $start_day = get_option('br_week_start_day', 'saturday');
        $day_number = $this->get_day_number($start_day);
        
        // Log for debugging
        error_log("Calendar data - Start day: $start_day (number: $day_number)");
        
        // Find the first occurrence of the start day
        while ($current->format('w') != $day_number) {
            $current->modify('+1 day');
        }
        
        // Generate pricing for each week starting on the configured day
        while ($current <= $end) {
            $week_start = $current->format('Y-m-d');
            $week_end = clone $current;
            $week_end->modify('+6 days');
            
            // Log each week being priced
            error_log("Pricing week: $week_start to " . $week_end->format('Y-m-d'));
            
            $pricing_data[$week_start] = array(
                'rate' => BR_Booking_Requests::get_weekly_rate($week_start),
                'available' => !$this->is_week_booked($week_start, $week_end->format('Y-m-d'), $booked_weeks)
            );
            
            $current->modify('+7 days');
        }
        
        wp_send_json_success(array(
            'booked_weeks' => $booked_weeks,
            'pricing_data' => $pricing_data,
            'start_day' => $day_number,
            'debug' => array(
                'configured_start_day' => $start_day,
                'day_number' => $day_number,
                'first_week' => array_key_first($pricing_data),
                'total_weeks' => count($pricing_data)
            )
        ));
    }
    
    /**
     * AJAX handler to submit booking
     */
    public function ajax_submit_booking() {
        check_ajax_referer('br_calendar_nonce', 'nonce');
        
        // Log the received data
        error_log('Booking submission - Checkin: ' . $_POST['checkin_date'] . ', Checkout: ' . $_POST['checkout_date']);
        
        // Validate required fields
        $required_fields = array('first_name', 'last_name', 'email', 'checkin_date', 'checkout_date');
        foreach ($required_fields as $field) {
            if (empty($_POST[$field])) {
                wp_send_json_error(array('message' => __('Please fill in all required fields', 'booking-requests')));
            }
        }
        
        // Validate dates
        $checkin = sanitize_text_field($_POST['checkin_date']);
        $checkout = sanitize_text_field($_POST['checkout_date']);
        
        if (!$this->validate_booking_dates($checkin, $checkout)) {
            // Calculate the actual difference for debugging
            $checkin_date = new DateTime($checkin);
            $checkout_date = new DateTime($checkout);
            $diff = $checkin_date->diff($checkout_date);
            
            wp_send_json_error(array(
                'message' => sprintf(
                    __('Invalid booking dates selected. Checkin: %s, Checkout: %s, Days: %d', 'booking-requests'),
                    $checkin,
                    $checkout,
                    $diff->days
                )
            ));
        }
        
        // Check availability again
        if (br_check_date_overlap($checkin, $checkout)) {
            wp_send_json_error(array('message' => __('These dates are no longer available', 'booking-requests')));
        }
        
        // Create booking
        $booking_data = array(
            'first_name' => sanitize_text_field($_POST['first_name']),
            'last_name' => sanitize_text_field($_POST['last_name']),
            'email' => sanitize_email($_POST['email']),
            'phone' => sanitize_text_field($_POST['phone']),
            'details' => sanitize_textarea_field($_POST['details']),
            'checkin_date' => $checkin,
            'checkout_date' => $checkout
        );
        
        $booking_id = BR_Booking_Requests::create_booking($booking_data);
        
        if ($booking_id) {
            wp_send_json_success(array(
                'message' => __('Your booking request has been submitted successfully! You will receive a confirmation email shortly.', 'booking-requests'),
                'booking_id' => $booking_id
            ));
        } else {
            wp_send_json_error(array('message' => __('Failed to create booking. Please try again.', 'booking-requests')));
        }
    }
    
    /**
     * Check if a week is booked
     */
    private function is_week_booked($start, $end, $booked_weeks) {
        foreach ($booked_weeks as $booked) {
            // Check for any overlap
            if (!($start >= $booked['end'] || $end <= $booked['start'])) {
                return true;
            }
        }
        return false;
    }
    
    /**
     * Get day number from day name
     */
    private function get_day_number($day_name) {
        $days = array(
            'sunday' => 0,
            'monday' => 1,
            'tuesday' => 2,
            'wednesday' => 3,
            'thursday' => 4,
            'friday' => 5,
            'saturday' => 6
        );
        return isset($days[strtolower($day_name)]) ? $days[strtolower($day_name)] : 6;
    }
    
    /**
     * Validate booking dates
     */
    private function validate_booking_dates($checkin, $checkout) {
        $checkin_date = new DateTime($checkin);
        $checkout_date = new DateTime($checkout);
        
        // Check if dates are valid
        if ($checkin_date >= $checkout_date) {
            return false;
        }
        
        // Check if it's exactly 6 days difference (which equals 7 nights)
        // Example: Sun to Sat = 6 days difference but 7 nights stay
        $diff = $checkin_date->diff($checkout_date);
        if ($diff->days !== 6) {
            error_log('Date validation failed: ' . $diff->days . ' days between ' . $checkin . ' and ' . $checkout);
            return false;
        }
        
        // Check if check-in is in the future
        $today = new DateTime();
        $today->setTime(0, 0, 0);
        if ($checkin_date <= $today) {
            return false;
        }
        
        return true;
    }
}