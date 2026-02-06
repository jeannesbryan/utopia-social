<?php
session_start();

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

// Handle logout
if (isset($_GET['action']) && $_GET['action'] === 'logout') {
    session_destroy();
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

// Handle login
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login'])) {
    $token = trim($_POST['token']);
    
    // Verify token by getting profile status
    $result = callUtopiaAPI('getProfileStatus', [], $token);
    
    if (isset($result['result'])) {
        $_SESSION['token'] = $token;
        
        // Get own contact info
        $contactInfo = callUtopiaAPI('getOwnContact');
        if (isset($contactInfo['result'])) {
            $_SESSION['pk'] = $contactInfo['result']['pk'];
            $_SESSION['nick'] = $contactInfo['result']['nick'];
            
            // Try to get uNS name
            $unsResult = callUtopiaAPI('unsSearchByPk', ['filter' => $contactInfo['result']['pk']]);
            $_SESSION['uns'] = isset($unsResult['result'][0]['name']) ? $unsResult['result'][0]['name'] : null;
            
            // Auto-join channel if not already joined
            callUtopiaAPI('joinChannel', ['ident' => CHANNEL_ID, 'password' => '']);
        }
        
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    } else {
        $loginError = 'Invalid token. Please check and try again.';
    }
}

// Handle AJAX requests
if (isset($_GET['ajax'])) {
    header('Content-Type: application/json');
    
    if ($_GET['ajax'] === 'post' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $message = trim($_POST['message']);
        
        if (strlen($message) > 140) {
            echo json_encode(['error' => 'Message exceeds 140 characters']);
            exit;
        }
        
        if (empty($message)) {
            echo json_encode(['error' => 'Message cannot be empty']);
            exit;
        }
        
        $result = callUtopiaAPI('sendChannelMessage', [
            'channelid' => CHANNEL_ID,
            'message' => $message
        ]);
        
        echo json_encode(['success' => isset($result['result'])]);
        exit;
    }
    
    if ($_GET['ajax'] === 'quote' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $message = trim($_POST['message']);
        $quoteId = $_POST['quote_id'];
        $quotedText = isset($_POST['quoted_text']) ? trim($_POST['quoted_text']) : '';
        $quotedAuthor = isset($_POST['quoted_author']) ? trim($_POST['quoted_author']) : '';
        
        if (strlen($message) > 140) {
            echo json_encode(['error' => 'Message exceeds 140 characters']);
            exit;
        }
        
        if (empty($message)) {
            echo json_encode(['error' => 'Message cannot be empty']);
            exit;
        }
        
        // Get the actual message to see its structure
        $channelMessages = callUtopiaAPI('getChannelMessages', [
            'channelid' => CHANNEL_ID
        ]);
        
        $targetMessage = null;
        if (isset($channelMessages['result'])) {
            foreach ($channelMessages['result'] as $msg) {
                if (isset($msg['id']) && strval($msg['id']) === strval($quoteId)) {
                    $targetMessage = $msg;
                    break;
                }
            }
        }
        
        // Check if target is a quote-reply itself (messageType: 6)
        if ($targetMessage && isset($targetMessage['messageType']) && $targetMessage['messageType'] == 6) {
            // This is already a quote, try to find the original message using tid
            if (isset($targetMessage['metaData']['data']['tid'])) {
                $originalTopicId = $targetMessage['metaData']['data']['tid'];
                // Find the original message
                foreach ($channelMessages['result'] as $msg) {
                    if (isset($msg['topicId']) && strval($msg['topicId']) === strval($originalTopicId)) {
                        $targetMessage = $msg;
                        break;
                    }
                }
            }
        }
        
        // Use topicId for quoting
        $idToQuote = isset($targetMessage['topicId']) ? $targetMessage['topicId'] : $quoteId;
        
        // Try the official sendChannelQuote method
        $result = callUtopiaAPI('sendChannelQuote', [
            'channelid' => CHANNEL_ID,
            'text' => $message,
            'id_message' => strval($idToQuote)
        ]);
        
        $success = isset($result['result']) && $result['result'] !== "0" && $result['result'] !== 0 && !empty($result['result']);
        
        // If sendChannelQuote fails, try posting as a regular message with formatted quote
        if (!$success) {
            // Format message like: "Your reply\n\n> @author: quoted text"
            $formattedMessage = $message . "\n\n> @" . $quotedAuthor . ": " . $quotedText;
            
            // Truncate if too long
            if (strlen($formattedMessage) > 140) {
                $formattedMessage = substr($message, 0, 137) . "...";
            }
            
            $fallbackResult = callUtopiaAPI('sendChannelMessage', [
                'channelid' => CHANNEL_ID,
                'message' => $formattedMessage
            ]);
            
            $fallbackSuccess = isset($fallbackResult['result']) && !empty($fallbackResult['result']);
            
            echo json_encode([
                'success' => $fallbackSuccess, 
                'method_used' => $fallbackSuccess ? 'fallback_formatted_message' : 'failed',
                'api_response' => $result,
                'fallback_response' => $fallbackResult,
                'formatted_message' => $formattedMessage
            ]);
        } else {
            echo json_encode([
                'success' => true, 
                'method_used' => 'sendChannelQuote',
                'api_response' => $result
            ]);
        }
        exit;
    }
    
    if ($_GET['ajax'] === 'timeline') {
        $offset = isset($_GET['offset']) ? intval($_GET['offset']) : 0;
        
        // Get messages without complex filter
        $result = callUtopiaAPI('getChannelMessages', [
            'channelid' => CHANNEL_ID
        ]);
        
        if (isset($result['result']) && is_array($result['result'])) {
            $messages = $result['result'];
            
            // Create a lookup map for messages by ID for quote-reply resolution
            $messageMap = [];
            foreach ($messages as $msg) {
                $msgId = isset($msg['id']) ? $msg['id'] : null;
                if ($msgId) {
                    $messageMap[$msgId] = $msg;
                    $messageMap[strval($msgId)] = $msg;
                }
            }
            
            // Sort messages by date (newest first)
            usort($messages, function($a, $b) {
                $timeA = isset($a['dateTime']) ? strtotime($a['dateTime']) : 0;
                $timeB = isset($b['dateTime']) ? strtotime($b['dateTime']) : 0;
                return $timeB - $timeA;
            });
            
            // Apply offset and limit manually
            $messages = array_slice($messages, $offset, POSTS_PER_PAGE);
            
            // Collect unique public keys for avatar fetching
            $uniquePks = [];
            $pkMap = []; // Map hashed PK to full PK
            
            foreach ($messages as $msg) {
                // Collect main message author PK
                $pk = isset($msg['pk']) ? $msg['pk'] : null;
                $hashedPk = isset($msg['hashedPk']) ? $msg['hashedPk'] : null;
                
                if ($pk && !empty($pk) && !in_array($pk, $uniquePks)) {
                    $uniquePks[] = $pk;
                }
                
                if ($hashedPk && !empty($hashedPk)) {
                    if (!in_array($hashedPk, $uniquePks)) {
                        $uniquePks[] = $hashedPk;
                    }
                    // Map hashed to full PK if we have both
                    if ($pk && !empty($pk)) {
                        $pkMap[$hashedPk] = $pk;
                    }
                }
                
                // Collect quoted user PK if exists
                if (isset($msg['metaData']['data']['hexPublicKey'])) {
                    $quotedPk = $msg['metaData']['data']['hexPublicKey'];
                    if (!empty($quotedPk) && !in_array($quotedPk, $uniquePks)) {
                        $uniquePks[] = $quotedPk;
                    }
                }
            }
            
            // Fetch avatars for all unique users
            $avatarCache = [];
            foreach ($uniquePks as $pk) {
                $avatarResult = callUtopiaAPI('getAvatarByKey', [
                    'pk' => $pk,
                    'coder' => 'BASE64',
                    'format' => 'JPG'
                ]);
                
                if (isset($avatarResult['result']) && !empty($avatarResult['result'])) {
                    $avatarCache[$pk] = 'data:image/jpeg;base64,' . $avatarResult['result'];
                }
            }
            
            // Process messages and add avatars
            foreach ($messages as &$msg) {
                // Get author nickname
                $authorNick = isset($msg['nick']) ? $msg['nick'] : 'Anonymous';
                
                // Get author public key - try both pk and hashedPk
                $authorPk = '';
                if (isset($msg['pk']) && !empty($msg['pk'])) {
                    $authorPk = $msg['pk'];
                } elseif (isset($msg['hashedPk']) && !empty($msg['hashedPk'])) {
                    $authorPk = $msg['hashedPk'];
                }
                
                $msg['displayName'] = $authorNick;
                $msg['authorPk'] = $authorPk;
                
                // Set avatar - use cached real avatar or generate default
                if ($authorPk && !empty($authorPk) && isset($avatarCache[$authorPk])) {
                    $msg['avatar'] = $avatarCache[$authorPk];
                } else {
                    $msg['avatar'] = 'data:image/svg+xml;base64,' . base64_encode(generateDefaultAvatar($authorNick));
                }
                
                // Get message text based on messageType
                $messageText = '';
                $messageType = isset($msg['messageType']) ? $msg['messageType'] : 1;
                
                // Initialize quotedPost
                $msg['quotedPost'] = null;
                
                if ($messageType === 6) {
                    // Quote-reply message (official format)
                    if (isset($msg['metaData']['data']['text'])) {
                        $messageText = $msg['metaData']['data']['text'];
                    }
                    
                    // Handle official quote-reply metadata
                    if (isset($msg['metaData']['data'])) {
                        $metaData = $msg['metaData']['data'];
                        
                        // The quoted text and author are directly in metaData
                        $quotedText = isset($metaData['quote']) ? $metaData['quote'] : '';
                        $quotedAuthor = isset($metaData['nick']) ? $metaData['nick'] : 'User';
                        $quotedPk = isset($metaData['hexPublicKey']) ? $metaData['hexPublicKey'] : '';
                        $quotedTid = isset($metaData['tid']) ? $metaData['tid'] : '';
                        
                        // Try to get the full PK if we have mapping from hashed to full
                        if (isset($pkMap[$quotedPk])) {
                            $quotedPk = $pkMap[$quotedPk];
                        }
                        
                        // Try to find the original message to get the date
                        $quotedDate = null;
                        if ($quotedTid) {
                            foreach ($messages as $origMsg) {
                                $origTid = isset($origMsg['topicId']) ? strval($origMsg['topicId']) : '';
                                if ($origTid === strval($quotedTid)) {
                                    $quotedDate = isset($origMsg['dateTime']) ? $origMsg['dateTime'] : null;
                                    break;
                                }
                            }
                        }
                        
                        // Try to get avatar for quoted user
                        $quotedAvatar = null;
                        if ($quotedPk && !empty($quotedPk)) {
                            if (isset($avatarCache[$quotedPk])) {
                                $quotedAvatar = $avatarCache[$quotedPk];
                            } else {
                                $avatarResult = callUtopiaAPI('getAvatarByKey', [
                                    'pk' => $quotedPk,
                                    'coder' => 'BASE64',
                                    'format' => 'JPG'
                                ]);
                                
                                if (isset($avatarResult['result']) && !empty($avatarResult['result'])) {
                                    $quotedAvatar = 'data:image/jpeg;base64,' . $avatarResult['result'];
                                    $avatarCache[$quotedPk] = $quotedAvatar;
                                }
                            }
                        }
                        
                        if (!$quotedAvatar) {
                            $quotedAvatar = 'data:image/svg+xml;base64,' . base64_encode(generateDefaultAvatar($quotedAuthor));
                        }
                        
                        if ($quotedText) {
                            $msg['quotedPost'] = [
                                'author' => $quotedAuthor,
                                'text' => $quotedText,
                                'avatar' => $quotedAvatar,
                                'tid' => $quotedTid,
                                'dateTime' => $quotedDate
                            ];
                        }
                    }
                } else {
                    // Normal message
                    if (isset($msg['text'])) {
                        $messageText = $msg['text'];
                    } elseif (isset($msg['message'])) {
                        $messageText = $msg['message'];
                    }
                    
                    // Debug: Add raw text to message for inspection
                    $msg['_raw_text'] = $messageText;
                    $msg['_has_newline'] = strpos($messageText, "\n") !== false;
                    $msg['_has_quote_marker'] = strpos($messageText, '> @') !== false;
                    
                    // Check if it's a fallback formatted quote (contains "\n\n> @" or actual newlines)
                    // Try with actual newlines first
                    if (preg_match('/^(.*?)\n\n> @([^:]+): (.+)$/s', $messageText, $matches)) {
                        // This is a fallback quote with real newlines
                        $quotedAuthor = trim($matches[2]);
                        
                        // Try to find the quoted user's PK and avatar by searching messages
                        $quotedAvatar = null;
                        $quotedPk = null;
                        foreach ($messages as $searchMsg) {
                            $searchNick = isset($searchMsg['nick']) ? $searchMsg['nick'] : '';
                            if ($searchNick === $quotedAuthor) {
                                $quotedPk = isset($searchMsg['pk']) ? $searchMsg['pk'] : (isset($searchMsg['hashedPk']) ? $searchMsg['hashedPk'] : null);
                                if ($quotedPk && !empty($quotedPk) && isset($avatarCache[$quotedPk])) {
                                    $quotedAvatar = $avatarCache[$quotedPk];
                                    break;
                                }
                            }
                        }
                        
                        // If no avatar found, generate default
                        if (!$quotedAvatar) {
                            $quotedAvatar = 'data:image/svg+xml;base64,' . base64_encode(generateDefaultAvatar($quotedAuthor));
                        }
                        
                        $msg['quotedPost'] = [
                            'author' => $quotedAuthor,
                            'text' => trim($matches[3]),
                            'avatar' => $quotedAvatar
                        ];
                        $messageText = trim($matches[1]); // The reply text
                    }
                    // Try with literal \n characters (in case they're not converted)
                    elseif (strpos($messageText, '> @') !== false && strpos($messageText, "\n") !== false) {
                        // Split by double newline
                        $parts = explode("\n\n", $messageText, 2);
                        if (count($parts) == 2 && strpos($parts[1], '> @') === 0) {
                            $replyText = trim($parts[0]);
                            $quotePart = trim($parts[1]);
                            
                            // Extract author and text from "> @author: text"
                            if (preg_match('/^> @([^:]+): (.+)$/s', $quotePart, $quoteMatches)) {
                                $quotedAuthor = trim($quoteMatches[1]);
                                
                                // Try to find the quoted user's PK and avatar
                                $quotedAvatar = null;
                                $quotedPk = null;
                                foreach ($messages as $searchMsg) {
                                    $searchNick = isset($searchMsg['nick']) ? $searchMsg['nick'] : '';
                                    if ($searchNick === $quotedAuthor) {
                                        $quotedPk = isset($searchMsg['pk']) ? $searchMsg['pk'] : (isset($searchMsg['hashedPk']) ? $searchMsg['hashedPk'] : null);
                                        if ($quotedPk && !empty($quotedPk) && isset($avatarCache[$quotedPk])) {
                                            $quotedAvatar = $avatarCache[$quotedPk];
                                            break;
                                        }
                                    }
                                }
                                
                                if (!$quotedAvatar) {
                                    $quotedAvatar = 'data:image/svg+xml;base64,' . base64_encode(generateDefaultAvatar($quotedAuthor));
                                }
                                
                                $msg['quotedPost'] = [
                                    'author' => $quotedAuthor,
                                    'text' => trim($quoteMatches[2]),
                                    'avatar' => $quotedAvatar
                                ];
                                $messageText = $replyText;
                            }
                        }
                    }
                }
                
                $msg['message'] = $messageText;
                
                // Add raw data for debugging
                $msg['_debug_fields'] = array_keys($msg);
            }
            
            echo json_encode(['messages' => $messages, 'count' => count($messages)]);
        } else {
            // Debug: include the full result
            $errorMsg = 'Unable to load messages';
            if (isset($result['error'])) {
                $errorMsg = $result['error'];
            } elseif (!isset($result['result'])) {
                $errorMsg = 'No result from API';
            }
            echo json_encode(['messages' => [], 'count' => 0, 'error' => $errorMsg, 'debug' => $result]);
        }
        exit;
    }
    
    if ($_GET['ajax'] === 'debug_message') {
        $messageId = isset($_GET['id']) ? $_GET['id'] : null;
        
        $result = callUtopiaAPI('getChannelMessages', [
            'channelid' => CHANNEL_ID
        ]);
        
        $targetMsg = null;
        if (isset($result['result'])) {
            foreach ($result['result'] as $msg) {
                if (isset($msg['id']) && strval($msg['id']) === strval($messageId)) {
                    $targetMsg = $msg;
                    break;
                }
            }
        }
        
        echo json_encode([
            'message' => $targetMsg,
            'text_raw' => isset($targetMsg['text']) ? $targetMsg['text'] : null,
            'text_with_escape' => isset($targetMsg['text']) ? addslashes($targetMsg['text']) : null,
            'contains_newline' => isset($targetMsg['text']) ? (strpos($targetMsg['text'], "\n") !== false) : false,
            'contains_quote_marker' => isset($targetMsg['text']) ? (strpos($targetMsg['text'], '> @') !== false) : false
        ]);
        exit;
    }
    
    if ($_GET['ajax'] === 'avatar') {
        $pk = $_GET['pk'];
        $result = callUtopiaAPI('getAvatarByKey', [
            'pk' => $pk,
            'coder' => 'BASE64',
            'format' => 'JPG'
        ]);
        
        if (isset($result['result']) && !empty($result['result'])) {
            echo json_encode(['avatar' => 'data:image/jpeg;base64,' . $result['result']]);
        } else {
            echo json_encode(['avatar' => null]);
        }
        exit;
    }
    
    if ($_GET['ajax'] === 'debug') {
        // Debug endpoint to see raw message structure
        $result = callUtopiaAPI('getChannelMessages', [
            'channelid' => CHANNEL_ID
        ]);
        
        if (isset($result['result']) && is_array($result['result']) && count($result['result']) > 0) {
            // Return first message to see its structure
            echo json_encode(['sample' => $result['result'][0], 'all_fields' => array_keys($result['result'][0])]);
        } else {
            echo json_encode(['error' => 'No messages available', 'result' => $result]);
        }
        exit;
    }
}

