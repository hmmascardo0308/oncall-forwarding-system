<?php
// sidebar.php - Sidebar component with dropdown groups
// Define menu groups and their pages (if not already defined)
if (!isset($menu_groups)) {
    $menu_groups = [
        'customers_sales' => [
            'label' => 'Customers & Sales',
            'icon' => 'users',
            'pages' => [
                'customer.php' => 'Customers Profile',
                'customer_pricing.php' => 'Customer Pricing',
                'sales_order_all.php' => 'Sales Order',
                'service_invoice.php' => 'Service Invoice'
            ]
        ],
        'vendors_purchases' => [
            'label' => 'Vendors & Purchases',
            'icon' => 'shopping-bag',
            'pages' => [
                'suppliers.php' => 'Suppliers Profile',
                'purchase_order.php' => 'Purchase Order',
                'purchase_order_list_all.php' => 'View Orders',
                'purchase_orders_list.php' => 'Purchase Receiving',
            ]
        ],
        'inventory_services' => [
            'label' => 'Inventory & Services',
            'icon' => 'package',
            'pages' => [
                'truck_masterlist.php' => 'Vehicles List',
                'trailers.php' => 'Trailers List',
                // 'prime_movers.php' => 'Prime Movers List',
                'items.php' => 'Items List',
                'item_issuance.php' => 'Items Issuance'
            ]
        ],
        'settings' => [
            'label' => 'Settings',
            'icon' => 'settings',
            'pages' => [
                'all_users.php' => 'Manage Users',
                'employee_list.php' => 'Employees',
                'general_settings.php' => 'General Settings'
            ]
        ]
    ];
}

// Function to check if any page in a group is accessible
// (kept for possible future use, but no longer used to hide groups)
function isGroupAccessible($group, $user_roles, $allowed_pages) {
    foreach ($group['pages'] as $page => $label) {
        if (hasAccess($page, $user_roles, $allowed_pages)) {
            return true;
        }
    }
    return false;
}

// Get current page - but allow override from including page
if (!isset($current_page)) {
    $current_page = basename($_SERVER['PHP_SELF']);
}

// For sub-pages that should map to a parent menu item
$parent_mapping = [
    'sales_order_list.php' => 'sales_order_all.php',
    'service_invoice_list.php' => 'service_invoice.php',
    'purchase_orders_view.php' => 'purchase_order.php',
    'reset_password.php' => 'all_users.php',
    'purchase_orders_all.php' => 'purchase_order.php',
    'sales_order.php'=> 'sales_order_all.php',
    'aged_payables.php' => 'reports.php',
    'aged_receivables.php' => 'reports.php',
    // 'purchase_order_list_all.php' => 'reports.php',
    'service_invoice_list_all.php' => 'reports.php',
  'sales_order_list_all.php' => 'reports.php',
  'vendor_list.php' => 'reports.php',
'vendor_ledger.php' => 'reports.php'





];

// For standalone pages that should map to sidebar links
$standalone_mapping = [
    'aged_payables.php' => 'reports.php',
    'aged_receivables.php' => 'reports.php',
    'customer_ledger.php' => 'reports.php',
    'customer_list.php' => 'reports.php',
    // 'purchase_order_list_all.php' => 'reports.php',
    'service_invoice_list_all.php' => 'reports.php',
  'sales_order_list_all.php' => 'reports.php',
  'vendor_list.php' => 'reports.php',
'vendor_ledger.php' => 'reports.php'






];

// Check if current page is a sub-page that should map to a parent
$active_page_for_sidebar = $current_page;
if (isset($parent_mapping[$current_page])) {
    $active_page_for_sidebar = $parent_mapping[$current_page];
}

