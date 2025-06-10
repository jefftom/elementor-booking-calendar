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
    }
    
    /**
     * Set HTML content type for emails
     */
    public function set_html_content_type() {
        return 'text/html';
    }
    
    /**
     * Send email wrapper that works with SMTP plugins
     */
    private function send_email($to, $subject, $message, $headers = array()) {
        // Debug logging
        error_log('Attempting to send email:');
        error_log('To: ' . $to);
        error_log('Subject: ' . $subject);
        error_log('Message length: ' . strlen($message));
        error_log('Message empty: ' . (empty($message) ? 'YES' : 'NO'));
        
        // Ensure message is not empty
        if (empty($message)) {
            error_log('ERROR: Email message is empty!');
            return false;
        }
        
        // Add content type to headers if not already set
        $has_content_type = false;
        foreach ($headers as $header) {
            if (stripos($header, 'content-type') !== false) {
                $has_content_type = true;
                break;
            }
        }
        
        if (!$has_content_type) {
            $headers[] = 'Content-Type: text/html; charset=UTF-8';
        }
        
        // Use wp_mail which will be handled by WP Mail SMTP or similar plugins
        $result = wp_mail($to, $subject, $message, $headers);
        
        // Log the result
        error_log('Email send result: ' . ($result ? 'SUCCESS' : 'FAILED'));
        
        return $result;
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
        
        // Check if template file exists
        $template_path = BR_PLUGIN_PATH . 'templates/emails/admin-notification.php';
        if (!file_exists($template_path)) {
            error_log('Booking email template not found: ' . $template_path);
            // Fallback to simple message
            $message = sprintf(
                '<h2>New Booking Request</h2>
                <p><strong>Guest:</strong> %s %s</p>
                <p><strong>Email:</strong> %s</p>
                <p><strong>Phone:</strong> %s</p>
                <p><strong>Dates:</strong> %s to %s (%d nights)</p>
                <p><strong>Weekly Rate:</strong> €%s</p>
                <p><strong>Message:</strong> %s</p>
                <p><a href="%s">Approve Booking</a> | <a href="%s">Deny Booking</a></p>',
                esc_html($booking_data['first_name']),
                esc_html($booking_data['last_name']),
                esc_html($booking_data['email']),
                esc_html($booking_data['phone']),
                date('d/m/Y', strtotime($booking_data['checkin_date'])),
                date('d/m/Y', strtotime($booking_data['checkout_date'])),
                $nights,
                number_format($booking_data['weekly_rate'], 0, ',', '.'),
                nl2br(esc_html($booking_data['details'])),
                esc_url($approve_url),
                esc_url($deny_url)
            );
        } else {
            // Load template
            ob_start();
            include $template_path;
            $message = ob_get_clean();
        }
        
        // Ensure we have a message
        if (empty($message)) {
            error_log('Booking email message is empty after template rendering');
            $message = '<p>A new booking request has been submitted. Please check your admin dashboard.</p>';
        }
        
        $headers = array(
            'From: ' . get_option('br_email_from_name', get_bloginfo('name')) . ' <' . get_option('br_email_from', get_option('admin_email')) . '>',
            'Reply-To: ' . $booking_data['email'],
            'Content-Type: text/html; charset=UTF-8'
        );
        
        // Send to each admin email
        $sent_count = 0;
        foreach ($admin_emails as $admin_email) {
            if ($this->send_email($admin_email, $subject, $message, $headers)) {
                $sent_count++;
            } else {
                error_log('Failed to send booking notification to: ' . $admin_email);
            }
        }
        
        // Log email activity
        $this->log_email('admin_notification', $booking_data['id'], $admin_emails);
        
        return $sent_count > 0;
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
            'From: ' . get_option('br_email_from_name', get_bloginfo('name')) . ' <' . get_option('br_email_from', get_option('admin_email')) . '>',
            'Content-Type: text/html; charset=UTF-8'
        );
        
        $result = $this->send_email($booking->email, $subject, $message, $headers);
        
        // Log email activity
        $this->log_email('approval', $booking_id, $booking->email);
        
        return $result;
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
            'From: ' . get_option('br_email_from_name', get_bloginfo('name')) . ' <' . get_option('br_email_from', get_option('admin_email')) . '>',
            'Content-Type: text/html; charset=UTF-8'
        );
        
        $result = $this->send_email($booking->email, $subject, $message, $headers);
        
        // Log email activity
        $this->log_email('denial', $booking_id, $booking->email);
        
        return $result;
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