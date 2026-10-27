<?php

return [

    'media_disk' => env('VMIX_MEDIA_DISK', 'public'),

    'headshot_width' => (int) env('VMIX_HEADSHOT_WIDTH', 256),

    'headshot_height' => (int) env('VMIX_HEADSHOT_HEIGHT', 256),

    'slots_per_page' => 12,

];
