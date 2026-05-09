<?php
require_once 'config.php';

// Jika belum login, usir kembali ke gerbang depan
if (!isset($_SESSION['token'])) {
    header('Location: index.php');
    exit;
}

// ==========================================
// 🚀 BACKEND AJAX API HANDLER (STATUS & MOOD)
// ==========================================
if (isset($_GET['ajax']) && $_GET['ajax'] === 'update_status' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $status = $_POST['status'] ?? 'Available';
    $mood = $_POST['mood'] ?? '';
    
    $res = callUtopiaAPI('setProfileStatus', [
        'status' => $status,
        'mood' => $mood
    ]);
    echo json_encode($res);
    exit;
}

$userName = $_SESSION['uns'] ?? $_SESSION['nick'];
$userPk = $_SESSION['pk'];

// 🚀 AUTO-FETCH AVATAR JIKA BELUM ADA DI CACHE
if (!isset($_SESSION['ava_cache'])) {
    $_SESSION['ava_cache'] = [];
}

if (!array_key_exists($userPk, $_SESSION['ava_cache'])) {
    $ava = callUtopiaAPI('getContactAvatar', ['pk' => $userPk, 'coder' => 'BASE64', 'format' => 'JPG']);
    if (empty($ava['result']) || $ava['result'] === "0") {
        $ava = callUtopiaAPI('getAvatarByKey', ['pk' => $userPk, 'coder' => 'BASE64', 'format' => 'JPG']);
    }
    if (!empty($ava['result']) && $ava['result'] !== "0") {
        $_SESSION['ava_cache'][$userPk] = 'data:image/jpeg;base64,'.$ava['result'];
    } else {
        $_SESSION['ava_cache'][$userPk] = null;
    }
}

$userAvatar = $_SESSION['ava_cache'][$userPk] !== null 
    ? $_SESSION['ava_cache'][$userPk] 
    : 'data:image/svg+xml;base64,'.base64_encode(generateDefaultAvatar($_SESSION['nick']));

