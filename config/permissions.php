<?php

/*
 * User Permissions and Restrictions.
 *
 * The administrator always has full access (and cannot be restricted, so the system can never be locked).
 * For Pharmacy Staff and Residents the administrator turns each feature on or off in
 * Admin → User Permissions. A permission that was never changed uses its "default" below, which is the
 * access each role had before this feature, so nothing changes until the administrator restricts something.
 *
 * Each permission: key => [module, label, description, default].
 */
return [
    'roles' => [
        'staff' => 'Pharmacy Staff',
        'resident' => 'Resident',
    ],

    'permissions' => [
        'staff' => [
            'medicines.view' => ['Medicine List', 'View medicine list', 'Open the Medicine List with stock, batches and expiration dates.', true],
            'medicines.manage' => ['Medicine List', 'Add and edit medicines', 'Add new medicines, edit medicine details and confirm when there is no stock.', true],
            'inventory.view' => ['Stock & Batches', 'View stock and batches', 'Open Stock & Batches, expiry alerts and the stock history.', true],
            'inventory.manage' => ['Stock & Batches', 'Record stock-in and stock-out', 'Add new stock batches and remove expired, damaged or lost items.', true],
            'requests.view' => ['Requests', 'View requests', 'Open the list of medicine and restock requests and their details.', true],
            'requests.review' => ['Requests', 'Approve and reject medicine requests', 'Approve or reject residents\' medicine requests (restock requests stay with the administrator).', true],
            'dispensing.dispense' => ['Dispensing', 'Dispense medicines', 'Verify residents by QR code, Resident ID or name and release medicines.', true],
            'residents.register' => ['Dispensing', 'Register walk-in residents', 'Register residents who have no account yet (for example, no cellphone).', true],
            'dispensing.records' => ['Dispensing', 'View dispensing records', 'Open the list of all medicines released to residents.', true],
        ],
        'resident' => [
            'medicines.view' => ['Medicines', 'Check medicine availability', 'See which medicines are available at Botika ng Bayan.', true],
            'requests.create' => ['Requests', 'Submit medicine requests', 'Request available medicines for pickup, and update a request while it is under review.', true],
            'restock.create' => ['Requests', 'Submit restock requests', 'Ask for an out-of-stock medicine to be restocked.', true],
            'requests.view' => ['Requests', 'View and track requests', 'Open My Requests and follow the status of each request.', true],
            'requests.cancel' => ['Requests', 'Cancel requests', 'Cancel their own request while it is pending or not yet claimed.', true],
            'history.view' => ['History', 'View medicine history', 'See the medicines they received from Botika ng Bayan.', true],
            'notifications.view' => ['Notifications', 'View notifications', 'Open their SMS and in-app notifications.', true],
            'profile.edit' => ['Profile', 'Edit profile', 'Change their name, address, mobile number, password and profile picture.', true],
        ],
    ],

    // Shown to the administrator as locked rows: these features are for the administrator only.
    'admin_only' => [
        ['Medicine List', 'Delete medicines'],
        ['Requests', 'Approve and reject restock requests'],
        ['User Accounts', 'Create, edit and deactivate accounts'],
        ['User Permissions', 'Manage user permissions'],
        ['SMS Notifications', 'Send SMS announcements'],
        ['Reports', 'Generate reports'],
        ['Demand Forecasting', 'View and generate forecasts'],
    ],
];
