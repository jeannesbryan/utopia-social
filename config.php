<?php
// Pastikan session menyala
if (session_status() === PHP_SESSION_NONE) { session_start(); }

// Konstanta Global
define('UTOPIA_API_URL', 'http://127.0.0.1:20000/api/1.0'); 
define('CHANNEL_ID', '2F5F675D31CA664E102AFDF061516AE3'); // Default channel untuk modul Social
define('POSTS_PER_PAGE', 50);

// Fungsi Pengeksekusi Curl ke API Utopia
function callRawUtopiaAPI($payload) {
    $ch = curl_init(UTOPIA_API_URL); 
    curl_setopt_array($ch, [
        CURLOPT_POST => true, 
        CURLOPT_POSTFIELDS => $payload, 
        CURLOPT_RETURNTRANSFER => true, 
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'], 
        CURLOPT_TIMEOUT => 10
    ]);
    $res = curl_exec($ch); 
    $err = curl_error($ch); 
    curl_close($ch);
    
    if ($err) return ['error' => 'Connection error: ' . $err];
    return json_decode($res, true, 512, JSON_BIGINT_AS_STRING) ?: ['error' => 'Invalid JSON/Empty API response'];
}

// Fungsi Wrapper API (Otomatis sisipkan Token)
function callUtopiaAPI($method, $params = [], $token = null) {
    $data = ['method' => $method, 'token' => $token ?? $_SESSION['token'] ?? null]; 
    if (!empty($params)) $data['params'] = $params;
    return callRawUtopiaAPI(json_encode($data));
}

// Fungsi Bawaan Pembuat Avatar Default
function generateDefaultAvatar($t) { 
    $c = '#'.substr(md5($t),0,6); $i = strtoupper(substr($t,0,1)); 
    return '<svg width="48" height="48" xmlns="http://www.w3.org/2000/svg"><rect width="48" height="48" fill="'.$c.'"/><text x="50%" y="50%" dominant-baseline="middle" text-anchor="middle" font-size="24" fill="white" font-family="Arial">'.$i.'</text></svg>'; 
}
?>