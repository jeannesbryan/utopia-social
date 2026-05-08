<?php
require_once '../config.php';

if (!isset($_SESSION['token'])) {
    header('Location: ../index.php');
    exit;
}

if (isset($_GET['ajax'])) {
    header('Content-Type: application/json');
    
    if ($_GET['ajax'] === 'my_uns') {
        $res = callUtopiaAPI('unsSearchByPk', ['filter' => $_SESSION['pk']]);
        echo json_encode($res); exit;
    }
    
    if ($_GET['ajax'] === 'register' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $nick = trim($_POST['nick'] ?? '');
        $channelId = trim($_POST['channelId'] ?? '');
        $isPrimary = (isset($_POST['isPrimary']) && $_POST['isPrimary'] === 'true');
        
        $params = ['nick' => $nick, 'isPrimary' => $isPrimary];
        if (!empty($channelId)) $params['channelId'] = $channelId;
        
        $res = callUtopiaAPI('unsCreateRecordRequest', $params);
        echo json_encode($res); exit;
    }
    
    // 🚀 SMART SEARCH BACKEND: Otomatis deteksi uNS Name atau 64-char PK!
    if ($_GET['ajax'] === 'search' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $query = trim($_POST['query'] ?? '');
        // Cek apakah query adalah 64 karakter hexadecimal (Public Key)
        if (strlen($query) === 64 && ctype_xdigit($query)) {
            $res = callUtopiaAPI('unsSearchByPk', ['filter' => $query]);
        } else {
            $res = callUtopiaAPI('unsSearchByNick', ['filter' => $query]);
        }
        echo json_encode($res); exit;
    }

    if ($_GET['ajax'] === 'search_by_pk' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $res = callUtopiaAPI('unsSearchByPk', ['filter' => trim($_POST['pk'] ?? '')]);
        echo json_encode($res); exit;
    }
    
    if ($_GET['ajax'] === 'history' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $res = callUtopiaAPI('unsHistoryName', ['name' => trim($_POST['name'] ?? '')]);
        echo json_encode($res); exit;
    }
    
    if ($_GET['ajax'] === 'transfer' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $res = callUtopiaAPI('requestUnsTransfer', ['name' => trim($_POST['name'] ?? ''), 'hexNewOwnerPk' => trim($_POST['pk'] ?? '')]);
        echo json_encode($res); exit;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>uNS Manager - Utopia Web</title>
    <link rel="icon" type="image/svg+xml" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'%3E%3Crect width='100' height='100' fill='%2300ff41'/%3E%3Ctext x='50' y='50' font-size='45' font-weight='bold' fill='%23000000' text-anchor='middle' dominant-baseline='central' font-family='Arial, sans-serif'%3EU%3C/text%3E%3C/svg%3E">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Arial, sans-serif; background: #000; color: #e7e9ea; min-height: 100vh; display: flex; flex-direction: column; }
        .header { background: rgba(0,0,0,0.85); backdrop-filter: blur(12px); border-bottom: 1px solid #2f3336; padding: 16px 30px; display: flex; justify-content: space-between; align-items: center; position: sticky; top: 0; z-index: 100; }
        .header-logo { display: flex; align-items: center; gap: 12px; font-size: 20px; font-weight: 800; }
        .header-logo svg { width: 32px; height: 32px; }
        .btn-back { padding: 8px 16px; background: transparent; color: #e7e9ea; border: 1px solid #2f3336; border-radius: 20px; text-decoration: none; font-size: 14px; font-weight: 700; transition: 0.2s; }
        .btn-back:hover { background: rgba(255,255,255,0.1); }
        .main-container { flex: 1; max-width: 1200px; margin: 0 auto; width: 100%; display: flex; gap: 24px; padding: 30px 20px; }
        .sidebar { width: 250px; flex-shrink: 0; display: flex; flex-direction: column; gap: 8px; }
        .tab-btn { padding: 14px 20px; background: transparent; color: #71767b; border: 1px solid transparent; border-radius: 12px; text-align: left; font-size: 16px; font-weight: 700; cursor: pointer; transition: 0.2s; }
        .tab-btn:hover { background: #16181c; color: #e7e9ea; }
        .tab-btn.active { background: rgba(0, 255, 65, 0.1); color: #00ff41; border-color: #00ff41; }
        .content-area { flex: 1; background: #16181c; border: 1px solid #2f3336; border-radius: 16px; padding: 30px; min-height: 500px; }
        .tab-content { display: none; }
        .tab-content.active { display: block; animation: fadeIn 0.3s ease; }
        @keyframes fadeIn { from { opacity: 0; transform: translateY(5px); } to { opacity: 1; transform: translateY(0); } }
        .section-title { font-size: 24px; font-weight: 800; margin-bottom: 8px; }
        .section-desc { font-size: 15px; color: #71767b; margin-bottom: 24px; line-height: 1.5; }
        .form-group { margin-bottom: 20px; }
        label { display: block; margin-bottom: 8px; font-size: 14px; font-weight: 600; color: #71767b; }
        input[type="text"] { width: 100%; padding: 12px; background: #000; border: 1px solid #2f3336; border-radius: 8px; color: #e7e9ea; font-size: 15px; font-family: monospace; outline: none; transition: 0.2s; }
        input[type="text"]:focus { border-color: #00ff41; }
        .btn { padding: 12px 24px; background: #00ff41; color: #000; border: none; border-radius: 24px; font-size: 15px; font-weight: 700; cursor: pointer; transition: 0.2s; }
        .btn:hover { background: #00cc34; }
        table { width: 100%; border-collapse: collapse; margin-top: 20px; }
        th, td { text-align: left; padding: 14px; border-bottom: 1px solid #2f3336; font-size: 15px; }
        th { color: #71767b; font-weight: 600; text-transform: uppercase; font-size: 12px; letter-spacing: 1px; }
        .highlight { color: #00ff41; font-weight: 700; }
        .empty-state { text-align: center; padding: 40px; color: #71767b; font-style: italic; }
        .alert-box { padding: 12px; border-radius: 8px; margin-bottom: 20px; display: none; font-size: 14px; }
        .alert-success { background: rgba(0,255,65,0.1); border: 1px solid #00ff41; color: #00ff41; }
        .alert-error { background: rgba(244,33,46,0.1); border: 1px solid #f4212e; color: #f4212e; }
        
        .checkbox-container { display: flex; align-items: center; gap: 10px; margin-bottom: 20px; cursor: pointer; }
        .checkbox-container input[type="checkbox"] { width: 20px; height: 20px; cursor: pointer; accent-color: #00ff41; }
        .checkbox-container label { margin-bottom: 0; cursor: pointer; font-size: 15px; color: #e7e9ea; }
        
        .domain-badge { display: inline-block; background: rgba(0,255,65,0.1); border: 1px solid #00ff41; color: #00ff41; padding: 4px 10px; border-radius: 12px; margin: 4px 6px 4px 0; font-size: 12px; font-weight: 600; letter-spacing: 0.5px; }
    </style>
</head>
<body>

<div class="header">
    <div class="header-logo">
        <svg viewBox="0 0 100 100" xmlns="http://www.w3.org/2000/svg">
            <rect width="100" height="100" rx="20" fill="#00ff41"/>
            <text x="50" y="50" font-size="45" font-weight="bold" fill="#000" text-anchor="middle" dominant-baseline="central" font-family="Arial">U</text>
        </svg>
        uNS Manager
    </div>
    <a href="../dashboard.php" class="btn-back">← Back to Dashboard</a>
</div>

<div class="main-container">
    <div class="sidebar">
        <button class="tab-btn active" onclick="openTab('my_domains')">🗂️ My Domains</button>
        <button class="tab-btn" onclick="openTab('register')">➕ Register uNS</button>
        <button class="tab-btn" onclick="openTab('explorer')">🔍 uNS Explorer</button>
        <button class="tab-btn" onclick="openTab('transfer')">🤝 Transfer Center</button>
    </div>

    <div class="content-area">
        <div id="tab-my_domains" class="tab-content active">
            <h2 class="section-title">My Web3 Domains</h2>
            <div class="section-desc">Daftar identitas uNS yang terdaftar di Public Key Anda.</div>
            <button class="btn" onclick="loadMyUns()" style="padding: 8px 16px; font-size: 13px;">🔄 Refresh List</button>
            <table id="myUnsTable">
                <thead><tr><th>uNS Name</th><th>Registered Date</th><th>Status</th></tr></thead>
                <tbody id="myUnsBody"><tr><td colspan="3" class="empty-state">Memuat data domain Anda...</td></tr></tbody>
            </table>
        </div>

        <div id="tab-register" class="tab-content">
            <h2 class="section-title">Register New uNS</h2>
            <div class="section-desc">Klaim identitas Anda di jaringan desentralisasi Utopia. Masukkan nama yang unik!</div>
            <div id="regAlert" class="alert-box"></div>
            
            <div class="form-group">
                <label>Desired uNS Name</label>
                <input type="text" id="regName" placeholder="e.g. jomokerto">
            </div>
            <div class="form-group">
                <label>Route to Channel ID (Opsional)</label>
                <input type="text" id="regChannel" placeholder="Biarkan kosong untuk profil pribadi">
            </div>
            
            <div class="checkbox-container">
                <input type="checkbox" id="regPrimary">
                <label for="regPrimary">Associate this name as Primary uNS</label>
            </div>

            <button class="btn" id="btnReg" onclick="registerUns()">Register Name</button>
        </div>

        <div id="tab-explorer" class="tab-content">
            <h2 class="section-title">uNS Explorer</h2>
            <div class="section-desc">Lacak kepemilikan dan sejarah dari sebuah nama uNS atau PK beserta portofolionya.</div>
            <div class="form-group" style="display: flex; gap: 12px;">
                <input type="text" id="searchQuery" placeholder="Ketik nama uNS atau 64-karakter PK..." style="flex:1;">
                <button class="btn" onclick="searchUns()">Search</button>
            </div>
            <div id="searchResult" style="margin-top: 20px;"></div>
        </div>

        <div id="tab-transfer" class="tab-content">
            <h2 class="section-title">Transfer Center</h2>
            <div class="section-desc">Pusat serah terima domain Web3.</div>
            <div id="tfAlert" class="alert-box"></div>
            <div class="form-group">
                <label>uNS Name to Transfer</label>
                <input type="text" id="tfName">
            </div>
            <div class="form-group">
                <label>Recipient Public Key</label>
                <input type="text" id="tfPk" placeholder="64 chars PK">
            </div>
            <button class="btn" id="btnTf" onclick="transferUns()">Request Transfer</button>
        </div>
    </div>
</div>

<script>
function openTab(tabId) {
    document.querySelectorAll('.tab-content').forEach(el => el.classList.remove('active'));
    document.querySelectorAll('.tab-btn').forEach(el => el.classList.remove('active'));
    document.getElementById('tab-' + tabId).classList.add('active');
    event.currentTarget.classList.add('active');
    if(tabId === 'my_domains') loadMyUns();
}

function showAlert(id, type, msg) {
    let el = document.getElementById(id); el.style.display = 'block'; el.className = 'alert-box alert-' + type; el.textContent = msg;
    setTimeout(() => el.style.display = 'none', 5000);
}

async function getRegDate(name) {
    let fd = new FormData(); fd.append('name', name);
    try {
        let r = await fetch('?ajax=history', {method:'POST', body:fd});
        let d = await r.json();
        
        if(d.result && d.result.length > 0) {
            d.result.sort((a,b) => {
                let dateA = a.issued || 0;
                let dateB = b.issued || 0;
                return new Date(dateA) - new Date(dateB);
            });
            let firstRecord = d.result[0];
            let rawDate = firstRecord.issued;
            return rawDate ? new Date(rawDate).toLocaleDateString('id-ID') : 'Unknown';
        }
    } catch(e) {
        console.error("Error fetching history:", e);
    }
    return 'Unknown';
}

async function loadMyUns() {
    let tbody = document.getElementById('myUnsBody');
    tbody.innerHTML = '<tr><td colspan="3" class="empty-state">Loading domains and history...</td></tr>';
    fetch('?ajax=my_uns').then(r=>r.json()).then(async d => {
        if(d.result && d.result.length > 0) {
            tbody.innerHTML = '';
            for (let r of d.result) {
                let rawName = r.name || r.nick || (typeof r === 'string' ? r : 'Unknown');
                let upperName = rawName.toUpperCase(); 
                let isPrimary = (rawName === '<?=htmlspecialchars($_SESSION['uns'] ?? '')?>') ? ' <small style="color:#71767b;">(PRIMARY)</small>' : '';
                
                let rowId = 'row-' + rawName.replace(/[^a-z0-9]/gi, '-');
                tbody.innerHTML += `<tr id="${rowId}"><td class="highlight" style="letter-spacing:1px; font-size:16px;">${upperName}${isPrimary}</td><td class="reg-date">...</td><td><span style="color:#00ff41; font-weight:600;">Registered</span></td></tr>`;
                
                getRegDate(rawName).then(date => {
                    let row = document.getElementById(rowId);
                    if(row) row.querySelector('.reg-date').textContent = date;
                });
            }
        } else {
            tbody.innerHTML = '<tr><td colspan="3" class="empty-state">Anda belum memiliki uNS.</td></tr>';
        }
    });
}

function registerUns() {
    let name = document.getElementById('regName').value.trim();
    let ch = document.getElementById('regChannel').value.trim();
    let isPrimary = document.getElementById('regPrimary').checked ? 'true' : 'false';
    
    if(!name) return showAlert('regAlert', 'error', 'Nama tidak boleh kosong!');
    let btn = document.getElementById('btnReg'); btn.disabled = true; btn.textContent = 'Processing...';
    
    let fd = new FormData(); 
    fd.append('nick', name); 
    fd.append('channelId', ch);
    fd.append('isPrimary', isPrimary);
    
    fetch('?ajax=register', {method:'POST', body:fd}).then(r=>r.json()).then(d => {
        if(d.result) {
            showAlert('regAlert', 'success', 'Registrasi uNS berhasil dikirim!');
            document.getElementById('regName').value = '';
            document.getElementById('regChannel').value = '';
            document.getElementById('regPrimary').checked = false;
        } else {
            showAlert('regAlert', 'error', 'Gagal: Nama dipakai atau saldo kurang.');
        }
        btn.disabled = false; btn.textContent = 'Register Name';
    });
}

function searchUns() {
    let query = document.getElementById('searchQuery').value.trim();
    let resBox = document.getElementById('searchResult'); if(!query) return;
    resBox.innerHTML = '<div class="empty-state">Melacak blockchain...</div>';
    let fd = new FormData(); fd.append('query', query);
    
    fetch('?ajax=search', {method:'POST', body:fd}).then(r=>r.json()).then(async d => {
        if(d.result && d.result.length > 0) {
            // Karena API mereturn daftar nama kalau kita cari pakai PK, 
            // kita ambil hasil pertama sebagai "Main Domain"
            let r = d.result[0]; let rawName = r.name || r.nick;
            let date = await getRegDate(rawName);
            
            resBox.innerHTML = `<table style="background:#000; border-radius:8px;">
                <tr><th style="width:150px">Main Domain</th><td class="highlight" style="font-size:16px; letter-spacing:1px;">${rawName.toUpperCase()}</td></tr>
                <tr><th>Registered</th><td>${date}</td></tr>
                <tr><th>Owner PK</th><td style="word-break:break-all; font-family:monospace; color:#71767b;">${r.pk}</td></tr>
                <tr><th>Other Domains</th><td id="assocDomains">Melacak portofolio...</td></tr>
            </table>`;
            
            let fdPk = new FormData(); fdPk.append('pk', r.pk);
            fetch('?ajax=search_by_pk', {method:'POST', body:fdPk}).then(rp=>rp.json()).then(dp => {
                let assocBox = document.getElementById('assocDomains');
                if(dp.result && dp.result.length > 0) {
                    let badges = '';
                    dp.result.forEach(ns => {
                        let n = ns.name || ns.nick;
                        if(n.toLowerCase() !== rawName.toLowerCase()) {
                            badges += `<span class="domain-badge">${n.toUpperCase()}</span>`;
                        }
                    });
                    assocBox.innerHTML = badges || '<span style="color:#71767b;">Tidak ada domain lain.</span>';
                } else {
                    assocBox.innerHTML = '<span style="color:#71767b;">Tidak ada domain lain.</span>';
                }
            });

            let fdHist = new FormData(); fdHist.append('name', rawName);
            fetch('?ajax=history', {method:'POST', body:fdHist}).then(rh=>rh.json()).then(dh => {
                if(dh.result && dh.result.length > 0) {
                    resBox.innerHTML += `<h3 style="margin-top:20px; font-size:16px;">Record History (${rawName.toUpperCase()})</h3><table><thead><tr><th>Date</th><th>Action</th></tr></thead><tbody id="histBody"></tbody></table>`;
                    let hb = document.getElementById('histBody');
                    dh.result.forEach(hx => {
                        let rawHistDate = hx.issued;
                        let displayDate = rawHistDate ? new Date(rawHistDate).toLocaleString('id-ID') : 'Unknown Date';
                        let actionStatus = hx.isPrimary ? '<span style="color:#ffd400;">Set as Primary</span>' : 'Registered / Updated';
                        hb.innerHTML += `<tr><td>${displayDate}</td><td>${actionStatus}</td></tr>`;
                    });
                }
            });
        } else { 
            // 🚀 SMART EMPTY MESSAGE
            let emptyMsg = query.length === 64 ? 'PK ini belum memiliki uNS yang terdaftar!' : 'Nama tersedia dan siap didaftarkan!';
            resBox.innerHTML = `<div class="alert-box alert-success" style="display:block">${emptyMsg}</div>`; 
        }
    });
}

function transferUns() {
    let name = document.getElementById('tfName').value.trim();
    let pk = document.getElementById('tfPk').value.trim();
    if(!name || pk.length !== 64) return showAlert('tfAlert', 'error', 'Isi nama dan PK 64 karakter!');
    let btn = document.getElementById('btnTf'); btn.disabled = true;
    let fd = new FormData(); fd.append('name', name); fd.append('pk', pk);
    fetch('?ajax=transfer', {method:'POST', body:fd}).then(r=>r.json()).then(d => {
        if(d.result && d.result.length > 10) showAlert('tfAlert', 'success', 'Request Transfer dikirim!');
        else showAlert('tfAlert', 'error', 'Gagal mengirim transfer.');
        btn.disabled = false;
    });
}

loadMyUns();
</script>
</body>
</html>