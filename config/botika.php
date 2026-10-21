<?php

return [
    // Admin and staff are warned when a batch will expire within this many months ("near expiry").
    'expiry_warning_months' => (int) env('BOTIKA_EXPIRY_WARNING_MONTHS', 1),
    // Number of past complete months used by the demand forecasting module.
    'forecast_history_months' => (int) env('BOTIKA_FORECAST_HISTORY_MONTHS', 12),
    // Approved medicine requests not claimed within this many days are cancelled automatically,
    // so the medicine set aside for them goes back to the available stock.
    'unclaimed_days' => (int) env('BOTIKA_UNCLAIMED_DAYS', 7),

    // A temporary default password set by the administrator stops working after this many days
    // if the user has not logged in and changed it (the administrator then resets it: a new temporary password).
    'temp_password_days' => (int) env('BOTIKA_TEMP_PASSWORD_DAYS', 3),
    // Password history: a new password cannot be any of the account's last N passwords, counting the
    // current one (5 = the current password and the 4 before it). Applies to every password change and reset.
    'password_history' => (int) env('BOTIKA_PASSWORD_HISTORY', 5),
    // Most a resident can ask for of one medicine in a single request (medicine or restock request).
    // Keep the same number as MAX_REQUEST_QUANTITY in the frontend (src/validation.ts).
    'max_request_quantity' => (int) env('BOTIKA_MAX_REQUEST_QUANTITY', 30),

    // The 63 barangays of Bulan, Sorsogon (source: PhilAtlas / PSGC). Used for the address dropdown.
    'barangays' => [
        'A. Bonifacio',
        'Abad Santos',
        'Aguinaldo',
        'Antipolo',
        'Beguin',
        'Benigno S. Aquino',
        'Bical',
        'Bonga',
        'Butag',
        'Cadandanan',
        'Calomagon',
        'Calpi',
        'Cocok-Cabitan',
        'Daganas',
        'Danao',
        'Dolos',
        'E. Quirino',
        'Fabrica',
        'G. del Pilar',
        'Gate',
        'Inararan',
        'J. Gerona',
        'J. P. Laurel',
        'Jamorawon',
        'Lajong',
        'Libertad',
        'M. Roxas',
        'Magsaysay',
        'Managanaga',
        'Marinab',
        'Montecalvario',
        'N. Roque',
        'Namo',
        'Nasuje',
        'Obrero',
        'Osmeña',
        'Otavi',
        'Padre Diaz',
        'Palale',
        'Quezon',
        'R. Gerona',
        'Recto',
        'Sagrada',
        'San Francisco',
        'San Isidro',
        'San Juan Bag-o',
        'San Juan Daan',
        'San Rafael',
        'San Ramon',
        'San Vicente',
        'Santa Remedios',
        'Santa Teresita',
        'Sigad',
        'Somagongsong',
        'Taromata',
        'Zone I Poblacion',
        'Zone II Poblacion',
        'Zone III Poblacion',
        'Zone IV Poblacion',
        'Zone V Poblacion',
        'Zone VI Poblacion',
        'Zone VII Poblacion',
        'Zone VIII Poblacion',
    ],
];
