<?php
require_once 'config.php';

// Jika belum login, usir kembali ke gerbang depan
if (!isset($_SESSION['token'])) {
    header('Location: index.php');
    exit;
}

$userName = $_SESSION['uns'] ?? $_SESSION['nick'];
$userPk = $_SESSION['pk'];

// 🚀 AUTO-FETCH AVATAR JIKA BELUM ADA DI CACHE
if (!isset($_SESSION['ava_cache'])) {
    $_SESSION['ava_cache'] = [];
}

if (!array_key_exists($userPk, $_SESSION['ava_cache'])) {
    // Tarik avatar dari API
    $ava = callUtopiaAPI('getContactAvatar', ['pk' => $userPk, 'coder' => 'BASE64', 'format' => 'JPG']);
    if (empty($ava['result']) || $ava['result'] === "0") {
        $ava = callUtopiaAPI('getAvatarByKey', ['pk' => $userPk, 'coder' => 'BASE64', 'format' => 'JPG']);
    }
    
    // Simpan ke Cache
    if (!empty($ava['result']) && $ava['result'] !== "0") {
        $_SESSION['ava_cache'][$userPk] = 'data:image/jpeg;base64,'.$ava['result'];
    } else {
        $_SESSION['ava_cache'][$userPk] = null;
    }
}

