<?php
error_reporting(0);
ini_set('display_errors', 0);
date_default_timezone_set('Asia/Tehran');

// افزایش محدودیت‌ها برای آپلود فایل
@ini_set('upload_max_filesize', '128M');
@ini_set('post_max_size', '128M');
@ini_set('memory_limit', '256M');

/* ================= CONFIG ================= */
$config = [
    'password'       => '14001404', 
    'refresh_rate'   => 20000, 
    'base_file'      => 'chat_history',
    'users_file'     => 'chat_users.json',
    'upload_dir'     => 'uploads',
    'max_messages'   => 50,
    'use_whitelist'  => false,
    'whitelist'      => ['09120000000'],
    'rooms' => [
        'general' => 'گفتگوی عمومی',
        'news'    => 'اخبار و اطلاعیه ها',
        'docs'    => 'فایل جزوات'
        ]
];

/* ================= BACKEND ================= */
function getJson($path) {
    if (!file_exists($path)) return [];
    $data = file_get_contents($path);
    return json_decode($data, true) ?? [];
}

function saveJson($path, $data) {
    file_put_contents($path, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $action = $_POST['action'] ?? '';
    
    $roomKey = $_POST['room'] ?? array_key_first($config['rooms']);
    if (!array_key_exists($roomKey, $config['rooms'])) {
        $roomKey = array_key_first($config['rooms']);
    }

    $dataFile = __DIR__ . '/' . $config['base_file'] . '_' . $roomKey . '.json';
    $usersFile = __DIR__ . '/' . $config['users_file'];

    // --- AUTH ---
    if ($action === 'logout') {
        setcookie('chat_token', '', time() - 3600, "/", "", false, true);
        exit(json_encode(['status'=>'success']));
    }

    if ($action === 'login') {
        $sysPass = $_POST['password'] ?? '';
        $mobile = $_POST['mobile'] ?? '';
        $name = trim($_POST['name'] ?? '');
        $userPass = $_POST['user_password'] ?? '';
        
        if ($sysPass !== $config['password']) exit(json_encode(['status'=>'error', 'msg'=>'رمز عبور چت روم اشتباه است']));
        if ($config['use_whitelist'] && !in_array($mobile, $config['whitelist'])) exit(json_encode(['status'=>'error', 'msg'=>'شماره مجاز نیست']));

        $users = getJson($usersFile);
        $userData = $users[$mobile] ?? null;

        if (is_string($userData)) { $userData = ['name' => $userData]; }

        if ($userData && isset($userData['pass_hash'])) {
            if (empty($userPass)) exit(json_encode(['status'=>'need_pass', 'msg'=>'لطفا رمز عبور خود را وارد کنید']));
            if (!password_verify($userPass, $userData['pass_hash'])) exit(json_encode(['status'=>'error', 'msg'=>'رمز عبور شما اشتباه است']));
            $finalName = $userData['name'];
        } else {
            if (empty($name) || empty($userPass)) exit(json_encode(['status'=>'need_register', 'msg'=>'لطفا نام نمایشی و یک رمز عبور جدید تعیین کنید']));
            $users[$mobile] = [
                'name' => $name,
                'pass_hash' => password_hash($userPass, PASSWORD_DEFAULT),
                'last_login' => date('Y-m-d H:i:s')
            ];
            saveJson($usersFile, $users);
            $finalName = $name;
        }

        $token = base64_encode(json_encode(['mobile' => $mobile, 'name' => $finalName]));
        setcookie('chat_token', $token, time() + (12 * 3600), "/", "", false, true);
        exit(json_encode(['status'=>'success', 'user'=>['mobile'=>$mobile, 'name'=>$finalName]]));
    }

    $authMobile = ''; $authName = '';
    if (isset($_COOKIE['chat_token'])) {
        $tokenData = json_decode(base64_decode($_COOKIE['chat_token']), true);
        if ($tokenData && (!$config['use_whitelist'] || in_array($tokenData['mobile'], $config['whitelist']))) {
            $authMobile = $tokenData['mobile'];
            $authName = $tokenData['name'];
        }
    }

    if (!$authMobile && $action !== 'check_auth') exit(json_encode(['status'=>'auth_error']));
    if ($action === 'check_auth') exit(json_encode(['status'=> $authMobile ? 'logged_in' : 'logged_out', 'user'=>['mobile'=>$authMobile, 'name'=>$authName]]));

    // --- MESSAGES ---
    if ($action === 'fetch') {
        exit(json_encode(['messages' => getJson($dataFile), 'current_user_mobile' => $authMobile]));
    }

    if ($action === 'send') {
        $msgText = $_POST['message'] ?? '';
        $replyToId = $_POST['reply_to_id'] ?? null;
        
        $uploadedFiles = [];
        $hasFile = false;

        // Handle File Uploads
        if (isset($_FILES['files'])) {
            $upDir = __DIR__ . '/' . $config['upload_dir'];
            if (!is_dir($upDir)) mkdir($upDir, 0755, true);

            $files = $_FILES['files'];
            if (!is_array($files['name'])) {
                $count = 1;
                $files = [
                    'name' => [$files['name']],
                    'type' => [$files['type']],
                    'tmp_name' => [$files['tmp_name']],
                    'error' => [$files['error']],
                    'size' => [$files['size']]
                ];
            } else {
                $count = count($files['name']);
            }

            for ($i = 0; $i < $count; $i++) {
                if ($files['error'][$i] === UPLOAD_ERR_OK) {
                    $hasFile = true;
                    $name = $files['name'][$i];
                    $tmp = $files['tmp_name'][$i];
                    $size = $files['size'][$i];
                    
                    $ext = pathinfo($name, PATHINFO_EXTENSION);
                    if (empty($ext) || $name === 'voice_record.webm') $ext = 'webm'; 

                    $newName = time() . '_' . rand(1000,9999) . '_' . $i . '.' . $ext;
                    
                    if (move_uploaded_file($tmp, $upDir . '/' . $newName)) {
                        $uploadedFiles[] = [
                            'name' => ($name === 'voice_record.webm') ? 'پیام صوتی' : $name,
                            'path' => $config['upload_dir'] . '/' . $newName,
                            'is_image' => in_array(strtolower($ext), ['jpg', 'jpeg', 'png', 'gif', 'webp']),
                            'is_audio' => in_array(strtolower($ext), ['mp3', 'wav', 'ogg', 'webm', 'm4a']),
                            'size' => round($size/1024) . ' KB'
                        ];
                    }
                }
            }
        }

        if (trim($msgText) === '' && !$hasFile) exit(json_encode(['status'=>'empty']));

        $msgs = getJson($dataFile);
        
        $replyInfo = null;
        if ($replyToId) {
            foreach ($msgs as $m) {
                if ($m['id'] == $replyToId) {
                    $replyInfo = [
                        'id' => $m['id'],
                        'name' => $m['name'],
                        'preview' => mb_substr($m['msg'], 0, 50) . (mb_strlen($m['msg'])>50 ? '...' : ''),
                        'has_file' => !empty($m['files']) || !empty($m['file'])
                    ];
                    break;
                }
            }
        }

        if (count($msgs) >= $config['max_messages']) {
            rename($dataFile, __DIR__ . '/' . $config['base_file'] . '_' . $roomKey . '_archive_' . date('Ymd_His') . '.json');
            $msgs = [['id'=>time(), 'mobile'=>'sys', 'name'=>'System', 'msg'=>'--- آرشیو شد ---', 'time'=>date('H:i'), 'date'=>date('Y-m-d'), 'type'=>'system']];
        }

        $msgs[] = [
            'id' => time() . rand(1000,9999),
            'mobile' => $authMobile,
            'name' => $authName,
            'msg' => $msgText,
            'files' => $uploadedFiles,
            'reply_to' => $replyInfo,
            'time' => date('H:i'),
            'date' => date('Y-m-d'),
            'type' => 'normal',
            'reactions' => []
        ];
        saveJson($dataFile, $msgs);
        exit(json_encode(['status'=>'success']));
    }

    // --- ACTIONS ---
    $msgs = getJson($dataFile);
    $id = $_POST['id'] ?? '';
    $foundKey = null;

    foreach ($msgs as $k => $m) {
        if ($m['id'] == $id) { $foundKey = $k; break; }
    }

    if ($foundKey !== null) {
        if ($action === 'delete' && $msgs[$foundKey]['mobile'] === $authMobile) {
            array_splice($msgs, $foundKey, 1);
            saveJson($dataFile, $msgs);
            exit(json_encode(['status'=>'success']));
        }
        if ($action === 'edit' && $msgs[$foundKey]['mobile'] === $authMobile) {
            $msgs[$foundKey]['msg'] = $_POST['text'];
            $msgs[$foundKey]['edited'] = true;
            saveJson($dataFile, $msgs);
            exit(json_encode(['status'=>'success']));
        }
        if ($action === 'react') {
            $emoji = $_POST['emoji'];
            $currentReactions = $msgs[$foundKey]['reactions'] ?? [];
            if (isset($currentReactions[$authMobile]) && $currentReactions[$authMobile]['emoji'] === $emoji) {
                unset($currentReactions[$authMobile]);
            } else {
                $currentReactions[$authMobile] = ['emoji' => $emoji, 'name' => $authName];
            }
            $msgs[$foundKey]['reactions'] = $currentReactions;
            saveJson($dataFile, $msgs);
            exit(json_encode(['status'=>'success']));
        }
    }
    exit(json_encode(['status'=>'error']));
}
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
    <title>Siterah</title>
    <style>
        @font-face { font-family: 'Alibaba'; src: url('Alibaba.ttf') format('truetype'); }
        
        :root {
            --bg: #0f172a; 
            --chat-bg: #1e293b; 
            --me: #374151; 
            --other: #1f2937; 
            --text: #f1f5f9; 
            --accent: #3b82f6; 
            --border: #334155;
            --msg-me-bg: #2563eb; 
            --msg-other-bg: #334155;
            --msg-text: #f1f5f9;
            --msg-other-text: #e2e8f0;
            --reply-bg: rgba(0,0,0,0.15);
            --shadow: rgba(0,0,0,0.2);
            --active-tab-bg: #2563eb;
            --tab-text: #94a3b8;
            --date-badge-bg: rgba(30, 41, 59, 0.95);
            --ctx-bg: rgba(30, 41, 59, 0.9);
            --link-color: #93c5fd; 
        }

        [data-theme="light"] {
            --bg: #f3f4f6; 
            --chat-bg: #ffffff; 
            --me: #e5e7eb; 
            --other: #f9fafb; 
            --text: #1f2937; 
            --accent: #2563eb; 
            --border: #e5e7eb;
            --msg-me-bg: #e1f5fe; 
            --msg-other-bg: #ffffff;
            --msg-text: #000;
            --msg-other-text: #1f2937;
            --reply-bg: rgba(0,0,0,0.05);
            --shadow: rgba(0,0,0,0.05);
            --active-tab-bg: #e1f5fe;
            --tab-text: #64748b;
            --date-badge-bg: rgba(243, 244, 246, 0.95);
            --ctx-bg: rgba(255, 255, 255, 0.9);
            --link-color: #2563eb;
        }

        /* Prevent Selection - Fixed for Mobile */
        * { 
            box-sizing: border-box; margin: 0; padding: 0; font-family: 'Alibaba', Tahoma, sans-serif; 
            -webkit-tap-highlight-color: transparent;
            -webkit-touch-callout: none; /* iOS Safari disable long press menu */
            -webkit-user-select: none; /* Safari */
            -moz-user-select: none; 
            -ms-user-select: none; 
            user-select: none; 
        }

        /* Allow Selection only in Input Fields */
        input, textarea { 
            -webkit-user-select: text;
            user-select: text;
        }

        body { 
            background: var(--bg); color: var(--text); height: 100vh; height: 100dvh; overflow: hidden; 
            font-size: 14px; transition: background 0.3s, color 0.3s; 
        }
        
        ::-webkit-scrollbar { width: 8px; }
        ::-webkit-scrollbar-track { background: transparent; }
        ::-webkit-scrollbar-thumb { background: #475569; border-radius: 8px; }

        /* LOGIN */
        #decoy-layer { position: fixed; inset:0; display:flex; flex-direction:column; align-items:center; justify-content:center; background:#fff; z-index:9999; }
        #decoy-input { margin-top:50px; border:1px solid #ddd; padding:8px; width:200px; text-align:center; }
        
        #login-modal { position:fixed; inset:0; background:rgba(0,0,0,0.8); z-index:8000; display:none; align-items:center; justify-content:center; backdrop-filter: blur(2px); }
        .login-box { width:90%; max-width:300px; padding:25px; background:var(--chat-bg); border-radius:12px; text-align:center; color:var(--text); border: 1px solid var(--border); }
        .login-inp { width:100%; padding:12px; margin:10px 0; background:var(--bg); border:1px solid var(--border); border-radius:8px; color:var(--text); text-align:center; }
        .login-btn { width:100%; padding:12px; background:var(--accent); border:none; border-radius:8px; color:white; font-weight:bold; cursor:pointer; }
        
        /* CHAT LAYOUT */
        #chat-layer { display:none; height:100%; flex-direction:column; background:var(--bg); color:var(--text); }
        
        #header-wrapper { padding: 10px 0 0 0; width: 100%; z-index: 10; flex-shrink: 0; display: flex; flex-direction: column; align-items: center; gap: 10px; }
        header { 
            max-width: 800px; 
            margin: 0 auto;
            background:var(--chat-bg); 
            padding:10px 15px; 
            display:flex; 
            justify-content:space-between; 
            align-items:center; 
            border:1px solid var(--border); 
            border-radius: 20px;
            box-shadow: 0 4px 6px -1px var(--shadow);
            width: 95%;
        }

        #room-tabs {
            width: 95%; max-width: 800px;
            display: flex; gap: 8px; overflow-x: auto;
            padding-bottom: 5px; scrollbar-width: none;
        }
        #room-tabs::-webkit-scrollbar { display: none; }
        .room-tab {
            white-space: nowrap; padding: 8px 16px;
            background: var(--chat-bg); color: var(--tab-text);
            border: 1px solid var(--border); border-radius: 12px;
            font-size: 0.85rem; cursor: pointer; transition: 0.2s;
            opacity: 0.8;
        }
        .room-tab.active {
            background: var(--active-tab-bg);
            color: var(--accent);
            border-color: var(--accent);
            font-weight: bold; opacity: 1;
        }
        
        #scroll-area { flex: 1; overflow-y: auto; width: 100%; display: flex; flex-direction: column; }
        #msg-inner { width: 100%; max-width: 800px; margin: 0 auto; padding: 20px 15px; display: flex; flex-direction: column; gap: 10px; padding-bottom: 20px;}

        /* DATE GROUPING */
        .day-group { display: flex; flex-direction: column; gap: 10px; position: relative; }
        
        .date-sticky-header {
            position: sticky;
            top: 0;
            z-index: 5;
            text-align: center;
            padding: 10px 0;
            pointer-events: none;
        }
        
        .date-badge {
            display: inline-block;
            background: var(--date-badge-bg);
            color: var(--text);
            padding: 4px 12px;
            border-radius: 12px;
            font-size: 0.8rem;
            backdrop-filter: blur(8px);
            border: 1px solid var(--border);
            box-shadow: 0 2px 5px var(--shadow);
            pointer-events: auto;
            font-weight: bold;
        }

        .msg-container { display: flex; width: 100%; }
        .msg-container.me { justify-content: flex-end; }
        .msg-container.other { justify-content: flex-start; }
        
        .msg-wrapper { display: flex; align-items: flex-end; gap: 10px; max-width: 85%; position: relative; }
        .msg-container.me .msg-wrapper { flex-direction: row-reverse; } 
        
        .avatar { width: 34px; height: 34px; border-radius: 50%; background: var(--border); display: flex; align-items: center; justify-content: center; flex-shrink: 0; color: var(--text); }
        .avatar svg { width: 20px; height: 20px; fill: var(--text); opacity: 0.5; }

        .msg-bubble { 
            padding: 8px 12px; border-radius: 16px; position: relative; font-size: 0.95rem; line-height: 1.4; 
            min-width: 50px; 
            word-wrap: break-word; white-space: pre-wrap; display: flex; flex-direction: column;
            box-shadow: 0 1px 2px var(--shadow); transition: background 0.3s;
        }
        
        .msg-container.me .msg-bubble { background: var(--msg-me-bg); color: var(--msg-text); border-bottom-left-radius: 4px; }
        .msg-container.other .msg-bubble { background: var(--msg-other-bg); color: var(--msg-other-text); border-bottom-right-radius: 4px; border: 1px solid var(--border); }

        .msg-name { font-size: 0.75rem; font-weight: bold; margin-bottom: 2px; color: var(--accent); }
        .msg-container.me .msg-name { display: none; }

        .msg-footer { display: flex; justify-content: flex-end; align-items: center; margin-top: 4px; opacity: 0.7; font-size: 0.65rem; gap: 5px; }

        .copy-btn {
            background: none; border: none; cursor: pointer; color: inherit; opacity: 0.5; font-size: 0.8rem; padding: 2px;
            transition: 0.2s;
        }
        .copy-btn:hover { opacity: 1; transform: scale(1.1); }
        .copy-btn:active { transform: scale(0.9); }

        .reply-quote {
            border-right: 3px solid var(--accent); background: var(--reply-bg);
            padding: 4px 8px; border-radius: 6px; margin-bottom: 6px;
            font-size: 0.8rem; cursor: pointer; display: flex; flex-direction: column;
            transition: opacity 0.2s;
        }
        .reply-sender { font-weight: bold; margin-bottom: 2px; opacity: 0.9; }
        .reply-text { white-space: nowrap; overflow: hidden; text-overflow: ellipsis; opacity: 0.8; font-size: 0.75rem;}

        /* INPUT AREA */
        #input-wrapper {
            background: linear-gradient(to top, var(--bg) 95%, transparent);
            padding: 10px 0; width: 100%; position: relative; z-index: 50;
            flex-shrink: 0;
            padding-bottom: calc(10px + env(safe-area-inset-bottom));
        }
        #input-container { max-width: 800px; margin: 0 auto; padding: 0 10px; position: relative; }
        #input-box {
            background: var(--chat-bg); border: 1px solid var(--border); border-radius: 20px;
            padding: 8px; display: flex; flex-direction: column; position: relative;
            box-shadow: 0 -2px 10px var(--shadow); 
            gap: 5px;
            min-height: 105px;
            transition: height 0.05s;
        }
        
        #resize-handle { 
            width: 40px; height: 4px; background: #94a3b8; border-radius: 2px; 
            margin: 0 auto 5px auto; cursor: ns-resize; flex-shrink: 0; opacity: 0.5; 
            touch-action: none; display: block; 
        }

        #reply-bar { display: none; background: var(--bg); border-right: 3px solid var(--accent); padding: 8px 12px; margin-bottom: 5px; border-radius: 6px; align-items: center; justify-content: space-between; }

        .input-row-text { width: 100%; display: flex; flex: 1; }
        textarea { 
            width: 100%; background:transparent; border:none; color:var(--text); resize:none; padding:5px; outline:none; font-size:1rem; 
            min-height: 40px; height: 100%;
        }

        .input-row-tools { display: flex; justify-content: space-between; align-items: center; width: 100%; padding-top: 5px; border-top: 1px solid var(--border); flex-shrink: 0; }
        .tools-group { display: flex; gap: 8px; align-items: center; }
        
        .icon-btn { 
            width: 36px; height: 36px; display: flex; align-items: center; justify-content: center; 
            border-radius: 50%; border: none; background: transparent; color: var(--text); 
            opacity: 0.7; cursor: pointer; transition: 0.2s; 
            flex-shrink: 0; font-size: 1.2rem;
        }
        .icon-btn:active { background: var(--bg); opacity: 1; transform: scale(0.95); }
        .send-btn { background: var(--accent); color: white; opacity: 1; border-radius: 12px; width: 40px; height: 40px; }
        
        /* NEW CONTEXT MENU STYLE */
        #ctx-backdrop { position: fixed; inset: 0; z-index: 9999; display: none; }
        #ctx-menu { 
            position: absolute;
            background: var(--ctx-bg); 
            backdrop-filter: blur(12px);
            border: 1px solid var(--border); 
            border-radius: 10px; 
            padding: 6px; 
            display: none; 
            flex-direction: column; 
            gap: 2px; 
            z-index: 10000; 
            box-shadow: 0 4px 20px rgba(0,0,0,0.4); 
            width: 220px; 
            animation: fadeIn 0.15s ease-out;
            transform-origin: top left;
        }
        @keyframes fadeIn { from { opacity: 0; transform: scale(0.95); } to { opacity: 1; transform: scale(1); } }

        .ctx-row { display: grid; grid-template-columns: repeat(5, 1fr); gap: 4px; padding: 4px; border-bottom: 1px solid var(--border); margin-bottom: 4px; }
        .ctx-emoji { font-size: 1.4rem; cursor: pointer; transition: 0.2s; text-align: center; border-radius: 4px; padding: 2px; }
        .ctx-emoji:hover { background: rgba(255,255,255,0.1); transform: scale(1.1); }
        
        .ctx-list-item { 
            padding: 8px 12px; cursor: pointer; border-radius: 6px; font-size: 0.9rem; display: flex; align-items: center; gap: 10px; color: var(--text);
            transition: 0.1s;
        }
        .ctx-list-item:hover { background: var(--accent); color: #fff; }
        .ctx-list-item.delete:hover { background: #ef4444; }
        .ctx-icon { width: 16px; text-align: center; font-size: 1rem; }

        #emoji-grid { 
            position:absolute; bottom:100%; right:0; left: 0; margin: 0 auto; max-width: 800px;
            background:var(--chat-bg); padding:10px; border-radius:12px; display:none; 
            grid-template-columns:repeat(auto-fill, minmax(45px, 1fr)); 
            gap:5px; box-shadow:0 5px 20px var(--shadow); border:1px solid var(--border); margin-bottom: 10px; z-index: 101; 
            max-height: 250px; overflow-y: auto;
        }
        .emoji-item { cursor:pointer; font-size:1.6rem; padding:8px; border-radius:8px; text-align:center; transition: 0.1s;}
        .emoji-item:active { background: var(--bg); transform: scale(0.9); }
        
        .reactions-list { display: flex; flex-wrap: wrap; gap: 4px; margin-top: 6px; }
        .reaction-item { background: rgba(0,0,0,0.1); border-radius: 12px; padding: 2px 8px; font-size: 0.75rem; color: var(--text); display:flex; gap:4px; align-items:center; opacity: 0.8; }
        
        .msg-img { max-width: 100%; border-radius: 8px; margin-bottom: 5px; cursor: pointer; display: block; }
        .msg-audio { max-width: 100%; height: 40px; margin-bottom: 5px; border-radius: 20px; display: block; }
        .msg-link { text-decoration: underline; color: var(--link-color); }
        .file-attachment { display:flex; align-items:center; margin-bottom:5px; text-decoration:none; color:inherit; background:rgba(0,0,0,0.05); padding:8px; border-radius:8px; border:1px solid var(--border); }

        .rec-dot { color: #ef4444; animation: pulse 1s infinite; font-size: 1.2rem; }
        @keyframes pulse { 0% { opacity: 1; } 50% { opacity: 0.5; } 100% { opacity: 1; } }

        @media (max-width: 600px) {
            #input-container { padding: 0 10px; }
            header, #room-tabs { width: 95%; } 
            .msg-wrapper { max-width: 90%; }
            /* Mobile Context Menu (Bottom Sheet) */
            #ctx-menu { 
                position: fixed; top: auto !important; bottom: 0; left: 0; right: 0; 
                width: 100%; border-radius: 20px 20px 0 0; border: none; border-top: 1px solid var(--border);
                transform: none; animation: slideUp 0.2s ease-out; box-shadow: 0 -5px 20px rgba(0,0,0,0.3);
            }
            .input-row-tools { padding-top: 8px; }
        }
        @keyframes slideUp { from { transform: translateY(100%); } to { transform: translateY(0); } }
    </style>
</head>
<body>
    <div id="decoy-layer">
        <h1 style="font-size:4rem;color:#ccc">404</h1>
        <p style="color:#888">Not Found</p>
        <p style="color:#888">راهنمایی برای ورود به سامانه : 09999923069</p>
        <input type="text" id="decoy-input" autocomplete="off">
    </div>

    <div id="login-modal">
        <div class="login-box">
            <h3>Login</h3>
            <p id="login-msg" style="color:#ef4444;font-size:0.8rem;margin:10px 0"></p>
            <input type="tel" id="mobile-inp" class="login-inp" placeholder="شماره موبایل" dir="ltr">
            <div id="register-fields" style="display:none">
                <input type="text" id="name-inp" class="login-inp" placeholder="نام نمایشی (فارسی)">
            </div>
            <div id="password-field">
                <input type="password" id="pass-inp" class="login-inp" placeholder="رمز عبور شخصی" dir="ltr">
            </div>
            <button id="login-btn" class="login-btn">ورود / بررسی</button>
        </div>
    </div>

    <div id="ctx-backdrop" onclick="closeCtx()"></div>
    <div id="ctx-menu">
        <div class="ctx-row">
            <span class="ctx-emoji" onclick="doReact('❤️')">❤️</span>
            <span class="ctx-emoji" onclick="doReact('😂')">😂</span>
            <span class="ctx-emoji" onclick="doReact('😭')">😭</span>
            <span class="ctx-emoji" onclick="doReact('👍')">👍</span>
            <span class="ctx-emoji" onclick="doReact('🔥')">🔥</span>
            <span class="ctx-emoji" onclick="doReact('🌹')">🌹</span>
            <span class="ctx-emoji" onclick="doReact('🙏')">🙏</span>
            <span class="ctx-emoji" onclick="doReact('☺️')">☺️</span>
            <span class="ctx-emoji" onclick="doReact('😅')">😅</span>
            <span class="ctx-emoji" onclick="doReact('😳')">😳</span>
        </div>
        
        <div class="ctx-list-item" onclick="doReply()">
            <span class="ctx-icon">↩️</span> <span>پاسخ</span>
        </div>
        <div class="ctx-list-item" onclick="doCopyText()">
            <span class="ctx-icon">📋</span> <span>کپی متن</span>
        </div>

        <div id="ctx-edit-opts" style="display:none; flex-direction:column;">
            <div class="ctx-list-item" onclick="doEdit()">
                <span class="ctx-icon">✏️</span> <span>ویرایش</span>
            </div>
            <div class="ctx-list-item delete" onclick="doDelete()" style="color:#ef4444;">
                <span class="ctx-icon">🗑</span> <span>حذف پیام</span>
            </div>
        </div>
    </div>

    <div id="chat-layer">
        <div id="header-wrapper">
            <header>
                <div style="display:flex; align-items:center; gap:10px">
                    <button id="theme-btn" class="icon-btn" style="font-size:1.2rem; opacity:1">🌓</button>
                    <span style="font-weight:bold" id="header-name">...</span>
                </div>
                <button onclick="logout()" style="background:none;border:1px solid #ef4444;color:#ef4444;padding:5px 10px;border-radius:6px;cursor:pointer">خروج</button>
            </header>
            
            <div id="room-tabs">
                <?php foreach($config['rooms'] as $key => $label): ?>
                    <div class="room-tab" id="tab-<?php echo $key; ?>" onclick="switchRoom('<?php echo $key; ?>')"><?php echo $label; ?></div>
                <?php endforeach; ?>
            </div>
        </div>

        <div id="scroll-area">
            <div id="msg-inner"></div>
        </div>
            
        <div id="input-wrapper">
            <div id="input-container">
                <div id="emoji-grid"></div>
                <div id="input-box">
                    <div id="resize-handle"></div>

                    <div id="reply-bar">
                        <div style="overflow:hidden">
                            <div id="reply-label" style="font-size:0.7rem; color:var(--accent); font-weight:bold"></div>
                            <div id="reply-content" style="font-size:0.8rem; color:var(--text); opacity:0.7; white-space:nowrap; overflow:hidden; text-overflow:ellipsis"></div>
                        </div>
                        <span style="cursor:pointer; padding:5px; font-size:1.2rem; color:#ef4444" onclick="cancelReply()">×</span>
                    </div>

                    <!-- Recording UI -->
                    <div id="rec-ui" style="display:none; align-items:center; padding:5px; gap:10px; height:100%; width:100%;">
                        <span class="rec-dot">●</span>
                        <span id="rec-time" style="font-variant-numeric: tabular-nums;">00:00</span>
                        <div style="flex:1"></div>
                        <button class="icon-btn" onclick="cancelRec()" style="color:#ef4444; border:1px solid #ef4444; font-size:0.9rem; width:auto; padding:0 10px; border-radius:15px;">لغو</button>
                        <button class="icon-btn" onclick="finishRec()" style="color:#fff; background:#22c55e; font-size:1rem; opacity:1; width:auto; padding:0 10px; border-radius:15px;">ارسال</button>
                    </div>

                    <!-- Normal Input -->
                    <div id="normal-input-group" style="display:flex; flex-direction:column; height:100%;">
                        <div class="input-row-text">
                            <textarea id="msg-text" placeholder="پیام خود را بنویسید..." rows="1"></textarea>
                        </div>
                        
                        <div class="input-row-tools">
                            <div class="tools-group">
                                <button class="icon-btn" id="emoji-toggle">😊</button>
                                <input type="file" id="file-inp" multiple hidden> 
                                <button class="icon-btn" onclick="document.getElementById('file-inp').click()">📎</button>
                                <button class="icon-btn" id="rec-btn" onclick="startRec()">🎤</button>
                                <button class="icon-btn" id="cancel-edit-btn" style="display:none;color:#ef4444">✕</button>
                            </div>
                            <div class="tools-group">
                                <button class="icon-btn send-btn" id="send-btn">➤</button>
                            </div>
                        </div>
                    </div>

                </div>
            </div>
        </div>
    </div>

    <script>
        const CONFIG = { pass: "<?php echo $config['password']; ?>", delay: <?php echo $config['refresh_rate']; ?> };
        const EMOJIS = ["😀","😂","😍","😎","😭","😡","👍","👎","❤️","💔","🤝","🙏","🌹","☺️","😅","😳","✅","❌","🔥"];
        let userMobile = null;
        let chatData = [];
        let editingId = null;
        let replyingTo = null;
        let selectedFiles = []; 
        let ctxTargetId = null;
        let currentRoom = '<?php echo array_key_first($config['rooms']); ?>';
        
        // Voice Logic Vars
        let mediaRec = null;
        let audioChunks = [];
        let recInterval = null;
        let recStartTime = 0;
        let recStream = null;

        const ui = {
            decoyInp: document.getElementById('decoy-input'),
            login: document.getElementById('login-modal'),
            chat: document.getElementById('chat-layer'),
            msgs: document.getElementById('msg-inner'),
            scrollArea: document.getElementById('scroll-area'),
            txt: document.getElementById('msg-text'),
            ctxMenu: document.getElementById('ctx-menu'),
            ctxBackdrop: document.getElementById('ctx-backdrop'),
            emojiGrid: document.getElementById('emoji-grid'),
            inputBox: document.getElementById('input-box'),
            replyBar: document.getElementById('reply-bar'),
            replyLabel: document.getElementById('reply-label'),
            replyContent: document.getElementById('reply-content'),
            themeBtn: document.getElementById('theme-btn'),
            recUi: document.getElementById('rec-ui'),
            normalGroup: document.getElementById('normal-input-group'),
            recTime: document.getElementById('rec-time'),
            roomTabs: document.querySelectorAll('.room-tab')
        };

        function initTheme() {
            const saved = localStorage.getItem('chat_theme') || 'dark';
            setTheme(saved);
        }
        function setTheme(t) {
            document.documentElement.setAttribute('data-theme', t);
            localStorage.setItem('chat_theme', t);
            ui.themeBtn.innerHTML = t === 'dark' ? '🌙' : '☀️';
        }
        ui.themeBtn.onclick = () => {
            const current = document.documentElement.getAttribute('data-theme');
            setTheme(current === 'dark' ? 'light' : 'dark');
        };

        window.onload = () => {
            initTheme();
            checkAuth();
            ui.emojiGrid.innerHTML = EMOJIS.map(e => `<div class="emoji-item">${e}</div>`).join('');
            document.getElementById('emoji-toggle').onclick = (e) => {
                e.stopPropagation();
                ui.emojiGrid.style.display = ui.emojiGrid.style.display === 'grid' ? 'none' : 'grid';
            };
            document.querySelectorAll('.emoji-item').forEach(el => { el.onclick = () => ui.txt.value += el.innerText; });

            document.addEventListener('click', () => { ui.emojiGrid.style.display = 'none'; });
            ui.emojiGrid.onclick = e => e.stopPropagation();
            
            const handle = document.getElementById('resize-handle');
            handle.addEventListener('mousedown', initResize);
            handle.addEventListener('touchstart', initResize);

            switchRoom(currentRoom, false);
        };

        // Resize Logic
        function initResize(e) {
            if(e.cancelable) e.preventDefault();
            const startY = e.clientY || e.touches[0].clientY;
            const startH = ui.inputBox.offsetHeight;
            const doDrag = (e) => {
                const curY = e.clientY || e.touches[0].clientY;
                const diff = startY - curY;
                const newH = Math.min(Math.max(105, startH + diff), 500); 
                ui.inputBox.style.height = newH + 'px';
            };
            const stopDrag = () => {
                document.removeEventListener('mousemove', doDrag); document.removeEventListener('mouseup', stopDrag);
                document.removeEventListener('touchmove', doDrag); document.removeEventListener('touchend', stopDrag);
            };
            document.addEventListener('mousemove', doDrag); document.addEventListener('mouseup', stopDrag);
            document.addEventListener('touchmove', doDrag, {passive: false}); document.addEventListener('touchend', stopDrag);
        }

        function switchRoom(roomId, shouldLoad = true) {
            currentRoom = roomId;
            ui.roomTabs.forEach(tab => tab.classList.remove('active'));
            document.getElementById('tab-' + roomId).classList.add('active');
            
            if(shouldLoad) {
                cancelReply();
                document.getElementById('cancel-edit-btn').click();
                ui.msgs.innerHTML = '<div style="text-align:center;padding:20px;opacity:0.5">در حال بارگذاری اتاق...</div>';
                loadMsgs(true);
            }
        }

        // --- REWRITTEN VOICE LOGIC ---
        async function startRec() {
            if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
                alert('مرورگر شما از ضبط صدا پشتیبانی نمی‌کند یا دسترسی ندارد (HTTPS الزامی است).');
                return;
            }
            try {
                recStream = await navigator.mediaDevices.getUserMedia({ audio: true });
                mediaRec = new MediaRecorder(recStream);
                audioChunks = [];
                
                mediaRec.ondataavailable = e => {
                    if (e.data.size > 0) audioChunks.push(e.data);
                };
                
                mediaRec.start();
                
                ui.normalGroup.style.display = 'none';
                ui.recUi.style.display = 'flex';
                recStartTime = Date.now();
                updateRecTime();
                recInterval = setInterval(updateRecTime, 1000);
            } catch(e) {
                console.error(e);
                alert('خطا در دسترسی به میکروفون: ' + e.message);
            }
        }

        function updateRecTime() {
            const diff = Math.floor((Date.now() - recStartTime) / 1000);
            const m = String(Math.floor(diff / 60)).padStart(2,'0');
            const s = String(diff % 60).padStart(2,'0');
            ui.recTime.innerText = m + ':' + s;
        }

        function cancelRec() {
            stopRecInternal();
            audioChunks = [];
        }

        function finishRec() {
            if (!mediaRec || mediaRec.state === 'inactive') return;
            
            mediaRec.onstop = () => {
                const blob = new Blob(audioChunks, { type: 'audio/webm' });
                // Convert Blob to File object for FormData
                const file = new File([blob], "voice_record.webm", { type: "audio/webm" });
                sendVoice(file);
            };
            
            mediaRec.stop();
            stopRecInternal();
        }

        function stopRecInternal() {
            if (recStream) {
                recStream.getTracks().forEach(track => track.stop());
                recStream = null;
            }
            clearInterval(recInterval);
            ui.recUi.style.display = 'none';
            ui.normalGroup.style.display = 'flex';
        }

        function sendVoice(file) {
            const fd = new FormData();
            fd.append('action', 'send');
            // 'files[]' is key expected by PHP
            fd.append('files[]', file);
            if(replyingTo) fd.append('reply_to_id', replyingTo.id);
            fd.append('room', currentRoom);
            
            // UI Feedback
            const tempBtn = document.querySelector('#rec-ui button:last-child');
            const originalText = tempBtn ? tempBtn.innerText : '';
            if(tempBtn) tempBtn.innerText = '...';

            fetch('', { method: 'POST', body: fd }).then(r=>r.json()).then(d => {
                if(d.status === 'success') { 
                    cancelReply(); 
                    loadMsgs(true); 
                } else {
                    alert(d.msg || 'Error');
                }
            }).catch(e => alert('Upload failed'));
        }

        function checkAuth() {
            post('check_auth', {}).then(d => {
                if(d.status === 'logged_in') {
                    document.getElementById('decoy-layer').style.display = 'none';
                    enterChat(d.user);
                }
            });
        }
        ui.decoyInp.oninput = (e) => {
            if(e.target.value === CONFIG.pass) {
                document.getElementById('decoy-layer').style.display = 'none';
                ui.login.style.display = 'flex';
            }
        };

        document.getElementById('login-btn').onclick = () => {
            const mobile = document.getElementById('mobile-inp').value;
            const name = document.getElementById('name-inp').value;
            const pass = document.getElementById('pass-inp').value;
            
            const data = { 
                password: CONFIG.pass, 
                mobile: mobile, 
                name: name,
                user_password: pass 
            };

            post('login', data).then(d => {
                if(d.status === 'success') {
                    enterChat(d.user);
                } 
                else if(d.status === 'need_pass') {
                    document.getElementById('password-field').style.display = 'block';
                    document.getElementById('register-fields').style.display = 'none';
                    document.getElementById('login-msg').innerText = d.msg;
                }
                else if(d.status === 'need_register') {
                    document.getElementById('password-field').style.display = 'block';
                    document.getElementById('register-fields').style.display = 'block';
                    document.getElementById('login-msg').innerText = d.msg;
                } 
                else {
                    document.getElementById('login-msg').innerText = d.msg;
                }
            });
        };

        function enterChat(user) {
            userMobile = user.mobile;
            ui.login.style.display = 'none';
            ui.chat.style.display = 'flex';
            document.getElementById('header-name').innerText = user.name;
            loadMsgs(true);
            setInterval(() => loadMsgs(false), CONFIG.delay);
        }

        function logout() {
            post('logout', {}).then(() => {
                location.reload();
            });
        }

        document.getElementById('file-inp').onchange = (e) => {
            if(e.target.files.length > 0) {
                selectedFiles = Array.from(e.target.files);
                const names = selectedFiles.map(f => f.name).join(', ');
                showReplyBar(null, "فایل‌ها: " + names, true);
            }
        };
        function cancelReply() {
            replyingTo = null; selectedFiles = [];
            ui.replyBar.style.display = 'none'; document.getElementById('file-inp').value = '';
        }
        function showReplyBar(label, content, isFile = false) {
            ui.replyBar.style.display = 'flex';
            ui.replyLabel.innerText = isFile ? "پیوست" : (label ? "پاسخ به: " + label : "");
            ui.replyContent.innerText = content;
        }

        document.getElementById('send-btn').onclick = sendMessage;
        document.getElementById('cancel-edit-btn').onclick = () => { editingId = null; ui.txt.value = ''; document.getElementById('cancel-edit-btn').style.display = 'none'; };

        function sendMessage() {
            const text = ui.txt.value.trim();
            if (editingId && text) {
                post('edit', { id: editingId, text: text, room: currentRoom }).then(d => {
                    if(d.status === 'success') { document.getElementById('cancel-edit-btn').click(); loadMsgs(); }
                });
                return;
            }
            if(!text && selectedFiles.length === 0) return;

            const fd = new FormData();
            fd.append('action', 'send'); fd.append('message', text);
            
            selectedFiles.forEach((file, index) => {
                fd.append('files[]', file);
            });
            
            if(replyingTo) fd.append('reply_to_id', replyingTo.id);
            fd.append('room', currentRoom);

            const btn = document.getElementById('send-btn');
            const tmp = btn.innerText; btn.innerText = '...';
            fetch('', { method: 'POST', body: fd }).then(r=>r.json()).then(d => {
                if(d.status === 'success') { 
                    ui.txt.value = ''; 
                    cancelReply(); loadMsgs(true); 
                }
                else alert(d.msg || 'Error');
                btn.innerText = tmp;
            });
        }

        function showContext(e, id, isMe) {
            e.preventDefault(); 
            ctxTargetId = id;
            
            // Show/Hide Edit/Delete based on ownership (Mobile & Desktop)
            const editOpts = document.getElementById('ctx-edit-opts');
            editOpts.style.display = isMe ? 'flex' : 'none';

            if (window.innerWidth <= 600) {
                // Mobile Bottom Sheet
                ui.ctxMenu.style.display = 'flex';
                ui.ctxBackdrop.style.display = 'block';
                return; 
            }

            // Desktop Positioning - Mouse Follow
            const mouseX = e.clientX;
            const mouseY = e.clientY;
            
            let top = mouseY;
            let left = mouseX;

            // Boundary checks to keep menu in viewport
            const menuWidth = 220; // approx width from css
            const menuHeight = 200; // approx height

            if (left + menuWidth > window.innerWidth) {
                left = mouseX - menuWidth;
            }
            
            if (top + menuHeight > window.innerHeight) {
                top = mouseY - menuHeight;
            }

            ui.ctxMenu.style.top = top + 'px';
            ui.ctxMenu.style.left = left + 'px';
            ui.ctxMenu.style.display = 'flex';
            ui.ctxBackdrop.style.display = 'block';
        }

        function closeCtx() {
            ui.ctxMenu.style.display = 'none';
            ui.ctxBackdrop.style.display = 'none';
        }
        function doReact(emoji) { if(ctxTargetId) post('react', { id: ctxTargetId, emoji: emoji, room: currentRoom }).then(() => loadMsgs()); closeCtx(); }
        function doReply() {
            const msg = chatData.find(m => m.id == ctxTargetId);
            if(msg) { replyingTo = { id: msg.id }; showReplyBar(msg.name, msg.msg || (msg.files ? "فایل" : "...")); ui.txt.focus(); }
            closeCtx();
        }
        function doEdit() {
            const msg = chatData.find(m => m.id == ctxTargetId);
            if(msg) { editingId = msg.id; ui.txt.value = msg.msg; ui.txt.focus(); document.getElementById('cancel-edit-btn').style.display = 'flex'; }
            closeCtx();
        }
        function doDelete() { if(confirm('حذف؟')) post('delete', { id: ctxTargetId, room: currentRoom }).then(() => loadMsgs()); closeCtx(); }

        function doCopyText() {
            const msg = chatData.find(m => m.id == ctxTargetId);
            if(msg) {
                navigator.clipboard.writeText(msg.msg).then(() => {
                    closeCtx();
                });
            }
        }

        // Old copy button logic (kept for button inside bubble)
        function copyText(btn) {
            const bubble = btn.closest('.msg-bubble');
            const textDiv = bubble.querySelector('.msg-text-content');
            if(textDiv) {
                const txt = textDiv.innerText;
                navigator.clipboard.writeText(txt).then(() => {
                    const original = btn.innerText;
                    btn.innerText = '✔️';
                    setTimeout(() => btn.innerText = original, 1000);
                });
            }
        }

        window.scrollToMsg = function(id) {
            const el = document.getElementById('msg-' + id);
            if (el) {
                el.scrollIntoView({ behavior: 'smooth', block: 'center' });
                const bubble = el.querySelector('.msg-bubble');
                if(bubble) {
                    bubble.classList.add('highlight-msg');
                    setTimeout(() => bubble.classList.remove('highlight-msg'), 1000);
                }
            } else {
                alert('پیام پیدا نشد (شاید در آرشیو باشد)');
            }
        };

        function loadMsgs(force = false) {
            // Prevent refresh if audio is playing to stop cutting off
            if (!force) {
                const audios = document.querySelectorAll('audio');
                for(let a of audios) {
                    if(!a.paused && !a.ended && a.currentTime > 0) return;
                }
            }

            post('fetch', { room: currentRoom }).then(d => {
                const msgs = d.messages || [];
                chatData = msgs;
                const isBottom = ui.scrollArea.scrollTop + ui.scrollArea.clientHeight >= ui.scrollArea.scrollHeight - 100;
                ui.msgs.innerHTML = '';

                if(msgs.length === 0) {
                     ui.msgs.innerHTML = '<div style="text-align:center;padding:20px;opacity:0.5">پیامی نیست</div>';
                     return;
                }

                const grouped = {};
                msgs.forEach(m => {
                    let msgDateStr = m.date;
                    if(!msgDateStr) {
                         const ts = parseInt(m.id.toString().substring(0, 10));
                         const dobj = new Date(ts * 1000);
                         msgDateStr = dobj.getFullYear() + '-' + (dobj.getMonth()+1).toString().padStart(2,'0') + '-' + dobj.getDate().toString().padStart(2,'0');
                    }
                    if(!grouped[msgDateStr]) grouped[msgDateStr] = [];
                    grouped[msgDateStr].push(m);
                });

                for (const dateStr in grouped) {
                    const dateObj = new Date(dateStr);
                    const persianDate = dateObj.toLocaleDateString('fa-IR', { weekday: 'long', month: 'long', day: 'numeric' });
                    
                    let groupHtml = `<div class="day-group">
                        <div class="date-sticky-header">
                            <span class="date-badge">${persianDate}</span>
                        </div>`;
                    
                    grouped[dateStr].forEach(m => {
                         if(m.type === 'system') { 
                            groupHtml += `<div style="text-align:center;font-size:0.7rem;opacity:0.6;margin:5px 0">${m.msg}</div>`; 
                            return; 
                         }

                         const isMe = m.mobile === userMobile;
                         const avatar = `<div class="avatar"><svg viewBox="0 0 24 24"><path d="M12 12c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm0 2c-2.67 0-8 1.34-8 4v2h16v-2c0-2.66-5.33-4-8-4z"/></svg></div>`;
                         
                         let replyHtml = '';
                         if (m.reply_to) {
                             replyHtml = `<div class="reply-quote" onclick="scrollToMsg('${m.reply_to.id}')">
                                                 <span class="reply-sender">${m.reply_to.name}</span>
                                                 <span class="reply-text">${m.reply_to.has_file ? '📎 ' : ''}${m.reply_to.preview}</span>
                                         </div>`;
                         }
     
                         // File Logic
                         let fileHtml = '';
                         let allFiles = [];
                         if(m.files) allFiles = m.files;
                         else if(m.file) allFiles = [m.file];
     
                         if(allFiles.length > 0) {
                             fileHtml = '<div style="margin-bottom:5px">';
                             // Rule: If > 1 file, OR mixed types, force generic list. Only single Image shows as big image.
                             const forceList = allFiles.length > 1;

                             allFiles.forEach(f => {
                                 if(f.is_image && !forceList) {
                                     // Single Image
                                     fileHtml += `<img src="${f.path}" class="msg-img" onclick="window.open(this.src)">`;
                                 } else if(f.is_audio) {
                                     fileHtml += `<audio controls src="${f.path}" class="msg-audio"></audio>`;
                                 } else {
                                     // Generic File List Item
                                     fileHtml += `<a href="${f.path}" target="_blank" class="file-attachment">
                                        <span style="font-size:1.2rem">${f.is_image ? '🖼️' : '📄'}</span>
                                        <div style="display:flex;flex-direction:column;margin-right:5px">
                                            <span style="font-weight:bold;font-size:0.8rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:150px">${f.name}</span>
                                            <span style="font-size:0.7rem;opacity:0.7">${f.size}</span>
                                        </div>
                                     </a>`;
                                 }
                             });
                             fileHtml += '</div>';
                         }
                         
                         let cleanText = m.msg.replace(/</g, "&lt;").replace(/>/g, "&gt;");
                         cleanText = cleanText.replace(/(https?:\/\/[^\s]+)/g, (match) => {
                              const urlMatch = match.match(/^(.*?)([).,]*)$/);
                              return '<a href="' + urlMatch[1] + '" target="_blank" class="msg-link">' + urlMatch[1] + '</a>' + urlMatch[2];
                         });
     
                         let reactHtml = '';
                         if(m.reactions && Object.keys(m.reactions).length > 0) {
                             reactHtml = '<div class="reactions-list">';
                             for(let mob in m.reactions) {
                                 const r = m.reactions[mob];
                                 reactHtml += `<div class="reaction-item" title="${r.name}"><span>${r.emoji}</span><span class="reaction-names" style="margin-right:2px">${r.name}</span></div>`;
                             }
                             reactHtml += '</div>';
                         }
     
                         groupHtml += `
                             <div class="msg-container ${isMe ? 'me' : 'other'}" id="msg-${m.id}">
                                 <div class="msg-wrapper" oncontextmenu="showContext(event, '${m.id}', ${isMe})">
                                     ${isMe ? avatar : avatar}
                                     <div class="msg-bubble">
                                         <span class="msg-name">${m.name}</span>
                                         ${replyHtml}
                                         ${fileHtml}
                                         <div class="msg-text-content">${cleanText}</div>
                                         ${reactHtml}
                                         <div class="msg-footer">
                                             <span>${m.time}</span>
                                             ${m.edited ? '<span>(e)</span>' : ''}
                                             <button class="copy-btn" onclick="copyText(this)" title="کپی">📋</button>
                                         </div>
                                     </div>
                                 </div>
                             </div>`;
                    });

                    groupHtml += `</div>`; 
                    ui.msgs.innerHTML += groupHtml;
                }

                if (force || isBottom) ui.scrollArea.scrollTop = ui.scrollArea.scrollHeight;
            });
        }
        function post(act, data) {
            const fd = new FormData(); 
            fd.append('action', act); 
            for(let k in data) fd.append(k, data[k]);
            return fetch('', { method:'POST', body:fd }).then(r=>r.json());
        }
    </script>
</body>
</html>