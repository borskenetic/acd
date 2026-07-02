<?php

return [
    'sso_enabled' => env('ZENDY_SSO_ENABLED', false),

    'redirect_url' => env('ZENDY_REDIRECT_URL', 'https://zendy.io/'),

    'sso_url' => env('ZENDY_SSO_URL', 'https://zendy.io/sso-login'),
];
