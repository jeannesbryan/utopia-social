<?php
// 🚀 INTEGRASI LANGSUNG DENGAN CONFIG UTAMA
require_once '../config.php';

// Usir ke halaman login kalau belum masuk
if (!isset($_SESSION['token'])) {
    header('Location: ../index.php');
    exit;
}

// 🚀 CEK KONFIGURASI TIMELINE AGAR TIDAK BENTROK
if (!defined('CHANNEL_ID')) {
    define('CHANNEL_ID', '2F5F675D31CA664E102AFDF061516AE3'); 
}
if (!defined('POSTS_PER_PAGE')) {
    define('POSTS_PER_PAGE', 50);
}

// ==========================================
// 🚀 BACKEND AJAX API HANDLER (TIMELINE)
// ==========================================
if (isset($_GET['ajax'])) {
    header('Content-Type: application/json');
    
    // 1. Posting Pesan / Gambar Baru
    if ($_GET['ajax'] === 'post' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $msg = trim($_POST['message']); 
        $base64 = trim($_POST['base64_image'] ?? '');
        $filename = trim($_POST['filename_image'] ?? 'image.jpg');
        
        if (strlen($msg) > 280) { echo json_encode(['error' => 'Exceeds 280 chars']); exit; }
        if (empty($msg) && empty($base64)) { echo json_encode(['error' => 'Empty message']); exit; }
        
        if (!empty($base64)) {
            $res = callUtopiaAPI('sendChannelPicture', [
                'channelid' => CHANNEL_ID,
                'base64_image' => $base64,
                'comment' => $msg, 
                'filename_image' => $filename
            ]);
        } else {
            $res = callUtopiaAPI('sendChannelMessage', ['channelid' => CHANNEL_ID, 'message' => $msg]);
        }
        
        echo json_encode(['success' => isset($res['result']), 'error' => $res['error'] ?? 'API Error']); exit;
    }
    
    // 2. Balas Pesan (Quote Reply)
    if ($_GET['ajax'] === 'quote' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $msg = trim($_POST['message']); 
        $qId = strval($_POST['quote_id']);
        $qAuth = trim($_POST['quoted_author'] ?? ''); 
        $qText = trim($_POST['quoted_text'] ?? '');
        
        if (strlen($msg) > 280 || empty($msg)) { echo json_encode(['error' => 'Invalid message length']); exit; }
        
        // Format Quote Klasik Utopia
        $fmt = $msg . "\n----------------------\n@" . $qAuth . ": " . $qText . "\n----------------------";
        $res = callUtopiaAPI('sendChannelMessage', ['channelid'=>CHANNEL_ID, 'message'=>$fmt]);
        echo json_encode(['success'=>isset($res['result']), 'error'=>$res['error']??'Post Failed']); exit;
    }
    
    // 3. Tarik Timeline (Lazy Loading)
    if ($_GET['ajax'] === 'timeline') {
        $offset = intval($_GET['offset'] ?? 0); 
        
        $res = callUtopiaAPI('getChannelMessages', ['channelid' => CHANNEL_ID]);
        if (isset($res['result']) && is_array($res['result']) && empty($res['result'])) {
            usleep(500000); 
            $res = callUtopiaAPI('getChannelMessages', ['channelid' => CHANNEL_ID]);
        }
        
        if (!isset($res['result']) || !is_array($res['result'])) { 
            echo json_encode(['messages'=>[], 'count'=>0, 'error'=>$res['error'] ?? 'API Error']); exit; 
        }
        
        $msgs = $res['result']; 
        $uniquePks = [];
        $hashToFull = []; // 🚀 MESIN PENERJEMAH PK HASHED KE FULL PK
        $nickToFull = []; // 🚀 MESIN PENERJEMAH NICKNAME KE FULL PK
        
        // BANGUN KAMUS PK DARI SELURUH RIWAYAT SEBELUM DIPOTONG
        foreach ($msgs as $m) {
            $p = $m['pk'] ?? '';
            $h = $m['hashedPk'] ?? '';
            $n = $m['nick'] ?? '';
            if (!empty($p) && strlen($p) === 64) {
                if (!empty($h)) $hashToFull[$h] = $p;
                if (!empty($n)) $nickToFull[$n] = $p;
            }
        }
        
        // Urutkan dari yang paling baru
        usort($msgs, function($a, $b) { return strtotime($b['dateTime']??0) - strtotime($a['dateTime']??0); });
        $msgs = array_slice($msgs, $offset, POSTS_PER_PAGE);
        
        // Kumpulkan Unique PK (Pastikan menggunakan Full PK 64 Karakter)
        foreach ($msgs as &$m) {
            $p = $m['pk'] ?? ''; 
            $h = $m['hashedPk'] ?? '';
            $qPkRaw = $m['metaData']['data']['hexPublicKey'] ?? '';
            
            // Konversi ke Full PK
            $fullP = !empty($p) ? $p : ($hashToFull[$h] ?? $h);
            if(!empty($fullP)) $uniquePks[] = $fullP;
            
            // Konversi Quote PK ke Full PK
            if(!empty($qPkRaw)) {
                $qFull = $hashToFull[$qPkRaw] ?? $qPkRaw;
                $uniquePks[] = $qFull;
            }
        }
        
        if (!isset($_SESSION['ava_cache'])) $_SESSION['ava_cache'] = [];
        if (!isset($_SESSION['nick_ava_cache'])) $_SESSION['nick_ava_cache'] = []; 
        
        $avaCache = [];
        
        // Tarik Avatar Berdasarkan Full PK
        foreach (array_unique($uniquePks) as $pk) {
            if(empty($pk)) continue;
            if (array_key_exists($pk, $_SESSION['ava_cache'])) {
                if ($_SESSION['ava_cache'][$pk] !== null) $avaCache[$pk] = $_SESSION['ava_cache'][$pk];
                continue;
            }
            $ava = callUtopiaAPI('getContactAvatar', ['pk' => $pk, 'coder' => 'BASE64', 'format' => 'JPG']);
            if (empty($ava['result']) || $ava['result'] === "0") {
                $ava = callUtopiaAPI('getAvatarByKey', ['pk' => $pk, 'coder' => 'BASE64', 'format' => 'JPG']);
            }
            if (!empty($ava['result']) && $ava['result'] !== "0") {
                $img = 'data:image/jpeg;base64,'.$ava['result'];
                $avaCache[$pk] = $img;
                $_SESSION['ava_cache'][$pk] = $img; 
            } else {
                $_SESSION['ava_cache'][$pk] = null; 
            }
        }
        
        // SIMPAN NICKNAME KE GLOBAL AVATAR CACHE
        foreach ($nickToFull as $n => $fullP) {
            if (isset($avaCache[$fullP])) {
                $_SESSION['nick_ava_cache'][$n] = $avaCache[$fullP];
            }
        }
        foreach ($msgs as $m) {
            $n = $m['nick'] ?? '';
            $fullP = !empty($m['pk']) ? $m['pk'] : ($hashToFull[$m['hashedPk'] ?? ''] ?? '');
            if (!empty($n) && !empty($fullP) && isset($avaCache[$fullP])) {
                $_SESSION['nick_ava_cache'][$n] = $avaCache[$fullP];
            }
        }
        
        foreach ($msgs as &$m) {
            $p = $m['pk'] ?? ''; 
            $h = $m['hashedPk'] ?? '';
            $fullP = !empty($p) ? $p : ($hashToFull[$h] ?? $h); // 🚀 TERAPKAN FULL PK KE PENGIRIM UTAMA
            
            $m['displayName'] = $m['nick'] ?? 'Anonymous'; 
            $m['authorPk'] = $fullP; 
            // Coba ambil dari avaCache, kalau gagal coba nick_ava_cache, kalau gagal pakai inisial
            $m['avatar'] = $avaCache[$fullP] ?? ($_SESSION['nick_ava_cache'][$m['displayName']] ?? 'data:image/svg+xml;base64,'.base64_encode(generateDefaultAvatar($m['displayName'])));
            
            // --- UNIVERSAL PARSER MULAI DI SINI ---
            $txt = ''; $m['quotedPost'] = null; $m['attachedImage'] = '';
            $mdType = $m['metaData']['type'] ?? '';
            $mdData = $m['metaData']['data'] ?? [];
            
            // 1. Gambar
            if ($mdType === 'picture' || isset($mdData['pictureData'])) {
                $picBase64 = $mdData['pictureData'] ?? '';
                $picFmt = $mdData['pictureFormat'] ?? 'png';
                if (!empty($picBase64)) {
                    $m['attachedImage'] = '<div style="margin-top:12px; margin-bottom:8px;"><img src="data:image/' . htmlspecialchars($picFmt) . ';base64,' . htmlspecialchars($picBase64) . '" style="max-width:100%; max-height:400px; border-radius:12px; border:1px solid #2f3336; object-fit:cover;"></div>';
                }
                $txt = $mdData['comment'] ?? ($m['text'] ?? ($m['message'] ?? ''));
            } 
            // 2. Text Android
            elseif ($mdType === 'text') {
                $txt = $mdData['text'] ?? '';
            } 
            // 3. Native Quote Utopia App
            elseif (!empty($mdData['quote'])) {
                $txt = $mdData['text'] ?? ''; 
                $qPkRaw = $mdData['hexPublicKey'] ?? '';
                $qPkFull = $hashToFull[$qPkRaw] ?? $qPkRaw; // Tembak dengan kamus translator
                $qNick = $mdData['nick'] ?? 'User';
                
                $qAva = $avaCache[$qPkFull] ?? ($_SESSION['nick_ava_cache'][$qNick] ?? 'data:image/svg+xml;base64,'.base64_encode(generateDefaultAvatar($qNick)));
                $m['quotedPost'] = ['author' => $qNick, 'text' => $mdData['quote'], 'avatar' => $qAva];
            } 
            // 4. Default
            else {
                $txt = $m['text'] ?? ($m['message'] ?? '');
            }
            
            // 5. Tangkap Quote Format Klasik Regex
            $isQ = false;
            if(empty($m['quotedPost']) && preg_match('/^(.*?)\n-{10,}\s*\n@([^:]+):\s*(.*?)\n-{10,}\s*$/s', $txt, $matches)){
                $txt = trim($matches[1]); $qAuth = trim($matches[2]); $qTxt = trim($matches[3]); $isQ = true;
            } elseif(empty($m['quotedPost']) && preg_match('/^\[(.*?)\n(.*?)\]\n(.*)$/s', $txt, $matches)){
                $qAuth = trim($matches[1]); $qTxt = trim($matches[2]); $txt = trim($matches[3]); $isQ = true;
            }
            
            if($isQ){
                $qAva = $_SESSION['nick_ava_cache'][$qAuth] ?? 'data:image/svg+xml;base64,'.base64_encode(generateDefaultAvatar($qAuth));
                $m['quotedPost'] = ['author' => $qAuth, 'text' => $qTxt, 'avatar' => $qAva];
            }
            
            $m['message'] = trim($txt);
        }
        echo json_encode(['messages' => array_values($msgs), 'count' => count($msgs)]); exit;
    }
    
    // 4. Tarik Avatar Individual
    if ($_GET['ajax'] === 'avatar') {
        $pk = $_GET['pk'];
        if (isset($_SESSION['ava_cache'][$pk]) && $_SESSION['ava_cache'][$pk] !== null) {
            echo json_encode(['avatar' => $_SESSION['ava_cache'][$pk]]); exit;
        }
        $res = callUtopiaAPI('getContactAvatar', ['pk' => $pk, 'coder' => 'BASE64', 'format' => 'JPG']);
        if(empty($res['result']) || $res['result'] === "0") $res = callUtopiaAPI('getAvatarByKey', ['pk' => $pk, 'coder' => 'BASE64', 'format' => 'JPG']);
        
        $finalAva = (empty($res['result']) || $res['result']==="0") ? null : 'data:image/jpeg;base64,'.$res['result'];
        if ($finalAva) $_SESSION['ava_cache'][$pk] = $finalAva;
        echo json_encode(['avatar' => $finalAva]); exit;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Utopia Social</title>
    <link rel="icon" type="image/svg+xml" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'%3E%3Crect width='100' height='100' fill='%231d9bf0'/%3E%3Ctext x='50' y='50' font-size='45' font-weight='bold' fill='%23ffffff' text-anchor='middle' dominant-baseline='central' font-family='Arial, sans-serif'%3EU%3C/text%3E%3C/svg%3E">
    <style>
        *{margin:0;padding:0;box-sizing:border-box} body{font-family:-apple-system,BlinkMacSystemFont,sans-serif;background:#000;color:#e7e9ea;line-height:1.5}
        .container{max-width:600px;margin:0 auto;min-height:100vh;border-left:1px solid #2f3336;border-right:1px solid #2f3336}
        
        textarea{width:100%;padding:12px;background:#000;border:1px solid #2f3336;border-radius:4px;color:#e7e9ea;font-size:15px;font-family:inherit;resize:none;min-height:80px;margin-bottom:8px} textarea:focus{outline:none;border-color:#1d9bf0}
        
        .error-box{color:#f4212e;padding:12px;background:rgba(244,33,46,0.1);border:1px solid rgba(244,33,46,0.5);border-radius:8px;font-size:14px;margin:16px;display:none}
        
        /* HEADER */
        .header{position:sticky;top:0;background:rgba(0,0,0,0.85);backdrop-filter:blur(12px);border-bottom:1px solid #2f3336;padding:16px;z-index:100;display:flex;justify-content:space-between;align-items:center;}
        .header-logo { display: flex; align-items: center; gap: 12px; font-size: 20px; font-weight: 800; cursor: pointer;}
        .header-logo svg { width: 32px; height: 32px; }
        .btn-back { padding: 6px 14px; background: transparent; color: #e7e9ea; border: 1px solid #2f3336; border-radius: 20px; text-decoration: none; font-size: 13px; font-weight: 700; transition: 0.2s; }
        .btn-back:hover { background: rgba(255,255,255,0.1); }
        
        /* COMPOSER */
        .composer{border-bottom:1px solid #2f3336;padding:16px} .composer-input{width:100%} .composer-footer{display:flex;justify-content:space-between;align-items:center}
        .char-count{color:#71767b;font-size:14px} .char-count.warning{color:#ffd400} .char-count.error{color:#f4212e} 
        .btn-post{padding:8px 24px;background:#1d9bf0;color:#fff;border:none;border-radius:24px;font-size:15px;font-weight:700;cursor:pointer} .btn-post:hover{background:#1a8cd8} .btn-post:disabled{opacity:0.5;cursor:not-allowed}

        /* UPLOAD GAMBAR */
        .composer-tools { display: flex; align-items: center; margin-top: 5px; padding-top: 10px; border-top: 1px solid #2f3336; }
        .btn-icon { color: #1d9bf0; font-size: 14px; font-weight: 700; cursor: pointer; display: flex; align-items: center; gap: 6px; transition: color 0.2s; }
        .btn-icon:hover { text-decoration: underline; color: #1a8cd8; }
        .image-preview-container { display: none; margin-top: 12px; position: relative; width: fit-content; }
        .image-preview-container img { max-width: 100%; max-height: 250px; border-radius: 12px; border: 1px solid #2f3336; }
        .btn-remove-img { position: absolute; top: 8px; right: 8px; background: rgba(0,0,0,0.7); color: #fff; border: none; border-radius: 50%; width: 28px; height: 28px; font-size: 18px; cursor: pointer; display: flex; justify-content: center; align-items: center; }
        .btn-remove-img:hover { background: rgba(244,33,46,0.9); }

        /* POST TIMELINE */
        .timeline{padding-bottom:60px} .post{border-bottom:1px solid #2f3336;padding:16px;transition:background 0.2s} .post:hover{background:rgba(255,255,255,0.03)} .post-content{display:flex;gap:12px} .post-body{flex:1;min-width:0}
        .post-header{display:flex;align-items:center;gap:8px;margin-bottom:4px} 
        .post-author{font-weight:700;font-size:15px} 
        .author-link { color: inherit; text-decoration: none; transition: color 0.2s; }
        .author-link:hover { color: #1d9bf0; text-decoration: underline; }
        .post-handle,.post-time{color:#71767b;font-size:15px} .post-text{font-size:15px;margin-bottom:12px;word-wrap:break-word;white-space:pre-wrap}
        
        /* QUOTED POST */
        .quoted-post{border:1px solid #2f3336;border-radius:12px;padding:12px;margin-top:12px;background:rgba(255,255,255,0.02)} .quoted-post-content{display:flex;gap:12px} .avatar{width:40px;height:40px;border-radius:50%;object-fit:cover;background:#2f3336;} .quoted-post .avatar{width:32px;height:32px} .quoted-post-body{flex:1;min-width:0} .quoted-post .post-header{margin-bottom:8px;display:flex;align-items:center;gap:8px} .quoted-post .post-text{margin-bottom:0;color:#e7e9ea;font-size:14px}
        
        .post-actions{display:flex;gap:16px;margin-top:12px} .action-btn{display:flex;align-items:center;gap:8px;padding:4px 8px;background:transparent;color:#71767b;border:1px solid #2f3336;border-radius:16px;font-size:13px;cursor:pointer;transition:all 0.2s} .action-btn:hover{background:rgba(29,155,240,0.1);color:#1d9bf0;border-color:#1d9bf0}
        
        /* MODAL QUOTE */
        .modal{display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.8);z-index:1000;align-items:center;justify-content:center} .modal.active{display:flex} .modal-content{background:#16181c;border-radius:16px;padding:24px;max-width:500px;width:90%;max-height:90vh;overflow-y:auto} .modal-header{display:flex;justify-content:space-between;align-items:center;margin-bottom:20px} .modal-title{font-size:20px;font-weight:700} .close-btn{background:transparent;border:none;color:#e7e9ea;font-size:24px;cursor:pointer;width:32px;height:32px;display:flex;align-items:center;justify-content:center;border-radius:50%} .close-btn:hover{background:rgba(255,255,255,0.1)}
        
        /* LOADING */
        .loading{text-align:center;padding:40px;color:#71767b} .loading-spinner{display:inline-block;width:24px;height:24px;border:3px solid #2f3336;border-top-color:#1d9bf0;border-radius:50%;animation:spin 0.8s linear infinite} @keyframes spin{to{transform:rotate(360deg)}} .no-more{text-align:center;padding:20px;color:#71767b;font-size:14px}
    </style>
</head>
<body>

<div class="container">
    <div class="header">
        <div class="header-logo" onclick="window.scrollTo({top:0, behavior:'smooth'})">
            <svg viewBox="0 0 100 100" xmlns="http://www.w3.org/2000/svg"><rect width="100" height="100" rx="20" fill="#1d9bf0"/><text x="50" y="50" font-size="45" font-weight="bold" fill="#fff" text-anchor="middle" dominant-baseline="central" font-family="Arial">U</text></svg>
            Utopia Social
        </div>
        <div style="display:flex; gap:12px;">
            <a href="me.php" class="btn-back" style="background: rgba(29, 155, 240, 0.1); color: #1d9bf0; border-color: #1d9bf0;">👤 Profile</a>
            <a href="../dashboard.php" class="btn-back">← Back</a>
        </div>
    </div>
    
    <div class="composer">
        <div class="composer-input">
            <textarea id="postText" placeholder="What's happening?" maxlength="280"></textarea>
            
            <div class="image-preview-container" id="imagePreview">
                <img id="previewImg" src="">
                <button class="btn-remove-img" id="removeImageBtn" title="Hapus Gambar">&times;</button>
            </div>

            <div class="composer-tools">
                <label for="imageInput" class="btn-icon">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor"><path d="M19.75 2H4.25C3.01 2 2 3.01 2 4.25v15.5C2 20.99 3.01 22 4.25 22h15.5c1.24 0 2.25-1.01 2.25-2.25V4.25C22 3.01 20.99 2 19.75 2zM4.25 3.5h15.5c.413 0 .75.337.75.75v9.676l-3.858-3.858c-.14-.14-.33-.22-.53-.22h-.003c-.2 0-.393.078-.532.224l-4.317 4.384-1.813-1.806c-.14-.14-.33-.22-.53-.22-.193-.03-.395.08-.535.227L3.5 17.642V4.25c0-.413.337-.75.75-.75zm-.744 16.28l5.418-5.534 6.282 6.254H4.25c-.402 0-.727-.322-.744-.72zm16.244.72h-2.42l-5.007-4.987 3.792-3.85 4.385 4.384v3.703c0 .413-.337.75-.75.75z"></path><circle cx="8.868" cy="8.309" r="1.542"></circle></svg>
                    Upload Gambar
                </label>
                <input type="file" id="imageInput" accept="image/png, image/jpeg, image/gif" style="display:none">
            </div>

            <div class="composer-footer" style="margin-top:12px">
                <span class="char-count" id="charCount">0 / 280</span>
                <button class="btn-post" id="postBtn" disabled>Post</button>
            </div>
        </div>
    </div>
    
    <div id="apiErrorLog" class="error-box"></div>
    <div class="timeline" id="timeline"><div class="loading"><div class="loading-spinner"></div></div></div>
</div>

<div class="modal" id="quoteModal">
    <div class="modal-content">
        <div class="modal-header">
            <h2 class="modal-title">Quote Post</h2>
            <button class="close-btn" onclick="closeQuoteModal()">&times;</button>
        </div>
        <div id="quotedPostPreview"></div>
        <textarea id="quoteText" placeholder="Add your comment..." maxlength="280" style="margin-top:16px;"></textarea>
        <div class="composer-footer" style="margin-top:12px;">
            <span class="char-count" id="quoteCharCount">0 / 280</span>
            <button class="btn-post" id="quoteBtn">Post Quote</button>
        </div>
    </div>
</div>

<script>
// 🚀 INJEKSI PK SAYA UNTUK URL DINAMIS
const MY_PK = "<?= $_SESSION['pk'] ?? '' ?>";

let offset=0, loading=false, hasMore=true, cQuoteId=null, cAuthor='', cText='';
let selectedImageBase64 = ''; let selectedImageName = '';

function escapeHtmlStrict(t) { return String(t||'').replace(/&/g,"&amp;").replace(/</g,"&lt;").replace(/>/g,"&gt;").replace(/"/g,"&quot;").replace(/'/g,"&#039;"); }
function escapeHtml(t) { let d=document.createElement('div'); d.textContent=t; return d.innerHTML; }
function showError(msg) { const e = document.getElementById('apiErrorLog'); e.style.display = 'block'; e.textContent = "Error:\n" + msg; }
function hideError() { document.getElementById('apiErrorLog').style.display = 'none'; }

const updateCount = () => { 
    let l = document.getElementById('postText').value.length; 
    let cnt = document.getElementById('charCount');
    let btn = document.getElementById('postBtn');
    cnt.textContent = l + ' / 280'; 
    cnt.className = 'char-count' + (l>250?' warning':'') + (l>275?' error':''); 
    btn.disabled = (l === 0 && selectedImageBase64 === '') || l > 280; 
};

document.getElementById('postText').addEventListener('input', updateCount);

document.getElementById('imageInput').addEventListener('change', function(e) {
    let file = e.target.files[0];
    if (!file) return;
    selectedImageName = file.name;
    let reader = new FileReader();
    reader.onload = function(event) {
        let dataUrl = event.target.result;
        document.getElementById('previewImg').src = dataUrl;
        document.getElementById('imagePreview').style.display = 'block';
        selectedImageBase64 = dataUrl.split(',')[1];
        updateCount(); 
    };
    reader.readAsDataURL(file);
});

document.getElementById('removeImageBtn').addEventListener('click', function() {
    selectedImageBase64 = ''; selectedImageName = '';
    document.getElementById('imageInput').value = '';
    document.getElementById('imagePreview').style.display = 'none';
    updateCount();
});

document.getElementById('postBtn').addEventListener('click', function(){
    let msg = document.getElementById('postText').value.trim(); 
    if(!msg && !selectedImageBase64) return;
    if(msg.length > 280) return;
    
    this.disabled = true; hideError(); 
    let fd = new FormData(); 
    fd.append('message', msg);
    
    if (selectedImageBase64) {
        fd.append('base64_image', selectedImageBase64);
        fd.append('filename_image', selectedImageName);
    }
    
    fetch('?ajax=post',{method:'POST',body:fd}).then(r=>r.json()).then(d=>{
        if(d.success){ 
            document.getElementById('postText').value=''; 
            document.getElementById('removeImageBtn').click(); 
            document.getElementById('charCount').textContent='0 / 280'; 
            document.getElementById('charCount').className='char-count'; 
            document.getElementById('timeline').innerHTML=''; 
            document.getElementById('timeline').insertAdjacentHTML('beforeend', '<div class="loading"><div class="loading-spinner"></div></div>'); 
            offset=0; hasMore=true; setTimeout(()=>{loadTimeline(); this.disabled=false;},1500); 
        }
        else { showError(d.error||'Failed to post'); this.disabled=false; }
    }).catch(()=>{ showError('Network Error'); this.disabled=false; });
});

document.getElementById('quoteBtn').addEventListener('click', function(){
    let msg = document.getElementById('quoteText').value.trim(); if(!msg || msg.length>280 || !cQuoteId) return;
    this.disabled = true; hideError(); let fd = new FormData(); fd.append('message', msg); fd.append('quote_id', cQuoteId); fd.append('quoted_author', cAuthor); fd.append('quoted_text', cText);
    fetch('?ajax=quote',{method:'POST',body:fd}).then(r=>r.json()).then(d=>{
        if(d.success){ closeQuoteModal(); document.getElementById('timeline').innerHTML=''; document.getElementById('timeline').insertAdjacentHTML('beforeend', '<div class="loading"><div class="loading-spinner"></div></div>'); offset=0; hasMore=true; setTimeout(()=>{loadTimeline(); this.disabled=false;},1500); }
        else { showError(d.error||'Quote Failed'); this.disabled=false; }
    }).catch(()=>{ showError('Network Error'); this.disabled=false; });
});

function openQuoteModal(id, auth, txt){
    cQuoteId=id; cAuthor=auth; cText=txt; document.getElementById('quoteText').value=''; document.getElementById('quoteCharCount').textContent='0 / 280';
    document.getElementById('quotedPostPreview').innerHTML=`<div class="quoted-post"><div class="post-header"><span class="post-author">${escapeHtml(auth)}</span></div><div class="post-text">${escapeHtml(txt)}</div></div>`;
    document.getElementById('quoteModal').classList.add('active'); hideError();
}
function closeQuoteModal(){ document.getElementById('quoteModal').classList.remove('active'); cQuoteId=null; }
document.getElementById('quoteModal').addEventListener('click', function(e){ if(e.target===this) closeQuoteModal(); });

function loadTimeline(){
    if(loading || !hasMore) return; loading = true;
    fetch('?ajax=timeline&offset='+offset).then(r=>r.json()).then(d=>{
        let tl = document.getElementById('timeline');
        if(offset===0) tl.innerHTML=''; else { let ldr=tl.querySelector('.loading'); if(ldr) ldr.remove(); }
        if(d.error){ showError(d.error); loading=false; return; }
        if(d.messages && d.messages.length>0){
            d.messages.forEach(m => tl.appendChild(createPost(m))); offset+=d.messages.length;
            if(d.messages.length<<?=POSTS_PER_PAGE?>){ hasMore=false; tl.insertAdjacentHTML('beforeend', '<div class="no-more">No more posts</div>'); }
        } else { hasMore=false; tl.insertAdjacentHTML('beforeend', offset===0?'<div class="no-more">No posts yet!</div>':'<div class="no-more">No more posts</div>'); }
        loading=false;
    }).catch(()=>{ showError('Error loading timeline'); loading=false; });
}

function createPost(m){
    let p=document.createElement('div'); p.className='post'; let auth=m.displayName||m.nick||'Anon', txt=m.message||'';
    let qH=''; if(m.quotedPost && m.quotedPost.text){
        qH=`<div class="quoted-post"><div class="quoted-post-content"><img src="${m.quotedPost.avatar}" class="avatar"><div class="quoted-post-body"><div class="post-header"><span class="post-author">${escapeHtml(m.quotedPost.author)}</span></div><div class="post-text">${escapeHtml(m.quotedPost.text)}</div></div></div></div>`;
    }
    let imgH = m.attachedImage || '';
    
    // 🚀 LOGIKA URL DINAMIS (Me vs User)
    let profileUrl = (m.authorPk === MY_PK && MY_PK !== '') ? 'me.php' : 'user.php?pk=' + encodeURIComponent(m.authorPk);
    
    p.innerHTML=`<div class="post-content"><img src="${m.avatar}" class="avatar"><div class="post-body"><div class="post-header"><a href="${profileUrl}" class="author-link"><span class="post-author">${escapeHtml(auth)}</span></a><span class="post-handle">·</span><span class="post-time">${new Date(m.dateTime).toLocaleString()}</span></div><div class="post-text">${escapeHtml(txt)}</div>${imgH}${qH}<div class="post-actions"><button class="action-btn btn-quote" data-id="${escapeHtmlStrict(m.id)}" data-auth="${escapeHtmlStrict(auth)}" data-txt="${escapeHtmlStrict(txt)}">Quote Reply</button></div></div></div>`;
    
    let btn = p.querySelector('.btn-quote'); if(btn) btn.addEventListener('click', function(){ openQuoteModal(this.getAttribute('data-id'), this.getAttribute('data-auth'), this.getAttribute('data-txt')); });
    return p;
}
window.addEventListener('scroll', function(){ if((window.innerHeight+window.scrollY)>=document.body.offsetHeight-500) { if(!loading && hasMore){ document.getElementById('timeline').insertAdjacentHTML('beforeend', '<div class="loading"><div class="loading-spinner"></div></div>'); loadTimeline(); } } });

loadTimeline();
</script>
</body>
</html>