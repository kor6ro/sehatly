<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Push delivery driver (F-005)
    |--------------------------------------------------------------------------
    |
    | `log` writes a delivery line and is the only driver permitted in
    | `local`/`testing`. `fcm` posts to Firebase Cloud Messaging HTTP v1 using a
    | Google service-account JSON whose PATH comes from `FIREBASE_CREDENTIALS`.
    |
    | The service-account file lives OUTSIDE the repository, its path is never
    | committed, and `.gitignore` refuses the common download names. The guard
    | refuses a non-local `fcm` deployment with no path configured.
    |
    | `project_id` is read from `FIREBASE_PROJECT_ID` rather than from the JSON so a
    | deployment can point at a project without editing the key file; when it is
    | empty the JSON's own `project_id` is used.
    */

    'driver' => env('PUSH_DRIVER', 'log'),

    'firebase' => [
        'credentials' => env('FIREBASE_CREDENTIALS'),
        'project_id' => env('FIREBASE_PROJECT_ID'),
        'token_uri' => env('FIREBASE_TOKEN_URI', 'https://oauth2.googleapis.com/token'),
        'endpoint' => env('FIREBASE_ENDPOINT', 'https://fcm.googleapis.com/v1'),
        'timeout' => (int) env('FIREBASE_TIMEOUT', 10),
    ],

];
