<?php
// 🚀 INTEGRASI LANGSUNG DENGAN CONFIG UTAMA
require_once '../config.php';

if (!isset($_SESSION['token'])) { 
    header('Location: ../index.php'); 
    exit; 
}

// Handler Logout dari Profile
if (isset($_GET['action']) && $_GET['action'] === 'logout') { 
    session_destroy(); 
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

// Tarik Hashed PK sendiri jika belum ada di sesi
if (!isset($_SESSION['hashed_pk']) || empty($_SESSION['hashed_pk'])) {
    $cInfo = callUtopiaAPI('getOwnContact'); 
    if (isset($cInfo['result'])) $_SESSION['hashed_pk'] = $cInfo['result']['hashedPk'] ?? '';
}

// ==========================================
// 🚀 BACKEND AJAX API HANDLER (MY POSTS)
// ==========================================
if (isset($_GET['ajax']) && $_GET['ajax'] === 'my_posts') {
    header('Content-Type: application/json'); 
    $offset = intval($_GET['offset'] ?? 0);
    
    // 🚀 FITUR AUTO-RETRY
    $res = callUtopiaAPI('getChannelMessages', ['channelid' => CHANNEL_ID]);
    if (isset($res['result']) && is_array($res['result']) && empty($res['result'])) {
        usleep(500000); // Jeda 0.5 detik
        $res = callUtopiaAPI('getChannelMessages', ['channelid' => CHANNEL_ID]);
    }
    
    if (!isset($res['result']) || !is_array($res['result'])) { 
        echo json_encode(['messages' => [], 'count' => 0, 'error' => 'API Error']); exit; 
    }
    
    $msgs = $res['result']; 
    $myHashedPk = $_SESSION['hashed_pk'] ?? ''; 
    $myPk = $_SESSION['pk'] ?? '';
    
    $hashToFull = []; 
    $nickToFull = [];
    
    // 1. Bangun Kamus PK dari seluruh riwayat pesan
    foreach ($msgs as $m) {
        $p = $m['pk'] ?? '';
        $h = $m['hashedPk'] ?? '';
        $n = $m['nick'] ?? '';
        if (!empty($p) && strlen($p) === 64) {
            if (!empty($h)) $hashToFull[$h] = $p;
            if (!empty($n)) $nickToFull[$n] = $p;
        }
    }
    
    // 2. Saring hanya pesan milik SAYA
    $myMsgs = array_filter($msgs, function($m) use ($myHashedPk, $myPk, $hashToFull) {
        $p = $m['pk'] ?? '';
        $h = $m['hashedPk'] ?? '';
        $fullP = !empty($p) ? $p : ($hashToFull[$h] ?? $h); // Terapkan Translator
        
        return ($fullP === $myPk && !empty($myPk)) || ($h === $myHashedPk && !empty($myHashedPk));
    });
    
    // Urutkan dan potong sesuai halaman
    usort($myMsgs, function($a, $b) { return strtotime($b['dateTime']??0) - strtotime($a['dateTime']??0); });
    $myMsgs = array_slice($myMsgs, $offset, POSTS_PER_PAGE);
    
    // 3. Kumpulkan PK unik yang perlu ditarik avatarnya (Saya + Orang yang di-quote)
    $uniquePks = [];
    if(!empty($myPk)) $uniquePks[] = $myPk;
    if(!empty($myHashedPk)) $uniquePks[] = $myHashedPk;
    
    foreach ($myMsgs as $m) {
        $qPkRaw = $m['metaData']['data']['hexPublicKey'] ?? '';
        if(!empty($qPkRaw)) {
            $qFull = $hashToFull[$qPkRaw] ?? $qPkRaw; // Terjemahkan Quote PK
            $uniquePks[] = $qFull;
        }
    }
    
    // 4. Eksekusi Tarik Avatar API
    if (!isset($_SESSION['ava_cache'])) $_SESSION['ava_cache'] = [];
    if (!isset($_SESSION['nick_ava_cache'])) $_SESSION['nick_ava_cache'] = [];
    $avaCache = [];
    
    foreach (array_unique($uniquePks) as $pk) {
        if(empty($pk)) continue;
        if (array_key_exists($pk, $_SESSION['ava_cache'])) {
            if ($_SESSION['ava_cache'][$pk] !== null) $avaCache[$pk] = $_SESSION['ava_cache'][$pk];
            continue;
        }
        $ava = callUtopiaAPI('getContactAvatar', ['pk' => $pk, 'coder' => 'BASE64', 'format' => 'JPG']);
        if (empty($ava['result']) || $ava['result'] === "0") $ava = callUtopiaAPI('getAvatarByKey', ['pk' => $pk, 'coder' => 'BASE64', 'format' => 'JPG']);
        if (!empty($ava['result']) && $ava['result'] !== "0") {
            $img = 'data:image/jpeg;base64,'.$ava['result'];
            $avaCache[$pk] = $img;
            $_SESSION['ava_cache'][$pk] = $img;
        } else {
            $_SESSION['ava_cache'][$pk] = null;
        }
    }
    
    // Simpan Nickname SAYA dan orang lain ke cache
    $myName = $_SESSION['uns'] ?? $_SESSION['nick'];
    if(!empty($myName) && !empty($myPk) && isset($avaCache[$myPk])) {
        $_SESSION['nick_ava_cache'][$myName] = $avaCache[$myPk];
    }
    foreach ($nickToFull as $n => $fullP) {
        if (isset($avaCache[$fullP])) {
            $_SESSION['nick_ava_cache'][$n] = $avaCache[$fullP];
        }
    }
    
    $myAva = $avaCache[$myPk] ?? ($avaCache[$myHashedPk] ?? ($_SESSION['nick_ava_cache'][$myName] ?? 'data:image/svg+xml;base64,'.base64_encode(generateDefaultAvatar($myName))));
    
    // 5. Universal Parser Loop
    foreach ($myMsgs as &$m) {
        $m['displayName'] = $myName; 
        $m['avatar'] = $myAva;
        
        $txt = ''; $m['quotedPost'] = null; $m['attachedImage'] = '';
        $mdType = $m['metaData']['type'] ?? '';
        $mdData = $m['metaData']['data'] ?? [];
        
        if ($mdType === 'picture' || isset($mdData['pictureData'])) {
            $picBase64 = $mdData['pictureData'] ?? '';
            $picFmt = $mdData['pictureFormat'] ?? 'png';
            if (!empty($picBase64)) {
                $m['attachedImage'] = '<div style="margin-top:12px; margin-bottom:8px;"><img src="data:image/' . htmlspecialchars($picFmt) . ';base64,' . htmlspecialchars($picBase64) . '" style="max-width:100%; max-height:400px; border-radius:12px; border:1px solid #2f3336; object-fit:cover;"></div>';
            }
            $txt = $mdData['comment'] ?? ($m['text'] ?? ($m['message'] ?? ''));
        } 
        elseif ($mdType === 'text') {
            $txt = $mdData['text'] ?? '';
        } 
        elseif (!empty($mdData['quote'])) {
            $txt = $mdData['text'] ?? ''; 
            $qPkRaw = $mdData['hexPublicKey'] ?? '';
            $qPkFull = $hashToFull[$qPkRaw] ?? $qPkRaw; // 🚀 Translasi Anti-Buta-Huruf
            $qNick = $mdData['nick'] ?? 'User';
            
            $qAva = $avaCache[$qPkFull] ?? ($_SESSION['nick_ava_cache'][$qNick] ?? 'data:image/svg+xml;base64,'.base64_encode(generateDefaultAvatar($qNick)));
            $m['quotedPost'] = ['author' => $qNick, 'text' => $mdData['quote'], 'avatar' => $qAva];
        } 
        else { 
            $txt = $m['text'] ?? ($m['message'] ?? ''); 
        }
        
        // Tangkap Quote Format Klasik Regex (Web)
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
        if (!isset($m['id'])) $m['id'] = uniqid();
    }
    echo json_encode(['messages' => array_values($myMsgs), 'count' => count($myMsgs)]);
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Profile - Utopia Social</title>
    <link rel="icon" type="image/svg+xml" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'%3E%3Crect width='100' height='100' fill='%231d9bf0'/%3E%3Ctext x='50' y='50' font-size='45' font-weight='bold' fill='%23ffffff' text-anchor='middle' dominant-baseline='central' font-family='Arial, sans-serif'%3EU%3C/text%3E%3C/svg%3E">
    <style>
        *{margin:0;padding:0;box-sizing:border-box} body{font-family:-apple-system,BlinkMacSystemFont,sans-serif;background:#000;color:#e7e9ea;line-height:1.5}
        .container{max-width:600px;margin:0 auto;min-height:100vh;border-left:1px solid #2f3336;border-right:1px solid #2f3336}
        .header{position:sticky;top:0;background:rgba(0,0,0,0.85);backdrop-filter:blur(12px);border-bottom:1px solid #2f3336;padding:16px;z-index:100}
        .header-content{display:flex;align-items:center;gap:16px} .header-left{display:flex;align-items:center;gap:16px;flex:1}
        .back-btn{width:36px;height:36px;display:flex;align-items:center;justify-content:center;background:transparent;border:none;color:#e7e9ea;font-size:20px;cursor:pointer;border-radius:50%;transition:background 0.2s;flex-shrink:0} .back-btn:hover{background:rgba(255,255,255,0.1)}
        .header-name{font-size:20px;font-weight:700} .header-count{color:#71767b;font-size:13px}
        .profile-banner{padding:24px 16px;border-bottom:1px solid #2f3336} .profile-info{display:flex;align-items:center;gap:16px;margin-bottom:16px}
        .profile-avatar{width:80px;height:80px;border-radius:50%;object-fit:cover;border:4px solid #000}
        .profile-details{flex:1;}
        .profile-name{font-size:24px;font-weight:700;margin-bottom:4px} .profile-handle{color:#71767b;font-size:15px;cursor:pointer;user-select:all;transition:color 0.2s;word-break:break-all} .profile-handle:hover{color:#1d9bf0}
        
        .timeline{padding-bottom:60px} .post{border-bottom:1px solid #2f3336;padding:16px;transition:background 0.2s} .post:hover{background:rgba(255,255,255,0.03)} .post-content{display:flex;gap:12px} .avatar{width:48px;height:48px;border-radius:50%;object-fit:cover} .post-body{flex:1;min-width:0}
        .post-header{display:flex;align-items:center;gap:8px;margin-bottom:4px} .post-author{font-weight:700;font-size:15px} .post-handle,.post-time{color:#71767b;font-size:15px} .post-text{font-size:15px;margin-bottom:12px;word-wrap:break-word;white-space:pre-wrap}
        .quoted-post{border:1px solid #2f3336;border-radius:12px;padding:12px;margin-top:12px;background:rgba(255,255,255,0.02)} .quoted-post-content{display:flex;gap:12px} .quoted-post .avatar{width:32px;height:32px} .quoted-post-body{flex:1;min-width:0} .quoted-post .post-header{margin-bottom:8px;display:flex;align-items:center;gap:8px} .quoted-post .post-text{margin-bottom:0;color:#e7e9ea;font-size:14px}
        .loading{text-align:center;padding:40px;color:#71767b} .loading-spinner{display:inline-block;width:24px;height:24px;border:3px solid #2f3336;border-top-color:#1d9bf0;border-radius:50%;animation:spin 0.8s linear infinite} @keyframes spin{to{transform:rotate(360deg)}} .no-more{text-align:center;padding:20px;color:#71767b;font-size:14px}
        .logout-btn{padding:8px 16px;background:transparent;color:#f4212e;border:1px solid #f4212e;border-radius:24px;font-size:14px;font-weight:700;cursor:pointer;transition:all 0.2s;text-decoration:none;flex-shrink:0} .logout-btn:hover{background:rgba(244,33,46,0.1)}
    </style>
</head>
<body>
<div class="container">
    <div class="header">
        <div class="header-content">
            <div class="header-left">
                <button class="back-btn" onclick="window.location.href='index.php'">←</button>
                <div class="header-title">
                    <div class="header-name"><?=htmlspecialchars($_SESSION['uns'] ?? $_SESSION['nick'])?></div>
                    <div class="header-count" id="postCount">0 posts</div>
                </div>
            </div>
            <a href="?action=logout" class="logout-btn">Logout</a>
        </div>
    </div>
    
    <div class="profile-banner">
        <div class="profile-info">
            <img src="data:image/svg+xml;base64,<?=base64_encode(generateDefaultAvatar($_SESSION['nick']))?>" class="profile-avatar" id="profileAvatar">
            <div class="profile-details">
                <div class="profile-name"><?=htmlspecialchars($_SESSION['uns'] ?? $_SESSION['nick'])?></div>
                <div class="profile-handle" onclick="navigator.clipboard.writeText('<?=htmlspecialchars($_SESSION['pk'])?>')"><?=htmlspecialchars($_SESSION['pk'])?></div>
            </div>
        </div>
    </div>
    
    <div class="timeline" id="timeline"><div class="loading"><div class="loading-spinner"></div></div></div>
</div>

<script>
let offset=0, loading=false, hasMore=true, totalPosts=0;
function escapeHtml(t) { let d=document.createElement('div'); d.textContent=t; return d.innerHTML; }

// Fetch My Avatar
fetch('index.php?ajax=avatar&pk=<?=$_SESSION['pk']?>').then(r=>r.json()).then(d=>{
    if(d.avatar) document.getElementById('profileAvatar').src=d.avatar;
});

function loadMyPosts(){
    if(loading || !hasMore) return; loading = true;
    fetch('?ajax=my_posts&offset='+offset).then(r=>r.json()).then(d=>{
        let tl = document.getElementById('timeline');
        if(offset===0){ tl.innerHTML=''; totalPosts=0; } else { let ldr=tl.querySelector('.loading'); if(ldr) ldr.remove(); }
        if(d.error){ tl.insertAdjacentHTML('beforeend', '<div class="no-more">Error: '+escapeHtml(d.error)+'</div>'); loading=false; return; }
        if(d.messages && d.messages.length>0){
            d.messages.forEach(m => { tl.appendChild(createPost(m)); totalPosts++; }); offset+=d.messages.length;
            if(d.messages.length<<?=POSTS_PER_PAGE?>){ hasMore=false; tl.insertAdjacentHTML('beforeend', '<div class="no-more">No more posts</div>'); }
        } else { hasMore=false; tl.insertAdjacentHTML('beforeend', offset===0?'<div class="no-more">You haven\'t posted anything yet</div>':'<div class="no-more">No more posts</div>'); }
        document.getElementById('postCount').textContent = totalPosts + ' post' + (totalPosts!==1?'s':''); loading=false;
    }).catch(()=>{ document.getElementById('timeline').insertAdjacentHTML('beforeend', '<div class="no-more">Error loading posts</div>'); loading=false; });
}

function createPost(m){
    let p=document.createElement('div'); p.className='post'; let auth=m.displayName||m.nick||'Anon', txt=m.message||'';
    let qH=''; if(m.quotedPost && m.quotedPost.text){ qH=`<div class="quoted-post"><div class="quoted-post-content"><img src="${m.quotedPost.avatar}" class="avatar" style="width:32px;height:32px;"><div class="quoted-post-body"><div class="post-header"><span class="post-author">${escapeHtml(m.quotedPost.author)}</span></div><div class="post-text">${escapeHtml(m.quotedPost.text)}</div></div></div></div>`; }
    
    let imgH = m.attachedImage || '';
    
    p.innerHTML=`<div class="post-content"><img src="${m.avatar}" class="avatar"><div class="post-body"><div class="post-header"><span class="post-author">${escapeHtml(auth)}</span><span class="post-handle">·</span><span class="post-time">${new Date(m.dateTime).toLocaleString()}</span></div><div class="post-text">${escapeHtml(txt)}</div>${imgH}${qH}</div></div>`;
    return p;
}

window.addEventListener('scroll', function(){ if((window.innerHeight+window.scrollY)>=document.body.offsetHeight-500) { if(!loading && hasMore){ document.getElementById('timeline').insertAdjacentHTML('beforeend', '<div class="loading"><div class="loading-spinner"></div></div>'); loadMyPosts(); } } });

loadMyPosts();
</script>
</body>
</html>