// 🚀 FETCH CURRENT STATUS & MOOD
$profileStatusRes = callUtopiaAPI('getProfileStatus');
$currentStatus = $profileStatusRes['result']['status'] ?? 'Available';
$currentMood = $profileStatusRes['result']['mood'] ?? '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - Wetopia Super App</title>
    <link rel="icon" type="image/svg+xml" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'%3E%3Crect width='100' height='100' fill='%2300ff41'/%3E%3Ctext x='50' y='50' font-size='45' font-weight='bold' fill='%23000000' text-anchor='middle' dominant-baseline='central' font-family='Arial, sans-serif'%3EW%3C/text%3E%3C/svg%3E">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; background: #000; color: #e7e9ea; min-height: 100vh; display: flex; flex-direction: column; }
        
        /* HEADER */
        .header { background: rgba(0,0,0,0.85); backdrop-filter: blur(12px); border-bottom: 1px solid #2f3336; padding: 16px 30px; display: flex; justify-content: space-between; align-items: center; position: sticky; top: 0; z-index: 100; }
        .header-logo { display: flex; align-items: center; gap: 12px; font-size: 22px; font-weight: 800; letter-spacing: 1px; color: #fff; }
        .header-logo svg { width: 32px; height: 32px; }
        .user-profile { display: flex; align-items: center; gap: 12px; }
        .user-avatar { width: 40px; height: 40px; border-radius: 50%; border: 2px solid #2f3336; object-fit: cover; background: #16181c; }
        .user-info { display: flex; flex-direction: column; text-align: right; }
        .user-name { font-weight: 700; font-size: 15px; }
        .user-pk { font-size: 12px; color: #71767b; }
        .btn-logout { margin-left: 20px; padding: 8px 16px; background: transparent; color: #f4212e; border: 1px solid #f4212e; border-radius: 20px; text-decoration: none; font-size: 14px; font-weight: 700; transition: 0.2s; }
        .btn-logout:hover { background: rgba(244,33,46,0.1); }

        /* MAIN CONTENT */
        .main-container { flex: 1; max-width: 1200px; margin: 0 auto; padding: 40px 20px; width: 100%; }
        .welcome-text { font-size: 32px; font-weight: 800; margin-bottom: 8px; }
        .subtitle-text { font-size: 16px; color: #71767b; margin-bottom: 30px; }
        
        /* STATUS PANEL */
        .status-panel { background: #16181c; border: 1px solid #2f3336; border-radius: 16px; padding: 20px; margin-bottom: 40px; display: flex; flex-direction: column; gap: 12px; }
        .status-title { font-weight: 700; font-size: 16px; color: #e7e9ea; }
        .status-controls { display: flex; gap: 12px; align-items: center; flex-wrap: wrap; }
        .status-select { background: #000; color: #fff; border: 1px solid #2f3336; padding: 10px 15px; border-radius: 8px; font-size: 14px; outline: none; cursor: pointer; }
        .status-select:focus { border-color: #00ff41; }
        .mood-input { flex: 1; min-width: 200px; background: #000; color: #fff; border: 1px solid #2f3336; padding: 10px 15px; border-radius: 8px; font-size: 14px; outline: none; }
        .mood-input:focus { border-color: #00ff41; }
        .btn-update-status { background: #00ff41; color: #000; border: none; padding: 10px 24px; border-radius: 8px; font-weight: 700; cursor: pointer; transition: 0.2s; }
        .btn-update-status:hover { background: #00cc33; }
        .btn-update-status:disabled { opacity: 0.5; cursor: not-allowed; }

        /* GRID CARDS */
        .apps-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 24px; }
        .app-card { background: #16181c; border: 1px solid #2f3336; border-radius: 16px; padding: 24px; transition: transform 0.2s, border-color 0.2s; display: flex; flex-direction: column; text-decoration: none; color: inherit; }
        .app-card:hover { transform: translateY(-5px); border-color: #00ff41; }
        
        .app-icon { width: 60px; height: 60px; border-radius: 12px; display: flex; align-items: center; justify-content: center; margin-bottom: 20px; font-size: 28px; }
        .icon-social { background: rgba(29, 155, 240, 0.1); color: #1d9bf0; }
        .icon-messenger { background: rgba(153, 51, 255, 0.1); color: #9933ff; }
        .icon-mail { background: rgba(255, 75, 43, 0.1); color: #ff4b2b; }
        .icon-uns { background: rgba(0, 255, 65, 0.1); color: #00ff41; }
        .icon-wallet { background: rgba(255, 212, 0, 0.1); color: #ffd400; }
        
        .app-title { font-size: 22px; font-weight: 700; margin-bottom: 10px; }
        .app-desc { font-size: 15px; color: #71767b; line-height: 1.5; flex: 1; margin-bottom: 20px; }
        
        .app-launch { display: flex; align-items: center; gap: 8px; font-weight: 700; font-size: 15px; transition: 0.2s; }
        .app-card:hover .app-launch.social { color: #1d9bf0; }
        .app-card:hover .app-launch.messenger { color: #9933ff; }
        .app-card:hover .app-launch.mail { color: #ff4b2b; }
        .app-card:hover .app-launch.uns { color: #00ff41; }
        .app-card:hover .app-launch.wallet { color: #ffd400; }
    </style>
</head>
<body>

<div class="header">
    <div class="header-logo">
        <svg viewBox="0 0 100 100" xmlns="http://www.w3.org/2000/svg">
            <rect width="100" height="100" rx="20" fill="#00ff41"/>
            <text x="50" y="50" font-size="45" font-weight="bold" fill="#000" text-anchor="middle" dominant-baseline="central" font-family="Arial">W</text>
        </svg>
        Wetopia
    </div>
    <div class="user-profile">
        <div class="user-info">
            <div class="user-name"><?=htmlspecialchars($userName)?></div>
            <div class="user-pk"><?=htmlspecialchars(substr($userPk, 0, 8) . '...' . substr($userPk, -8))?></div>
        </div>
        <img src="<?=$userAvatar?>" class="user-avatar" alt="Avatar">
        <a href="index.php?action=logout" class="btn-logout">Logout</a>
    </div>
</div>

<div class="main-container">
    <div class="welcome-text">Welcome back, <?=htmlspecialchars($userName)?>!</div>
    <div class="subtitle-text">Select a module to start interacting with the Web3 Network.</div>

    <div class="status-panel">
        <div class="status-title">Current Status & Mood</div>
        <div class="status-controls">
            <select class="status-select" id="profileStatus">
                <option value="Available" <?= $currentStatus === 'Available' ? 'selected' : '' ?>>🟢 Available</option>
                <option value="Away" <?= $currentStatus === 'Away' ? 'selected' : '' ?>>🟡 Away</option>
                <option value="DoNotDisturb" <?= $currentStatus === 'DoNotDisturb' ? 'selected' : '' ?>>🔴 Do Not Disturb</option>
                <option value="Invisible" <?= $currentStatus === 'Invisible' ? 'selected' : '' ?>>⚫ Invisible</option>
            </select>
            <input type="text" class="mood-input" id="profileMood" value="<?=htmlspecialchars($currentMood)?>" placeholder="What's on your mind?">
            <button class="btn-update-status" id="btnUpdateStatus" onclick="updateStatus()">Update</button>
        </div>
    </div>

    <div class="apps-grid">
        
        <a href="social/index.php" class="app-card">
            <div class="app-icon icon-social">💬</div>
            <div class="app-title">Wetopia Social</div>
            <div class="app-desc">Dive into the decentralized timeline. Chat in public channels, share moments, and engage with the community.</div>
            <div class="app-launch social">Launch Social &rarr;</div>
        </a>

        <a href="messenger/index.php" class="app-card">
            <div class="app-icon icon-messenger">🛡️</div>
            <div class="app-title">uChat Premium</div>
            <div class="app-desc">Encrypted P2P instant messaging. Chat secretly, send files, and BUZZ your friends' screens in real-time.</div>
            <div class="app-launch messenger">Launch uChat &rarr;</div>
        </a>

        <a href="mail/index.php" class="app-card">
            <div class="app-icon icon-mail">✉️</div>
            <div class="app-title">uMail Premium</div>
            <div class="app-desc">Secure Webmail. Send and receive formal, end-to-end encrypted emails without relying on central servers.</div>
            <div class="app-launch mail">Launch uMail &rarr;</div>
        </a>

        <a href="uns/index.php" class="app-card">
            <div class="app-icon icon-uns">🌐</div>
            <div class="app-title">uNS Manager</div>
            <div class="app-desc">Web3 domain control center. Register new domain names or manage your Primary uNS identity.</div>
            <div class="app-launch uns">Launch uNS &rarr;</div>
        </a>

        <a href="wallet/index.php" class="app-card">
            <div class="app-icon icon-wallet">💳</div>
            <div class="app-title">Wetopia Finance</div>
            <div class="app-desc">Manage your crypto assets. Check your Crypton (CRP) balance, create Vouchers, and send funds anonymously.</div>
            <div class="app-launch wallet">Launch Finance &rarr;</div>
        </a>

    </div>
</div>

<script>
async function updateStatus() {
    let btn = document.getElementById('btnUpdateStatus');
    let statusVal = document.getElementById('profileStatus').value;
    let moodVal = document.getElementById('profileMood').value;
    
    btn.innerText = 'Updating...'; 
    btn.disabled = true;
    
    let fd = new FormData();
    fd.append('status', statusVal);
    fd.append('mood', moodVal);
    
    try {
        let r = await fetch('?ajax=update_status', {method: 'POST', body: fd});
        let d = await r.json();
        
        if(d.result) {
            btn.innerText = 'Updated!';
            btn.style.background = '#1d9bf0'; // Biru sukses sebentar
            btn.style.color = '#fff';
        } else {
            alert('Failed to update status.');
            btn.innerText = 'Update';
        }
    } catch(e) {
        alert('Network Error');
        btn.innerText = 'Update';
    }
    
    setTimeout(() => { 
        btn.innerText = 'Update'; 
        btn.disabled = false; 
        btn.style.background = '#00ff41'; 
        btn.style.color = '#000';
    }, 2000);
}
</script>

</body>
</html>