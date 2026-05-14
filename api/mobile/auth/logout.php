<?php
require_once __DIR__ . '/../bootstrap.php';

mobileApiRequireMethod(['POST']);

$auth = mobileApiAuthenticate($mobileApiDb);
mobileApiRevokeToken($mobileApiDb, $auth['token']);

mobileApiSuccess(null, 'Logout successful.');
