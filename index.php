<?php
// 🚀 Call API Core (Config)
require_once 'config.php';

// Logout Feature
if (isset($_GET['action']) && $_GET['action'] === 'logout') { 
    session_destroy(); 
    header('Location: index.php'); 
    exit; 
}

// If already logged in, redirect to Dashboard
if (isset($_SESSION['token'])) {
    header('Location: dashboard.php');
    exit;
}

// Login Process Logic
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login'])) {
    $token = trim($_POST['token']); 
    
    // Check if token is valid by calling profile status
    $res = callUtopiaAPI('getProfileStatus', [], $token);
    
    if (isset($res['result'])) {
        $_SESSION['token'] = $token; 
        
        // Fetch user profile data to store in session
        $contact = callUtopiaAPI('getOwnContact');
        if (isset($contact['result'])) {
            $_SESSION['pk'] = $contact['result']['pk']; 
            $_SESSION['hashed_pk'] = $contact['result']['hashedPk'] ?? '';
            $_SESSION['nick'] = $contact['result']['nick'];
            
            // Track uNS name if available
            $uns = callUtopiaAPI('unsSearchByPk', ['filter' => $contact['result']['pk']]);
            $_SESSION['uns'] = $uns['result'][0]['name'] ?? null; 
            
            // Join default channel in the background to prepare for later
            callUtopiaAPI('joinChannel', ['ident' => CHANNEL_ID, 'password' => '']);
        }
        
        // LOGIN SUCCESS! Redirect to Dashboard
        header('Location: dashboard.php'); 
        exit;
    } else { 
        $loginError = 'Invalid API Token. Please ensure the Utopia client is running!'; 
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - Wetopia Super App</title>
    <link rel="icon" type="image/svg+xml" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'%3E%3Crect width='100' height='100' fill='%2300ff41'/%3E%3Ctext x='50' y='50' font-size='45' font-weight='bold' fill='%23000000' text-anchor='middle' dominant-baseline='central' font-family='Arial, sans-serif'%3EW%3C/text%3E%3C/svg%3E">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; background: #000; color: #e7e9ea; height: 100vh; display: flex; align-items: center; justify-content: center; }
        
        .login-container { width: 100%; display: flex; justify-content: center; padding: 20px; } 
        .login-box { width: 100%; max-width: 400px; background: #16181c; border: 1px solid #2f3336; border-radius: 16px; padding: 40px; box-shadow: 0 10px 30px rgba(0,0,0,0.5); }
        
        .login-logo { display: flex; justify-content: center; margin-bottom: 24px; }
        .login-logo svg { width: 60px; height: 60px; }
        
        .login-title { font-size: 28px; font-weight: 700; margin-bottom: 8px; text-align: center; color: #e7e9ea; }
        .login-subtitle { font-size: 15px; color: #71767b; text-align: center; margin-bottom: 30px; }
        
        .form-group { margin-bottom: 20px; } 
        label { display: block; margin-bottom: 8px; font-size: 14px; font-weight: 600; color: #71767b; }
        
        input[type="text"] { width: 100%; padding: 14px; background: #000; border: 1px solid #2f3336; border-radius: 8px; color: #e7e9ea; font-size: 15px; font-family: monospace; letter-spacing: 1px; transition: border-color 0.2s; } 
        input[type="text"]:focus { outline: none; border-color: #00ff41; }
        
        .btn { width: 100%; padding: 14px; background: #00ff41; color: #000; border: none; border-radius: 24px; font-size: 16px; font-weight: 700; cursor: pointer; transition: background 0.2s, transform 0.1s; } 
        .btn:hover { background: #00cc34; } 
        .btn:active { transform: scale(0.98); }
        
        .error-box { color: #f4212e; padding: 12px; background: rgba(244,33,46,0.1); border: 1px solid rgba(244,33,46,0.5); border-radius: 8px; font-size: 14px; margin-bottom: 20px; text-align: center; }
    </style>
</head>
<body>

<div class="login-container">
    <div class="login-box">
        <div class="login-logo">
            <svg viewBox="0 0 100 100" xmlns="http://www.w3.org/2000/svg">
                <rect width="100" height="100" rx="20" fill="#00ff41"/>
                <text x="50" y="50" font-size="45" font-weight="bold" fill="#000" text-anchor="middle" dominant-baseline="central" font-family="Arial">W</text>
            </svg>
        </div>
        
        <h1 class="login-title">Wetopia</h1>
        <div class="login-subtitle">Decentralized Web3 Super App</div>
        
        <?php if (isset($loginError)): ?>
            <div class="error-box"><?=htmlspecialchars($loginError)?></div>
        <?php endif; ?>
        
        <form method="POST">
            <div class="form-group">
                <label>REST API TOKEN</label>
                <input type="text" id="token" name="token" required autocomplete="off" placeholder="Paste your token here...">
            </div>
            <button type="submit" name="login" class="btn">Connect to Node</button>
        </form>
    </div>
</div>

</body>
</html>