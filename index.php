
<?php
error_reporting(0);
ini_set('display_errors', 0);

@ini_set('upload_max_filesize', '100M');
@ini_set('post_max_size', '100M');
@ini_set('memory_limit', '256M');

/* ================= CONFIG ================= */
$config = [
    'password'       => '14001404', // رمز ورود کلی به چت روم
    'refresh_rate'   => 50000, // میلی ثانیه
    'base_file'      => 'chat_history',
    'users_file'     => 'chat_users.json',
    'upload_dir'     => 'uploads',
    'max_messages'   => 50,
    'use_whitelist'  => false,
    'whitelist'      => [
        '09120000000',
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
    $dataFile = __DIR__ . '/' . $config['base_file'] . '.json';
    $usersFile = __DIR__ . '/' . $config['users_file'];

    // --- AUTH: LOGOUT ---
    if ($action === 'logout') {
        setcookie('chat_token', '', time() - 3600, "/", "", false, true);
        exit(json_encode(['status'=>'success']));
    }

    // --- AUTH: LOGIN ---
    if ($action === 'login') {
        $sysPass = $_POST['password'] ?? ''; // رمز کلی سیستم
        $mobile = $_POST['mobile'] ?? '';
        $name = trim($_POST['name'] ?? '');
        $userPass = $_POST['user_password'] ?? ''; // رمز شخصی کاربر
        
        // 1. بررسی رمز کلی سیستم
        if ($sysPass !== $config['password']) exit(json_encode(['status'=>'error', 'msg'=>'رمز عبور چت روم اشتباه است']));
        if ($config['use_whitelist'] && !in_array($mobile, $config['whitelist'])) exit(json_encode(['status'=>'error', 'msg'=>'شماره مجاز نیست']));

        $users = getJson($usersFile);
        $userData = $users[$mobile] ?? null;

        // پشتیبانی از نسخه قبلی
        if (is_string($userData)) {
            $userData = ['name' => $userData];
        }

        // 2. سناریوی ثبت نام یا ورود
        if ($userData && isset($userData['pass_hash'])) {
            // --- کاربر وجود دارد و رمز دارد (Login) ---
            if (empty($userPass)) {
                exit(json_encode(['status'=>'need_pass', 'msg'=>'لطفا رمز عبور خود را وارد کنید']));
            }
            // بررسی صحت رمز
            if (!password_verify($userPass, $userData['pass_hash'])) {
                exit(json_encode(['status'=>'error', 'msg'=>'رمز عبور شما اشتباه است']));
            }
            $finalName = $userData['name'];
        } else {
            // --- کاربر جدید است یا رمز ندارد (Register) ---
            if (empty($name) || empty($userPass)) {
                exit(json_encode(['status'=>'need_register', 'msg'=>'لطفا نام نمایشی و یک رمز عبور جدید تعیین کنید']));
            }
            
            // ذخیره کاربر جدید
            $users[$mobile] = [
                'name' => $name,
                'pass_hash' => password_hash($userPass, PASSWORD_DEFAULT),
                'last_login' => date('Y-m-d H:i:s')
            ];
            saveJson($usersFile, $users);
            $finalName = $name;
        }

        // ساخت کوکی و ورود موفق
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
        $hasFile = isset($_FILES['file']) && $_FILES['file']['error'] === UPLOAD_ERR_OK;

        if (trim($msgText) === '' && !$hasFile) exit(json_encode(['status'=>'empty']));

        $fileData = null;
        if ($hasFile) {
            $upDir = __DIR__ . '/' . $config['upload_dir'];
            if (!is_dir($upDir)) mkdir($upDir, 0755, true);
            $f = $_FILES['file'];
            $ext = pathinfo($f['name'], PATHINFO_EXTENSION);
            $newName = time() . '_' . rand(1000,9999) . '.' . $ext;
            
            if ($f['size'] > 100 * 1024 * 1024) exit(json_encode(['status'=>'error', 'msg'=>'حجم فایل زیاد است']));
            
            if (move_uploaded_file($f['tmp_name'], $upDir . '/' . $newName)) {
                $fileData = [
                    'name' => $f['name'],
                    'path' => $config['upload_dir'] . '/' . $newName,
                    'is_image' => in_array(strtolower($ext), ['jpg', 'jpeg', 'png', 'gif', 'webp']),
                    'size' => round($f['size']/1024) . ' KB'
                ];
            }
        }

        $msgs = getJson($dataFile);
        
        $replyInfo = null;
        if ($replyToId) {
            foreach ($msgs as $m) {
                if ($m['id'] == $replyToId) {
                    $replyInfo = [
                        'id' => $m['id'],
                        'name' => $m['name'],
                        'preview' => mb_substr($m['msg'], 0, 50) . (mb_strlen($m['msg'])>50 ? '...' : ''),
                        'has_file' => !empty($m['file'])
                    ];
                    break;
                }
            }
        }

        if (count($msgs) >= $config['max_messages']) {
            rename($dataFile, __DIR__ . '/' . $config['base_file'] . '_archive_' . date('Ymd_His') . '.json');
            $msgs = [['id'=>time(), 'mobile'=>'sys', 'name'=>'System', 'msg'=>'--- آرشیو شد ---', 'time'=>date('H:i'), 'type'=>'system']];
        }

        $msgs[] = [
            'id' => time() . rand(1000,9999),
            'mobile' => $authMobile,
            'name' => $authName,
            'msg' => $msgText,
            'file' => $fileData,
            'reply_to' => $replyInfo,
            'time' => date('H:i'),
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
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Siterah</title>
    <style>
        @font-face { font-family: 'Alibaba'; src: url('Alibaba.ttf') format('truetype'); }
        
        :root {
            /* متغیرهای پیش‌فرض (تم تیره) */
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
        }

        /* تم روشن */
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
        }

        * { box-sizing: border-box; margin: 0; padding: 0; font-family: 'Alibaba', Tahoma, sans-serif; -webkit-tap-highlight-color: transparent; }
        body { background: var(--bg); color: var(--text); height: 100vh; overflow: hidden; font-size: 14px; transition: background 0.3s, color 0.3s; }
        
        ::-webkit-scrollbar { width: 15px; }
        ::-webkit-scrollbar-track { background: var(--bg); }
        ::-webkit-scrollbar-thumb { background: #475569; border-radius: 8px; }

        /* LOGIN */
        #decoy-layer { position: fixed; inset:0; display:flex; flex-direction:column; align-items:center; justify-content:center; background:#fff; z-index:9999; }
        #decoy-input { margin-top:50px; border:1px solid #ddd; padding:8px; width:200px; text-align:center; }
        
        #login-modal { position:fixed; inset:0; background:rgba(0,0,0,0.8); z-index:8000; display:none; align-items:center; justify-content:center; backdrop-filter: blur(2px); }
        .login-box { width:300px; padding:25px; background:var(--chat-bg); border-radius:12px; text-align:center; color:var(--text); border: 1px solid var(--border); }
        .login-inp { width:100%; padding:12px; margin:10px 0; background:var(--bg); border:1px solid var(--border); border-radius:8px; color:var(--text); text-align:center; }
        .login-btn { width:100%; padding:12px; background:var(--accent); border:none; border-radius:8px; color:white; font-weight:bold; cursor:pointer; }
        .login-label { font-size: 0.8rem; opacity: 0.7; margin-top: 5px; display: block; text-align: right; }

        /* CHAT LAYOUT */
        #chat-layer { display:none; height:100%; flex-direction:column; background:var(--bg); color:var(--text); }
        
        #header-wrapper { padding: 10px 0; width: 100%; z-index: 10; }
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
        
        #scroll-area { flex: 1; overflow-y: auto; width: 100%; display: flex; flex-direction: column; }
        #msg-inner { width: 100%; max-width: 800px; margin: 0 auto; padding: 20px 15px; display: flex; flex-direction: column; gap: 15px; }

        .msg-container { display: flex; width: 100%; }
        .msg-container.me { justify-content: flex-end; }
        .msg-container.other { justify-content: flex-start; }
        
        .msg-wrapper { display: flex; align-items: flex-end; gap: 10px; max-width: 80%; }
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

        @keyframes flash { 0% { background: #f59e0b; } 100% { background: var(--msg-other-bg); } }
        @keyframes flash-me { 0% { background: #f59e0b; } 100% { background: var(--msg-me-bg); } }
        .highlight-msg { animation: flash 1s ease; }
        .msg-container.me .highlight-msg { animation: flash-me 1s ease; }

        .reply-quote {
            border-right: 3px solid var(--accent); background: var(--reply-bg);
            padding: 4px 8px; border-radius: 6px; margin-bottom: 6px;
            font-size: 0.8rem; cursor: pointer; display: flex; flex-direction: column;
            transition: opacity 0.2s;
        }
        .reply-sender { font-weight: bold; margin-bottom: 2px; opacity: 0.9; }
        .reply-text { white-space: nowrap; overflow: hidden; text-overflow: ellipsis; opacity: 0.8; font-size: 0.75rem;}

        /* INPUT */
        #input-wrapper {
            background: linear-gradient(to top, var(--bg) 80%, transparent);
            padding: 10px 0; width: 100%; position: relative; z-index: 50;
        }
        #input-container { max-width: 800px; margin: 0 auto; padding: 0 10px; position: relative; }
        #input-box {
            background: var(--chat-bg); border: 1px solid var(--border); border-radius: 20px;
            padding: 5px; display: flex; flex-direction: column; position: relative;
            box-shadow: 0 -2px 10px var(--shadow); min-height: 60px;
        }
        
        #resize-handle { 
            width: 40px; height: 4px; background: #94a3b8; border-radius: 2px; 
            margin: 4px auto; cursor: ns-resize; flex-shrink: 0; opacity: 0.5; 
            touch-action: none; 
        }
        #reply-bar { display: none; background: var(--bg); border-right: 3px solid var(--accent); padding: 8px 12px; margin: 5px; border-radius: 6px; align-items: center; justify-content: space-between; }

        .input-row { display: flex; align-items: flex-end; gap: 5px; padding: 5px; flex: 1; height: 100%; }
        textarea { 
            flex:1; background:transparent; border:none; color:var(--text); resize:none; padding:8px; outline:none; font-size:1rem; 
            height: 100%; 
            min-height: 36px;
        }
        
        .icon-btn { width: 36px; height: 36px; display: flex; align-items: center; justify-content: center; border-radius: 50%; border: none; background: transparent; color: var(--text); opacity: 0.6; cursor: pointer; transition: 0.2s; flex-shrink: 0; }
        .icon-btn:hover { background: var(--bg); opacity: 1; }
        .send-btn { background: var(--accent); color: white; opacity: 1; }
        
        #ctx-menu { position: fixed; background: var(--chat-bg); border: 1px solid var(--border); border-radius: 8px; padding: 8px; display: none; flex-direction: column; gap: 8px; z-index: 10000; box-shadow: 0 5px 15px var(--shadow); min-width: 120px; }
        .ctx-row { display: flex; gap: 10px; justify-content: center; padding-bottom: 8px; border-bottom: 1px solid var(--border); }
        .ctx-emoji { font-size: 1.4rem; cursor: pointer; transition: 0.2s; }
        .ctx-emoji:hover { transform: scale(1.2); }
        .ctx-btn { cursor: pointer; padding: 6px 10px; border-radius: 4px; color: var(--text); font-size: 0.9rem; }
        .ctx-btn:hover { background: var(--bg); }

        #emoji-grid { position:absolute; bottom:100%; right:10px; background:var(--chat-bg); padding:10px; border-radius:12px; display:none; grid-template-columns:repeat(6,1fr); gap:5px; box-shadow:0 5px 20px var(--shadow); border:1px solid var(--border); margin-bottom: 10px; z-index: 101; }
        .emoji-item { cursor:pointer; font-size:1.4rem; padding:6px; border-radius:4px; text-align:center; }
        .emoji-item:hover { background: var(--bg); }
        
        .reactions-list { display: flex; flex-wrap: wrap; gap: 4px; margin-top: 6px; }
        .reaction-item { background: rgba(0,0,0,0.1); border-radius: 12px; padding: 2px 8px; font-size: 0.75rem; color: var(--text); display:flex; gap:4px; align-items:center; opacity: 0.8; }
        .msg-link { color: var(--accent); text-decoration: underline; }
        .msg-img { max-width: 100%; border-radius: 8px; margin-top: 5px; cursor: pointer; }
    </style>
</head>
<body>
    <div id="decoy-layer">
        <h1 style="font-size:4rem;color:#ccc">404</h1>
        <p style="color:#888">Not Found</p>
        <p style="color:#888">اگه نتونستین وارد بشین با من در تماس باشید : 09999923069</p>
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
            
            <!-- اینجا اصلاح شد: استایل دیسپلی نان برداشته شد تا همیشه نمایش داده شود -->
            <div id="password-field">
                <input type="password" id="pass-inp" class="login-inp" placeholder="رمز عبور شخصی" dir="ltr">
            </div>

            <button id="login-btn" class="login-btn">ورود / بررسی</button>
        </div>
    </div>

    <div id="ctx-menu">
        <div class="ctx-row">
            <span class="ctx-emoji" onclick="doReact('❤️')">❤️</span>
            <span class="ctx-emoji" onclick="doReact('😂')">😂</span>
            <span class="ctx-emoji" onclick="doReact('😭')">😭</span>
            <span class="ctx-emoji" onclick="doReact('👍')">👍</span>
            <span class="ctx-emoji" onclick="doReact('🔥')">🔥</span>
        </div>
        <div class="ctx-opts">
            <div class="ctx-btn" onclick="doReply()">↩️ پاسخ</div>
            <div id="ctx-edit-opts" style="display:none; flex-direction:column; gap:5px">
                <div class="ctx-btn" onclick="doEdit()">✎ ویرایش</div>
                <div class="ctx-btn" onclick="doDelete()" style="color:#ef4444">🗑 حذف</div>
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

                    <div class="input-row">
                        <button class="icon-btn" id="emoji-toggle">😊</button>
                        <textarea id="msg-text" placeholder="پیام ..."></textarea>
                        
                        <input type="file" id="file-inp" hidden>
                        <button class="icon-btn" onclick="document.getElementById('file-inp').click()">📎</button>
                        
                        <button class="icon-btn send-btn" id="send-btn">➤</button>
                        <button class="icon-btn" id="cancel-edit-btn" style="display:none;color:#ef4444">✕</button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        const CONFIG = { pass: "<?php echo $config['password']; ?>", delay: <?php echo $config['refresh_rate']; ?> };
        const EMOJIS = ["😀","😂","😍","😎","😭","😡","👍","👎","❤️","💔","🤝","🙏","👀","✅","❌","🔥"];
        let userMobile = null;
        let chatData = [];
        let editingId = null;
        let replyingTo = null;
        let selectedFile = null;
        let ctxTargetId = null;

        const ui = {
            decoyInp: document.getElementById('decoy-input'),
            login: document.getElementById('login-modal'),
            chat: document.getElementById('chat-layer'),
            msgs: document.getElementById('msg-inner'),
            scrollArea: document.getElementById('scroll-area'),
            txt: document.getElementById('msg-text'),
            ctxMenu: document.getElementById('ctx-menu'),
            emojiGrid: document.getElementById('emoji-grid'),
            inputBox: document.getElementById('input-box'),
            replyBar: document.getElementById('reply-bar'),
            replyLabel: document.getElementById('reply-label'),
            replyContent: document.getElementById('reply-content'),
            themeBtn: document.getElementById('theme-btn')
        };

        // --- THEME LOGIC ---
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

            const handle = document.getElementById('resize-handle');
            handle.addEventListener('mousedown', initResize);
            handle.addEventListener('touchstart', initResize);

            document.addEventListener('click', () => { ui.ctxMenu.style.display = 'none'; ui.emojiGrid.style.display = 'none'; });
            ui.emojiGrid.onclick = e => e.stopPropagation();
            ui.ctxMenu.onclick = e => e.stopPropagation();
        };

        // --- RESIZE FIX ---
        function initResize(e) {
            if(e.cancelable) e.preventDefault();
            
            const startY = e.clientY || e.touches[0].clientY;
            const startH = ui.inputBox.offsetHeight;
            
            const doDrag = (e) => {
                const curY = e.clientY || e.touches[0].clientY;
                const diff = startY - curY;
                const newH = Math.min(Math.max(60, startH + diff), 400);
                
                ui.inputBox.style.height = newH + 'px';
            };
            
            const stopDrag = () => {
                document.removeEventListener('mousemove', doDrag); document.removeEventListener('mouseup', stopDrag);
                document.removeEventListener('touchmove', doDrag); document.removeEventListener('touchend', stopDrag);
            };
            
            document.addEventListener('mousemove', doDrag); document.addEventListener('mouseup', stopDrag);
            document.addEventListener('touchmove', doDrag, {passive: false}); document.addEventListener('touchend', stopDrag);
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

        // --- LOGIN LOGIC ---
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
                    // کاربر قدیمی است، فقط رمز بخواه
                    document.getElementById('password-field').style.display = 'block';
                    document.getElementById('register-fields').style.display = 'none';
                    document.getElementById('login-msg').innerText = d.msg;
                }
                else if(d.status === 'need_register') {
                    // کاربر جدید است، رمز و نام بخواه
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

        // --- LOGOUT FIX ---
        function logout() {
            post('logout', {}).then(() => {
                location.reload();
            });
        }

        document.getElementById('file-inp').onchange = (e) => {
            if(e.target.files[0]) {
                selectedFile = e.target.files[0];
                showReplyBar(null, "فایل: " + selectedFile.name, true);
            }
        };
        function cancelReply() {
            replyingTo = null; selectedFile = null;
            ui.replyBar.style.display = 'none'; document.getElementById('file-inp').value = '';
        }
        function showReplyBar(label, content, isFile = false) {
            ui.replyBar.style.display = 'flex';
            ui.replyLabel.innerText = isFile ? "پیوست" : (label ? "پاسخ به: " + label : "");
            ui.replyContent.innerText = content;
        }

        document.getElementById('send-btn').onclick = sendMessage;
        ui.txt.onkeydown = (e) => { 
            if(e.key === 'Enter' && !e.shiftKey) { 
                e.preventDefault(); 
                sendMessage(); 
            } 
        };
        document.getElementById('cancel-edit-btn').onclick = () => { editingId = null; ui.txt.value = ''; document.getElementById('cancel-edit-btn').style.display = 'none'; };

        function sendMessage() {
            const text = ui.txt.value.trim();
            if (editingId && text) {
                post('edit', { id: editingId, text: text }).then(d => {
                    if(d.status === 'success') { document.getElementById('cancel-edit-btn').click(); loadMsgs(); }
                });
                return;
            }
            if(!text && !selectedFile) return;

            const fd = new FormData();
            fd.append('action', 'send'); fd.append('message', text);
            if(selectedFile) fd.append('file', selectedFile);
            if(replyingTo) fd.append('reply_to_id', replyingTo.id);

            const btn = document.getElementById('send-btn');
            const tmp = btn.innerText; btn.innerText = '...';
            fetch('', { method: 'POST', body: fd }).then(r=>r.json()).then(d => {
                if(d.status === 'success') { ui.txt.value = ''; cancelReply(); loadMsgs(true); }
                else alert(d.msg || 'Error');
                btn.innerText = tmp;
            });
        }

        function showContext(e, id, isMe) {
            e.preventDefault(); ctxTargetId = id;
            ui.ctxMenu.style.left = e.clientX + 'px'; ui.ctxMenu.style.top = e.clientY + 'px';
            ui.ctxMenu.style.display = 'flex';
            document.getElementById('ctx-edit-opts').style.display = isMe ? 'flex' : 'none';
        }
        function doReact(emoji) { if(ctxTargetId) post('react', { id: ctxTargetId, emoji: emoji }).then(() => loadMsgs()); ui.ctxMenu.style.display = 'none'; }
        function doReply() {
            const msg = chatData.find(m => m.id == ctxTargetId);
            if(msg) { replyingTo = { id: msg.id }; showReplyBar(msg.name, msg.msg || (msg.file ? "فایل" : "...")); ui.txt.focus(); }
            ui.ctxMenu.style.display = 'none';
        }
        function doEdit() {
            const msg = chatData.find(m => m.id == ctxTargetId);
            if(msg) { editingId = msg.id; ui.txt.value = msg.msg; ui.txt.focus(); document.getElementById('cancel-edit-btn').style.display = 'flex'; }
            ui.ctxMenu.style.display = 'none';
        }
        function doDelete() { if(confirm('حذف؟')) post('delete', { id: ctxTargetId }).then(() => loadMsgs()); ui.ctxMenu.style.display = 'none'; }

        // --- SCROLL TO REPLY ---
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
            post('fetch', {}).then(d => {
                const msgs = d.messages || [];
                chatData = msgs;
                const isBottom = ui.scrollArea.scrollTop + ui.scrollArea.clientHeight >= ui.scrollArea.scrollHeight - 50;
                ui.msgs.innerHTML = '';

                msgs.forEach(m => {
                    if(m.type === 'system') { ui.msgs.innerHTML += `<div style="text-align:center;font-size:0.7rem;opacity:0.6;margin:10px 0">${m.msg}</div>`; return; }

                    const isMe = m.mobile === userMobile;
                    const avatar = `<div class="avatar"><svg viewBox="0 0 24 24"><path d="M12 12c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm0 2c-2.67 0-8 1.34-8 4v2h16v-2c0-2.66-5.33-4-8-4z"/></svg></div>`;
                    
                    let replyHtml = '';
                    if (m.reply_to) {
                        replyHtml = `<div class="reply-quote" onclick="scrollToMsg('${m.reply_to.id}')">
                                        <span class="reply-sender">${m.reply_to.name}</span>
                                        <span class="reply-text">${m.reply_to.has_file ? '📎 ' : ''}${m.reply_to.preview}</span>
                                    </div>`;
                    }

                    let fileHtml = '';
                    if(m.file) {
                        if(m.file.is_image) fileHtml = `<img src="${m.file.path}" class="msg-img" onclick="window.open(this.src)">`;
                        else fileHtml = `<a href="${m.file.path}" target="_blank" style="display:flex;align-items:center;margin-top:5px;text-decoration:none;color:inherit;background:rgba(0,0,0,0.05);padding:5px;border-radius:4px">📎 ${m.file.name}</a>`;
                    }
                    
                    let cleanText = m.msg.replace(/</g, "&lt;").replace(/>/g, "&gt;");
                    cleanText = cleanText.replace(/(https?:\/\/[^\s]+)/g, '<a href="$1" target="_blank" class="msg-link">$1</a>');

                    let reactHtml = '';
                    if(m.reactions && Object.keys(m.reactions).length > 0) {
                        reactHtml = '<div class="reactions-list">';
                        for(let mob in m.reactions) {
                            const r = m.reactions[mob];
                            reactHtml += `<div class="reaction-item" title="${r.name}"><span>${r.emoji}</span><span class="reaction-names" style="margin-right:2px">${r.name}</span></div>`;
                        }
                        reactHtml += '</div>';
                    }

                    const html = `
                        <div class="msg-container ${isMe ? 'me' : 'other'}" id="msg-${m.id}">
                            <div class="msg-wrapper" oncontextmenu="showContext(event, '${m.id}', ${isMe})">
                                ${isMe ? avatar : avatar}
                                <div class="msg-bubble">
                                    <span class="msg-name">${m.name}</span>
                                    ${replyHtml}
                                    <div>${cleanText}</div>
                                    ${fileHtml}
                                    ${reactHtml}
                                    <div class="msg-footer"><span>${m.time}</span>${m.edited ? '<span>(e)</span>' : ''}</div>
                                </div>
                            </div>
                        </div>`;
                    ui.msgs.innerHTML += html;
                });

                if (force || isBottom) ui.scrollArea.scrollTop = ui.scrollArea.scrollHeight;
            });
        }
        function post(act, data) {
            const fd = new FormData(); fd.append('action', act); for(let k in data) fd.append(k, data[k]);
            return fetch('', { method:'POST', body:fd }).then(r=>r.json());
        }
    </script>
</body>
</html>