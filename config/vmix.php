<?php

return [

    'graphics_url' => env('VMIX_GRAPHICS_URL', 'https://d2ms7y76opxgn0.cloudfront.net/assets/demo/graphics/default/'),

    'headshots_url' => env('VMIX_HEADSHOTS_URL', 'https://d2ms7y76opxgn0.cloudfront.net/assets/demo/headshots/'),

    'headshot_width' => (int) env('VMIX_HEADSHOT_WIDTH', 256),

    'headshot_height' => (int) env('VMIX_HEADSHOT_HEIGHT', 256),

    'asset_version' => env('VMIX_ASSET_VERSION', '0'),

    'slots_per_page' => 12,

];
