<?php
// 🚀 INTEGRASI LANGSUNG DENGAN CONFIG UTAMA
require_once '../config.php';

if (!isset($_SESSION['token'])) { 
    header('Location: ../index.php'); 
    exit; 
}

// 🚀 CEK KONFIGURASI TIMELINE AGAR TIDAK BENTROK
if (!defined('CHANNEL_ID')) {
    define('CHANNEL_ID', '2F5F675D31CA664E102AFDF061516AE3'); 
}

$targetPk = $_GET['pk'] ?? '';
if(empty($targetPk)) { header('Location: index.php'); exit; }

// Wajib cari Hashed PK (32-char)
$tHashedPk = (strlen($targetPk) === 32) ? $targetPk : '';
if (empty($tHashedPk)) {
    $cContacts = callUtopiaAPI('getChannelContacts', ['channelid' => CHANNEL_ID]);
    if (isset($cContacts['result'])) {
        foreach ($cContacts['result'] as $c) {
            if (($c['pk'] ?? '') === $targetPk && !empty($c['hashedPk'])) { $tHashedPk = $c['hashedPk']; break; }
        }
    }
}

// ==========================================
// 🚀 BACKEND AJAX API HANDLER
// ==========================================
if (isset($_GET['ajax'])) {
    header('Content-Type: application/json');
    
    if ($_GET['ajax'] === 'send' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $msg = trim($_POST['message']); if (empty($msg)) exit;
        if (empty($tHashedPk)) { echo json_encode(['success'=>false, 'error'=>'ID user tidak ditemukan di channel ini.']); exit; }
        
        $res = callUtopiaAPI('sendChannelPrivateMessageToContact', [
            'channelid' => CHANNEL_ID, 
            'contactHashedPk' => $tHashedPk, 
            'message' => $msg
        ]);
        echo json_encode(['success' => (isset($res['result']) && $res['result'] != "0")]); exit;
    }
    
    if ($_GET['ajax'] === 'history') {
        $history = [];
        if (!empty($tHashedPk)) {
            $r = callUtopiaAPI('getChannelPrivateMessagesOfContact', [
                'channelid' => CHANNEL_ID, 
                'contactHashedPk' => $tHashedPk
            ]);
            if (isset($r['result']) && is_array($r['result'])) $history = $r['result'];
        }
        
        usort($history, function($a, $b) { return strtotime($a['dateTime']??0) - strtotime($b['dateTime']??0); });
        
        // Tarik Hashed PK Sendiri dari Session (sudah di-cache di config atau me.php)
        if (!isset($_SESSION['hashed_pk']) || empty($_SESSION['hashed_pk'])) {
            $cInfo = callUtopiaAPI('getOwnContact');
            if(isset($cInfo['result'])) $_SESSION['hashed_pk'] = $cInfo['result']['hashedPk'] ?? '';
        }
        
        echo json_encode([
            'messages' => $history, 
            'myHashedPk' => $_SESSION['hashed_pk'] ?? ''
        ]); exit;
    }
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Channel PM - Utopia Social</title>
    <link rel="icon" type="image/svg+xml" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'%3E%3Crect width='100' height='100' fill='%231d9bf0'/%3E%3Ctext x='50' y='50' font-size='45' font-weight='bold' fill='%23ffffff' text-anchor='middle' dominant-baseline='central' font-family='Arial, sans-serif'%3EU%3C/text%3E%3C/svg%3E">
    <style>
        *{margin:0;padding:0;box-sizing:border-box} 
        body{font-family:-apple-system,BlinkMacSystemFont,sans-serif;background:#000;color:#e7e9ea;height:100vh;display:flex;justify-content:center}
        .container{width:100%;max-width:600px;display:flex;flex-direction:column;border-left:1px solid #2f3336;border-right:1px solid #2f3336;height:100%}
        
        .header{background:rgba(0,0,0,0.85);backdrop-filter:blur(12px);border-bottom:1px solid #2f3336;padding:16px;display:flex;align-items:center;gap:16px}
        .back-btn{width:36px;height:36px;display:flex;align-items:center;justify-content:center;background:transparent;border:none;color:#e7e9ea;font-size:20px;cursor:pointer;border-radius:50%} .back-btn:hover{background:rgba(255,255,255,0.1)}
        .header-name{font-size:18px;font-weight:700} .header-status{color:#1d9bf0;font-size:12px;font-weight:400}
        
        .chat-box{flex:1;overflow-y:auto;padding:16px;display:flex;flex-direction:column;gap:12px;}
        .chat-bubble{max-width:85%;padding:10px 16px;border-radius:18px;font-size:15px;word-wrap:break-word;white-space:pre-wrap;}
        .chat-left{align-self:flex-start;background:#2f3336;color:#e7e9ea;border-bottom-left-radius:4px}
        .chat-right{align-self:flex-end;background:#1d9bf0;color:#fff;border-bottom-right-radius:4px}
        
        .composer{border-top:1px solid #2f3336;padding:16px;display:flex;gap:12px;background:#000}
        .composer input{flex:1;padding:12px 18px;background:#16181c;border:1px solid #2f3336;border-radius:24px;color:#e7e9ea;font-size:15px;outline:none} .composer input:focus{border-color:#1d9bf0}
        .btn-send{padding:0 20px;background:#1d9bf0;color:#fff;border:none;border-radius:24px;font-weight:700;cursor:pointer} .btn-send:hover{background:#1a8cd8;} .btn-send:disabled{opacity:0.5;cursor:not-allowed;}
        
        ::-webkit-scrollbar { width: 6px; }
        ::-webkit-scrollbar-thumb { background: #2f3336; border-radius: 10px; }
    </style>
</head>
<body>
<div class="container">
    <div class="header">
        <button class="back-btn" onclick="window.location.href='user.php?pk=<?=urlencode($targetPk)?>'">←</button>
        <div><div class="header-name">Channel PM</div><div class="header-status">Jalur Grup Aktif</div></div>
    </div>
    <div class="chat-box" id="chatBox"><div style="text-align:center;color:#71767b;margin-top:20px">Sinkronisasi pesan channel...</div></div>
    <div class="composer"><input type="text" id="chatInput" placeholder="Balas privat via grup..." autocomplete="off"><button class="btn-send" id="sendBtn" disabled>Kirim</button></div>
</div>
<script>
let lastMsgCount = -1, myHashedPk = '';
function escapeHtml(t) { let d=document.createElement('div'); d.textContent=t; return d.innerHTML; }
function loadChat() {
    fetch('?ajax=history&pk=<?=urlencode($targetPk)?>').then(r=>r.json()).then(d=>{
        myHashedPk = d.myHashedPk;
        if(d.messages && d.messages.length !== lastMsgCount) {
            const box = document.getElementById('chatBox'); box.innerHTML = '';
            if(d.messages.length === 0) box.innerHTML = '<div style="text-align:center;color:#71767b;margin-top:20px;font-size:13px">Belum ada obrolan PM di channel ini.</div>';
            else {
                d.messages.forEach(m => {
                    let div = document.createElement('div');
                    let isMe = (m.hashedPk === myHashedPk || m.from === myHashedPk);
                    div.className = 'chat-bubble ' + (isMe ? 'chat-right' : 'chat-left');
                    div.innerHTML = escapeHtml(m.text || m.message || '');
                    box.appendChild(div);
                });
                box.scrollTop = box.scrollHeight;
            }
            lastMsgCount = d.messages.length;
        }
    });
}
document.getElementById('chatInput').addEventListener('input', function(){ document.getElementById('sendBtn').disabled = !this.value.trim(); });
document.getElementById('chatInput').addEventListener('keypress', function(e){ if(e.key === 'Enter') document.getElementById('sendBtn').click(); });
document.getElementById('sendBtn').addEventListener('click', function(){
    let input = document.getElementById('chatInput'), msg = input.value.trim(); if(!msg) return;
    this.disabled = true; input.value = '';
    let fd = new FormData(); fd.append('message', msg);
    fetch('?ajax=send&pk=<?=urlencode($targetPk)?>', {method:'POST', body:fd}).then(()=>loadChat());
});
setInterval(loadChat, 4000); loadChat();
</script>
</body>
</html>