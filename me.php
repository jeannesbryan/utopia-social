<?php
session_start();

// Redirect to login if not logged in
if (!isset($_SESSION['token'])) {
    header('Location: index.php');
    exit;
}

// Configuration
define('UTOPIA_API_URL', 'http://127.0.0.1:20000/api/1.0');
define('CHANNEL_ID', '2F5F675D31CA664E102AFDF061516AE3');
define('POSTS_PER_PAGE', 50);

// Helper function to make API calls
function callUtopiaAPI($method, $params = [], $token = null) {
    $token = $token ?? $_SESSION['token'] ?? null;
    
    $data = [
        'method' => $method,
        'token' => $token
    ];
    
    if (!empty($params)) {
        $data['params'] = $params;
    }
    
    $ch = curl_init(UTOPIA_API_URL);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);
    
    if ($curlError) {
        return ['error' => 'Connection error: ' . $curlError];
    }
    
    if ($httpCode !== 200) {
        return ['error' => 'API returned status code: ' . $httpCode];
    }
    
    if (empty($response)) {
        return ['error' => 'Empty response from API'];
    }
    
    $decoded = json_decode($response, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        return ['error' => 'Invalid JSON from API: ' . json_last_error_msg()];
    }
    
    return $decoded;
}

// Generate default avatar SVG
function generateDefaultAvatar($text) {
    $hash = md5($text);
    $color = '#' . substr($hash, 0, 6);
    $initial = strtoupper(substr($text, 0, 1));
    
    return '<svg width="48" height="48" xmlns="http://www.w3.org/2000/svg"><rect width="48" height="48" fill="' . $color . '"/><text x="50%" y="50%" dominant-baseline="middle" text-anchor="middle" font-size="24" fill="white" font-family="Arial">' . $initial . '</text></svg>';
}

// Handle AJAX requests
if (isset($_GET['ajax'])) {
    header('Content-Type: application/json');
    
    if ($_GET['ajax'] === 'my_posts') {
        $offset = isset($_GET['offset']) ? intval($_GET['offset']) : 0;
        
        // Get all channel messages
        $result = callUtopiaAPI('getChannelMessages', [
            'channelid' => CHANNEL_ID
        ]);
        
        if (isset($result['result']) && is_array($result['result'])) {
            $messages = $result['result'];
            
            // Filter only user's posts (compare hashedPk or pk)
            $myHashedPk = $_SESSION['hashed_pk'] ?? '';
            $myPk = $_SESSION['pk'] ?? '';
            
            $myMessages = array_filter($messages, function($msg) use ($myHashedPk, $myPk) {
                $msgHashedPk = isset($msg['hashedPk']) ? $msg['hashedPk'] : '';
                $msgPk = isset($msg['pk']) ? $msg['pk'] : '';
                
                return ($msgHashedPk === $myHashedPk && !empty($myHashedPk)) || 
                       ($msgPk === $myPk && !empty($myPk));
            });
            
            // Sort messages by date (newest first)
            usort($myMessages, function($a, $b) {
                $timeA = isset($a['dateTime']) ? strtotime($a['dateTime']) : 0;
                $timeB = isset($b['dateTime']) ? strtotime($b['dateTime']) : 0;
                return $timeB - $timeA;
            });
            
            // Apply offset and limit
            $myMessages = array_slice($myMessages, $offset, POSTS_PER_PAGE);
            
            // Get user avatar
            $avatarResult = callUtopiaAPI('getAvatarByKey', [
                'pk' => $myPk,
                'coder' => 'BASE64',
                'format' => 'JPG'
            ]);
            
            $avatar = 'data:image/svg+xml;base64,' . base64_encode(generateDefaultAvatar($_SESSION['nick']));
            if (isset($avatarResult['result']) && !empty($avatarResult['result'])) {
                $avatar = 'data:image/jpeg;base64,' . $avatarResult['result'];
            }
            
            // Process messages
            foreach ($myMessages as &$msg) {
                $msg['displayName'] = $_SESSION['uns'] ?? $_SESSION['nick'];
                $msg['avatar'] = $avatar;
                
                // Get message text based on messageType
                $messageType = isset($msg['messageType']) ? $msg['messageType'] : 1;
                
                if ($messageType === 6) {
                    // Quote-reply message
                    if (isset($msg['metaData']['data']['text'])) {
                        $msg['message'] = $msg['metaData']['data']['text'];
                    }
                    
                    // Handle quoted post
                    if (isset($msg['metaData']['data'])) {
                        $metaData = $msg['metaData']['data'];
                        $msg['quotedPost'] = [
                            'author' => isset($metaData['nick']) ? $metaData['nick'] : 'User',
                            'text' => isset($metaData['quote']) ? $metaData['quote'] : ''
                        ];
                    }
                } else {
                    // Normal message
                    $msg['message'] = isset($msg['text']) ? $msg['text'] : '';
                    $msg['quotedPost'] = null;
                }
                
                if (!isset($msg['id'])) {
                    $msg['id'] = uniqid();
                }
            }
            
            echo json_encode(['messages' => array_values($myMessages), 'count' => count($myMessages)]);
        } else {
            echo json_encode(['messages' => [], 'count' => 0, 'error' => 'Unable to load messages']);
        }
        exit;
    }
}

