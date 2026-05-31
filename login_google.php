<?php
session_start();
require_once __DIR__ . '/config.php';

$state = bin2hex(random_bytes(16));
$_SESSION['oauth_state'] = $state;

$query = http_build_query([
    'client_id'     => $_ENV['GOOGLE_CLIENT_ID'],
    'redirect_uri'  => $_ENV['GOOGLE_REDIRECT_URI'],
    'response_type' => 'code',
    'scope'         => 'openid email profile',
    'state'         => $state,
    'prompt'        => 'select_account',
]);
header('Location: https://accounts.google.com/o/oauth2/v2/auth?' . $query);
exit;