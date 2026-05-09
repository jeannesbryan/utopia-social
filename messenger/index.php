<?php
require_once '../config.php';

if (!isset($_SESSION['token'])) {
    header('Location: ../index.php');
    exit;
}

// ==========================================
// 🚀 BACKEND AJAX API HANDLER (MESSENGER FINAL)
// ==========================================
if (isset($_GET['ajax'])) {
    header('Content-Type: application/json');
    
    if ($_GET['ajax'] === 'contacts') {
        $res = callUtopiaAPI('getContacts', ['filter' => '']);
        echo json_encode($res); exit;
    }
    
    if ($_GET['ajax'] === 'avatar' && isset($_GET['pk'])) {
        $res = callUtopiaAPI('getContactAvatar', ['pk' => $_GET['pk'], 'coder' => 'BASE64', 'format' => 'JPG']);
        echo json_encode($res); exit;
    }
    
    if ($_GET['ajax'] === 'messages' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $pk = trim($_POST['pk'] ?? '');
        $res = callUtopiaAPI('getContactMessages', ['pk' => $pk]);
        echo json_encode($res); exit;
    }
    
    if ($_GET['ajax'] === 'send' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $res = callUtopiaAPI('sendInstantMessage', ['to' => trim($_POST['to'] ?? ''), 'text' => trim($_POST['text'] ?? '')]);
        echo json_encode($res); exit;
    }

    if ($_GET['ajax'] === 'reply' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $res = callUtopiaAPI('sendInstantQuote', [
            'to' => trim($_POST['to'] ?? ''), 
            'text' => trim($_POST['text'] ?? ''),
            'id_message' => trim($_POST['id_message'] ?? '')
        ]);
        echo json_encode($res); exit;
    }

    if ($_GET['ajax'] === 'clear_chat' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $res = callUtopiaAPI('removeInstantMessages', ['hex_contact_public_key' => trim($_POST['pk'] ?? '')]);
        echo json_encode($res); exit;
    }
    
    if ($_GET['ajax'] === 'buzz' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $res = callUtopiaAPI('sendInstantBuzz', ['to' => trim($_POST['to'] ?? ''), 'comments' => 'BUZZ!']);
        echo json_encode($res); exit;
    }

    if ($_GET['ajax'] === 'send_file' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $to = trim($_POST['to'] ?? '');
        $fileName = trim($_POST['fileName'] ?? 'document.file');
        $fileData = $_POST['fileDataBase64'] ?? '';
        
        if (strpos($fileData, ',') !== false) {
            $fileData = explode(',', $fileData)[1];
        }

        $uploadRes = callUtopiaAPI('uploadFile', ['fileDataBase64' => $fileData, 'fileName' => $fileName]);

        if (isset($uploadRes['result']) && !empty($uploadRes['result'])) {
            $fileId = $uploadRes['result'];
            $sendRes = callUtopiaAPI('sendFileByMessage', ['to' => $to, 'fileId' => (string)$fileId]);
            echo json_encode($sendRes); exit;
        } else {
            echo json_encode(['error' => 'Gagal menitipkan file.']); exit;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>uChat - Private Messenger Premium</title>
    <link rel="icon" type="image/svg+xml" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'%3E%3Crect width='100' height='100' fill='%2300ff41'/%3E%3Ctext x='50' y='50' font-size='45' font-weight='bold' fill='%23000000' text-anchor='middle' dominant-baseline='central' font-family='Arial, sans-serif'%3EU%3C/text%3E%3C/svg%3E">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Arial, sans-serif; background: #000; color: #e7e9ea; height: 100vh; display: flex; flex-direction: column; overflow: hidden; }
        .header { background: rgba(0,0,0,0.85); backdrop-filter: blur(12px); border-bottom: 1px solid #2f3336; padding: 12px 30px; display: flex; justify-content: space-between; align-items: center; z-index: 100; flex-shrink: 0; }
        .header-logo { display: flex; align-items: center; gap: 12px; font-size: 20px; font-weight: 800; }
        .header-logo svg { width: 32px; height: 32px; }
        .btn-back { padding: 8px 16px; background: transparent; color: #e7e9ea; border: 1px solid #2f3336; border-radius: 20px; text-decoration: none; font-size: 14px; font-weight: 700; transition: 0.2s; }
        .btn-back:hover { background: rgba(255,255,255,0.1); }

        .chat-container { display: flex; flex: 1; overflow: hidden; max-width: 1400px; margin: 0 auto; width: 100%; border-left: 1px solid #2f3336; border-right: 1px solid #2f3336; }
        
        .sidebar { width: 350px; background: #16181c; border-right: 1px solid #2f3336; display: flex; flex-direction: column; }
        .sidebar-header { padding: 16px; font-weight: 800; font-size: 18px; border-bottom: 1px solid #2f3336; background: #000; }
        .contact-list { flex: 1; overflow-y: auto; }
        .contact-item { padding: 12px 16px; border-bottom: 1px solid #2f3336; cursor: pointer; transition: 0.2s; display: flex; align-items: center; gap: 12px; }
        .contact-item:hover { background: rgba(0, 255, 65, 0.05); }
        .contact-item.active { background: rgba(0, 255, 65, 0.1); border-left: 4px solid #00ff41; }
        
        .avatar { width: 48px; height: 48px; border-radius: 50%; background: #2f3336; overflow: hidden; flex-shrink: 0; border: 2px solid #2f3336; }
        .avatar img { width: 100%; height: 100%; object-fit: cover; }
        .contact-info { flex: 1; min-width: 0; }
        .contact-nick { font-weight: 700; font-size: 15px; color: #e7e9ea; margin-bottom: 2px; }
        .contact-pk { font-size: 11px; color: #71767b; font-family: monospace; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }

        .chat-area { flex: 1; display: flex; flex-direction: column; background: #000; position: relative; }
        .chat-header { padding: 12px 20px; background: #16181c; border-bottom: 1px solid #2f3336; display: flex; align-items: center; gap: 15px; }
        .chat-header .avatar { width: 40px; height: 40px; }
        .chat-title-box { flex: 1; }
        .chat-title { font-size: 16px; font-weight: 800; }
        
        .btn-clear { background: rgba(244,33,46,0.1); color: #f4212e; border: 1px solid #f4212e; padding: 6px 16px; border-radius: 20px; font-weight: 700; cursor: pointer; transition: 0.2s; }
        .btn-clear:hover { background: rgba(244,33,46,0.2); transform: scale(1.05); }
        .btn-buzz { background: #ffd400; color: #000; border: none; padding: 6px 16px; border-radius: 20px; font-weight: 800; cursor: pointer; transition: 0.2s; }
        .btn-buzz:hover { transform: scale(1.05); }
        
        .message-list { flex: 1; overflow-y: auto; padding: 20px; display: flex; flex-direction: column; gap: 12px; }
        .msg-row { display: flex; width: 100%; align-items: flex-end; gap: 8px; }
        .msg-row.sent { justify-content: flex-end; }
        .msg-row.received { justify-content: flex-start; }
        
        .bubble { position: relative; max-width: 80%; padding: 10px 14px; border-radius: 16px; font-size: 14.5px; line-height: 1.4; word-break: break-word;}
        .msg-row.sent .bubble { background: #00ff41; color: #000; border-bottom-right-radius: 4px; }
        .msg-row.received .bubble { background: #2f3336; color: #e7e9ea; border-bottom-left-radius: 4px; }
        
        .raw-dump { background: rgba(0,0,0,0.5); padding: 10px; border-radius: 8px; font-family: 'Courier New', monospace; font-size: 11px; white-space: pre-wrap; margin-top: 5px; border: 1px solid rgba(255,255,255,0.1); color: #00ff41; }
        
        .quote-box { background: rgba(0,0,0,0.25); border-left: 3px solid rgba(255,255,255,0.4); padding: 8px 12px; margin-bottom: 8px; border-radius: 4px; font-size: 13px; opacity: 0.9; }
        .msg-row.sent .quote-box { border-left-color: #000; background: rgba(0,0,0,0.15); }
        .quote-nick { font-weight: bold; margin-bottom: 4px; }
        .msg-row.sent .quote-nick { color: #000; }
        .msg-row.received .quote-nick { color: #00ff41; }
        .quote-text { font-style: italic; display: -webkit-box; -webkit-line-clamp: 3; -webkit-box-orient: vertical; overflow: hidden; }

        .msg-actions { display: none; cursor: pointer; font-size: 12px; margin-top: 6px; font-weight: bold; opacity: 0.7; }
        .bubble:hover .msg-actions { display: block; }

        .reply-banner { display: none; background: #2f3336; padding: 10px 20px; border-top: 1px solid #000; border-bottom: 1px solid #000; align-items: center; justify-content: space-between; font-size: 13px; }
        .reply-text-snippet { color: #e7e9ea; font-style: italic; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 90%; border-left: 3px solid #00ff41; padding-left: 8px; }
        .btn-cancel-reply { background: transparent; border: none; color: #f4212e; cursor: pointer; font-weight: bold; font-size: 16px; }

        .input-area { padding: 16px 20px; background: #16181c; display: flex; gap: 10px; align-items: center; }
        .btn-attach { background: #2f3336; color: #fff; border: none; width: 42px; height: 42px; border-radius: 50%; font-size: 18px; cursor: pointer; display: flex; justify-content: center; align-items: center; transition: 0.2s; }
        .btn-attach:hover { background: #71767b; }
        .input-text { flex: 1; background: #000; border: 1px solid #2f3336; padding: 12px 20px; border-radius: 24px; color: #e7e9ea; outline: none; }
        .btn-send { background: #00ff41; color: #000; border: none; padding: 12px 24px; border-radius: 24px; font-weight: 800; cursor: pointer; }

        .empty-chat { flex:1; display:flex; align-items:center; justify-content:center; color:#71767b; flex-direction: column; gap:10px; }
        ::-webkit-scrollbar { width: 6px; }
        ::-webkit-scrollbar-thumb { background: #2f3336; border-radius: 10px; }
    </style>
</head>
<body>

<div class="header">
    <div class="header-logo">
        <svg viewBox="0 0 100 100" xmlns="http://www.w3.org/2000/svg"><rect width="100" height="100" rx="20" fill="#00ff41"/><path d="M30 40 L50 60 L70 40" stroke="#000" stroke-width="8" fill="none" stroke-linecap="round"/></svg>
        uChat Premium
    </div>
    <a href="../dashboard.php" class="btn-back">← Back</a>
</div>

<div class="chat-container">
    <div class="sidebar">
        <div class="sidebar-header">Contacts</div>
        <div class="contact-list" id="contactList"></div>
    </div>
    <div class="chat-area" id="chatArea">
        <div class="empty-chat">Pilih kontak untuk mulai mengobrol.</div>
    </div>
</div>

<script>
let activePk = '';
let chatInterval = null;
let avatarCache = {}; 
let currentReplyId = null;
let lastActivity = {}; 
let rawContacts = []; 

async function getAvatar(pk) {
    if(avatarCache[pk]) return avatarCache[pk];
    try {
        let r = await fetch(`?ajax=avatar&pk=${pk}`);
        let d = await r.json();
        if(d.result) {
            avatarCache[pk] = `data:image/jpeg;base64,${d.result}`;
            return avatarCache[pk];
        }
    } catch(e) {}
    return 'data:image/svg+xml,%3Csvg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="%2371767b"%3E%3Cpath d="M12 12c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm0 2c-2.67 0-8 1.34-8 4v2h16v-2c0-2.66-5.33-4-8-4z"/%3E%3C/svg%3E';
}

async function loadContacts() {
    let r = await fetch('?ajax=contacts');
    let d = await r.json();
    if (d.result) {
        rawContacts = d.result;
        renderContactList();
    }
}

async function renderContactList() {
    let list = document.getElementById('contactList');
    if (!rawContacts.length) return;

    let sorted = [...rawContacts].sort((a, b) => {
        let timeA = lastActivity[a.pk] || 0;
        let timeB = lastActivity[b.pk] || 0;
        return timeB - timeA; 
    });

    list.innerHTML = '';
    for(let c of sorted) {
        if(!c.pk) continue;
        let ava = await getAvatar(c.pk);
        let isActive = (c.pk === activePk) ? 'active' : '';
        list.innerHTML += `<div class="contact-item ${isActive}" onclick="openChat('${c.pk}', '${c.nick}', '${ava}')">
            <div class="avatar"><img src="${ava}"></div>
            <div class="contact-info">
                <div class="contact-nick">${c.nick || 'Unknown'}</div>
                <div class="contact-pk">${c.pk}</div>
            </div>
        </div>`;
    }
}

async function openChat(pk, nick, ava) {
    activePk = pk;
    currentReplyId = null;
    renderContactList();

    document.getElementById('chatArea').innerHTML = `
        <div class="chat-header">
            <div class="avatar"><img src="${ava}"></div>
            <div class="chat-title-box"><div class="chat-title">${nick}</div></div>
            <button class="btn-clear" onclick="clearChat()">🗑️ Clear</button>
            <button class="btn-buzz" onclick="sendBuzz()">🔔 BUZZ</button>
        </div>
        <div class="message-list" id="messageList"></div>
        <div class="reply-banner" id="replyBanner">
            <div class="reply-text-snippet" id="replyTextSnippet">Replying to...</div>
            <button class="btn-cancel-reply" onclick="cancelReply()">✕</button>
        </div>
        <div class="input-area">
            <input type="file" id="attachFile" style="display:none" onchange="uploadAndSendFile(event)">
            <button class="btn-attach" id="btnAttach" onclick="document.getElementById('attachFile').click()" title="Attach File">📎</button>
            <input type="text" id="chatInput" class="input-text" placeholder="Ketik pesan..." autocomplete="off" onkeypress="if(event.key === 'Enter') sendMessage()">
            <button class="btn-send" onclick="sendMessage()">Send</button>
        </div>`;
    
    loadMessages();
    if(chatInterval) clearInterval(chatInterval);
    chatInterval = setInterval(loadMessages, 3000);
}

function prepareReply(msgId, snippet) {
    currentReplyId = msgId;
    document.getElementById('replyBanner').style.display = 'flex';
    document.getElementById('replyTextSnippet').innerText = `Replying to: "${snippet}"`;
    document.getElementById('chatInput').focus();
}

function cancelReply() {
    currentReplyId = null;
    document.getElementById('replyBanner').style.display = 'none';
}

// 🚀 FITUR CLEAR CHAT + TENDANG DARI SIDEBAR
async function clearChat() {
    if(!activePk) return;
    if(!confirm("⚠️ PERINGATAN: Yakin ingin menghapus seluruh riwayat chat & menutup obrolan ini?")) return;
    
    let fd = new FormData(); fd.append('pk', activePk);
    await fetch('?ajax=clear_chat', {method: 'POST', body: fd});
    
    // Tendang dari array list sidebar secara visual
    rawContacts = rawContacts.filter(c => c.pk !== activePk);
    delete lastActivity[activePk];
    
    // Kosongkan state chat yang aktif
    activePk = '';
    
    // Render ulang layar
    renderContactList();
    document.getElementById('chatArea').innerHTML = '<div class="empty-chat">Pilih kontak untuk mulai mengobrol.</div>';
}

async function loadMessages() {
    if(!activePk) return;
    let fd = new FormData(); fd.append('pk', activePk);
    let r = await fetch('?ajax=messages', {method: 'POST', body: fd});
    let d = await r.json();
    let msgBox = document.getElementById('messageList'); if(!msgBox) return;

    let isScrolledToBottom = msgBox.scrollHeight - msgBox.clientHeight <= msgBox.scrollTop + 50;

    if (d.result && d.result.length > 0) {
        let latestMsgTime = new Date(d.result[0].dateTime).getTime();
        if (lastActivity[activePk] !== latestMsgTime) {
            lastActivity[activePk] = latestMsgTime;
            renderContactList(); 
        }

        let sortedMessages = [...d.result].reverse(); 
        let html = '';
        sortedMessages.forEach(m => {
            let isReceived = (m.isIncoming === true || m.from === activePk);
            
            let text = "";
            if (m.text !== undefined && m.text !== null) text = m.text;
            else if (m.body !== undefined && m.body !== null) text = m.body;

            if(text === "" && m.metaData) {
                let metaType = m.metaData.type;
                
                if (metaType === 'quote') {
                    let qData = m.metaData.data || {};
                    let qNick = qData.nick || 'Unknown';
                    let qQuote = qData.quote || '';
                    let qText = qData.text || '';
                    
                    text = `
                        <div class="quote-box">
                            <div class="quote-nick">${qNick}</div>
                            <div class="quote-text">${qQuote.replace(/<[^>]*>?/gm, '')}</div>
                        </div>
                        <div class="reply-text">${qText}</div>
                    `;
                }
                else if (metaType === 'authcmd') {
                    html += `<div style="display:flex; flex-direction:column; align-items:center; width:100%; margin: 8px 0; gap:4px;">
                        <div style="background: rgba(255,255,255,0.05); color: #71767b; padding: 4px 12px; border-radius: 12px; font-size: 11px;">You have requested an authorization</div>
                        <div style="background: rgba(0,255,65,0.05); color: #00ff41; padding: 4px 12px; border-radius: 12px; font-size: 10px; border:1px solid rgba(0,255,65,0.1);">The authorization request is approved automatically</div>
                    </div>`;
                    return; 
                } 
                else if (metaType === 'friend') {
                    html += `<div style="display:flex; justify-content:center; width:100%; margin: 8px 0;"><div style="background: rgba(0,255,65,0.1); color: #00ff41; padding: 4px 12px; border-radius: 12px; font-size: 11px;">🤝 Contact Authorization Updated</div></div>`;
                    return;
                }
                else {
                    let rawJson = JSON.stringify(m, null, 2);
                    text = `<div class="raw-dump"><b>[SYSTEM METADATA]</b><br>${rawJson}</div>`;
                }
            }

            if (text === "") text = '<em style="color:#71767b; font-size:12px;">[Format pesan tidak terbaca]</em>';

            let safeSnippetText = (m.metaData && m.metaData.type === 'quote') ? m.metaData.data.text : text;
            let safeText = safeSnippetText.replace(/<[^>]*>?/gm, '').replace(/'/g, "\\'").replace(/"/g, "&quot;");
            
            // 🚀 TOMBOL REPLY (Tombol Inspect & Delete sudah dihilangkan)
            let replyBtn = `<div class="msg-actions" onclick="prepareReply('${m.id}', '${safeText}')">↩️ Reply</div>`;
            let actionButtons = `<div style="display:flex; gap:10px; justify-content:${isReceived ? 'flex-start' : 'flex-end'};">` + replyBtn + `</div>`;

            if (m.file && m.file !== null) {
                let fileName = m.file.name || m.file.fileName || 'Encrypted File';
                let fileSize = m.file.size ? Math.round(m.file.size / 1024) + ' KB' : '';
                text = `<div style="display:flex; align-items:center; gap:10px; background:rgba(0,0,0,0.2); padding:8px; border-radius:8px;"><div style="font-size:24px;">📁</div><div><div style="font-weight:bold; font-size:13px;">${fileName}</div><div style="font-size:11px; opacity:0.7;">${fileSize}</div></div></div>` + text;
                safeText = "File: " + fileName;
                replyBtn = `<div class="msg-actions" onclick="prepareReply('${m.id}', '${safeText}')">↩️ Reply</div>`;
                actionButtons = `<div style="display:flex; gap:10px; justify-content:${isReceived ? 'flex-start' : 'flex-end'};">` + replyBtn + `</div>`;
            }
            
            html += `<div class="msg-row ${isReceived ? 'received' : 'sent'}"><div class="bubble">${text}${actionButtons}</div></div>`;
        });
        msgBox.innerHTML = html;
        if(isScrolledToBottom) msgBox.scrollTop = msgBox.scrollHeight;
    } else {
        msgBox.innerHTML = '<div style="text-align:center; color:#71767b; margin-top:20px;">Belum ada pesan. Sapa teman Anda!</div>';
    }
}

async function sendMessage() {
    let input = document.getElementById('chatInput'); let text = input.value.trim(); if(!text) return;
    input.value = ''; 
    let fd = new FormData(); fd.append('to', activePk); fd.append('text', text);
    
    lastActivity[activePk] = Date.now();
    renderContactList();

    if (currentReplyId) {
        fd.append('id_message', currentReplyId);
        await fetch('?ajax=reply', {method: 'POST', body: fd});
        cancelReply(); 
    } else {
        await fetch('?ajax=send', {method: 'POST', body: fd});
    }
    loadMessages();
}

async function sendBuzz() {
    let fd = new FormData(); fd.append('to', activePk); await fetch('?ajax=buzz', {method: 'POST', body: fd});
}

function uploadAndSendFile(event) {
    let file = event.target.files[0]; if(!file) return;
    let btn = document.getElementById('btnAttach'); btn.innerHTML = '⏳';
    let reader = new FileReader();
    reader.onload = function(e) {
        let fd = new FormData();
        fd.append('to', activePk); fd.append('fileName', file.name); fd.append('fileDataBase64', e.target.result);
        fetch('?ajax=send_file', {method: 'POST', body: fd}).then(() => {
            btn.innerHTML = '📎'; loadMessages();
        });
    };
    reader.readAsDataURL(file);
}

loadContacts();
</script>
</body>
</html>