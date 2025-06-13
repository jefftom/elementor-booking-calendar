<?php
/**
 * Bookings List View
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Get current page for pagination
$current_page = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
$per_page = 20;

// Get filter parameters
$status_filter = isset($_GET['status']) ? sanitize_text_field($_GET['status']) : '';
$search = isset($_GET['s']) ? sanitize_text_field($_GET['s']) : '';

// Calculate pagination
$total_items = BR_Database::get_bookings_count($status_filter);
$total_pages = ceil($total_items / $per_page);

// Get bookings
$bookings = BR_Database::get_bookings(array(
    'status' => $status_filter,
    'search' => $search,
    'limit' => $per_page,
    'offset' => ($current_page - 1) * $per_page,
    'orderby' => 'created_at',
    'order' => 'DESC'
));

?>

<div class="wrap">
    <h1 class="wp-heading-inline"><?php _e('Booking Requests', 'booking-requests'); ?></h1>
    <a href="<?php echo admin_url('admin.php?page=booking-requests-add'); ?>" class="page-title-action"><?php _e('Add New', 'booking-requests'); ?></a>
    <hr class="wp-header-end">
    
    <?php if (isset($_GET['message'])): ?>
        <?php
        $message = '';
        switch ($_GET['message']) {
            case 'approved':
                $message = __('Booking approved successfully.', 'booking-requests');
                break;
            case 'denied':
                $message = __('Booking denied.', 'booking-requests');
                break;
            case 'deleted':
                $message = __('Booking deleted.', 'booking-requests');
                break;
            case 'updated':
                $message = __('Booking updated.', 'booking-requests');
                break;
        }
        if ($message): ?>
            <div class="notice notice-success is-dismissible">
                <p><?php echo esc_html($message); ?></p>
            </div>
        <?php endif; ?>
    <?php endif; ?>
    
    <!-- Filters -->
    <div class="tablenav top">
        <div class="alignleft actions">
            <form method="get" action="">
                <input type="hidden" name="page" value="booking-requests">
                
                <select name="status" id="filter-by-status">
                    <option value=""><?php _e('All Statuses', 'booking-requests'); ?></option>
                    <option value="pending" <?php selected($status_filter, 'pending'); ?>><?php _e('Pending', 'booking-requests'); ?></option>
                    <option value="approved" <?php selected($status_filter, 'approved'); ?>><?php _e('Approved', 'booking-requests'); ?></option>
                    <option value="denied" <?php selected($status_filter, 'denied'); ?>><?php _e('Denied', 'booking-requests'); ?></option>
                </select>
                
                <input type="submit" class="button" value="<?php _e('Filter', 'booking-requests'); ?>">
            </form>
        </div>
        
        <div class="alignleft actions">
            <form method="post" action="">
                <select name="action" id="bulk-action-selector">
                    <option value="-1"><?php _e('Bulk Actions', 'booking-requests'); ?></option>
                    <option value="approve"><?php _e('Approve', 'booking-requests'); ?></option>
                    <option value="deny"><?php _e('Deny', 'booking-requests'); ?></option>
                    <option value="delete"><?php _e('Delete', 'booking-requests'); ?></option>
                </select>
                <input type="submit" class="button action" value="<?php _e('Apply', 'booking-requests'); ?>">
            </form>
        </div>
        
        <div class="alignright">
            <form method="get" action="">
                <input type="hidden" name="page" value="booking-requests">
                <label class="screen-reader-text" for="search-bookings"><?php _e('Search Bookings', 'booking-requests'); ?></label>
                <input type="search" id="search-bookings" name="s" value="<?php echo esc_attr($search); ?>" placeholder="<?php _e('Search bookings...', 'booking-requests'); ?>">
                <input type="submit" class="button" value="<?php _e('Search', 'booking-requests'); ?>">
            </form>
        </div>
    </div>
    
    <!-- Bookings Table -->
    <table class="wp-list-table widefat fixed striped booking-requests">
        <thead>
            <tr>
                <td class="manage-column column-cb check-column">
                    <input type="checkbox" id="cb-select-all-1">
                </td>
                <th scope="col" class="manage-column"><?php _e('Guest Name', 'booking-requests'); ?></th>
                <th scope="col" class="manage-column"><?php _e('Email', 'booking-requests'); ?></th>
                <th scope="col" class="manage-column"><?php _e('Check-in', 'booking-requests'); ?></th>
                <th scope="col" class="manage-column"><?php _e('Check-out', 'booking-requests'); ?></th>
                <th scope="col" class="manage-column"><?php _e('Nights', 'booking-requests'); ?></th>
                <th scope="col" class="manage-column"><?php _e('Total Price', 'booking-requests'); ?></th>
                <th scope="col" class="manage-column"><?php _e('Status', 'booking-requests'); ?></th>
                <th scope="col" class="manage-column"><?php _e('Services', 'booking-requests'); ?></th>
                <th scope="col" class="manage-column"><?php _e('Submitted', 'booking-requests'); ?></th>
                <th scope="col" class="manage-column"><?php _e('Actions', 'booking-requests'); ?></th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($bookings)): ?>
                <tr>
                    <td colspan="11"><?php _e('No bookings found.', 'booking-requests'); ?></td>
                </tr>
            <?php else: ?>
                <?php foreach ($bookings as $booking): 
                    $checkin = new DateTime($booking->checkin_date);
                    $checkout = new DateTime($booking->checkout_date);
                    $nights = $checkin->diff($checkout)->days;
                    
                    $services = array();
                    if ($booking->additional_services) {
                        $selected_services = maybe_unserialize($booking->additional_services);
                        if (is_array($selected_services)) {
                            $services_count = count($selected_services);
                            $services = $services_count > 0 ? $services_count . ' services' : 'None';
                        }
                    }
                ?>
                    <tr>
                        <th scope="row" class="check-column">
                            <input type="checkbox" name="booking_ids[]" value="<?php echo $booking->id; ?>">
                        </th>
                        <td>
                            <strong>
                                <a href="<?php echo admin_url('admin.php?page=booking-requests&action=view&id=' . $booking->id); ?>">
                                    <?php echo esc_html($booking->guest_name); ?>
                                </a>
                            </strong>
                            <div class="row-actions">
                                <span class="edit">
                                    <a href="<?php echo admin_url('admin.php?page=booking-requests-add&id=' . $booking->id); ?>">
                                        <?php _e('Edit', 'booking-requests'); ?>
                                    </a> | 
                                </span>
                                <span class="view">
                                    <a href="<?php echo admin_url('admin.php?page=booking-requests&action=view&id=' . $booking->id); ?>">
                                        <?php _e('View', 'booking-requests'); ?>
                                    </a> | 
                                </span>
                                <?php if ($booking->status === 'pending'): ?>
                                    <span class="approve">
                                        <a href="<?php echo wp_nonce_url(admin_url('admin.php?page=booking-requests&action=approve&id=' . $booking->id), 'booking_action'); ?>" class="approve">
                                            <?php _e('Approve', 'booking-requests'); ?>
                                        </a> | 
                                    </span>
                                    <span class="deny">
                                        <a href="<?php echo wp_nonce_url(admin_url('admin.php?page=booking-requests&action=deny&id=' . $booking->id), 'booking_action'); ?>" class="deny">
                                            <?php _e('Deny', 'booking-requests'); ?>
                                        </a> | 
                                    </span>
                                <?php endif; ?>
                                <span class="trash">
                                    <a href="<?php echo wp_nonce_url(admin_url('admin.php?page=booking-requests&action=delete&id=' . $booking->id), 'booking_action'); ?>" class="delete" onclick="return confirm('<?php _e('Are you sure you want to delete this booking?', 'booking-requests'); ?>')">
                                        <?php _e('Delete', 'booking-requests'); ?>
                                    </a>
                                </span>
                            </div>
                        </td>
                        <td><?php echo esc_html($booking->email); ?></td>
                        <td><?php echo date('M j, Y', strtotime($booking->checkin_date)); ?></td>
                        <td><?php echo date('M j, Y', strtotime($booking->checkout_date)); ?></td>
                        <td><?php echo $nights; ?></td>
                        <td>€<?php echo number_format($booking->total_price, 0, ',', '.'); ?></td>
                        <td>
                            <span class="br-status br-status-<?php echo esc_attr($booking->status); ?>">
                                <?php echo ucfirst($booking->status); ?>
                            </span>
                        </td>
                        <td><?php echo is_string($services) ? $services : (empty($services) ? 'None' : count($services) . ' services'); ?></td>
                        <td><?php echo date('M j, Y', strtotime($booking->created_at)); ?></td>
                        <td>
                            <a href="<?php echo admin_url('admin.php?page=booking-requests-add&id=' . $booking->id); ?>" class="button button-small">
                                <?php _e('Edit', 'booking-requests'); ?>
                            </a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
        <tfoot>
            <tr>
                <td class="manage-column column-cb check-column">
                    <input type="checkbox" id="cb-select-all-2">
                </td>
                <th scope="col" class="manage-column"><?php _e('Guest Name', 'booking-requests'); ?></th>
                <th scope="col" class="manage-column"><?php _e('Email', 'booking-requests'); ?></th>
                <th scope="col" class="manage-column"><?php _e('Check-in', 'booking-requests'); ?></th>
                <th scope="col" class="manage-column"><?php _e('Check-out', 'booking-requests'); ?></th>
                <th scope="col" class="manage-column"><?php _e('Nights', 'booking-requests'); ?></th>
                <th scope="col" class="manage-column"><?php _e('Total Price', 'booking-requests'); ?></th>
                <th scope="col" class="manage-column"><?php _e('Status', 'booking-requests'); ?></th>
                <th scope="col" class="manage-column"><?php _e('Services', 'booking-requests'); ?></th>
                <th scope="col" class="manage-column"><?php _e('Submitted', 'booking-requests'); ?></th>
                <th scope="col" class="manage-column"><?php _e('Actions', 'booking-requests'); ?></th>
            </tr>
        </tfoot>
    </table>
    
    <!-- Pagination -->
    <?php if ($total_pages > 1): ?>
        <div class="tablenav bottom">
            <div class="tablenav-pages">
                <span class="displaying-num"><?php printf(_n('%s item', '%s items', $total_items, 'booking-requests'), $total_items); ?></span>
                <?php
                echo paginate_links(array(
                    'base' => add_query_arg('paged', '%#%'),
                    'format' => '',
                    'prev_text' => __('&laquo;'),
                    'next_text' => __('&raquo;'),
                    'total' => $total_pages,
                    'current' => $current_page
                ));
                ?>
            </div>
        </div>
    <?php endif; ?>
</div>

<style>
.br-status {
    display: inline-block;
    padding: 4px 8px;
    border-radius: 3px;
    font-size: 12px;
    font-weight: 600;
    text-transform: uppercase;
}
.br-status-pending {
    background: #f0ad4e;
    color: #fff;
}
.br-status-approved {
    background: #5cb85c;
    color: #fff;
}
.br-status-denied {
    background: #d9534f;
    color: #fff;
}
.column-cb {
    width: 2.2em;
}
.booking-requests .column-actions {
    width: 100px;
}
</style>