// Gunakan dari Cache, jika gagal/kosong baru pakai Inisial
$userAvatar = $_SESSION['ava_cache'][$userPk] !== null 
    ? $_SESSION['ava_cache'][$userPk] 
    : 'data:image/svg+xml;base64,'.base64_encode(generateDefaultAvatar($_SESSION['nick']));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - Utopia Web Client</title>
    <link rel="icon" type="image/svg+xml" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'%3E%3Crect width='100' height='100' fill='%2300ff41'/%3E%3Ctext x='50' y='50' font-size='45' font-weight='bold' fill='%23000000' text-anchor='middle' dominant-baseline='central' font-family='Arial, sans-serif'%3EU%3C/text%3E%3C/svg%3E">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; background: #000; color: #e7e9ea; min-height: 100vh; display: flex; flex-direction: column; }
        
        /* HEADER */
        .header { background: rgba(0,0,0,0.85); backdrop-filter: blur(12px); border-bottom: 1px solid #2f3336; padding: 16px 30px; display: flex; justify-content: space-between; align-items: center; position: sticky; top: 0; z-index: 100; }
        .header-logo { display: flex; align-items: center; gap: 12px; font-size: 20px; font-weight: 800; letter-spacing: 1px; }
        .header-logo svg { width: 32px; height: 32px; }
        .user-profile { display: flex; align-items: center; gap: 12px; }
        .user-avatar { width: 40px; height: 40px; border-radius: 50%; border: 2px solid #2f3336; object-fit: cover; background: #16181c; }
        .user-info { display: flex; flex-direction: column; }
        .user-name { font-weight: 700; font-size: 15px; }
        .user-pk { font-size: 12px; color: #71767b; }
        .btn-logout { margin-left: 20px; padding: 8px 16px; background: transparent; color: #f4212e; border: 1px solid #f4212e; border-radius: 20px; text-decoration: none; font-size: 14px; font-weight: 700; transition: 0.2s; }
        .btn-logout:hover { background: rgba(244,33,46,0.1); }

        /* MAIN CONTENT */
        .main-container { flex: 1; max-width: 1200px; margin: 0 auto; padding: 40px 20px; width: 100%; }
        .welcome-text { font-size: 32px; font-weight: 800; margin-bottom: 8px; }
        .subtitle-text { font-size: 16px; color: #71767b; margin-bottom: 40px; }
        
        /* GRID CARDS */
        .apps-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 24px; }
        .app-card { background: #16181c; border: 1px solid #2f3336; border-radius: 16px; padding: 24px; transition: transform 0.2s, border-color 0.2s; display: flex; flex-direction: column; text-decoration: none; color: inherit; }
        .app-card:hover { transform: translateY(-5px); border-color: #00ff41; }
        
        .app-icon { width: 60px; height: 60px; border-radius: 12px; display: flex; align-items: center; justify-content: center; margin-bottom: 20px; font-size: 28px; }
        .icon-social { background: rgba(29, 155, 240, 0.1); color: #1d9bf0; }
        .icon-messenger { background: rgba(153, 51, 255, 0.1); color: #9933ff; } /* 🚀 WARNA BARU UNTUK MESSENGER */
        .icon-uns { background: rgba(0, 255, 65, 0.1); color: #00ff41; }
        .icon-wallet { background: rgba(255, 212, 0, 0.1); color: #ffd400; }
        
        .app-title { font-size: 22px; font-weight: 700; margin-bottom: 10px; }
        .app-desc { font-size: 15px; color: #71767b; line-height: 1.5; flex: 1; margin-bottom: 20px; }
        
        .app-launch { display: flex; align-items: center; gap: 8px; font-weight: 700; font-size: 15px; transition: 0.2s; }
        .app-card:hover .app-launch.social { color: #1d9bf0; }
        .app-card:hover .app-launch.messenger { color: #9933ff; } /* 🚀 HOVER MESSENGER */
        .app-card:hover .app-launch.uns { color: #00ff41; }
        .app-card:hover .app-launch.wallet { color: #ffd400; }
    </style>
</head>
<body>

<div class="header">
    <div class="header-logo">
        <svg viewBox="0 0 100 100" xmlns="http://www.w3.org/2000/svg">
            <rect width="100" height="100" rx="20" fill="#00ff41"/>
            <text x="50" y="50" font-size="45" font-weight="bold" fill="#000" text-anchor="middle" dominant-baseline="central" font-family="Arial">U</text>
        </svg>
        Utopia Web
    </div>
    <div class="user-profile">
        <div class="user-info" style="text-align: right;">
            <div class="user-name"><?=htmlspecialchars($userName)?></div>
            <div class="user-pk"><?=htmlspecialchars(substr($userPk, 0, 8) . '...' . substr($userPk, -8))?></div>
        </div>
        <img src="<?=$userAvatar?>" class="user-avatar" alt="Avatar">
        <a href="index.php?action=logout" class="btn-logout">Logout</a>
    </div>
</div>

<div class="main-container">
    <div class="welcome-text">Welcome back, <?=htmlspecialchars($userName)?>!</div>
    <div class="subtitle-text">Select a module to start interacting with the Utopia Network.</div>

    <div class="apps-grid">
        
        <a href="social/index.php" class="app-card">
            <div class="app-icon icon-social">💬</div>
            <div class="app-title">Utopia Social</div>
            <div class="app-desc">Tenggelam dalam linimasa komunitas. Ngobrol di channel dan bagikan momen penting Anda.</div>
            <div class="app-launch social">Launch Social &rarr;</div>
        </a>

        <a href="messenger/index.php" class="app-card">
            <div class="app-icon icon-messenger">🛡️</div>
            <div class="app-title">uChat Premium</div>
            <div class="app-desc">Layanan pesan instan P2P terenkripsi. Ngobrol rahasia, kirim file, dan getarkan layar teman dengan BUZZ!</div>
            <div class="app-launch messenger">Launch uChat &rarr;</div>
        </a>

        <a href="uns/index.php" class="app-card">
            <div class="app-icon icon-uns">🌐</div>
            <div class="app-title">uNS Manager</div>
            <div class="app-desc">Pusat kontrol domain Web3 Utopia. Daftarkan nama baru atau atur gelar utama (Primary uNS) Anda.</div>
            <div class="app-launch uns">Launch uNS &rarr;</div>
        </a>

        <a href="wallet/index.php" class="app-card">
            <div class="app-icon icon-wallet">💳</div>
            <div class="app-title">Utopia Finance</div>
            <div class="app-desc">Kelola aset kripto Anda. Cek saldo Crypton (CRP), buat Voucher, dan kirim dana secara anonim.</div>
            <div class="app-launch wallet">Launch Wallet &rarr;</div>
        </a>

    </div>
</div>

</body>
</html>