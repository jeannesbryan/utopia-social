<?php
require_once '../config.php';

// Proteksi Gerbang
if (!isset($_SESSION['token'])) {
    header('Location: ../index.php');
    exit;
}

// ==========================================
// 🚀 BACKEND AJAX API HANDLER (FINANCE)
// ==========================================
if (isset($_GET['ajax'])) {
    header('Content-Type: application/json');
    
    // 1. Cek Saldo CRP & UUSD
    if ($_GET['ajax'] === 'balance') {
        $crp = callUtopiaAPI('getBalance', ['currency' => 'CRP']);
        $uusd = callUtopiaAPI('getBalance', ['currency' => 'UUSD']);
        echo json_encode([
            'crp' => $crp['result'] ?? 0, 
            'uusd' => $uusd['result'] ?? 0
        ]); 
        exit;
    }
    
    // 2. Cek Riwayat Transaksi (History)
    if ($_GET['ajax'] === 'history') {
        // Tarik history CRP & UUSD (Bisa digabung atau dipisah di frontend)
        $res = callUtopiaAPI('getFinanceHistory', ['currency' => 'CRP']);
        echo json_encode($res); 
        exit;
    }
    
    // 3. Kirim Pembayaran (Transfer)
    if ($_GET['ajax'] === 'send' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $params = [
            'to' => trim($_POST['to'] ?? ''),
            'amount' => trim($_POST['amount'] ?? ''),
            'currency' => trim($_POST['currency'] ?? 'CRP'),
            'comment' => trim($_POST['comment'] ?? '')
        ];
        $res = callUtopiaAPI('sendPayment', $params);
        echo json_encode($res); 
        exit;
    }
    
    // 4. Buat Voucher
    if ($_GET['ajax'] === 'create_voucher' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $params = [
            'amount' => trim($_POST['amount'] ?? ''),
            'currency' => trim($_POST['currency'] ?? 'CRP')
        ];
        $res = callUtopiaAPI('createVoucher', $params);
        echo json_encode($res); 
        exit;
    }
    
    // 5. Gunakan / Klaim Voucher
    if ($_GET['ajax'] === 'use_voucher' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $params = [
            'voucherid' => trim($_POST['voucherid'] ?? '')
        ];
        $res = callUtopiaAPI('useVoucher', $params);
        echo json_encode($res); 
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Utopia Finance - Wallet</title>
    <link rel="icon" type="image/svg+xml" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'%3E%3Crect width='100' height='100' fill='%23ffd400'/%3E%3Ctext x='50' y='50' font-size='45' font-weight='bold' fill='%23000000' text-anchor='middle' dominant-baseline='central' font-family='Arial, sans-serif'%3EU%3C/text%3E%3C/svg%3E">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Arial, sans-serif; background: #000; color: #e7e9ea; min-height: 100vh; display: flex; flex-direction: column; }
        
        /* HEADER */
        .header { background: rgba(0,0,0,0.85); backdrop-filter: blur(12px); border-bottom: 1px solid #2f3336; padding: 16px 30px; display: flex; justify-content: space-between; align-items: center; position: sticky; top: 0; z-index: 100; }
        .header-logo { display: flex; align-items: center; gap: 12px; font-size: 20px; font-weight: 800; }
        .header-logo svg { width: 32px; height: 32px; }
        .btn-back { padding: 8px 16px; background: transparent; color: #e7e9ea; border: 1px solid #2f3336; border-radius: 20px; text-decoration: none; font-size: 14px; font-weight: 700; transition: 0.2s; }
        .btn-back:hover { background: rgba(255,255,255,0.1); }

        /* LAYOUT & SIDEBAR */
        .main-container { flex: 1; max-width: 1200px; margin: 0 auto; width: 100%; display: flex; gap: 24px; padding: 30px 20px; }
        .sidebar { width: 250px; flex-shrink: 0; display: flex; flex-direction: column; gap: 8px; }
        .tab-btn { padding: 14px 20px; background: transparent; color: #71767b; border: 1px solid transparent; border-radius: 12px; text-align: left; font-size: 16px; font-weight: 700; cursor: pointer; transition: 0.2s; }
        .tab-btn:hover { background: #16181c; color: #e7e9ea; }
        .tab-btn.active { background: rgba(255, 212, 0, 0.1); color: #ffd400; border-color: #ffd400; }
        
        /* CONTENT AREA */
        .content-area { flex: 1; background: #16181c; border: 1px solid #2f3336; border-radius: 16px; padding: 30px; min-height: 500px; }
        .tab-content { display: none; }
        .tab-content.active { display: block; animation: fadeIn 0.3s ease; }
        @keyframes fadeIn { from { opacity: 0; transform: translateY(5px); } to { opacity: 1; transform: translateY(0); } }
        
        .section-title { font-size: 24px; font-weight: 800; margin-bottom: 8px; }
        .section-desc { font-size: 15px; color: #71767b; margin-bottom: 24px; line-height: 1.5; }

        /* BALANCES CARD */
        .balance-cards { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 30px; }
        .balance-card { background: #000; border: 1px solid #2f3336; border-radius: 16px; padding: 24px; text-align: center; }
        .balance-label { color: #71767b; font-size: 14px; font-weight: 700; text-transform: uppercase; letter-spacing: 1px; margin-bottom: 8px; }
        .balance-value { font-size: 36px; font-weight: 800; }
        .val-crp { color: #1d9bf0; }
        .val-uusd { color: #00ff41; }

        /* FORMS & TABLES */
        .form-group { margin-bottom: 20px; }
        label { display: block; margin-bottom: 8px; font-size: 14px; font-weight: 600; color: #71767b; }
        input[type="text"], input[type="number"], select { width: 100%; padding: 12px; background: #000; border: 1px solid #2f3336; border-radius: 8px; color: #e7e9ea; font-size: 15px; font-family: monospace; outline: none; transition: 0.2s; }
        input:focus, select:focus { border-color: #ffd400; }
        
        .btn { padding: 12px 24px; background: #ffd400; color: #000; border: none; border-radius: 24px; font-size: 15px; font-weight: 700; cursor: pointer; transition: 0.2s; }
        .btn:hover { background: #e6bf00; }
        .btn:disabled { opacity: 0.5; cursor: not-allowed; }

        table { width: 100%; border-collapse: collapse; margin-top: 20px; }
        th, td { text-align: left; padding: 14px; border-bottom: 1px solid #2f3336; font-size: 15px; }
        th { color: #71767b; font-weight: 600; text-transform: uppercase; font-size: 12px; letter-spacing: 1px; }
        .highlight { color: #ffd400; font-weight: 700; }
        
        .empty-state { text-align: center; padding: 40px; color: #71767b; font-style: italic; }
        .alert-box { padding: 12px; border-radius: 8px; margin-bottom: 20px; display: none; font-size: 14px; word-break: break-all; }
        .alert-success { background: rgba(0,255,65,0.1); border: 1px solid #00ff41; color: #00ff41; }
        .alert-error { background: rgba(244,33,46,0.1); border: 1px solid #f4212e; color: #f4212e; }
        .tx-in { color: #00ff41; }
        .tx-out { color: #f4212e; }
    </style>
</head>
<body>

<div class="header">
    <div class="header-logo">
        <svg viewBox="0 0 100 100" xmlns="http://www.w3.org/2000/svg">
            <rect width="100" height="100" rx="20" fill="#ffd400"/>
            <text x="50" y="50" font-size="45" font-weight="bold" fill="#000" text-anchor="middle" dominant-baseline="central" font-family="Arial">U</text>
        </svg>
        Utopia Finance
    </div>
    <a href="../dashboard.php" class="btn-back">← Back to Dashboard</a>
</div>

<div class="main-container">
    <div class="sidebar">
        <button class="tab-btn active" onclick="openTab('overview')">💰 Dashboard</button>
        <button class="tab-btn" onclick="openTab('send')">💸 Send Crypto</button>
        <button class="tab-btn" onclick="openTab('vouchers')">🎟️ Vouchers</button>
    </div>

    <div class="content-area">
        
        <div id="tab-overview" class="tab-content active">
            <h2 class="section-title">Wallet Overview</h2>
            <div class="section-desc">Ringkasan saldo dan riwayat transaksi terbaru Anda di jaringan Utopia.</div>
            
            <div class="balance-cards">
                <div class="balance-card">
                    <div class="balance-label">Crypton (CRP)</div>
                    <div class="balance-value val-crp" id="balCrp">...</div>
                </div>
                <div class="balance-card">
                    <div class="balance-label">Utopia USD (UUSD)</div>
                    <div class="balance-value val-uusd" id="balUusd">...</div>
                </div>
            </div>

            <h3 style="font-size: 18px; margin-bottom: 10px;">Recent Transactions (CRP)</h3>
            <button class="btn" onclick="loadDashboard()" style="padding: 6px 12px; font-size: 12px;">🔄 Refresh</button>
            <table id="historyTable">
                <thead><tr><th>Date</th><th>Type</th><th>Amount</th><th>Status</th></tr></thead>
                <tbody id="historyBody"><tr><td colspan="4" class="empty-state">Memuat data transaksi...</td></tr></tbody>
            </table>
        </div>

        <div id="tab-send" class="tab-content">
            <h2 class="section-title">Send Payment</h2>
            <div class="section-desc">Kirim Crypton atau UUSD ke pengguna Utopia lain tanpa jejak dan biaya rendah.</div>
            <div id="sendAlert" class="alert-box"></div>
            
            <div class="form-group">
                <label>Recipient (Public Key, uNS, or Card ID)</label>
                <input type="text" id="sendTo" placeholder="e.g. jomokerto atau FBC57A...">
            </div>
            
            <div style="display:flex; gap:20px;">
                <div class="form-group" style="flex:1;">
                    <label>Amount</label>
                    <input type="number" id="sendAmount" step="0.01" placeholder="0.00">
                </div>
                <div class="form-group" style="width:150px;">
                    <label>Currency</label>
                    <select id="sendCurrency">
                        <option value="CRP">CRP</option>
                        <option value="UUSD">UUSD</option>
                    </select>
                </div>
            </div>

            <div class="form-group">
                <label>Comment (Optional - Encrypted)</label>
                <input type="text" id="sendComment" placeholder="Pesan untuk penerima...">
            </div>
            
            <button class="btn" id="btnSend" onclick="executeSend()">Confirm Transfer</button>
        </div>

        <div id="tab-vouchers" class="tab-content">
            <h2 class="section-title">Crypto Vouchers</h2>
            <div class="section-desc">Buat voucher kertas (teks) untuk hadiah, atau klaim voucher yang Anda dapatkan.</div>
            
            <div style="display:grid; grid-template-columns: 1fr 1fr; gap: 30px;">
                <div style="background:#000; padding:20px; border-radius:12px; border:1px solid #2f3336;">
                    <h3 style="margin-bottom:15px;">Redeem Voucher</h3>
                    <div id="useAlert" class="alert-box"></div>
                    <div class="form-group">
                        <label>Voucher Code</label>
                        <input type="text" id="useVoucherCode" placeholder="UTP-XXXX-XXXX-XXXX...">
                    </div>
                    <button class="btn" id="btnUse" onclick="useVoucher()" style="width:100%">Claim Funds</button>
                </div>

                <div style="background:#000; padding:20px; border-radius:12px; border:1px solid #2f3336;">
                    <h3 style="margin-bottom:15px;">Create Voucher</h3>
                    <div id="createAlert" class="alert-box"></div>
                    <div style="display:flex; gap:10px;">
                        <div class="form-group" style="flex:1;">
                            <label>Amount</label>
                            <input type="number" id="createAmount" step="0.01" placeholder="0.00">
                        </div>
                        <div class="form-group" style="width:100px;">
                            <label>Currency</label>
                            <select id="createCurrency">
                                <option value="CRP">CRP</option>
                                <option value="UUSD">UUSD</option>
                            </select>
                        </div>
                    </div>
                    <button class="btn" id="btnCreate" onclick="createVoucher()" style="width:100%">Generate Voucher</button>
                </div>
            </div>
        </div>

    </div>
</div>

<script>
// Logic Pindah Tab
function openTab(tabId) {
    document.querySelectorAll('.tab-content').forEach(el => el.classList.remove('active'));
    document.querySelectorAll('.tab-btn').forEach(el => el.classList.remove('active'));
    document.getElementById('tab-' + tabId).classList.add('active');
    event.currentTarget.classList.add('active');
    if(tabId === 'overview') loadDashboard();
}

function showAlert(id, type, msg) {
    let el = document.getElementById(id);
    el.style.display = 'block';
    el.className = 'alert-box alert-' + type;
    el.innerHTML = msg; // allow html for copyable voucher
    setTimeout(() => { if(type==='error') el.style.display = 'none'; }, 6000);
}

// 1. FETCH BALANCES & HISTORY
function loadDashboard() {
    // Balances
    document.getElementById('balCrp').textContent = '...';
    document.getElementById('balUusd').textContent = '...';
    fetch('?ajax=balance').then(r=>r.json()).then(d => {
        document.getElementById('balCrp').textContent = parseFloat(d.crp).toFixed(4);
        document.getElementById('balUusd').textContent = '$' + parseFloat(d.uusd).toFixed(2);
    });

    // History
    let tbody = document.getElementById('historyBody');
    tbody.innerHTML = '<tr><td colspan="4" class="empty-state">Loading...</td></tr>';
    fetch('?ajax=history').then(r=>r.json()).then(d => {
        if(d.result && d.result.length > 0) {
            tbody.innerHTML = '';
            // Ambil 10 transaksi terakhir
            let txs = d.result.slice(0, 10);
            
            txs.forEach(tx => {
                // SMART DATE: Tangkap dari variabel 'created' atau 'dateTime'
                let rawDate = tx.created || tx.dateTime || tx.date || tx.timestamp;
                let displayDate = rawDate ? new Date(rawDate).toLocaleString() : 'Unknown Date';

                // TIPE TRANSAKSI & ARAH: 1 = Masuk, 2 = Keluar
                let isOut = (tx.direction === 2 || tx.amount < 0);
                let colorClass = isOut ? 'tx-out' : 'tx-in';
                let sign = isOut ? '-' : '+';
                
                // Gunakan teks asli dari Utopia (contoh: "Interest reward") jika ada
                let typeText = tx.type || (isOut ? 'Sent' : 'Received');
                
                // Pastikan amount-nya absolut
                let absAmount = Math.abs(tx.amount || 0);

                // SMART STATUS: State 0 itu ternyata SUKSES! 
                let statusText = 'Completed'; 
                
                if (tx.state !== undefined) {
                    // Hanya state 1 yang Pending, dan state 4 yang Failed
                    if (tx.state === 1 || String(tx.state).toLowerCase() === 'pending') statusText = 'Pending';
                    else if (tx.state === 4 || String(tx.state).toLowerCase() === 'failed') statusText = 'Failed';
                }

                tbody.innerHTML += `<tr>
                    <td>${displayDate}</td>
                    <td>${typeText}</td>
                    <td class="${colorClass}" style="font-weight:bold;">${sign}${absAmount}</td>
                    <td><span style="color: ${statusText === 'Completed' ? '#00ff41' : (statusText === 'Failed' ? '#f4212e' : '#ffd400')}">${statusText}</span></td>
                </tr>`;
            });
        } else {
            tbody.innerHTML = '<tr><td colspan="4" class="empty-state">Belum ada transaksi.</td></tr>';
        }
    }).catch(()=> tbody.innerHTML = '<tr><td colspan="4" class="empty-state">Gagal mengambil data history.</td></tr>');
}

// 2. SEND PAYMENT
function executeSend() {
    let to = document.getElementById('sendTo').value.trim();
    let amount = document.getElementById('sendAmount').value.trim();
    let currency = document.getElementById('sendCurrency').value;
    let comment = document.getElementById('sendComment').value.trim();
    
    if(!to || !amount || amount <= 0) return showAlert('sendAlert', 'error', 'Penerima dan jumlah transfer harus diisi!');
    
    let btn = document.getElementById('btnSend'); btn.disabled = true; btn.textContent = 'Processing...';
    let fd = new FormData(); 
    fd.append('to', to); fd.append('amount', amount); fd.append('currency', currency); fd.append('comment', comment);
    
    fetch('?ajax=send', {method:'POST', body:fd}).then(r=>r.json()).then(d => {
        if(d.result) {
            showAlert('sendAlert', 'success', `Sukses mengirim ${amount} ${currency}! Reference: ${d.result}`);
            document.getElementById('sendTo').value = '';
            document.getElementById('sendAmount').value = '';
            document.getElementById('sendComment').value = '';
        }
        else showAlert('sendAlert', 'error', d.error || 'Transfer gagal. Periksa saldo atau alamat penerima.');
        btn.disabled = false; btn.textContent = 'Confirm Transfer';
    });
}

// 3. CREATE VOUCHER
function createVoucher() {
    let amount = document.getElementById('createAmount').value.trim();
    let currency = document.getElementById('createCurrency').value;
    if(!amount || amount <= 0) return showAlert('createAlert', 'error', 'Masukkan jumlah voucher yang valid!');
    
    let btn = document.getElementById('btnCreate'); btn.disabled = true; btn.textContent = 'Generating...';
    let fd = new FormData(); fd.append('amount', amount); fd.append('currency', currency);
    
    fetch('?ajax=create_voucher', {method:'POST', body:fd}).then(r=>r.json()).then(d => {
        if(d.result && typeof d.result === 'string') {
            showAlert('createAlert', 'success', `Voucher berhasil dibuat! <br><br><b>${d.result}</b><br><br><small>(Copy kode di atas dan berikan ke teman Anda)</small>`);
            document.getElementById('createAmount').value = '';
        } else {
            showAlert('createAlert', 'error', d.error || 'Gagal membuat voucher. Saldo tidak mencukupi.');
        }
        btn.disabled = false; btn.textContent = 'Generate Voucher';
    });
}

// 4. USE VOUCHER
function useVoucher() {
    let code = document.getElementById('useVoucherCode').value.trim();
    if(!code) return showAlert('useAlert', 'error', 'Masukkan kode voucher!');
    
    let btn = document.getElementById('btnUse'); btn.disabled = true; btn.textContent = 'Claiming...';
    let fd = new FormData(); fd.append('voucherid', code);
    
    fetch('?ajax=use_voucher', {method:'POST', body:fd}).then(r=>r.json()).then(d => {
        if(d.result) {
            showAlert('useAlert', 'success', `Voucher berhasil dicairkan! Saldo Anda telah bertambah.`);
            document.getElementById('useVoucherCode').value = '';
            loadDashboard(); // Refresh saldo
        } else {
            showAlert('useAlert', 'error', d.error || 'Voucher tidak valid atau sudah digunakan.');
        }
        btn.disabled = false; btn.textContent = 'Claim Funds';
    });
}

// Init Load
loadDashboard();
</script>
</body>
</html>