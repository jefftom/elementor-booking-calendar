<?php
/**
 * Email Handler Class
 */
class BR_Email_Handler {
    
    /**
     * Initialize email handler
     */
    public function init() {
        // Hook into booking events
        add_action('booking_request_submitted', array($this, 'send_admin_notification'));
        add_action('booking_request_approved', array($this, 'send_approval_email'));
        add_action('booking_request_denied', array($this, 'send_denial_email'));
        
        // Add custom email headers
        add_filter('wp_mail_content_type', array($this, 'set_html_content_type'));
    }
    
    /**
     * Set HTML content type for emails
     */
    public function set_html_content_type() {
        return 'text/html';
    }
    
    /**
     * Send admin notification for new booking
     */
    public function send_admin_notification($booking_data) {
        $admin_emails = get_option('br_admin_emails', get_option('admin_email'));
        $admin_emails = array_map('trim', explode(',', $admin_emails));
        
        $subject = sprintf(
            __('New Booking Request - %s %s (%s to %s)', 'booking-requests'),
            $booking_data['first_name'],
            $booking_data['last_name'],
            date('d/m/Y', strtotime($booking_data['checkin_date'])),
            date('d/m/Y', strtotime($booking_data['checkout_date']))
        );
        
        // Calculate nights
        $checkin = new DateTime($booking_data['checkin_date']);
        $checkout = new DateTime($booking_data['checkout_date']);
        $nights = $checkin->diff($checkout)->days;
        
        // Generate approval links
        $approve_url = add_query_arg(array(
            'br_action' => 'approve',
            'token' => $booking_data['approval_token']
        ), home_url());
        
        $deny_url = add_query_arg(array(
            'br_action' => 'deny',
            'token' => $booking_data['approval_token']
        ), home_url());
        
        // Load template
        ob_start();
        include BR_PLUGIN_PATH . 'templates/emails/admin-notification.php';
        $message = ob_get_clean();
        
        $headers = array(
            'From: ' . get_option('br_email_from_name', get_bloginfo('name')) . ' <' . get_option('br_email_from', get_option('admin_email')) . '>',
            'Reply-To: ' . $booking_data['email']
        );
        
        foreach ($admin_emails as $admin_email) {
            wp_mail($admin_email, $subject, $message, $headers);
        }
        
        // Log email activity
        $this->log_email('admin_notification', $booking_data['id'], $admin_emails);
    }
    
    /**
     * Send approval email to guest
     */
    public function send_approval_email($booking_id) {
        $booking = BR_Booking_Requests::get_booking($booking_id);
        if (!$booking) {
            return;
        }
        
        $subject = sprintf(
            __('Booking Confirmed - Your Stay at %s', 'booking-requests'),
            get_bloginfo('name')
        );
        
        // Calculate nights
        $checkin = new DateTime($booking->checkin_date);
        $checkout = new DateTime($booking->checkout_date);
        $nights = $checkin->diff($checkout)->days;
        
        // Load template
        ob_start();
        include BR_PLUGIN_PATH . 'templates/emails/approval-email.php';
        $message = ob_get_clean();
        
        $headers = array(
            'From: ' . get_option('br_email_from_name', get_bloginfo('name')) . ' <' . get_option('br_email_from', get_option('admin_email')) . '>'
        );
        
        wp_mail($booking->email, $subject, $message, $headers);
        
        // Log email activity
        $this->log_email('approval', $booking_id, $booking->email);
    }
    
    /**
     * Send denial email to guest
     */
    public function send_denial_email($booking_id) {
        $booking = BR_Booking_Requests::get_booking($booking_id);
        if (!$booking) {
            return;
        }
        
        $subject = sprintf(
            __('Booking Request Update - %s', 'booking-requests'),
            get_bloginfo('name')
        );
        
        // Find alternative dates
        $alternative_dates = $this->find_alternative_dates($booking->checkin_date, $booking->checkout_date);
        
        // Load template
        ob_start();
        include BR_PLUGIN_PATH . 'templates/emails/denial-email.php';
        $message = ob_get_clean();
        
        $headers = array(
            'From: ' . get_option('br_email_from_name', get_bloginfo('name')) . ' <' . get_option('br_email_from', get_option('admin_email')) . '>'
        );
        
        wp_mail($booking->email, $subject, $message, $headers);
        
        // Log email activity
        $this->log_email('denial', $booking_id, $booking->email);
    }
    
    /**
     * Find alternative available dates
     */
    private function find_alternative_dates($checkin, $checkout) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'booking_requests';
        
        $checkin_date = new DateTime($checkin);
        $checkout_date = new DateTime($checkout);
        $duration = $checkin_date->diff($checkout_date)->days;
        
        $alternatives = array();
        
        // Check dates before requested dates
        $alt_checkin = clone $checkin_date;
        $alt_checkin->modify('-' . $duration . ' days');
        $alt_checkout = clone $checkin_date;
        
        if ($this->check_availability($alt_checkin->format('Y-m-d'), $alt_checkout->format('Y-m-d'))) {
            $alternatives[] = array(
                'checkin' => $alt_checkin->format('d/m/Y'),
                'checkout' => $alt_checkout->format('d/m/Y')
            );
        }
        
        // Check dates after requested dates
        $alt_checkin = clone $checkout_date;
        $alt_checkout = clone $checkout_date;
        $alt_checkout->modify('+' . $duration . ' days');
        
        if ($this->check_availability($alt_checkin->format('Y-m-d'), $alt_checkout->format('Y-m-d'))) {
            $alternatives[] = array(
                'checkin' => $alt_checkin->format('d/m/Y'),
                'checkout' => $alt_checkout->format('d/m/Y')
            );
        }
        
        return $alternatives;
    }
    
    /**
     * Check if dates are available
     */
    private function check_availability($checkin, $checkout) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'booking_requests';
        
        $overlapping = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $table_name 
            WHERE status = 'approved' 
            AND NOT (checkin_date >= %s OR checkout_date <= %s)",
            $checkout,
            $checkin
        ));
        
        return $overlapping == 0;
    }
    
    /**
     * Log email activity
     */
    private function log_email($type, $booking_id, $recipient) {
        // This could be expanded to save to database or file
        do_action('br_email_sent', array(
            'type' => $type,
            'booking_id' => $booking_id,
            'recipient' => $recipient,
            'timestamp' => current_time('mysql')
        ));
    }
    
    /**
     * Get email template
     */
    public static function get_email_template($template_name, $variables = array()) {
        extract($variables);
        
        ob_start();
        include BR_PLUGIN_PATH . 'templates/emails/' . $template_name . '.php';
        return ob_get_clean();
    }
}