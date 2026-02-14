<?php

return [
    'manual_entry' => [
        'allowed_emails' => array_values(array_unique(array_filter(array_map(
            static fn (string $email): string => strtolower(trim($email)),
            explode(',', (string) env('SCENE_INTEL_MANUAL_ENTRY_ALLOWED_EMAILS', '')),
        )))),
    ],
];