// Generate default avatar SVG
function generateDefaultAvatar($text) {
    $hash = md5($text);
    $color = '#' . substr($hash, 0, 6);
    $initial = strtoupper(substr($text, 0, 1));
    
    return '<svg width="48" height="48" xmlns="http://www.w3.org/2000/svg"><rect width="48" height="48" fill="' . $color . '"/><text x="50%" y="50%" dominant-baseline="middle" text-anchor="middle" font-size="24" fill="white" font-family="Arial">' . $initial . '</text></svg>';
}

// Check if logged in
$isLoggedIn = isset($_SESSION['token']);

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Utopia Social</title>
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
        
        /* Login Page */
        .login-container {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
            padding: 20px;
        }
        
        .login-box {
            width: 100%;
            max-width: 400px;
            background: #16181c;
            border: 1px solid #2f3336;
            border-radius: 16px;
            padding: 40px;
        }
        
        .login-title {
            font-size: 31px;
            font-weight: 700;
            margin-bottom: 30px;
            text-align: center;
        }
        
        .form-group {
            margin-bottom: 20px;
        }
        
        label {
            display: block;
            margin-bottom: 8px;
            font-size: 15px;
            color: #71767b;
        }
        
        input[type="text"], textarea {
            width: 100%;
            padding: 12px;
            background: #000;
            border: 1px solid #2f3336;
            border-radius: 4px;
            color: #e7e9ea;
            font-size: 15px;
            font-family: inherit;
        }
        
        input[type="text"]:focus, textarea:focus {
            outline: none;
            border-color: #1d9bf0;
        }
        
        .btn {
            width: 100%;
            padding: 12px;
            background: #00ff41;
            color: #000;
            border: none;
            border-radius: 24px;
            font-size: 15px;
            font-weight: 700;
            cursor: pointer;
            transition: background 0.2s;
        }
        
        .btn:hover {
            background: #00cc34;
        }
        
        .btn:disabled {
            background: #00ff41;
            opacity: 0.5;
            cursor: not-allowed;
        }
        
        .error {
            color: #f4212e;
            margin-bottom: 20px;
            padding: 12px;
            background: rgba(244, 33, 46, 0.1);
            border-radius: 4px;
            font-size: 14px;
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
            flex-direction: column;
            gap: 12px;
        }
        
        .header-top {
            display: flex;
            align-items: center;
            gap: 12px;
        }
        
        .user-info {
            display: flex;
            align-items: center;
            gap: 12px;
            flex: 1;
        }
        
        .avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            object-fit: cover;
        }
        
        .user-details {
            display: flex;
            flex-direction: column;
            flex: 1;
            min-width: 0;
        }
        
        .user-name {
            font-weight: 700;
            font-size: 15px;
        }
        
        .user-handle {
            color: #71767b;
            font-size: 13px;
            cursor: pointer;
            user-select: all;
            transition: color 0.2s;
            word-break: break-all;
            line-height: 1.3;
        }
        
        .user-handle:hover {
            color: #00ff41;
        }
        
        .user-handle:active {
            color: #00cc34;
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
            align-self: flex-end;
            width: fit-content;
        }
        
        .logout-btn:hover {
            background: rgba(244, 33, 46, 0.1);
        }
        
        /* Post Composer */
        .composer {
            border-bottom: 1px solid #2f3336;
            padding: 16px;
        }
        
        .composer-input {
            width: 100%;
        }
        
        textarea {
            resize: none;
            min-height: 80px;
            margin-bottom: 12px;
        }
        
        .composer-footer {
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .char-count {
            color: #71767b;
            font-size: 14px;
        }
        
        .char-count.warning {
            color: #ffd400;
        }
        
        .char-count.error {
            color: #f4212e;
        }
        
        .btn-post {
            padding: 8px 24px;
            background: #00ff41;
            color: #000;
            border: none;
            border-radius: 24px;
            font-size: 15px;
            font-weight: 700;
            cursor: pointer;
            transition: background 0.2s;
        }
        
        .btn-post:hover {
            background: #00cc34;
        }
        
        .btn-post:disabled {
            opacity: 0.5;
            cursor: not-allowed;
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
        
        .quoted-post-content {
            display: flex;
            gap: 12px;
        }
        
        .quoted-post .avatar {
            width: 32px;
            height: 32px;
        }
        
        .quoted-post-body {
            flex: 1;
            min-width: 0;
        }
        
        .quoted-post .post-header {
            margin-bottom: 8px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .quoted-post .post-text {
            margin-bottom: 0;
            color: #e7e9ea;
            font-size: 14px;
        }
        
        .quoted-post .post-time {
            margin-left: auto;
        }
        
        .post-actions {
            display: flex;
            gap: 16px;
            margin-top: 12px;
        }
        
        .action-btn {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 4px 8px;
            background: transparent;
            color: #71767b;
            border: 1px solid #2f3336;
            border-radius: 16px;
            font-size: 13px;
            cursor: pointer;
            transition: all 0.2s;
        }
        
        .action-btn:hover {
            background: rgba(0, 255, 65, 0.1);
            color: #00ff41;
            border-color: #00ff41;
        }
        
        /* Quote Modal */
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.8);
            z-index: 1000;
            align-items: center;
            justify-content: center;
        }
        
        .modal.active {
            display: flex;
        }
        
        .modal-content {
            background: #16181c;
            border-radius: 16px;
            padding: 24px;
            max-width: 500px;
            width: 90%;
            max-height: 90vh;
            overflow-y: auto;
        }
        
        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }
        
        .modal-title {
            font-size: 20px;
            font-weight: 700;
        }
        
        .close-btn {
            background: transparent;
            border: none;
            color: #e7e9ea;
            font-size: 24px;
            cursor: pointer;
            width: 32px;
            height: 32px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
            transition: background 0.2s;
        }
        
        .close-btn:hover {
            background: rgba(255, 255, 255, 0.1);
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
    </style>
</head>
<body>
    <?php if (!$isLoggedIn): ?>
        <!-- Login Page -->
        <div class="login-container">
            <div class="login-box">
                <h1 class="login-title">Utopia Social</h1>
                <?php if (isset($loginError)): ?>
                    <div class="error"><?php echo htmlspecialchars($loginError); ?></div>
                <?php endif; ?>
                <form method="POST">
                    <div class="form-group">
                        <label for="token">API Token</label>
                        <input 
                            type="text" 
                            id="token" 
                            name="token" 
                            placeholder="Enter your Utopia API token" 
                            required
                            autocomplete="off"
                        >
                    </div>
                    <button type="submit" name="login" class="btn">Sign In</button>
                </form>
            </div>
        </div>
    <?php else: ?>
        <!-- Main Application -->
        <div class="container">
            <!-- Header -->
            <div class="header">
                <div class="header-content">
                    <div class="header-top">
                        <div class="user-info" onclick="window.location.href='me.php'" style="cursor: pointer;">
                            <img 
                                src="data:image/svg+xml;base64,<?php echo base64_encode(generateDefaultAvatar($_SESSION['nick'])); ?>" 
                                alt="Avatar" 
                                class="avatar"
                                id="userAvatar"
                            >
                            <div class="user-details">
                                <div class="user-name">
                                    <?php echo htmlspecialchars($_SESSION['uns'] ?? $_SESSION['nick']); ?>
                                </div>
                                <div class="user-handle" onclick="event.stopPropagation(); copyToClipboard('<?php echo htmlspecialchars($_SESSION['pk']); ?>')" title="Click to copy">
                                    <?php echo htmlspecialchars($_SESSION['pk']); ?>
                                </div>
                            </div>
                        </div>
                    </div>
                    <a href="?action=logout" class="logout-btn">Logout</a>
                </div>
            </div>
            
            <!-- Post Composer -->
            <div class="composer">
                <div class="composer-input">
                    <textarea 
                        id="postText" 
                        placeholder="What's happening?"
                        maxlength="140"
                    ></textarea>
                    <div class="composer-footer">
                        <span class="char-count" id="charCount">0 / 140</span>
                        <button class="btn-post" id="postBtn">Post</button>
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
        
        <!-- Quote Modal -->
        <div class="modal" id="quoteModal">
            <div class="modal-content">
                <div class="modal-header">
                    <h2 class="modal-title">Quote Post</h2>
                    <button class="close-btn" onclick="closeQuoteModal()">&times;</button>
                </div>
                <div id="quotedPostPreview"></div>
                <textarea 
                    id="quoteText" 
                    placeholder="Add your comment..."
                    maxlength="140"
                    style="margin-top: 16px;"
                ></textarea>
                <div class="composer-footer" style="margin-top: 12px;">
                    <span class="char-count" id="quoteCharCount">0 / 140</span>
                    <button class="btn-post" id="quoteBtn">Post Quote</button>
                </div>
            </div>
        </div>
        
        <script>
            let offset = 0;
            let loading = false;
            let hasMore = true;
            let currentQuoteId = null;
            let currentQuotedAuthor = '';
            let currentQuotedText = '';
            
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
            
            // Load user's avatar
            fetch('?ajax=avatar&pk=<?php echo $_SESSION['pk']; ?>')
                .then(r => r.json())
                .then(data => {
                    if (data.avatar) {
                        document.getElementById('userAvatar').src = data.avatar;
                    }
                });
            
            // Character counter for post
            document.getElementById('postText').addEventListener('input', function() {
                const count = this.value.length;
                const counter = document.getElementById('charCount');
                counter.textContent = count + ' / 140';
                
                if (count > 120) {
                    counter.className = 'char-count warning';
                }
                if (count > 135) {
                    counter.className = 'char-count error';
                }
                if (count <= 120) {
                    counter.className = 'char-count';
                }
                
                document.getElementById('postBtn').disabled = count === 0 || count > 140;
            });
            
            // Character counter for quote
            document.getElementById('quoteText').addEventListener('input', function() {
                const count = this.value.length;
                const counter = document.getElementById('quoteCharCount');
                counter.textContent = count + ' / 140';
                
                if (count > 120) {
                    counter.className = 'char-count warning';
                }
                if (count > 135) {
                    counter.className = 'char-count error';
                }
                if (count <= 120) {
                    counter.className = 'char-count';
                }
                
                document.getElementById('quoteBtn').disabled = count === 0 || count > 140;
            });
            
            // Post new message
            document.getElementById('postBtn').addEventListener('click', function() {
                const message = document.getElementById('postText').value.trim();
                if (!message || message.length > 140) return;
                
                this.disabled = true;
                const postBtn = this;
                
                const formData = new FormData();
                formData.append('message', message);
                
                fetch('?ajax=post', {
                    method: 'POST',
                    body: formData
                })
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        document.getElementById('postText').value = '';
                        document.getElementById('charCount').textContent = '0 / 140';
                        document.getElementById('charCount').className = 'char-count';
                        
                        // Show loading message
                        document.getElementById('timeline').innerHTML = '<div class="loading"><div class="loading-spinner"></div><div style="margin-top: 16px; color: #71767b;">Posting your message...</div></div>';
                        
                        // Reset timeline and reload after 2 seconds
                        offset = 0;
                        hasMore = true;
                        
                        setTimeout(() => {
                            loadTimeline();
                            postBtn.disabled = false;
                        }, 2000);
                    } else {
                        alert(data.error || 'Failed to post');
                        postBtn.disabled = false;
                    }
                })
                .catch(err => {
                    alert('Error posting message');
                    postBtn.disabled = false;
                });
            });
            
            // Post quote
            document.getElementById('quoteBtn').addEventListener('click', function() {
                const message = document.getElementById('quoteText').value.trim();
                if (!message || message.length > 140 || !currentQuoteId) return;
                
                this.disabled = true;
                const quoteBtn = this;
                
                console.log('Posting quote with ID:', currentQuoteId, 'Message:', message);
                
                const formData = new FormData();
                formData.append('message', message);
                formData.append('quote_id', currentQuoteId);
                formData.append('quoted_author', currentQuotedAuthor);
                formData.append('quoted_text', currentQuotedText);
                
                fetch('?ajax=quote', {
                    method: 'POST',
                    body: formData
                })
                .then(r => r.json())
                .then(data => {
                    console.log('Quote response:', data);
                    
                    if (data.success) {
                        closeQuoteModal();
                        
                        // Show loading message
                        document.getElementById('timeline').innerHTML = '<div class="loading"><div class="loading-spinner"></div><div style="margin-top: 16px; color: #71767b;">Posting your quote...</div></div>';
                        
                        // Reset timeline and reload after 2 seconds
                        offset = 0;
                        hasMore = true;
                        
                        setTimeout(() => {
                            loadTimeline();
                            quoteBtn.disabled = false;
                        }, 2000);
                    } else {
                        console.error('Quote failed:', data);
                        alert(data.error || 'Failed to post quote. ' + (data.method_used === 'fallback_formatted_message' ? 'Posted as formatted message.' : 'Check console for details.'));
                        quoteBtn.disabled = false;
                    }
                })
                .catch(err => {
                    console.error('Error posting quote:', err);
                    alert('Error posting quote');
                    quoteBtn.disabled = false;
                });
            });
            
            // Open quote modal
            function openQuoteModal(messageId, author, text) {
                currentQuoteId = messageId;
                currentQuotedAuthor = author;
                currentQuotedText = text;
                
                document.getElementById('quoteText').value = '';
                document.getElementById('quoteCharCount').textContent = '0 / 140';
                
                console.log('Opening quote modal with message ID:', messageId);
                
                document.getElementById('quotedPostPreview').innerHTML = `
                    <div class="quoted-post">
                        <div class="post-header">
                            <span class="post-author">${escapeHtml(author)}</span>
                        </div>
                        <div class="post-text">${escapeHtml(text)}</div>
                    </div>
                `;
                
                document.getElementById('quoteModal').classList.add('active');
            }
            
            // Close quote modal
            function closeQuoteModal() {
                document.getElementById('quoteModal').classList.remove('active');
                currentQuoteId = null;
            }
            
            // Close modal on outside click
            document.getElementById('quoteModal').addEventListener('click', function(e) {
                if (e.target === this) {
                    closeQuoteModal();
                }
            });
            
            // Load timeline
            function loadTimeline() {
                if (loading || !hasMore) return;
                loading = true;
                
                fetch('?ajax=timeline&offset=' + offset)
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
                        } else {
                            const loader = timeline.querySelector('.loading');
                            if (loader) loader.remove();
                        }
                        
                        if (data.error) {
                            console.error('API Error:', data.error);
                            if (data.debug) {
                                console.error('Debug info:', data.debug);
                            }
                            timeline.innerHTML = '<div class="no-more">Error loading posts: ' + escapeHtml(data.error) + '</div>';
                            loading = false;
                            return;
                        }
                        
                        if (data.messages && data.messages.length > 0) {
                            data.messages.forEach(msg => {
                                timeline.appendChild(createPostElement(msg));
                            });
                            
                            offset += data.messages.length;
                            
                            if (data.messages.length < <?php echo POSTS_PER_PAGE; ?>) {
                                hasMore = false;
                                timeline.innerHTML += '<div class="no-more">No more posts</div>';
                            }
                        } else {
                            if (offset === 0) {
                                timeline.innerHTML = '<div class="no-more">No posts yet. Be the first to post!</div>';
                            } else {
                                hasMore = false;
                                timeline.innerHTML += '<div class="no-more">No more posts</div>';
                            }
                        }
                        
                        loading = false;
                    })
                    .catch(err => {
                        console.error('Error loading timeline:', err);
                        const timeline = document.getElementById('timeline');
                        timeline.innerHTML = '<div class="no-more">Error loading timeline. Please refresh the page.</div>';
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
                const topicId = msg.topicId || msg.id;
                
                let quotedHtml = '';
                if (msg.quotedPost && msg.quotedPost.text) {
                    const quotedTime = msg.quotedPost.dateTime ? new Date(msg.quotedPost.dateTime).toLocaleString() : '';
                    const quotedTimeHtml = quotedTime ? `<span class="post-handle">·</span><span class="post-time">${quotedTime}</span>` : '';
                    
                    quotedHtml = `
                        <div class="quoted-post">
                            <div class="quoted-post-content">
                                <img src="${msg.quotedPost.avatar}" alt="Avatar" class="avatar" onerror="this.src='data:image/svg+xml;base64,${btoa(generateDefaultAvatarSvg(msg.quotedPost.author))}'">
                                <div class="quoted-post-body">
                                    <div class="post-header">
                                        <span class="post-author">${escapeHtml(msg.quotedPost.author)}</span>
                                        ${quotedTimeHtml}
                                    </div>
                                    <div class="post-text">${escapeHtml(msg.quotedPost.text)}</div>
                                </div>
                            </div>
                        </div>
                    `;
                }
                
                post.innerHTML = `
                    <div class="post-content">
                        <img src="${msg.avatar}" alt="Avatar" class="avatar" onerror="this.src='data:image/svg+xml;base64,${btoa(generateDefaultAvatarSvg(author))}'">
                        <div class="post-body">
                            <div class="post-header">
                                <span class="post-author">${escapeHtml(author)}</span>
                                <span class="post-handle">·</span>
                                <span class="post-time">${time}</span>
                            </div>
                            <div class="post-text">${escapeHtml(text)}</div>
                            ${quotedHtml}
                            <div class="post-actions">
                                <button class="action-btn" onclick="openQuoteModal('${msg.id}', '${escapeHtml(author)}', '${escapeHtml(text).replace(/'/g, "\\'")}')">
                                    Quote Reply
                                </button>
                            </div>
                        </div>
                    </div>
                `;
                
                return post;
            }
            
            // Generate default avatar SVG in JavaScript
            function generateDefaultAvatarSvg(text) {
                const hash = text.split('').reduce((acc, char) => {
                    return char.charCodeAt(0) + ((acc << 5) - acc);
                }, 0);
                const color = '#' + ((hash & 0x00FFFFFF) >>> 0).toString(16).padStart(6, '0');
                const initial = text.charAt(0).toUpperCase();
                return `<svg width="48" height="48" xmlns="http://www.w3.org/2000/svg"><rect width="48" height="48" fill="${color}"/><text x="50%" y="50%" dominant-baseline="middle" text-anchor="middle" font-size="24" fill="white" font-family="Arial">${initial}</text></svg>`;
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
                        loadTimeline();
                    }
                }
            });
            
            // Initial load
            loadTimeline();
        </script>
    <?php endif; ?>
</body>
</html>
