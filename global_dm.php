<?php
session_start();
if (!isset($_SESSION['token'])) { header('Location: index.php'); exit; }
define('UTOPIA_API_URL', 'http://127.0.0.1:20000/api/1.0');
define('CHANNEL_ID', '2F5F675D31CA664E102AFDF061516AE3');

$targetPk = $_GET['pk'] ?? '';
if(empty($targetPk)) { header('Location: index.php'); exit; }

function callUtopiaAPI($method, $params = []) {
    $data = ['method' => $method, 'token' => $_SESSION['token'], 'params' => $params];
    $ch = curl_init(UTOPIA_API_URL); 
    curl_setopt_array($ch, [CURLOPT_POST=>true, CURLOPT_POSTFIELDS=>json_encode($data), CURLOPT_RETURNTRANSFER=>true, CURLOPT_HTTPHEADER=>['Content-Type: application/json'], CURLOPT_TIMEOUT=>10]);
    $res = curl_exec($ch); curl_close($ch);
    return json_decode($res, true, 512, JSON_BIGINT_AS_STRING) ?: ['error' => 'Invalid JSON'];
}

// --- SUPER RESOLVER: Mencari Full PK (64 Char) ---
$tFullPk = (strlen($targetPk) === 64) ? $targetPk : '';

if (empty($tFullPk)) {
    // 1. Coba cari di Daftar Kontak Global (Tempat paling pasti jika sudah pernah interaksi)
    $gContacts = callUtopiaAPI('getContacts');
    if (isset($gContacts['result'])) {
        foreach ($gContacts['result'] as $c) {
            if (($c['hashedPk'] ?? '') === $targetPk && !empty($c['pk'])) { $tFullPk = $c['pk']; break; }
        }
    }
    
    // 2. Coba cari di Daftar Member Channel
    if (empty($tFullPk)) {
        $cContacts = callUtopiaAPI('getChannelContacts', ['channelid' => CHANNEL_ID]);
        if (isset($cContacts['result'])) {
            foreach ($cContacts['result'] as $c) {
                if (($c['hashedPk'] ?? '') === $targetPk && !empty($c['pk'])) { $tFullPk = $c['pk']; break; }
            }
        }
    }
}

if (isset($_GET['ajax'])) {
    header('Content-Type: application/json');
    if ($_GET['ajax'] === 'send' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $msg = trim($_POST['message']); if (empty($msg) || empty($tFullPk)) { echo json_encode(['success'=>false, 'error'=>'PK tidak valid.']); exit; }
        callUtopiaAPI('addContact', ['pk' => $tFullPk]); // Bypass filter
        $res = callUtopiaAPI('sendInstantMessage', ['to' => $tFullPk, 'text' => $msg]);
        echo json_encode(['success' => (isset($res['result']) && $res['result'] != "0")]); exit;
    }
    if ($_GET['ajax'] === 'history') {
        $history = [];
        if (!empty($tFullPk)) { $r = callUtopiaAPI('getContactMessages', ['pk' => $tFullPk]); if (isset($r['result'])) $history = $r['result']; }
        usort($history, function($a, $b) { return strtotime($a['dateTime']??0) - strtotime($b['dateTime']??0); });
        echo json_encode(['messages' => $history, 'myPk' => $_SESSION['pk'] ?? '']); exit;
    }
}
?>
<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>Global DM</title>
<style>
*{margin:0;padding:0;box-sizing:border-box} body{font-family:-apple-system,sans-serif;background:#000;color:#e7e9ea;height:100vh;display:flex;justify-content:center}
.container{width:100%;max-width:600px;display:flex;flex-direction:column;border-left:1px solid #2f3336;border-right:1px solid #2f3336;height:100%}
.header{background:rgba(0,0,0,0.85);backdrop-filter:blur(12px);border-bottom:1px solid #2f3336;padding:16px;display:flex;align-items:center;gap:16px}
.chat-box{flex:1;overflow-y:auto;padding:16px;display:flex;flex-direction:column;gap:12px}
.chat-bubble{max-width:85%;padding:10px 16px;border-radius:18px;font-size:15px;word-wrap:break-word;white-space:pre-wrap}
.chat-left{align-self:flex-start;background:#26292c;color:#e7e9ea;border-bottom-left-radius:4px}
.chat-right{align-self:flex-end;background:#1d9bf0;color:#fff;border-bottom-right-radius:4px}
.composer{border-top:1px solid #2f3336;padding:16px;display:flex;gap:12px;background:#000}
.composer input{flex:1;padding:12px 18px;background:#16181c;border:1px solid #2f3336;border-radius:24px;color:#e7e9ea;outline:none}
.btn-send{padding:0 20px;background:#1d9bf0;color:#fff;border:none;border-radius:24px;font-weight:700;cursor:pointer}
</style></head><body>
<div class="container">
    <div class="header"><button onclick="history.back()" style="background:none;border:none;color:#fff;cursor:pointer;font-size:20px">←</button><div><div style="font-weight:700">Global DM</div><div style="font-size:12px;color:#1d9bf0">Encrypted Mode</div></div></div>
    <div class="chat-box" id="chatBox"></div>
    <div class="composer"><input type="text" id="chatInput" placeholder="Kirim pesan..."><button class="btn-send" id="sendBtn">Send</button></div>
</div>
<script>
let lastCount = -1, isSending = false;
function load(){
    if(isSending) return;
    fetch('?ajax=history&pk=<?=urlencode($targetPk)?>').then(r=>r.json()).then(d=>{
        if(d.messages && d.messages.length !== lastCount){
            const box = document.getElementById('chatBox'); box.innerHTML = '';
            d.messages.forEach(m=>{
                let div = document.createElement('div');
                div.className = 'chat-bubble ' + ((m.pk === d.myPk || m.from === d.myPk) ? 'chat-right' : 'chat-left');
                div.textContent = m.text || m.message; box.appendChild(div);
            });
            box.scrollTop = box.scrollHeight; lastCount = d.messages.length;
        }
    });
}
document.getElementById('sendBtn').onclick=()=>{
    let msg = document.getElementById('chatInput').value.trim(); if(!msg) return;
    isSending = true; document.getElementById('chatInput').value = '';
    let fd = new FormData(); fd.append('message', msg);
    fetch('?ajax=send&pk=<?=urlencode($targetPk)?>',{method:'POST',body:fd}).then(r=>r.json()).then(d=>{
        if(!d.success) alert("Gagal kirim. Pastikan PK benar.");
        isSending = false; load();
    });
};
setInterval(load, 4000); load();
</script></body></html>