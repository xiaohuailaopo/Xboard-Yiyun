<?php

return [
    // Additional HTTPS Theme origins; the site's app_url is always allowed.
    'theme_origins' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('PAYMENT_THEME_ORIGINS', ''))
    ))),
];
