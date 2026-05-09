<?php
// 🚀 INTEGRATION WITH MAIN CONFIG
require_once '../config.php';

if (!isset($_SESSION['token'])) {
    header('Location: ../index.php');
    exit;
}

// ==========================================
// 🚀 BACKEND AJAX API HANDLER (WETOPIA MAIL)
// ==========================================
if (isset($_GET['ajax'])) {
    header('Content-Type: application/json');
    
    // 1. Fetch Email List + Auto uNS Resolution
    if ($_GET['ajax'] === 'list') {
        $folder = intval($_GET['folder'] ?? 1);
        $res = callUtopiaAPI('getEmails', ['folderType' => $folder, 'filter' => '']);
        
        if (!isset($res['result']) || !is_array($res['result'])) {
            echo json_encode(['messages' => [], 'pkMap' => []]); exit;
        }

        $emails = $res['result'];
        $pkMap = []; // Dictionary PK -> uNS
        $uniquePks = [];

        // Collect all unique sender PKs
        foreach ($emails as $m) {
            if(!empty($m['from'])) $uniquePks[] = $m['from'];
        }
        $uniquePks = array_unique($uniquePks);

        // 🚀 uNS RESOLUTION PROCESS (Translating PK to Name)
        if (!isset($_SESSION['uns_cache'])) $_SESSION['uns_cache'] = [];
        
        foreach ($uniquePks as $pk) {
            // Check cache first to optimize performance
            if (isset($_SESSION['uns_cache'][$pk])) {
                $pkMap[$pk] = $_SESSION['uns_cache'][$pk];
                continue;
            }

            // Find uNS associated with this PK via API
            $unsSearch = callUtopiaAPI('unsSearchByPk', ['filter' => $pk]);
            
            if (isset($unsSearch['result']) && !empty($unsSearch['result'])) {
                // Get the first found uNS (usually the primary one)
                $unsName = $unsSearch['result'][0]['nick'] ?? $unsSearch['result'][0]['name'] ?? null;
                if ($unsName) {
                    $pkMap[$pk] = $unsName;
                    $_SESSION['uns_cache'][$pk] = $unsName;
                }
            }
            
            // If still not found, store null so we don't query it again this session
            if (!isset($pkMap[$pk])) {
                $_SESSION['uns_cache'][$pk] = null;
            }
        }

        echo json_encode([
            'result' => $emails,
            'pkMap' => $pkMap
        ]); 
        exit;
    }
    
    // 2. Read Email Details
    if ($_GET['ajax'] === 'read' && isset($_GET['id'])) {
        $res = callUtopiaAPI('getEmailById', ['id' => $_GET['id']]);
        echo json_encode($res); exit;
    }
    
    // 3. Send New Email (Supports sending to uNS)
    if ($_GET['ajax'] === 'send' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $toRaw = trim($_POST['to']);
        $to = array_map('trim', explode(',', $toRaw)); // Split if multiple recipients
        $subject = trim($_POST['subject']);
        $body = trim($_POST['body']);
        
        $res = callUtopiaAPI('sendEmailMessage', [
            'to' => $to,
            'subject' => $subject,
            'body' => $body
        ]);
        echo json_encode($res); exit;
    }

    // 4. Delete Email
    if ($_GET['ajax'] === 'delete' && isset($_GET['id'])) {
        $res = callUtopiaAPI('deleteEmail', ['id' => $_GET['id']]);
        echo json_encode($res); exit;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Wetopia Mail - Secure Webmail</title>
    <link rel="icon" type="image/svg+xml" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'%3E%3Crect width='100' height='100' fill='%23ff4b2b'/%3E%3Ctext x='50' y='50' font-size='45' font-weight='bold' fill='%23000' text-anchor='middle' dominant-baseline='central' font-family='Arial'%3EW%3C/text%3E%3C/svg%3E">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Arial, sans-serif; background: #000; color: #e7e9ea; height: 100vh; display: flex; flex-direction: column; overflow: hidden; }
        .header { background: rgba(0,0,0,0.85); backdrop-filter: blur(12px); border-bottom: 1px solid #2f3336; padding: 12px 30px; display: flex; justify-content: space-between; align-items: center; z-index: 100; flex-shrink: 0; }
        .header-logo { display: flex; align-items: center; gap: 12px; font-size: 20px; font-weight: 800; color: #ff4b2b; }
        .btn-back { padding: 8px 16px; background: transparent; color: #e7e9ea; border: 1px solid #2f3336; border-radius: 20px; text-decoration: none; font-size: 14px; font-weight: 700; transition: 0.2s; }
        .btn-back:hover { background: rgba(255,255,255,0.1); }
        .mail-container { display: flex; flex: 1; overflow: hidden; }
        .sidebar { width: 260px; background: #16181c; border-right: 1px solid #2f3336; display: flex; flex-direction: column; padding: 20px 10px; }
        .btn-compose { background: #ff4b2b; color: white; border: none; padding: 15px; border-radius: 30px; font-weight: 800; cursor: pointer; margin-bottom: 25px; font-size: 15px; transition: 0.2s;}
        .btn-compose:hover { background: #e03e23; }
        .nav-item { padding: 12px 20px; border-radius: 25px; cursor: pointer; display: flex; align-items: center; gap: 15px; transition: 0.2s; color: #71767b; font-weight: 600; }
        .nav-item:hover { background: rgba(255,255,255,0.05); color: white; }
        .nav-item.active { background: rgba(255, 75, 43, 0.1); color: #ff4b2b; }
        .mail-list { width: 400px; border-right: 1px solid #2f3336; overflow-y: auto; background: #000; }
        .mail-item { padding: 15px 20px; border-bottom: 1px solid #2f3336; cursor: pointer; transition: 0.2s; }
        .mail-item:hover { background: #16181c; }
        .mail-item.active { border-left: 4px solid #ff4b2b; background: rgba(255,255,255,0.03); }
        .mail-sender { font-weight: 800; font-size: 14px; margin-bottom: 4px; color: #ff4b2b; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .mail-subject { font-weight: 700; font-size: 14px; color: #e7e9ea; margin-bottom: 4px; }
        .mail-preview { font-size: 13px; color: #71767b; }
        .mail-content { flex: 1; display: flex; flex-direction: column; background: #000; overflow-y: auto; }
        .view-subject { font-size: 24px; font-weight: 800; margin-bottom: 20px; padding: 30px 30px 10px 30px; }
        .view-meta { padding: 0 30px 20px 30px; border-bottom: 1px solid #2f3336; display: flex; justify-content: space-between; align-items: center; }
        .view-body { padding: 30px; font-size: 16px; line-height: 1.6; white-space: pre-wrap; color: #e7e9ea; }
        .modal { display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.8); z-index: 1000; justify-content: center; align-items: center; }
        .modal-box { background: #16181c; width: 600px; border-radius: 16px; border: 1px solid #2f3336; }
        .modal-body { padding: 20px; display: flex; flex-direction: column; gap: 10px; }
        .modal-input { background: #000; border: 1px solid #2f3336; padding: 12px; border-radius: 8px; color: white; outline: none; width: 100%; transition: border-color 0.2s;}
        .modal-input:focus { border-color: #ff4b2b; }
        .empty-state { height: 100%; display: flex; align-items: center; justify-content: center; color: #71767b; flex-direction: column; gap: 15px; }
        ::-webkit-scrollbar { width: 6px; }
        ::-webkit-scrollbar-thumb { background: #2f3336; border-radius: 10px; }
    </style>
</head>
<body>

<div class="header">
    <div class="header-logo">
        <svg viewBox="0 0 100 100" xmlns="http://www.w3.org/2000/svg" width="32" height="32">
            <rect width="100" height="100" rx="20" fill="#ff4b2b"/>
            <text x="50" y="50" font-size="45" font-weight="bold" fill="#000" text-anchor="middle" dominant-baseline="central" font-family="Arial">W</text>
        </svg>
        Wetopia Mail
    </div>
    <a href="../dashboard.php" class="btn-back">← Back to Dashboard</a>
</div>

<div class="mail-container">
    <div class="sidebar">
        <button class="btn-compose" onclick="openCompose()">+ Compose Email</button>
        <div class="nav-item active" onclick="switchFolder(1, this)">📥 Inbox</div>
        <div class="nav-item" onclick="switchFolder(2, this)">📤 Sent</div>
        <div class="nav-item" onclick="switchFolder(3, this)">🗑️ Trash</div>
    </div>
    <div class="mail-list" id="mailList"><div class="empty-state">Loading emails...</div></div>
    <div class="mail-content" id="mailContent"><div class="empty-state">✉️ Select a message to read.</div></div>
</div>

<div class="modal" id="composeModal">
    <div class="modal-box">
        <div style="padding:15px; border-bottom:1px solid #2f3336; display:flex; justify-content:space-between; font-weight:800;">
            Compose New Message 
            <button onclick="closeCompose()" style="background:none; border:none; color:#71767b; cursor:pointer; font-size: 20px;">&times;</button>
        </div>
        <div class="modal-body">
            <input type="text" id="mailTo" class="modal-input" placeholder="To (Public Key or uNS)">
            <input type="text" id="mailSubject" class="modal-input" placeholder="Subject">
            <textarea id="mailBody" class="modal-input" placeholder="Write your message here..." style="height:250px; resize:none;"></textarea>
            <button class="btn-compose" id="sendBtn" onclick="sendMail()" style="margin:0">Send Email</button>
        </div>
    </div>
</div>

<script>
let currentFolder = 1, pkMap = {};

async function switchFolder(f, el) {
    currentFolder = f;
    document.querySelectorAll('.nav-item').forEach(i => i.classList.remove('active'));
    el.classList.add('active');
    loadMails();
}

async function loadMails() {
    let list = document.getElementById('mailList');
    list.innerHTML = '<div class="empty-state">Loading emails...</div>';
    
    let r = await fetch(`?ajax=list&folder=${currentFolder}`);
    let d = await r.json();
    
    if (d.result && d.result.length > 0) {
        list.innerHTML = '';
        pkMap = d.pkMap || {}; // Store uNS dictionary from server
        
        d.result.reverse().forEach(m => {
            // 🚀 uNS DETECTION: Use uNS name if exists, otherwise slice the PK
            let senderDisplay = pkMap[m.from] ? pkMap[m.from] : (m.from.substring(0,12) + "...");
            
            list.innerHTML += `
                <div class="mail-item" onclick="readMail('${m.id}', this)">
                    <div class="mail-sender">${senderDisplay}</div>
                    <div class="mail-subject">${m.subject || '(No Subject)'}</div>
                    <div class="mail-preview">${new Date(m.dateTime).toLocaleDateString()}</div>
                </div>`;
        });
    } else { list.innerHTML = '<div class="empty-state">This folder is empty.</div>'; }
}

async function readMail(id, el) {
    document.querySelectorAll('.mail-item').forEach(i => i.classList.remove('active')); el.classList.add('active');
    document.getElementById('mailContent').innerHTML = '<div class="empty-state">Opening message...</div>';
    let r = await fetch(`?ajax=read&id=${id}`);
    let d = await r.json();
    if (d.result) {
        let m = d.result;
        let senderDisplay = pkMap[m.from] ? `<b>${pkMap[m.from]}</b> (${m.from.substring(0,10)}...)` : m.from;
        document.getElementById('mailContent').innerHTML = `
            <div class="view-subject">${m.subject || '(No Subject)'}</div>
            <div class="view-meta">
                <div>From: ${senderDisplay}</div>
                <button style="color:#f4212e; background:none; border:1px solid #f4212e; padding:5px 15px; border-radius:15px; cursor:pointer; font-weight:bold; transition: 0.2s;" onmouseover="this.style.background='rgba(244,33,46,0.1)'" onmouseout="this.style.background='none'" onclick="deleteMail('${m.id}')">🗑️ Delete</button>
            </div>
            <div class="view-body">${m.body}</div>`;
    }
}

function openCompose() { document.getElementById('composeModal').style.display = 'flex'; }
function closeCompose() { document.getElementById('composeModal').style.display = 'none'; }

async function sendMail() {
    let to = document.getElementById('mailTo').value, sub = document.getElementById('mailSubject').value, body = document.getElementById('mailBody').value;
    if(!to || !body) return alert('Recipient and Message Body are required!');
    let btn = document.getElementById('sendBtn'); btn.innerText = 'Sending...'; btn.disabled = true;
    let fd = new FormData(); fd.append('to', to); fd.append('subject', sub); fd.append('body', body);
    
    try {
        let r = await fetch('?ajax=send', {method: 'POST', body: fd});
        let d = await r.json();
        if(d.result) { 
            alert('Email sent successfully!'); 
            closeCompose(); 
            document.getElementById('mailTo').value = '';
            document.getElementById('mailSubject').value = '';
            document.getElementById('mailBody').value = '';
            loadMails(); 
        } else { 
            alert('Failed to send email. Check recipient public key or network.'); 
        }
    } catch (e) {
        alert('Network Error!');
    }
    btn.innerText = 'Send Email'; btn.disabled = false;
}

async function deleteMail(id) {
    if(!confirm("Are you sure you want to delete this message?")) return;
    await fetch(`?ajax=delete&id=${id}`);
    loadMails(); document.getElementById('mailContent').innerHTML = '<div class="empty-state">✉️ Select a message to read.</div>';
}

loadMails();
</script>
</body>
</html>