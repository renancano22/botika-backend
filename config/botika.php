<?php

return [
    // Batches expiring within this many days are flagged "near expiry".
    'expiry_warning_days' => (int) env('BOTIKA_EXPIRY_WARNING_DAYS', 30),
    // Number of past complete months used by the demand forecasting module.
    'forecast_history_months' => (int) env('BOTIKA_FORECAST_HISTORY_MONTHS', 12),
];