// Get user info
$contactInfo = callUtopiaAPI('getOwnContact');
if (isset($contactInfo['result'])) {
    $_SESSION['hashed_pk'] = isset($contactInfo['result']['hashedPk']) ? $contactInfo['result']['hashedPk'] : '';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Profile - Utopia Social</title>
    <link rel="icon" type="image/svg+xml" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'%3E%3Crect width='100' height='100' fill='%2300ff41'/%3E%3Ctext x='50' y='50' font-size='45' font-weight='bold' fill='%23000000' text-anchor='middle' dominant-baseline='central' font-family='Arial, sans-serif'%3EUS%3C/text%3E%3C/svg%3E">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
            background: #000;
            color: #e7e9ea;
            line-height: 1.5;
        }
        
        .container {
            max-width: 600px;
            margin: 0 auto;
            min-height: 100vh;
            border-left: 1px solid #2f3336;
            border-right: 1px solid #2f3336;
        }
        
        /* Header */
        .header {
            position: sticky;
            top: 0;
            background: rgba(0, 0, 0, 0.85);
            backdrop-filter: blur(12px);
            border-bottom: 1px solid #2f3336;
            padding: 16px;
            z-index: 100;
        }
        
        .header-content {
            display: flex;
            align-items: center;
            gap: 16px;
        }
        
        .header-left {
            display: flex;
            align-items: center;
            gap: 16px;
            flex: 1;
        }
        
        .back-btn {
            width: 36px;
            height: 36px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: transparent;
            border: none;
            color: #e7e9ea;
            font-size: 20px;
            cursor: pointer;
            border-radius: 50%;
            transition: background 0.2s;
            flex-shrink: 0;
        }
        
        .back-btn:hover {
            background: rgba(255, 255, 255, 0.1);
        }
        
        .header-title {
            flex: 1;
        }
        
        .header-name {
            font-size: 20px;
            font-weight: 700;
        }
        
        .header-count {
            color: #71767b;
            font-size: 13px;
        }
        
        /* Profile Banner */
        .profile-banner {
            padding: 24px 16px;
            border-bottom: 1px solid #2f3336;
        }
        
        .profile-info {
            display: flex;
            align-items: center;
            gap: 16px;
            margin-bottom: 16px;
        }
        
        .profile-avatar {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            object-fit: cover;
            border: 4px solid #000;
        }
        
        .profile-details {
            flex: 1;
        }
        
        .profile-name {
            font-size: 24px;
            font-weight: 700;
            margin-bottom: 4px;
        }
        
        .profile-handle {
            color: #71767b;
            font-size: 15px;
            cursor: pointer;
            user-select: all;
            transition: color 0.2s;
            word-break: break-all;
        }
        
        .profile-handle:hover {
            color: #00ff41;
        }
        
        .profile-handle:active {
            color: #00cc34;
        }
        
        /* Timeline */
        .timeline {
            padding-bottom: 60px;
        }
        
        .post {
            border-bottom: 1px solid #2f3336;
            padding: 16px;
            transition: background 0.2s;
        }
        
        .post:hover {
            background: rgba(255, 255, 255, 0.03);
        }
        
        .post-content {
            display: flex;
            gap: 12px;
        }
        
        .avatar {
            width: 48px;
            height: 48px;
            border-radius: 50%;
            object-fit: cover;
        }
        
        .post-body {
            flex: 1;
            min-width: 0;
        }
        
        .post-header {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 4px;
        }
        
        .post-author {
            font-weight: 700;
            font-size: 15px;
        }
        
        .post-handle {
            color: #71767b;
            font-size: 15px;
        }
        
        .post-time {
            color: #71767b;
            font-size: 15px;
        }
        
        .post-text {
            font-size: 15px;
            margin-bottom: 12px;
            word-wrap: break-word;
        }
        
        .quoted-post {
            border: 1px solid #2f3336;
            border-radius: 12px;
            padding: 12px;
            margin-top: 12px;
            background: rgba(255, 255, 255, 0.02);
        }
        
        .quoted-post .post-header {
            margin-bottom: 8px;
        }
        
        .quoted-post .post-text {
            margin-bottom: 0;
            color: #e7e9ea;
            font-size: 14px;
        }
        
        .loading {
            text-align: center;
            padding: 40px;
            color: #71767b;
        }
        
        .loading-spinner {
            display: inline-block;
            width: 24px;
            height: 24px;
            border: 3px solid #2f3336;
            border-top-color: #00ff41;
            border-radius: 50%;
            animation: spin 0.8s linear infinite;
        }
        
        @keyframes spin {
            to { transform: rotate(360deg); }
        }
        
        .no-more {
            text-align: center;
            padding: 20px;
            color: #71767b;
            font-size: 14px;
        }
        
        .logout-btn {
            padding: 8px 16px;
            background: transparent;
            color: #f4212e;
            border: 1px solid #f4212e;
            border-radius: 24px;
            font-size: 14px;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.2s;
            text-decoration: none;
            flex-shrink: 0;
        }
        
        .logout-btn:hover {
            background: rgba(244, 33, 46, 0.1);
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- Header -->
        <div class="header">
            <div class="header-content">
                <div class="header-left">
                    <button class="back-btn" onclick="window.location.href='index.php'">←</button>
                    <div class="header-title">
                        <div class="header-name"><?php echo htmlspecialchars($_SESSION['uns'] ?? $_SESSION['nick']); ?></div>
                        <div class="header-count" id="postCount">0 posts</div>
                    </div>
                </div>
                <a href="index.php?action=logout" class="logout-btn">Logout</a>
            </div>
        </div>
        
        <!-- Profile Banner -->
        <div class="profile-banner">
            <div class="profile-info">
                <img 
                    src="data:image/svg+xml;base64,<?php echo base64_encode(generateDefaultAvatar($_SESSION['nick'])); ?>" 
                    alt="Avatar" 
                    class="profile-avatar"
                    id="profileAvatar"
                >
                <div class="profile-details">
                    <div class="profile-name">
                        <?php echo htmlspecialchars($_SESSION['uns'] ?? $_SESSION['nick']); ?>
                    </div>
                    <div class="profile-handle" onclick="copyToClipboard('<?php echo htmlspecialchars($_SESSION['pk']); ?>')" title="Click to copy">
                        <?php echo htmlspecialchars($_SESSION['pk']); ?>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Timeline -->
        <div class="timeline" id="timeline">
            <div class="loading">
                <div class="loading-spinner"></div>
            </div>
        </div>
    </div>
    
    <script>
        let offset = 0;
        let loading = false;
        let hasMore = true;
        let totalPosts = 0;
        
        // Copy to clipboard function
        function copyToClipboard(text) {
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(text).then(() => {
                    // Show visual feedback
                    const elem = event.target;
                    const originalColor = elem.style.color;
                    elem.style.color = '#00ff41';
                    setTimeout(() => {
                        elem.style.color = originalColor;
                    }, 300);
                }).catch(err => {
                    console.error('Failed to copy:', err);
                });
            } else {
                // Fallback for older browsers
                const textArea = document.createElement('textarea');
                textArea.value = text;
                textArea.style.position = 'fixed';
                textArea.style.left = '-999999px';
                document.body.appendChild(textArea);
                textArea.select();
                try {
                    document.execCommand('copy');
                    const elem = event.target;
                    const originalColor = elem.style.color;
                    elem.style.color = '#00ff41';
                    setTimeout(() => {
                        elem.style.color = originalColor;
                    }, 300);
                } catch (err) {
                    console.error('Failed to copy:', err);
                }
                document.body.removeChild(textArea);
            }
        }
        
        // Load profile avatar
        fetch('index.php?ajax=avatar&pk=<?php echo $_SESSION['pk']; ?>')
            .then(r => r.json())
            .then(data => {
                if (data.avatar) {
                    document.getElementById('profileAvatar').src = data.avatar;
                }
            });
        
        // Load my posts
        function loadMyPosts() {
            if (loading || !hasMore) return;
            loading = true;
            
            fetch('?ajax=my_posts&offset=' + offset)
                .then(r => {
                    if (!r.ok) {
                        throw new Error('Network response was not ok');
                    }
                    return r.text();
                })
                .then(text => {
                    let data;
                    try {
                        data = JSON.parse(text);
                    } catch (e) {
                        console.error('JSON Parse Error:', e);
                        console.error('Response text:', text);
                        throw new Error('Invalid JSON response');
                    }
                    
                    const timeline = document.getElementById('timeline');
                    
                    if (offset === 0) {
                        timeline.innerHTML = '';
                        totalPosts = 0;
                    } else {
                        const loader = timeline.querySelector('.loading');
                        if (loader) loader.remove();
                    }
                    
                    if (data.error) {
                        console.error('API Error:', data.error);
                        timeline.innerHTML = '<div class="no-more">Error loading posts: ' + escapeHtml(data.error) + '</div>';
                        loading = false;
                        return;
                    }
                    
                    if (data.messages && data.messages.length > 0) {
                        data.messages.forEach(msg => {
                            timeline.appendChild(createPostElement(msg));
                            totalPosts++;
                        });
                        
                        offset += data.messages.length;
                        
                        if (data.messages.length < <?php echo POSTS_PER_PAGE; ?>) {
                            hasMore = false;
                            timeline.innerHTML += '<div class="no-more">No more posts</div>';
                        }
                    } else {
                        if (offset === 0) {
                            timeline.innerHTML = '<div class="no-more">You haven\'t posted anything yet</div>';
                        } else {
                            hasMore = false;
                            timeline.innerHTML += '<div class="no-more">No more posts</div>';
                        }
                    }
                    
                    // Update post count
                    document.getElementById('postCount').textContent = totalPosts + ' post' + (totalPosts !== 1 ? 's' : '');
                    
                    loading = false;
                })
                .catch(err => {
                    console.error('Error loading posts:', err);
                    const timeline = document.getElementById('timeline');
                    timeline.innerHTML = '<div class="no-more">Error loading posts. Please refresh the page.</div>';
                    loading = false;
                });
        }
        
        // Create post element
        function createPostElement(msg) {
            const post = document.createElement('div');
            post.className = 'post';
            
            const time = new Date(msg.dateTime).toLocaleString();
            const author = msg.displayName || msg.nick || 'Anonymous';
            const text = msg.message || msg.text || '';
            
            let quotedHtml = '';
            if (msg.quotedPost && msg.quotedPost.text) {
                quotedHtml = `
                    <div class="quoted-post">
                        <div class="post-header">
                            <span class="post-author">${escapeHtml(msg.quotedPost.author)}</span>
                        </div>
                        <div class="post-text">${escapeHtml(msg.quotedPost.text)}</div>
                    </div>
                `;
            }
            
            post.innerHTML = `
                <div class="post-content">
                    <img src="${msg.avatar}" alt="Avatar" class="avatar">
                    <div class="post-body">
                        <div class="post-header">
                            <span class="post-author">${escapeHtml(author)}</span>
                            <span class="post-handle">·</span>
                            <span class="post-time">${time}</span>
                        </div>
                        <div class="post-text">${escapeHtml(text)}</div>
                        ${quotedHtml}
                    </div>
                </div>
            `;
            
            return post;
        }
        
        // Escape HTML
        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }
        
        // Infinite scroll
        window.addEventListener('scroll', function() {
            if ((window.innerHeight + window.scrollY) >= document.body.offsetHeight - 500) {
                if (!loading && hasMore) {
                    const timeline = document.getElementById('timeline');
                    timeline.innerHTML += '<div class="loading"><div class="loading-spinner"></div></div>';
                    loadMyPosts();
                }
            }
        });
        
        // Initial load
        loadMyPosts();
    </script>
</body>
</html>
