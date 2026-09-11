<?php
/**
 * Access Control Configuration
 * Centralized role-based access control definitions
 * Include this file in any PHP file that needs access control
 */

// Define allowed pages based on roles
$allowed_pages = [
    'admin' => [
        'home.php', 
        'all_users.php', 
        'suppliers.php', 
        'items.php', 
        'customer.php', 
        'truck_masterlist.php', 
        'trailers.php', 
        'prime_movers.php', 
        'customer_pricing.php', 
        'purchase_order.php', 
        'sales_order.php', 
        'service_invoice.php', 
        'company_profile.php', 
        'purchase_orders_list.php', 
        'purchase_orders_view.php', 
        'general_settings.php', 
        'reset_password.php', 
        'sales_order_all.php', 
        'sales_order_list.php', 
        'aged_payables.php', 
        'reports.php', 
        'aged_receivables.php', 
        'employee_list.php',
        'customer_ledger.php',
        'customer_list.php',
    'purchase_order_list_all.php',
    'service_invoice_list_all.php',
'sales_order_list_all.php',
'vendor_list.php',
'vendor_ledger.php'
    ],

    'user' => [
        'home.php', 
        'suppliers.php', 
        'items.php', 
        'customer.php', 
        'truck_masterlist.php', 
        'trailers.php', 
        'prime_movers.php', 
        'customer_pricing.php', 
        'company_profile.php', 
        'purchase_orders_list.php', 
        'purchase_orders_view.php'
    ],

    'purchase_order_maker' => [
        'home.php', 
        'purchase_order.php', 
        'company_profile.php', 
        'purchase_orders_list.php', 
        'purchase_orders_view.php',
        'items.php',
        'item_issuance.php',
        'suppliers.php',
        'purchase_order_list_all.php',
        'vendor_ledger.php',
        'reports.php', 
        // 'aged_payables.php', 
        'vendor_list.php',
    ],

    'sales_order_maker' => [
        'home.php', 
        'sales_order.php', 
        'company_profile.php', 
        'sales_order_all.php', 
        'sales_order_list.php',
        'customer_pricing.php', 
        'customer.php', 
        'aged_payables.php', 
        'aged_receivables.php', 
        'customer_ledger.php',
        'customer_list.php',
        'sales_order_list_all.php',
        'reports.php', 
        // 'aged_receivables.php', 

    ],

    'service_invoice_maker' => [
        'home.php', 
        'service_invoice.php', 
        'company_profile.php',
        'sales_order_all.php', 
        'items.php', 
        'trailers.php', 
        'prime_movers.php', 
        'truck_masterlist.php', 
        'customer_ledger.php',
        'customer_list.php',
        'service_invoice_list_all.php',
        'reports.php', 
        'aged_receivables.php', 





    ],

    'customer_pricer' => [
        'home.php', 
        'customer_pricing.php', 
        'company_profile.php',
        'customer.php', 
        'customer_ledger.php',
        'customer_list.php',
        'reports.php', 



    ],

    'supplier_register' => [
        'suppliers.php',
        'company_profile.php',
        'vendor_list.php',
        'vendor_ledger.php',
        'reports.php', 


    ],

    'item_register' => [
        'items.php',
        'item_issuance.php',
        'company_profile.php',
        'reports.php', 

    ],

    'truck_register' => [
        'company_profile.php',
        'truck_masterlist.php', 
        'reports.php', 

    ],

    'prime_mover_register' => [
        'company_profile.php',
        'prime_movers.php', 
        'reports.php', 

    ],

    'customer_register' => [
        'company_profile.php',
        'customer.php', 
        'customer_ledger.php',
        'customer_list.php',
        'reports.php', 


    ],
];

/**
 * Check if user has access to a specific page
 * @param string $page - The page to check access for
 * @param array $user_roles - Array of user roles
 * @param array $allowed_pages - The allowed pages configuration
 * @return bool - True if access is granted, false otherwise
 */
function hasAccess($page, $user_roles, $allowed_pages) {
    // Admin has access to everything
    if (in_array('admin', $user_roles)) {
        return true;
    }
    
    // Check each role for access
    foreach ($user_roles as $role) {
        if (isset($allowed_pages[$role]) && in_array($page, $allowed_pages[$role])) {
            return true;
        }
    }
    return false;
}

/**
 * Get human-readable role name
 * @param string $role - The role name (e.g., 'purchase_order_maker')
 * @return string - Human-readable name (e.g., 'PO Maker')
 */
function getReadableRoleName($role) {
    switch ($role) {
        case 'purchase_order_maker':
            return 'PO Maker';
        case 'sales_order_maker':
            return 'SO Maker';
        case 'service_invoice_maker':
            return 'SI Maker';
        case 'customer_pricer':
            return 'Customer Pricer';
        case 'supplier_register':
            return 'Supplier Register';
        case 'item_register':
            return 'Item Register';
        case 'truck_register':
            return 'Truck Register';
        case 'prime_mover_register':
            return 'Prime Mover Register';
        case 'customer_register':
            return 'Customer Register';
        case 'admin':
            return 'Admin';
        case 'user':
            return 'User';
        default:
            return ucwords(str_replace('_', ' ', $role));
    }
}

/**
 * Get display name for roles (for badges/labels)
 * @param array $user_roles - Array of user roles
 * @return string - Formatted role display name
 */
function getRoleDisplayName($user_roles) {
    if (in_array('admin', $user_roles)) {
        return 'Admin';
    }
    
    $role_names = [];
    foreach ($user_roles as $role) {
        $role_names[] = getReadableRoleName($role);
    }
    
    return implode(' + ', $role_names);
}

/**
 * Get role badge class for styling
 * @param string $role - The role name
 * @return string - CSS class for the badge
 */
function getRoleBadgeClass($role) {
    return $role === 'admin' ? 'badge-admin' : 'badge-user';
}

/**
 * Check if current user has access to current page
 * Use this function at the top of each page for quick access control
 * 
 * @param array $user_roles - User roles from session
 * @param string $current_page - Current page filename (use basename($_SERVER['PHP_SELF']))
 * @param string $redirect_url - URL to redirect to if access denied (default: 'home.php')
 * @param array $allowed_pages - The allowed pages configuration
 * @return void - Redirects if access denied
 */
function requireAccess($user_roles, $current_page, $allowed_pages, $redirect_url = 'home.php') {
    if (!hasAccess($current_page, $user_roles, $allowed_pages)) {
        $_SESSION['flash_message'] = [
            'type' => 'error',
            'text' => "You don't have permission to access this page."
        ];
        header("Location: " . $redirect_url);
        exit;
    }
}
?>