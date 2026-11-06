<?php

declare(strict_types=1);

return [
    'routes' => [
        ['name' => 'gallery#index', 'url' => '/home', 'verb' => 'GET'],
        ['name' => 'gallery#album', 'url' => '/album/{token}', 'verb' => 'GET'],
        ['name' => 'gallery#themePreview', 'url' => '/theme-preview', 'verb' => 'GET'],
        ['name' => 'gallery#thumbnail', 'url' => '/gallery/thumb/{id}', 'verb' => 'GET'],
        ['name' => 'gallery#photo', 'url' => '/gallery/photo/{id}', 'verb' => 'GET'],
        ['name' => 'page#index', 'url' => '/', 'verb' => 'GET'],
        ['name' => 'upload#upload', 'url' => '/upload', 'verb' => 'POST'],
        ['name' => 'upload#sports', 'url' => '/sports', 'verb' => 'GET'],
        ['name' => 'desktop#config', 'url' => '/desktop/config', 'verb' => 'GET'],
        ['name' => 'desktop#upload', 'url' => '/desktop/upload', 'verb' => 'POST'],
        ['name' => 'admin#desktopCodes', 'url' => '/admin/desktop-codes', 'verb' => 'GET'],
        ['name' => 'admin#createDesktopCode', 'url' => '/admin/desktop-codes', 'verb' => 'POST'],
        ['name' => 'admin#revokeDesktopCode', 'url' => '/admin/desktop-codes/{id}/revoke', 'verb' => 'POST'],
        ['name' => 'admin#index', 'url' => '/admin', 'verb' => 'GET'],
        ['name' => 'admin#config', 'url' => '/admin/config', 'verb' => 'GET'],
        ['name' => 'admin#save', 'url' => '/admin/config', 'verb' => 'POST'],
        ['name' => 'admin#folders', 'url' => '/admin/folders', 'verb' => 'GET'],
        ['name' => 'admin#createFolder', 'url' => '/admin/folders', 'verb' => 'POST'],
        ['name' => 'admin#stats', 'url' => '/admin/stats', 'verb' => 'GET'],
        ['name' => 'admin#processFolder', 'url' => '/admin/process', 'verb' => 'POST'],
        ['name' => 'admin#pending', 'url' => '/admin/pending', 'verb' => 'GET'],
        ['name' => 'admin#saveScopes', 'url' => '/admin/scopes', 'verb' => 'POST'],
        ['name' => 'admin#albums', 'url' => '/admin/albums', 'verb' => 'GET'],
        ['name' => 'admin#createAlbum', 'url' => '/admin/albums', 'verb' => 'POST'],
        ['name' => 'request#index', 'url' => '/request', 'verb' => 'GET'],
        ['name' => 'request#submit', 'url' => '/request', 'verb' => 'POST'],
        ['name' => 'request#decide', 'url' => '/request/{id}/{action}/{sig}', 'verb' => 'GET'],
        ['name' => 'admin#requests', 'url' => '/admin/requests', 'verb' => 'GET'],
        ['name' => 'admin#saveWebhook', 'url' => '/admin/webhook', 'verb' => 'POST'],
    ],
];
