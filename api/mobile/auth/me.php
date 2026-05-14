<?php
require_once __DIR__ . '/../bootstrap.php';

mobileApiRequireMethod(['GET']);

$auth = mobileApiAuthenticate($mobileApiDb);

mobileApiSuccess([
    'user' => mobileApiUserPayload($auth['user']),
], 'Authenticated user loaded.');