// Check if current page should map to a standalone sidebar link
$active_standalone = $current_page;
if (isset($standalone_mapping[$current_page])) {
    $active_standalone = $standalone_mapping[$current_page];
}
?>
<aside class="sidebar">
    <div class="sidebar-brand">ONCALL PANEL</div>
    <nav class="nav-group">
        <div class="nav-label">Menu</div>

        <!-- Home - Accessible to everyone -->
        <a href="home.php" <?php echo ($current_page == 'home.php') ? 'class="active"' : ''; ?>
           onclick="return checkAccess('home.php')">
            <i data-lucide="layout-dashboard"></i> Home
        </a>

        <?php foreach ($menu_groups as $group_key => $group): ?>
            <!-- Always show every group -->
            <div class="nav-group-wrapper">
                <div class="nav-group-header" onclick="toggleDropdown('<?php echo $group_key; ?>')">
                    <span class="group-label">
                        <i data-lucide="<?php echo $group['icon']; ?>"></i>
                        <?php echo $group['label']; ?>
                    </span>
                    <i data-lucide="chevron-right" class="chevron" id="chevron-<?php echo $group_key; ?>"></i>
                </div>
                <div class="nav-dropdown" id="dropdown-<?php echo $group_key; ?>">
                    <?php foreach ($group['pages'] as $page => $label): ?>
                        <!-- Always show every page link -->
                        <a href="<?php echo $page; ?>"
                           <?php echo ($active_page_for_sidebar == $page) ? 'class="active"' : ''; ?>
                           onclick="return checkAccess('<?php echo $page; ?>')">
                            <?php echo $label; ?>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endforeach; ?>

        <!-- Reports - Accessible to all users -->
        <a href="reports.php" <?php echo ($active_standalone == 'reports.php') ? 'class="active"' : ''; ?>
           onclick="return checkAccess('reports.php')">
            <i data-lucide="file-text"></i> REPORTS
        </a>

        <!-- Company Profile - Accessible to all users -->
        <a href="company_profile.php" <?php echo ($current_page == 'company_profile.php') ? 'class="active"' : ''; ?>
           onclick="return checkAccess('company_profile.php')">
            <i data-lucide="building-2"></i> Company Profile
        </a>
    </nav>

    <div class="logout-btn">
        <a href="../logout.php" style="color:#f87171;">
            <i data-lucide="log-out"></i> Logout
        </a>
    </div>
</aside>

<!-- Sidebar JavaScript -->
<script>
    // Toggle dropdown function
    function toggleDropdown(groupKey) {
        const dropdown = document.getElementById('dropdown-' + groupKey);
        const chevron = document.getElementById('chevron-' + groupKey);
       
        if (dropdown) {
            dropdown.classList.toggle('open');
            if (chevron) {
                chevron.classList.toggle('rotated');
            }
        }
    }

    // Auto-open dropdown if a page inside it is active
    document.addEventListener('DOMContentLoaded', function() {
        <?php foreach ($menu_groups as $group_key => $group): ?>
            <?php foreach ($group['pages'] as $page => $label): ?>
                if (window.location.pathname.includes('<?php echo $page; ?>')) {
                    const dropdown = document.getElementById('dropdown-<?php echo $group_key; ?>');
                    const chevron = document.getElementById('chevron-<?php echo $group_key; ?>');
                    if (dropdown) {
                        dropdown.classList.add('open');
                    }
                    if (chevron) {
                        chevron.classList.add('rotated');
                    }
                }
            <?php endforeach; ?>
        <?php endforeach; ?>
       
        // Also check for sub-pages
        <?php foreach ($parent_mapping as $sub_page => $parent_page): ?>
            if (window.location.pathname.includes('<?php echo $sub_page; ?>')) {
                <?php foreach ($menu_groups as $group_key => $group): ?>
                    <?php foreach ($group['pages'] as $page => $label): ?>
                        if ('<?php echo $page; ?>' === '<?php echo $parent_page; ?>') {
                            const dropdown = document.getElementById('dropdown-<?php echo $group_key; ?>');
                            const chevron = document.getElementById('chevron-<?php echo $group_key; ?>');
                            if (dropdown) {
                                dropdown.classList.add('open');
                            }
                            if (chevron) {
                                chevron.classList.add('rotated');
                            }
                        }
                    <?php endforeach; ?>
                <?php endforeach; ?>
            }
        <?php endforeach; ?>
    });
</script>