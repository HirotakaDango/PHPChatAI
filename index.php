<?php
session_start();

// PWA App Icon Route (Clean SVG with proper headers)
if (isset($_GET['icon'])) {
  header('Content-Type: image/svg+xml; charset=utf-8');
  ?>
  <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="#4d6bfe">
    <path d="m10.75 11.5l7.075-7.075q.3-.3.7-.3t.7.3t.3.7t-.3.7l-7.05 7.075zm2.475 2.475l6.35-6.375q.3-.3.713-.3t.712.3t.3.713t-.3.712l-6.35 6.35zm-7.95 4.75Q3 16.45 3 13.25t2.275-5.475l3-3L9.75 6.25q.175.175.3.363T10.3 7L14 3.275q.3-.3.713-.3t.712.3t.3.712t-.3.713L11.1 9.025l-2.125 2.1l.475.475q1.15 1.15 1.1 2.75t-1.225 2.775l-1.425-1.4q.575-.575.638-1.362T8.025 13L6.85 11.85q-.3-.3-.3-.712t.3-.713l1.425-1.4q.3-.3.3-.713t-.3-.712l-1.6 1.6q-1.7 1.7-1.7 4.063t1.7 4.062t4.075 1.7t4.075-1.7l5.975-6q.3-.3.713-.3t.712.3t.3.713t-.3.712l-6 5.975Q13.95 21 10.75 21t-5.475-2.275M17 23.025V21q1.65 0 2.825-1.175T21 17h2.025q0 2.5-1.763 4.263T17 23.025M.975 7q0-2.5 1.763-4.262T7 .974V3Q5.35 3 4.175 4.175T3 7z"/>
  </svg>
  <?php
  exit;
}

// PWA Service Worker endpoint
if (isset($_GET['sw'])) {
  header('Content-Type: application/javascript; charset=utf-8');
  header('Service-Worker-Allowed: /');
  ?>
  const CACHE_NAME = 'PHPChatAI-cache-v3';
  const ASSETS = [
    './',
    './index.php',
    'https://fonts.googleapis.com/css2?family=Roboto:wght@300;400;500;700&display=swap',
    'https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@24,400,0,0'
  ];

  self.addEventListener('install', (e) => {
    e.waitUntil(
      caches.open(CACHE_NAME).then((cache) => {
        return cache.addAll(ASSETS).catch(() => {});
      }).then(() => self.skipWaiting())
    );
  });

  self.addEventListener('activate', (e) => {
    e.waitUntil(
      caches.keys().then((keys) => {
        return Promise.all(
          keys.map((key) => {
            if (key !== CACHE_NAME) {
              return caches.delete(key);
            }
          })
        );
      }).then(() => self.clients.claim())
    );
  });

  self.addEventListener('fetch', (e) => {
    const url = new URL(e.request.url);
    if (url.search.includes('api=')) {
      return;
    }
    e.respondWith(
      fetch(e.request)
        .then((res) => {
          if (res && res.status === 200) {
            const clone = res.clone();
            caches.open(CACHE_NAME).then((cache) => {
              cache.put(e.request, clone);
            });
          }
          return res;
        })
        .catch(() => {
          return caches.match(e.request).then((cachedRes) => {
            if (cachedRes) return cachedRes;
            if (e.request.mode === 'navigate') {
              return caches.match('./') || caches.match('./index.php');
            }
          });
        })
    );
  });
  <?php
  exit;
}

// PWA App Manifest config (Base64 Encoded for reliable PWA install)
$manifestData = [
  'short_name' => 'PHPChatAI',
  'name' => 'PHPChatAI System',
  'icons' => [
    [
      'src' => "data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='%234d6bfe'%3E%3Cpath d='m10.75 11.5l7.075-7.075q.3-.3.7-.3t.7.3t.3.7t-.3.7l-7.05 7.075zm2.475 2.475l6.35-6.375q.3-.3.713-.3t.712.3t.3.713t-.3.712l-6.35 6.35zm-7.95 4.75Q3 16.45 3 13.25t2.275-5.475l3-3L9.75 6.25q.175.175.3.363T10.3 7L14 3.275q.3-.3.713-.3t.712.3t.3.712t-.3.713L11.1 9.025l-2.125 2.1l.475.475q1.15 1.15 1.1 2.75t-1.225 2.775l-1.425-1.4q.575-.575.638-1.362T8.025 13L6.85 11.85q-.3-.3-.3-.712t.3-.713l1.425-1.4q.3-.3.3-.713t-.3-.712l-1.6 1.6q-1.7 1.7-1.7 4.063t1.7 4.062t4.075 1.7t4.075-1.7l5.975-6q.3-.3.713-.3t.712.3t.3.713t-.3.712l-6 5.975Q13.95 21 10.75 21t-5.475-2.275M17 23.025V21q1.65 0 2.825-1.175T21 17h2.025q0 2.5-1.763 4.263T17 23.025M.975 7q0-2.5 1.763-4.262T7 .974V3Q5.35 3 4.175 4.175T3 7z'/%3E%3C/svg%3E",
      'type' => 'image/svg+xml',
      'sizes' => '192x192 512x512',
      'purpose' => 'any maskable'
    ]
  ],
  'start_url' => './index.php',
  'background_color' => '#131415',
  'theme_color' => '#131415',
  'display' => 'standalone',
  'orientation' => 'portrait'
];
$manifestBase64 = base64_encode(json_encode($manifestData));

$dbFile = __DIR__ . '/phpchat.sqlite';
try {
  $db = new PDO('sqlite:' . $dbFile, null, null, [
    PDO::ATTR_PERSISTENT => true,
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
  ]);
  $db->exec("PRAGMA busy_timeout = 5000");
} catch (Exception $e) {
  die("Database connection failed.");
}

// Ensure tables exist
$db->exec("CREATE TABLE IF NOT EXISTS users (id INTEGER PRIMARY KEY AUTOINCREMENT, password TEXT)");
$db->exec("CREATE TABLE IF NOT EXISTS chats (id TEXT PRIMARY KEY, user_id INTEGER, title TEXT, pinned INTEGER DEFAULT 0, created_at DATETIME DEFAULT CURRENT_TIMESTAMP)");
$db->exec("CREATE TABLE IF NOT EXISTS messages (id TEXT PRIMARY KEY, chat_id TEXT, parent_id TEXT, role TEXT, content TEXT, created_at DATETIME DEFAULT CURRENT_TIMESTAMP)");
$db->exec("CREATE TABLE IF NOT EXISTS settings (key TEXT PRIMARY KEY, value TEXT)");

// Smart migrations for missing columns in users table
$userCols = [];
$stmt = $db->query("PRAGMA table_info(users)");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) { 
  $userCols[] = $row['name']; 
}

try {
  if (!in_array('name', $userCols)) $db->exec("ALTER TABLE users ADD COLUMN name TEXT");
  if (!in_array('email', $userCols)) $db->exec("ALTER TABLE users ADD COLUMN email TEXT");
  if (!in_array('username', $userCols)) $db->exec("ALTER TABLE users ADD COLUMN username TEXT");
} catch (Exception $e) {
  if (strpos($e->getMessage(), 'readonly database') !== false) {
    // Attempt to automatically repair permissions on the folder and SQLite file
    @chmod(__DIR__, 0755);
    @chmod($dbFile, 0644);
    
    // Retry database changes after the auto-fix attempt
    try {
      if (!in_array('name', $userCols)) $db->exec("ALTER TABLE users ADD COLUMN name TEXT");
      if (!in_array('email', $userCols)) $db->exec("ALTER TABLE users ADD COLUMN email TEXT");
      if (!in_array('username', $userCols)) $db->exec("ALTER TABLE users ADD COLUMN username TEXT");
    } catch (Exception $retryException) {
      // If auto-fix fails, show specific instructions for the Replit workspace
      die("<div style='font-family:sans-serif; padding:40px; text-align:center; background:#121212; color:#e3e3e3; height:100vh; box-sizing:border-box;'>
        <h2 style='color:#ff5252;'>Database Permission Denied</h2>
        <p>The PHP process does not have permission to write to the database folder or file on Replit.</p>
        <p><strong>To fix this on Replit:</strong></p>
        <ol style='text-align:left; max-width:500px; margin:0 auto 20px; line-height:1.6;'>
          <li>Open the <strong>Shell</strong> tab on the right side of Replit.</li>
          <li>Run the following commands one by one and press Enter:</li>
        </ol>
        <code style='background:#1e1e1e; padding:16px; display:inline-block; text-align:left; border-radius:8px; border:1px solid #333; font-size:1.1rem; line-height:1.5;'>
          chmod 755 /home/runner/workspace<br>
          chmod 755 " . __DIR__ . "<br>
          touch " . $dbFile . " && chmod 644 " . $dbFile . "
        </code>
        <p style='margin-top:20px;'><a href='#' onclick='window.location.reload(); return false;' style='color:#4d6bfe; text-decoration:none; font-weight:bold;'>Click here to retry</a> after running the commands.</p>
      </div>");
    }
  } else {
    die("Database migration failed: " . $e->getMessage());
  }
}

function getUserId() {
  return $_SESSION['user_id'] ?? null;
}
function jsonResponse($data) {
  header('Content-Type: application/json');
  echo json_encode($data);
  exit;
}

$isAdmin = false;
if (getUserId()) {
  $stmt = $db->prepare("SELECT * FROM users WHERE id = ?");
  $stmt->execute([getUserId()]);
  $currentUser = $stmt->fetch(PDO::FETCH_ASSOC);
  if ($currentUser) {
    $emailCheck = $currentUser['email'] ?? '';
    $userCheck = $currentUser['username'] ?? '';
    if ($emailCheck === 'musiclibrary@mail.com' || $userCheck === 'musiclibrary@mail.com') {
      $isAdmin = true;
    }
  }
}

$stmt = $db->query("SELECT value FROM settings WHERE key = 'hf_token'");
$tokenRow = $stmt->fetch(PDO::FETCH_ASSOC);
$hfToken = $tokenRow ? $tokenRow['value'] : '';

if (isset($_GET['api'])) {
  $api = $_GET['api'];

  if ($api === 'register') {
    $req = json_decode(file_get_contents('php://input'), true);
    if (!$req) jsonResponse(['error' => 'Invalid JSON payload.']);
    
    $name = trim($req['name'] ?? '');
    $email = trim($req['email'] ?? '');
    $pass = password_hash($req['password'] ?? '', PASSWORD_DEFAULT);
    
    $check = $db->prepare("SELECT id FROM users WHERE (email != '' AND email = ?) OR (username != '' AND username = ?)");
    $check->execute([$email, $email]);
    
    if ($check->fetch()) {
      jsonResponse(['error' => 'Email already in use.']);
    }

    try {
      $stmt = $db->prepare("INSERT INTO users (name, email, password) VALUES (?, ?, ?)");
      $stmt->execute([$name, $email, $pass]);
      $_SESSION['user_id'] = $db->lastInsertId();
      $_SESSION['name'] = $name;
      $_SESSION['email'] = $email;
      jsonResponse(['status' => 'success']);
    } catch (Exception $e) {
      jsonResponse(['error' => 'Registration failed: ' . $e->getMessage()]);
    }
  }

  if ($api === 'login') {
    $req = json_decode(file_get_contents('php://input'), true);
    if (!$req) jsonResponse(['error' => 'Invalid JSON payload.']);
    
    $inputEmail = trim($req['email'] ?? '');
    $inputPass = $req['password'] ?? '';
    
    $stmt = $db->prepare("SELECT * FROM users WHERE (email != '' AND email = ?) OR (username != '' AND username = ?)");
    $stmt->execute([$inputEmail, $inputEmail]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($user && password_verify($inputPass, $user['password'])) {
      $_SESSION['user_id'] = $user['id'];
      $_SESSION['name'] = $user['name'] ?? 'User';
      $_SESSION['email'] = $user['email'] ?? ($user['username'] ?? '');
      jsonResponse(['status' => 'success']);
    }
    jsonResponse(['error' => 'Invalid credentials.']);
  }

  if ($api === 'logout') {
    session_destroy();
    jsonResponse(['status' => 'success']);
  }

  $userId = getUserId();
  if (!$userId) jsonResponse(['error' => 'Unauthorized']);

  if ($api === 'get_chats') {
    $stmt = $db->prepare("SELECT * FROM chats WHERE user_id = ? ORDER BY pinned DESC, created_at DESC");
    $stmt->execute([$userId]);
    jsonResponse($stmt->fetchAll(PDO::FETCH_ASSOC));
  }

  if ($api === 'create_chat') {
    $newId = uniqid('c_');
    $stmt = $db->prepare("INSERT INTO chats (id, user_id, title) VALUES (?, ?, 'New Chat')");
    $stmt->execute([$newId, $userId]);
    jsonResponse(['id' => $newId]);
  }

  if ($api === 'delete_chat') {
    $req = json_decode(file_get_contents('php://input'), true);
    // Strict ownership verification to prevent cross-account API abuse
    $chk = $db->prepare("SELECT id FROM chats WHERE id = ? AND user_id = ?");
    $chk->execute([$req['id'], $userId]);
    if ($chk->fetch()) {
      $db->prepare("DELETE FROM chats WHERE id = ?")->execute([$req['id']]);
      $db->prepare("DELETE FROM messages WHERE chat_id = ?")->execute([$req['id']]);
      jsonResponse(['status' => 'success']);
    }
    jsonResponse(['error' => 'Unauthorized']);
  }

  if ($api === 'rename_chat') {
    $req = json_decode(file_get_contents('php://input'), true);
    $db->prepare("UPDATE chats SET title = ? WHERE id = ? AND user_id = ?")->execute([$req['title'], $req['id'], $userId]);
    jsonResponse(['status' => 'success']);
  }

  if ($api === 'pin_chat') {
    $req = json_decode(file_get_contents('php://input'), true);
    $db->prepare("UPDATE chats SET pinned = ? WHERE id = ? AND user_id = ?")->execute([$req['pinned'], $req['id'], $userId]);
    jsonResponse(['status' => 'success']);
  }

  if ($api === 'get_messages') {
    $chatId = $_GET['chat_id'];
    $stmt = $db->prepare("SELECT id FROM chats WHERE id = ? AND user_id = ?");
    $stmt->execute([$chatId, $userId]);
    if (!$stmt->fetch()) jsonResponse([]);
    
    $stmt = $db->prepare("SELECT * FROM messages WHERE chat_id = ? ORDER BY created_at ASC");
    $stmt->execute([$chatId]);
    jsonResponse($stmt->fetchAll(PDO::FETCH_ASSOC));
  }

  if ($api === 'save_message') {
    $req = json_decode(file_get_contents('php://input'), true);
    $stmt = $db->prepare("SELECT id FROM chats WHERE id = ? AND user_id = ?");
    $stmt->execute([$req['chat_id'], $userId]);
    if (!$stmt->fetch()) jsonResponse(['error' => 'Unauthorized']);
    
    $stmt = $db->prepare("INSERT INTO messages (id, chat_id, parent_id, role, content) VALUES (?, ?, ?, ?, ?) ON CONFLICT(id) DO UPDATE SET content = excluded.content");
    $stmt->execute([$req['id'], $req['chat_id'], $req['parent_id'], $req['role'], $req['content']]);
    jsonResponse(['status' => 'success']);
  }

  if ($api === 'change_password') {
    $req = json_decode(file_get_contents('php://input'), true);
    $old = $req['old_password'] ?? '';
    $new = $req['new_password'] ?? '';
    if (empty($old) || empty($new)) jsonResponse(['error' => 'All fields are required.']);
    
    $stmt = $db->prepare("SELECT password FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($user && password_verify($old, $user['password'])) {
      $newHash = password_hash($new, PASSWORD_DEFAULT);
      $db->prepare("UPDATE users SET password = ? WHERE id = ?")->execute([$newHash, $userId]);
      jsonResponse(['status' => 'success']);
    }
    jsonResponse(['error' => 'Incorrect old password.']);
  }

  if ($api === 'export_data') {
    $stmt = $db->prepare("SELECT id, title, pinned, created_at FROM chats WHERE user_id = ?");
    $stmt->execute([$userId]);
    $userChats = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $exportedData = [];
    foreach ($userChats as $chat) {
      $mStmt = $db->prepare("SELECT id, parent_id, role, content, created_at FROM messages WHERE chat_id = ? ORDER BY created_at ASC");
      $mStmt->execute([$chat['id']]);
      $chat['messages'] = $mStmt->fetchAll(PDO::FETCH_ASSOC);
      $exportedData[] = $chat;
    }
    header('Content-Type: application/json');
    header('Content-Disposition: attachment; filename="phpchatai_export.json"');
    echo json_encode($exportedData, JSON_PRETTY_PRINT);
    exit;
  }

  if ($api === 'get_setting') {
    if (!$isAdmin) jsonResponse(['error' => 'Unauthorized']);
    jsonResponse(['value' => $hfToken]);
  }

  if ($api === 'save_setting') {
    if (!$isAdmin) jsonResponse(['error' => 'Unauthorized']);
    $req = json_decode(file_get_contents('php://input'), true);
    $stmt = $db->prepare("INSERT INTO settings (key, value) VALUES ('hf_token', ?) ON CONFLICT(key) DO UPDATE SET value = excluded.value");
    $stmt->execute([$req['value']]);
    jsonResponse(['status' => 'success']);
  }

  if ($api === 'search') {
    $query = $_GET['q'] ?? '';
    $searchContext = "";
    $urls = [];
    $snippets = [];

    // Attempt 1: DuckDuckGo GET (Bypasses POST restrictions)
    $opts = [
      'http' => [
        'method'  => 'GET',
        'header'  => "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36\r\nAccept-Language: en-US,en;q=0.5\r\n",
        'timeout' => 1.5
      ]
    ];
    $html = @file_get_contents('https://html.duckduckgo.com/html/?q=' . urlencode($query), false, stream_context_create($opts));
    
    if ($html && preg_match_all('/<a[^>]+class="[^"]*result__snippet[^"]*"[^>]+href="([^"]+)"[^>]*>(.*?)<\/a>/is', $html, $matches)) {
      $limit = min(100, count($matches[1]));
      for ($i = 0; $i < $limit; $i++) {
        $rawUrl = $matches[1][$i];
        $text = trim(strip_tags($matches[2][$i]));
        $url = $rawUrl;
        if (preg_match('/uddg=([^&]+)/', $rawUrl, $m)) {
          $url = urldecode($m[1]);
        }
        if (!empty($text) && filter_var($url, FILTER_VALIDATE_URL)) {
          $snippets[] = "- [Source: $url]\n  $text";
          $urls[] = $url;
        }
      }
    }

    // Attempt 2: Fallback to Wikipedia Search API (Highly resilient on Replit IPs)
    if (empty($snippets)) {
      $wikiUrl = 'https://en.wikipedia.org/w/api.php?action=query&list=search&srsearch=' . urlencode($query) . '&format=json&utf8=';
      $wikiOpts = [
        'http' => [
          'method' => 'GET',
          'header' => "User-Agent: PHPChatAI/1.0 (https://github.com/yourusername/phpchatai)\r\n",
          'timeout' => 1.5
        ]
      ];
      $wikiJson = @file_get_contents($wikiUrl, false, stream_context_create($wikiOpts));
      if ($wikiJson) {
        $wikiData = json_decode($wikiJson, true);
        if (isset($wikiData['query']['search'])) {
          foreach ($wikiData['query']['search'] as $wResult) {
            $title = $wResult['title'];
            $text = trim(strip_tags($wResult['snippet']));
            $url = 'https://en.wikipedia.org/wiki/' . str_replace(' ', '_', $title);
            if (!empty($text)) {
              $snippets[] = "- [Source: $url]\n  $text";
              $urls[] = $url;
            }
          }
        }
      }
    }

    if (!empty($snippets)) {
      $searchContext = "[Web Search Context:]\n" . implode("\n\n", $snippets) . "\n\n(Base your answer on the above context. Cite your sources inline using brief, named hyperlinks like [Reuters](URL) or [Wikipedia](URL) matching the domain of the source. Do not write full raw URLs in parentheses.)\n\n";
    }

    jsonResponse([
      'context' => $searchContext, 
      'urls' => array_slice(array_unique($urls), 0, 5)
    ]);
  }

  if ($api === 'chat') {
    if (empty($hfToken)) jsonResponse(['error' => 'API token not configured by administrator.']);
    
    $req = json_decode(file_get_contents('php://input'), true);
    $messages = $req['messages'] ?? [];
    $model = $req['model'] ?? 'Qwen/Qwen2.5-72B-Instruct';
    
    $formattedMessages = [];
    foreach ($messages as $m) {
      $formattedMessages[] = ['role' => $m['role'], 'content' => $m['content']];
    }
    
    if (!empty($formattedMessages)) {
      $lastIdx = count($formattedMessages) - 1;
      $lastContent = $formattedMessages[$lastIdx]['content'];

      // Search is now handled dynamically via frontend ?api=search
      
      // Think Feature (Force reasoning prompt)
      if (!empty($req['think'])) {
        $formattedMessages[$lastIdx]['content'] .= "\n\n(Please think step-by-step and wrap your detailed reasoning inside <think>...</think> tags before providing the final answer.)";
      }
    }
    
    $payload = [
      'model' => $model,
      'messages' => $formattedMessages,
      'max_tokens' => 2048,
      'temperature' => 0.7,
      'stream' => true // Enable API Streaming
    ];
    
    $options = [
      'http' => [
        'header'  => "Content-type: application/json\r\nAuthorization: Bearer " . trim($hfToken) . "\r\n",
        'method'  => 'POST',
        'content' => json_encode($payload),
        'ignore_errors' => true,
        'timeout' => 45
      ],
      'ssl' => ['verify_peer' => false, 'verify_peer_name' => false]
    ];
    
    $context = stream_context_create($options);
    $fp = @fopen("https://router.huggingface.co/v1/chat/completions", 'r', false, $context);
    
    if (!$fp) {
      $error = error_get_last();
      jsonResponse(['error' => 'DNS_ERROR: ' . ($error['message'] ?? 'Unable to resolve host.')]);
    }
    
    // Check for HTTP errors before streaming
    $meta = stream_get_meta_data($fp);
    if (isset($meta['wrapper_data'])) {
      $statusLine = $meta['wrapper_data'][0] ?? '';
      if (preg_match('/HTTP\/\d+\.\d+\s+(\d+)/', $statusLine, $m) && $m[1] !== '200') {
        $errBody = stream_get_contents($fp);
        $errDecoded = json_decode($errBody, true);
          
        $eMsg = 'Unknown API Error';
        if (isset($errDecoded['error'])) {
          $eMsg = is_array($errDecoded['error']) ? ($errDecoded['error']['message'] ?? json_encode($errDecoded['error'])) : $errDecoded['error'];
        }
          
        if (isset($errDecoded['estimated_time'])) {
          $eMsg = "Model is warming up. Try again in " . ceil($errDecoded['estimated_time']) . "s.";
        }
          
        echo "data: " . json_encode(['error' => $eMsg]) . "\n\n";
        fclose($fp);
        exit;
      }
    }
    
    header('Content-Type: text/event-stream');
    header('Cache-Control: no-cache');
    header('Connection: keep-alive');
    header('X-Accel-Buffering: no'); // Tells Nginx/Replit web server proxies not to buffer streaming tokens
    @ini_set('output_buffering', 'off');
    @ini_set('zlib.output_compression', false);
    while (ob_get_level()) ob_end_flush(); // Clear all server buffers immediately
    ob_implicit_flush(true);
    
    // Stream chunks back to client
    while (!feof($fp)) {
      $chunk = fgets($fp);
      if ($chunk !== false) {
        echo $chunk;
        @ob_flush();
        flush();
      }
    }
    fclose($fp);
    exit;
  }
}
$isLoggedIn = getUserId() !== null;
?>
<!doctype html>
<html lang="en">
  <head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no">
    <title>PHPChatAI</title>
    <link rel="manifest" href="data:application/manifest+json;base64,<?= $manifestBase64 ?>">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="PHPChatAI">
    <link rel="apple-touch-icon" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='%234d6bfe'%3E%3Cpath d='m10.75 11.5l7.075-7.075q.3-.3.7-.3t.7.3t.3.7t-.3.7l-7.05 7.075zm2.475 2.475l6.35-6.375q.3-.3.713-.3t.712.3t.3.713t-.3.712l-6.35 6.35zm-7.95 4.75Q3 16.45 3 13.25t2.275-5.475l3-3L9.75 6.25q.175.175.3.363T10.3 7L14 3.275q.3-.3.713-.3t.712.3t.3.712t-.3.713L11.1 9.025l-2.125 2.1l.475.475q1.15 1.15 1.1 2.75t-1.225 2.775l-1.425-1.4q.575-.575.638-1.362T8.025 13L6.85 11.85q-.3-.3-.3-.712t.3-.713l1.425-1.4q.3-.3.3-.713t-.3-.712l-1.6 1.6q-1.7 1.7-1.7 4.063t1.7 4.062t4.075 1.7t4.075-1.7l5.975-6q.3-.3.713-.3t.712.3t.3.713t-.3.712l-6 5.975Q13.95 21 10.75 21t-5.475-2.275M17 23.025V21q1.65 0 2.825-1.175T21 17h2.025q0 2.5-1.763 4.263T17 23.025M.975 7q0-2.5 1.763-4.262T7 .974V3Q5.35 3 4.175 4.175T3 7z'/%3E%3C/svg%3E">
    <link rel="icon" type="image/svg+xml" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='%234d6bfe'%3E%3Cpath d='m10.75 11.5l7.075-7.075q.3-.3.7-.3t.7.3t.3.7t-.3.7l-7.05 7.075zm2.475 2.475l6.35-6.375q.3-.3.713-.3t.712.3t.3.713t-.3.712l-6.35 6.35zm-7.95 4.75Q3 16.45 3 13.25t2.275-5.475l3-3L9.75 6.25q.175.175.3.363T10.3 7L14 3.275q.3-.3.713-.3t.712.3t.3.712t-.3.713L11.1 9.025l-2.125 2.1l.475.475q1.15 1.15 1.1 2.75t-1.225 2.775l-1.425-1.4q.575-.575.638-1.362T8.025 13L6.85 11.85q-.3-.3-.3-.712t.3-.713l1.425-1.4q.3-.3.3-.713t-.3-.712l-1.6 1.6q-1.7 1.7-1.7 4.063t1.7 4.062t4.075 1.7t4.075-1.7l5.975-6q.3-.3.713-.3t.712.3t.3.713t-.3.712l-6 5.975Q13.95 21 10.75 21t-5.475-2.275M17 23.025V21q1.65 0 2.825-1.175T21 17h2.025q0 2.5-1.763 4.263T17 23.025M.975 7q0-2.5 1.763-4.262T7 .974V3Q5.35 3 4.175 4.175T3 7z'/%3E%3C/svg%3E">
    
    <!-- Open Graph / Facebook -->
    <meta property="og:type" content="website">
    <meta property="og:title" content="PHPChatAI">
    <meta property="og:description" content="A private, modern AI chat assistant powered by HuggingFace.">
    <meta property="og:image" content="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' width='1200' height='630'><rect width='100%25' height='100%25' fill='%23131415'/><g transform='translate(480,100) scale(10)' fill='%234d6bfe'><path d='m10.75 11.5l7.075-7.075q.3-.3.7-.3t.7.3t.3.7t-.3.7l-7.05 7.075zm2.475 2.475l6.35-6.375q.3-.3.713-.3t.712.3t.3.713t-.3.712l-6.35 6.35zm-7.95 4.75Q3 16.45 3 13.25t2.275-5.475l3-3L9.75 6.25q.175.175.3.363T10.3 7L14 3.275q.3-.3.713-.3t.712.3t.3.712t-.3.713L11.1 9.025l-2.125 2.1l.475.475q1.15 1.15 1.1 2.75t-1.225 2.775l-1.425-1.4q.575-.575.638-1.362T8.025 13L6.85 11.85q-.3-.3-.3-.712t.3-.713l1.425-1.4q.3-.3.3-.713t-.3-.712l-1.6 1.6q-1.7 1.7-1.7 4.063t1.7 4.062t4.075 1.7t4.075-1.7l5.975-6q.3-.3.713-.3t.712.3t.3.713t-.3.712l-6 5.975Q13.95 21 10.75 21t-5.475-2.275M17 23.025V21q1.65 0 2.825-1.175T21 17h2.025q0 2.5-1.763 4.263T17 23.025M.975 7q0-2.5 1.763-4.262T7 .974V3Q5.35 3 4.175 4.175T3 7z'/></g><text x='50%25' y='85%25' font-family='sans-serif' font-size='50' fill='%234d6bfe' font-weight='bold' text-anchor='middle'>PHPChatAI</text></svg>">
    <meta property="og:site_name" content="PHPChatAI">

    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@300;400;500;700&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined" rel="stylesheet" />
    <script src="https://cdn.jsdelivr.net/npm/marked/marked.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/dompurify/3.0.6/purify.min.js"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/highlight.js/11.9.0/styles/atom-one-dark.min.css">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/highlight.js/11.9.0/highlight.min.js"></script>
    <style>
      :root {
        --md-sys-color-primary: #6750a4;
        --md-sys-color-on-primary: #ffffff;
        --md-sys-color-surface: #fef7ff;
        --md-sys-color-on-surface: #1d1b20;
        --md-sys-color-surface-variant: #e7e0ec;
        --md-sys-color-on-surface-variant: #49454f;
        --md-sys-color-outline: #79747e;
        --md-sys-color-background: #fef7ff;
        --md-sys-color-on-background: #1d1b20;
        --md-sys-color-primary-container: #eaddff;
        --md-sys-color-on-primary-container: #21005d;
      }
      body.dark {
        --md-sys-color-primary: #4d6bfe;
        --md-sys-color-on-primary: #ffffff;
        --md-sys-color-surface: #171819;
        --md-sys-color-on-surface: #ececec;
        --md-sys-color-surface-variant: #131415;
        --md-sys-color-on-surface-variant: #a3a3a3;
        --md-sys-color-outline: #424242;
        --md-sys-color-background: #171819;
        --md-sys-color-on-background: #ececec;
        --md-sys-color-primary-container: #2f3032;
        --md-sys-color-on-primary-container: #ececec;
      }
      * {
        box-sizing: border-box;
        margin: 0;
        padding: 0;
      }
      body {
        font-family: 'Roboto', sans-serif;
        background: var(--md-sys-color-background);
        color: var(--md-sys-color-on-background);
        height: 100dvh;
        display: flex;
        overflow: hidden;
        transition: background 0.3s, color 0.3s;
      }
      button {
        font-family: inherit;
        cursor: pointer;
        border: none;
        background: transparent;
        color: inherit;
      }
      #auth-screen {
        display: <?= $isLoggedIn ? 'none' : 'flex' ?>;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        width: 100%;
        height: 100%;
        background: var(--md-sys-color-background);
        z-index: 1000;
        position: absolute;
      }
      .auth-box {
        background: var(--md-sys-color-surface-variant);
        color: var(--md-sys-color-on-surface-variant);
        padding: 40px;
        border-radius: 28px;
        width: 90%;
        max-width: 400px;
        text-align: center;
      }
      .auth-box h1 {
        margin-bottom: 24px;
        font-weight: 500;
        color: var(--md-sys-color-on-surface);
      }
      .auth-box input {
        width: 100%;
        padding: 16px;
        margin-bottom: 16px;
        border-radius: 12px;
        border: 1px solid var(--md-sys-color-outline);
        background: var(--md-sys-color-background);
        color: var(--md-sys-color-on-background);
        font-size: 1rem;
        outline: none;
      }
      .auth-box button {
        width: 100%;
        padding: 16px;
        border-radius: 100px;
        background: var(--md-sys-color-primary);
        color: var(--md-sys-color-on-primary);
        font-size: 1rem;
        font-weight: 500;
        transition: box-shadow 0.2s;
        margin-bottom: 12px;
      }
      .auth-box button:hover {
        box-shadow: 0 1px 3px rgba(0,0,0,0.3);
      }
      .auth-switch {
        color: var(--md-sys-color-primary);
        font-size: 0.9rem;
        cursor: pointer;
        text-decoration: underline;
      }
      #app {
        display: <?= $isLoggedIn ? 'flex' : 'none' ?>;
        width: 100%;
        height: 100%;
      }
      #sidebar {
        width: 300px;
        background: var(--md-sys-color-surface-variant);
        display: flex;
        flex-direction: column;
        transition: transform 0.3s;
        z-index: 50;
      }
      .sidebar-header {
        padding: 24px 16px 12px;
        display: flex;
        flex-direction: column;
        gap: 16px;
      }
      .new-chat-btn {
        display: flex;
        align-items: center;
        gap: 12px;
        padding: 12px 16px;
        border-radius: 8px;
        background: var(--md-sys-color-primary);
        color: var(--md-sys-color-on-primary);
        font-weight: 500;
        transition: background 0.2s, opacity 0.2s;
        justify-content: center;
        font-size: 0.95rem;
      }
      .new-chat-btn:hover {
        opacity: 0.9;
      }
      .search-box {
        display: flex;
        align-items: center;
        background: var(--md-sys-color-background);
        padding: 8px 16px;
        border-radius: 28px;
        border: 1px solid var(--md-sys-color-outline);
      }
      .search-box input {
        border: none;
        background: transparent;
        color: var(--md-sys-color-on-background);
        width: 100%;
        outline: none;
        margin-left: 8px;
      }
      .chat-list {
        flex: 1;
        overflow-y: auto;
        padding: 0 12px 12px;
        display: flex;
        flex-direction: column;
        gap: 4px;
      }
      .chat-item {
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding: 10px 12px;
        border-radius: 8px;
        font-size: 0.9rem;
        color: var(--md-sys-color-on-surface-variant);
        transition: background 0.2s;
        margin-bottom: 2px;
      }
      .chat-item:hover {
        background: rgba(0, 0, 0, 0.05);
      }
      .chat-item.active {
        background: var(--md-sys-color-primary-container);
        color: var(--md-sys-color-on-primary-container);
      }
      .chat-item span.title {
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
        flex: 1;
        text-align: left;
        cursor: pointer;
      }
      .chat-actions {
        display: none;
        gap: 4px;
      }
      .chat-item:hover .chat-actions {
        display: flex;
      }
      .chat-actions button {
        opacity: 0.7;
      }
      .chat-actions button:hover {
        opacity: 1;
      }
      .sidebar-footer {
        padding: 12px;
        border-top: 1px solid var(--md-sys-color-outline);
        display: flex;
        flex-direction: column;
        gap: 4px;
      }
      .sidebar-btn {
        display: flex;
        align-items: center;
        gap: 12px;
        padding: 12px 16px;
        border-radius: 28px;
        transition: background 0.2s;
        font-size: 0.9rem;
        width: 100%;
        text-align: left;
      }
      .sidebar-btn:hover {
        background: rgba(0,0,0,0.05);
      }
      #main-view {
        flex: 1;
        display: flex;
        flex-direction: column;
        position: relative;
        background: var(--md-sys-color-background);
      }
      .topbar {
        padding: 12px 16px;
        display: flex;
        align-items: center;
        justify-content: space-between;
        background: var(--md-sys-color-background);
        border-bottom: 1px solid var(--md-sys-color-outline);
        box-shadow: none;
      }
      .topbar-left {
        display: flex;
        align-items: center;
        gap: 12px;
      }
      .mobile-menu-btn {
        display: none;
        padding: 8px;
        border-radius: 50%;
      }
      .mobile-menu-btn:hover {
        background: var(--md-sys-color-surface-variant);
      }
      #chat-container {
        flex: 1;
        overflow-y: auto;
        padding: 24px;
        display: flex;
        flex-direction: column;
        align-items: center;
        scroll-behavior: smooth;
      }
      .message-row {
        width: 100%;
        max-width: 800px;
        display: flex;
        margin-bottom: 24px;
        animation: fadeIn 0.3s ease;
      }
      @keyframes fadeIn {
        from { opacity: 0; transform: translateY(5px); }
        to { opacity: 1; transform: translateY(0); }
      }
      .message-row.user {
        justify-content: flex-end;
      }
      .message-row.assistant {
        justify-content: flex-start;
      }
      .message-content-wrapper {
        max-width: 85%;
        display: flex;
        flex-direction: column;
        gap: 6px;
      }
      .user .message-bubble {
        background: var(--md-sys-color-primary-container);
        color: var(--md-sys-color-on-primary-container);
        padding: 12px 20px;
        border-radius: 20px 20px 4px 20px;
        font-size: 1rem;
        line-height: 1.5;
        white-space: pre-wrap;
        word-wrap: break-word;
      }
      .assistant .message-bubble {
        background: transparent;
        color: var(--md-sys-color-on-surface);
        padding: 12px 0px;
        border-radius: 0;
        font-size: 1rem;
        line-height: 1.6;
        word-wrap: break-word;
        width: 100%;
        overflow-x: auto;
      }
      .markdown-body pre {
        background: #1e1e1e;
        color: #fff;
        padding: 12px;
        border-radius: 12px;
        overflow-x: auto;
        margin: 12px 0;
        font-size: 0.9rem;
        position: relative;
      }
      .markdown-body pre code {
        font-family: monospace;
      }
      .markdown-body p {
        margin-bottom: 12px;
      }
      .markdown-body p:last-child {
        margin-bottom: 0;
      }
      .markdown-body code:not(pre code) {
        background: rgba(128,128,128,0.2);
        padding: 2px 4px;
        border-radius: 4px;
        font-family: monospace;
        font-size: 0.9em;
      }
      .markdown-body ul, .markdown-body ol {
        margin-left: 24px;
        margin-bottom: 12px;
      }
      .markdown-body a, .think-content a {
        color: var(--md-sys-color-primary);
        text-decoration: none;
        word-wrap: break-word;
        overflow-wrap: break-word;
        word-break: break-all;
      }
      .markdown-body a:hover, .think-content a:hover {
        text-decoration: underline;
      }
      .think-content a {
        color: var(--md-sys-color-outline);
      }
      .message-controls {
        display: flex;
        align-items: center;
        gap: 8px;
        font-size: 0.85rem;
        color: var(--md-sys-color-outline);
        opacity: 0;
        transition: opacity 0.2s;
      }
      .message-row:hover .message-controls {
        opacity: 1;
      }
      .message-controls button {
        padding: 4px;
        border-radius: 50%;
        display: flex;
        align-items: center;
      }
      .message-controls button:hover {
        background: rgba(0,0,0,0.05);
        color: var(--md-sys-color-on-background);
      }
      .branch-nav {
        display: flex;
        align-items: center;
        gap: 4px;
        font-weight: 500;
      }
      .user .message-controls {
        justify-content: flex-end;
      }
      .input-area {
        padding: 16px 24px 24px;
        display: flex;
        justify-content: center;
        background: linear-gradient(180deg, transparent, var(--md-sys-color-background) 20%);
      }
      .input-box {
        width: 100%;
        max-width: 800px;
        background: var(--md-sys-color-surface-variant);
        border-radius: 16px;
        display: flex;
        flex-direction: column;
        padding: 12px 12px 12px 16px;
        border: 1px solid var(--md-sys-color-outline);
        box-shadow: 0 4px 15px rgba(0,0,0,0.1);
      }
      .input-box textarea {
        flex: 1;
        background: transparent;
        border: none;
        color: var(--md-sys-color-on-background);
        font-family: inherit;
        font-size: 1rem;
        line-height: 1.5;
        resize: none;
        min-height: 52px;
        max-height: 250px;
        padding: 14px 8px 14px 0;
        outline: none;
      }
      .input-actions {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-top: 4px;
      }
      .feature-toggles {
        display: flex;
        gap: 8px;
      }
      .toggle-btn {
        display: flex;
        align-items: center;
        gap: 4px;
        padding: 6px 12px;
        border-radius: 16px;
        border: 1px solid var(--md-sys-color-outline);
        background: transparent;
        color: var(--md-sys-color-on-surface-variant);
        font-size: 0.85rem;
        cursor: pointer;
        transition: 0.2s;
      }
      .toggle-btn:hover {
        background: rgba(0, 0, 0, 0.05);
      }
      .toggle-btn.active {
        background: var(--md-sys-color-primary-container);
        color: var(--md-sys-color-on-primary-container);
        border-color: transparent;
      }
      .toggle-btn.icon-only {
        padding: 8px;
        border-radius: 50%;
      }
      #attachments-container:not(:empty) {
        margin-bottom: 8px;
      }
      .file-pill {
        display: flex;
        align-items: center;
        gap: 6px;
        background: var(--md-sys-color-surface);
        border: 1px solid var(--md-sys-color-outline);
        padding: 4px 10px;
        border-radius: 16px;
        font-size: 0.85rem;
        color: var(--md-sys-color-on-surface);
      }
      .file-pill .remove-file {
        cursor: pointer;
        color: var(--md-sys-color-outline);
      }
      .file-pill .remove-file:hover {
        color: #ff5252;
      }
      .theme-select-btn {
        flex: 1;
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 6px;
        padding: 10px;
        border-radius: 8px;
        font-size: 0.85rem;
        border: 1px solid var(--md-sys-color-outline);
        background: transparent;
        color: var(--md-sys-color-on-surface-variant);
        transition: background 0.2s, color 0.2s, border-color 0.2s;
      }
      .theme-select-btn:hover {
        background: rgba(128, 128, 128, 0.05);
      }
      .theme-select-btn.active {
        background: var(--md-sys-color-primary-container);
        color: var(--md-sys-color-on-primary-container);
        border-color: var(--md-sys-color-primary);
      }
      .think-box {
        margin-bottom: 16px;
        border-left: 3px solid var(--md-sys-color-outline);
        background: rgba(0, 0, 0, 0.03);
        border-radius: 4px 12px 12px 4px;
      }
      .think-box summary {
        padding: 12px;
        cursor: pointer;
        font-weight: 500;
        font-size: 0.9rem;
        user-select: none;
        display: flex;
        align-items: center;
        color: var(--md-sys-color-on-surface);
      }
      .think-box .think-content {
        padding: 0 16px 16px 16px;
        font-size: 0.95rem;
        opacity: 0.9;
        color: var(--md-sys-color-on-surface);
      }
      .send-btn {
        background: var(--md-sys-color-primary);
        color: var(--md-sys-color-on-primary);
        border-radius: 50%;
        width: 40px;
        height: 40px;
        display: flex;
        align-items: center;
        justify-content: center;
        margin-bottom: 4px;
        transition: transform 0.1s;
      }
      .send-btn:disabled {
        opacity: 0.3;
        cursor: not-allowed;
      }
      .send-btn:not(:disabled):active {
        transform: scale(0.95);
      }
      #scroll-to-bottom-btn {
        position: absolute;
        bottom: 175px;
        right: 24px;
        background: var(--md-sys-color-surface-variant);
        color: var(--md-sys-color-on-surface);
        border: 1px solid var(--md-sys-color-outline);
        border-radius: 50%;
        width: 40px;
        height: 40px;
        display: flex;
        align-items: center;
        justify-content: center;
        cursor: pointer;
        box-shadow: 0 4px 10px rgba(0,0,0,0.3);
        z-index: 99;
        transition: opacity 0.2s, transform 0.2s;
        opacity: 0;
        pointer-events: none;
      }
      #scroll-to-bottom-btn.show {
        opacity: 1;
        pointer-events: auto;
      }
      #scroll-to-bottom-btn:hover {
        background: var(--md-sys-color-primary);
        color: var(--md-sys-color-on-primary);
      }
      @media (max-width: 768px) {
        #sidebar {
          position: absolute;
          height: 100%;
          transform: translateX(-100%);
          width: 280px;
          box-shadow: 2px 0 10px rgba(0,0,0,0.1);
        }
        #sidebar.open {
          transform: translateX(0);
        }
        .mobile-menu-btn {
          display: block;
        }
        .message-controls {
          opacity: 1;
        }
      }
      #overlay {
        display: none;
        position: absolute;
        inset: 0;
        background: rgba(0,0,0,0.5);
        z-index: 40;
      }
      #overlay.show {
        display: block;
      }
      .modal {
        display: none;
        position: fixed;
        inset: 0;
        background: rgba(0,0,0,0.5);
        z-index: 2000;
        align-items: center;
        justify-content: center;
      }
      .modal.show {
        display: flex;
      }
      .modal-content {
        background: var(--md-sys-color-surface-variant);
        color: var(--md-sys-color-on-surface);
        padding: 32px;
        border-radius: 16px;
        width: 90%;
        max-width: 500px;
        max-height: 80vh;
        overflow-y: auto;
        border: 1px solid var(--md-sys-color-outline);
        box-shadow: 0 10px 30px rgba(0,0,0,0.5);
      }
      .modal-content h2 {
        margin-bottom: 16px;
        font-weight: 400;
      }
      .modal-content input, .modal-content select {
        width: 100%;
        padding: 12px 16px;
        margin-bottom: 16px;
        border-radius: 12px;
        border: 1px solid var(--md-sys-color-outline);
        background: var(--md-sys-color-surface-variant);
        color: var(--md-sys-color-on-background);
        outline: none;
        font-family: inherit;
        font-size: 0.95rem;
      }
      .modal-content select {
        appearance: none;
        -webkit-appearance: none;
        -moz-appearance: none;
        background-image: url("data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' width='24' height='24' viewBox='0 0 24 24' fill='none' stroke='%238e918f' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'><polyline points='6 9 12 15 18 9'></polyline></svg>");
        background-repeat: no-repeat;
        background-position: right 16px center; /* Positions the arrow 24px away from the right edge */
        background-size: 16px;
        padding-right: 48px; /* Adds extra padding to prevent text from overlapping the arrow */
      }
      .modal-content .btn-group {
        display: flex;
        gap: 8px;
        justify-content: flex-end;
        margin-top: 16px;
      }
      .btn-primary {
        background: var(--md-sys-color-primary);
        color: var(--md-sys-color-on-primary);
        padding: 10px 24px;
        border-radius: 100px;
      }
      .btn-secondary {
        background: var(--md-sys-color-surface-variant);
        color: var(--md-sys-color-on-surface-variant);
        padding: 10px 24px;
        border-radius: 100px;
      }
      .settings-section {
        border-top: 1px solid var(--md-sys-color-outline);
        margin-top: 16px;
        padding-top: 16px;
      }
      .settings-section h3 {
        font-size: 1rem;
        margin-bottom: 12px;
        font-weight: 500;
      }
      .typing-indicator span {
        display: inline-block;
        width: 6px;
        height: 6px;
        background-color: var(--md-sys-color-on-surface-variant);
        border-radius: 50%;
        animation: bounce 1.4s infinite ease-in-out both;
        margin-right: 4px;
      }
      .typing-indicator span:nth-child(1) {
        animation-delay: -0.32s;
      }
      .typing-indicator span:nth-child(2) {
        animation-delay: -0.16s;
      }
      @keyframes bounce {
        0%, 80%, 100% { transform: scale(0); }
        40% { transform: scale(1); }
      }
      .error-text {
        color: #b3261e;
        font-weight: 500;
      }
      .markdown-body hr {
        border: 0;
        height: 1px;
        background: var(--md-sys-color-outline);
        opacity: 0.2;
        margin: 16px 0;
      }
      .assistant .message-controls {
        padding-left: 4px;
        margin-top: 8px;
      }
    </style>
  </head>
  <body class="dark">
    <div id="auth-screen">
      <div class="auth-box">
        <h1 id="auth-title">Welcome back</h1>
        <input type="text" id="auth-name" placeholder="Name" autocomplete="off" style="display: none;">
        <input type="email" id="auth-email" placeholder="Email" autocomplete="off">
        <input type="password" id="auth-pass" placeholder="Password">
        <button onclick="handleAuth()">Continue</button>
        <div class="auth-switch" onclick="toggleAuthMode()" id="auth-switch-text">Don't have an account? Sign up</div>
      </div>
    </div>
    
    <div id="app">
      <div id="overlay" onclick="toggleSidebar()"></div>
      <nav id="sidebar">
        <div class="sidebar-header">
          <button class="new-chat-btn" onclick="startNewChat()">
            <span class="material-symbols-outlined">add</span> New chat
          </button>
          <div class="search-box">
            <span class="material-symbols-outlined">search</span>
            <input type="text" id="search-input" placeholder="Search chats..." oninput="filterChats()">
          </div>
        </div>
        <div class="chat-list" id="chat-list"></div>
        <div class="sidebar-footer">
          <button class="sidebar-btn" id="install-pwa-btn" style="display: none;" onclick="triggerPwaInstall()">
            <span class="material-symbols-outlined">download</span> Install App
          </button>
          <button class="sidebar-btn" onclick="openSettings()">
            <span class="material-symbols-outlined">settings</span> Settings
          </button>
          <button class="sidebar-btn" onclick="logout()">
            <span class="material-symbols-outlined">logout</span> Log out
          </button>
        </div>
      </nav>
      
      <main id="main-view">
        <header class="topbar">
          <div class="topbar-left">
            <button class="mobile-menu-btn" onclick="toggleSidebar()">
              <span class="material-symbols-outlined">menu</span>
            </button>
            <div id="topbar-title" style="font-weight: 500; font-size: 1.1rem; margin-left: 4px;">PHPChatAI</div>
          </div>
          <div style="display: flex; gap: 4px;">
            <button onclick="startNewChat()" style="padding: 8px; border-radius: 50%; display: flex; align-items: center; justify-content: center;" title="New Chat">
              <span class="material-symbols-outlined">edit_square</span>
            </button>
          </div>
        </header>
        <div id="chat-container"></div>
        <button id="scroll-to-bottom-btn" onclick="scrollToBottom()" title="Scroll to bottom">
          <span class="material-symbols-outlined">arrow_downward</span>
        </button>
        <div class="input-area">
          <div class="input-box">
            <div id="attachments-container" style="display: flex; gap: 8px; flex-wrap: wrap;"></div>
            <textarea id="msg-input" rows="1" placeholder="Message PHPChatAI..."></textarea>
            <div class="input-actions">
              <div class="feature-toggles">
                <button id="btn-search" class="toggle-btn icon-only" onclick="toggleSearch()" title="Search the web">
                  <span class="material-symbols-outlined" style="font-size: 20px;">language</span>
                </button>
                <button id="btn-think" class="toggle-btn icon-only" onclick="toggleThink()" title="Think step-by-step">
                  <span class="material-symbols-outlined" style="font-size: 20px;">psychology</span>
                </button>
                <button class="toggle-btn icon-only" onclick="document.getElementById('txt-upload').click()" title="Upload a text file">
                  <span class="material-symbols-outlined" style="font-size: 20px;">upload_file</span>
                </button>
                <input type="file" id="txt-upload" accept=".txt,.csv,.json,.md" style="display:none" onchange="handleFileUpload(event)">
              </div>
              <button class="send-btn" id="send-btn" onclick="handleAction()">
                <span class="material-symbols-outlined" id="send-icon" style="font-size: 20px;">arrow_upward</span>
              </button>
            </div>
          </div>
        </div>
      </main>
    </div>

    <!-- Settings Modal -->
    <div id="settings-modal" class="modal">
      <div class="modal-content" style="max-width: 550px;">
        <style>
          .settings-tabs {
            display: flex;
            gap: 8px;
            border-bottom: 1px solid var(--md-sys-color-outline);
            margin-bottom: 20px;
            overflow-x: auto;
            white-space: nowrap;
            -webkit-overflow-scrolling: touch;
          }
          .settings-tab {
            padding: 8px 16px;
            font-weight: 500;
            font-size: 0.9rem;
            color: var(--md-sys-color-on-surface-variant);
            border-bottom: 2px solid transparent;
            cursor: pointer;
            transition: all 0.2s ease;
          }
          .settings-tab:hover {
            color: var(--md-sys-color-on-surface);
          }
          .settings-tab.active {
            color: var(--md-sys-color-primary);
            border-bottom-color: var(--md-sys-color-primary);
          }
          .settings-tab-content {
            display: none;
            animation: fadeIn 0.2s ease;
          }
          .settings-tab-content.active {
            display: block;
          }
        </style>
        <h2>Settings</h2>
        
        <div class="settings-tabs">
          <div class="settings-tab active" onclick="switchSettingsTab('tab-ai')">AI Model</div>
          <div class="settings-tab" onclick="switchSettingsTab('tab-appearance')">Appearance</div>
          <div class="settings-tab" onclick="switchSettingsTab('tab-account')">Account</div>
          <?php if ($isAdmin): ?>
            <div class="settings-tab" onclick="switchSettingsTab('tab-admin')">Admin</div>
          <?php endif; ?>
          <div class="settings-tab" onclick="switchSettingsTab('tab-backup')">Backup</div>
        </div>

        <!-- AI Model Tab -->
        <div id="tab-ai" class="settings-tab-content active">
          <div class="settings-section" style="border: none; padding-top: 0; margin-top: 0;">
            <h3>AI Model</h3>
            <input type="text" id="model-search-input" placeholder="Search HuggingFace models..." oninput="searchHFModels()" style="margin-bottom: 8px;">
            <select id="settings-model">
              <optgroup label="Search Results" id="hf-search-results" style="display:none;"></optgroup>
              <optgroup label="Available Models">
                <option value="deepseek-ai/DeepSeek-R1">DeepSeek R1 (Ultimate Reasoning)</option>
                <option value="Qwen/Qwen3-4B-Thinking-2507">Qwen 3 4B Thinking (Fast Reasoning)</option>
                <option value="meta-llama/Llama-3.3-70B-Instruct">Llama 3.3 70B Instruct</option>
                <option value="Qwen/Qwen2.5-72B-Instruct">Qwen 2.5 72B Instruct</option>
                <option value="zai-org/GLM-4.5">GLM-4.5 (Advanced General)</option>
                <option value="Qwen/Qwen2.5-Coder-32B-Instruct">Qwen 2.5 Coder 32B</option>
              </optgroup>
            </select>
          </div>
        </div>

        <!-- Appearance Tab -->
        <div id="tab-appearance" class="settings-tab-content">
          <div class="settings-section" style="border: none; padding-top: 0; margin-top: 0;">
            <h3>Appearance</h3>
            <div style="display: flex; gap: 8px; margin-top: 8px;">
              <button id="theme-btn-light" class="theme-select-btn" onclick="setThemeMode('light')">
                <span class="material-symbols-outlined">light_mode</span> Light
              </button>
              <button id="theme-btn-dark" class="theme-select-btn" onclick="setThemeMode('dark')">
                <span class="material-symbols-outlined">dark_mode</span> Dark
              </button>
              <button id="theme-btn-system" class="theme-select-btn" onclick="setThemeMode('system')">
                <span class="material-symbols-outlined">desktop_windows</span> System
              </button>
            </div>
          </div>
        </div>

        <!-- Account Tab -->
        <div id="tab-account" class="settings-tab-content">
          <div class="settings-section" style="border: none; padding-top: 0; margin-top: 0;">
            <h3>Change Password</h3>
            <input type="password" id="old-pass-input" placeholder="Old Password" autocomplete="new-password">
            <input type="password" id="new-pass-input" placeholder="New Password" autocomplete="new-password">
            <button class="btn-primary" onclick="updatePassword()" style="width: 100%; margin-top: 4px; padding: 10px; font-size: 0.9rem;">Change Password</button>
          </div>
        </div>

        <!-- Admin Tab -->
        <?php if ($isAdmin): ?>
          <div id="tab-admin" class="settings-tab-content">
            <div class="settings-section" style="border: none; padding-top: 0; margin-top: 0;">
              <h3>Admin HuggingFace Token</h3>
              <div style="position: relative; display: flex; align-items: center;">
                <input type="password" id="hf-token-input" placeholder="HuggingFace API Token" style="margin-bottom: 0; padding-right: 80px;">
                <div style="position: absolute; right: 16px; display: flex; gap: 12px; align-items: center; z-index: 10;">
                  <button type="button" onclick="toggleTokenVisibility()" style="display: flex; align-items: center; justify-content: center; color: var(--md-sys-color-on-surface-variant); cursor: pointer;" title="Toggle Visibility">
                    <span class="material-symbols-outlined" id="token-visibility-icon" style="font-size: 20px;">visibility_off</span>
                  </button>
                  <button type="button" onclick="copyAdminToken()" style="display: flex; align-items: center; justify-content: center; color: var(--md-sys-color-on-surface-variant); cursor: pointer;" title="Copy Token">
                    <span class="material-symbols-outlined" id="token-copy-icon" style="font-size: 20px;">content_copy</span>
                  </button>
                </div>
              </div>
            </div>
          </div>
        <?php endif; ?>

        <!-- Backup Tab -->
        <div id="tab-backup" class="settings-tab-content">
          <div class="settings-section" style="border: none; padding-top: 0; margin-top: 0;">
            <h3>Backup</h3>
            <button class="btn-secondary" style="width:100%;" onclick="exportData()">Export Chats (JSON)</button>
          </div>
        </div>

        <div class="btn-group" style="margin-top: 24px; border-top: 1px solid var(--md-sys-color-outline); padding-top: 16px;">
          <button class="btn-secondary" onclick="closeSettings()">Cancel</button>
          <button class="btn-primary" onclick="saveSettings()">Save</button>
        </div>
      </div>
    </div>

    <script>
      marked.setOptions({
        highlight: function(code, lang) {
          const language = hljs.getLanguage(lang) ? lang : 'plaintext';
          return hljs.highlight(code, { language }).value;
        },
        breaks: true
      });

      const userName = <?= json_encode($_SESSION['name'] ?? 'User') ?>;
      let isLoginMode = true;
      let chats = [];
      let messages = [];
      let currentChatId = null;
      let activeLeafId = null;
      let isWaiting = false;
      let abortController = null;
      let searchQuery = '';
      
      let isSearchActive = localStorage.getItem('isSearchActive') === 'true';
      let isThinkActive = localStorage.getItem('isThinkActive') === 'true';
      let attachedFiles = [];
      let thinkTimes = {};

      function applyTheme() {
        const mode = localStorage.getItem('theme_mode') || 'system';
        let isDark = false;
        if (mode === 'system') {
          isDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
        } else {
          isDark = (mode === 'dark');
        }
        document.body.classList.toggle('dark', isDark);
        
        ['light', 'dark', 'system'].forEach(m => {
          const btn = document.getElementById(`theme-btn-${m}`);
          if (btn) {
            btn.classList.toggle('active', m === mode);
          }
        });
      }

      function setThemeMode(mode) {
        localStorage.setItem('theme_mode', mode);
        applyTheme();
      }

      // Handle OS theme changes dynamically when "System" is active
      window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', () => {
        if ((localStorage.getItem('theme_mode') || 'system') === 'system') {
          applyTheme();
        }
      });

      // Run immediately on page load to prevent a white flash
      applyTheme();

      function toggleSearch() {
        isSearchActive = !isSearchActive;
        localStorage.setItem('isSearchActive', isSearchActive);
        document.getElementById('btn-search').classList.toggle('active', isSearchActive);
      }

      function toggleThink() {
        isThinkActive = !isThinkActive;
        localStorage.setItem('isThinkActive', isThinkActive);
        document.getElementById('btn-think').classList.toggle('active', isThinkActive);
      }

      function toggleAuthMode() {
        isLoginMode = !isLoginMode;
        document.getElementById('auth-title').textContent = isLoginMode ? 'Welcome back' : 'Create an account';
        document.getElementById('auth-switch-text').textContent = isLoginMode ? "Don't have an account? Sign up" : "Already have an account? Log in";
        document.getElementById('auth-name').style.display = isLoginMode ? 'none' : 'block';
      }

      async function handleAuth() {
        const n = document.getElementById('auth-name').value.trim();
        const e = document.getElementById('auth-email').value.trim();
        const p = document.getElementById('auth-pass').value.trim();
        
        if (!e || !p || (!isLoginMode && !n)) return alert("Please fill all required fields");
        
        const endpoint = isLoginMode ? '?api=login' : '?api=register';
        
        try {
          const res = await fetch(endpoint, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({name: n, email: e, password: p})
          });
          
          const rawText = await res.text();
          let data;
          
          try {
            data = JSON.parse(rawText);
          } catch (err) {
            console.error("Server Error Response:", rawText);
            return alert("Server error occurred. Please check the browser console.");
          }
          
          if (data.error) alert(data.error);
          else location.reload();
        } catch (err) {
          alert("Network error: " + err.message);
        }
      }

      async function logout() {
        await fetch('?api=logout');
        location.href = window.location.pathname;
      }

      function toggleSidebar() {
        document.getElementById('sidebar').classList.toggle('open');
        document.getElementById('overlay').classList.toggle('show');
      }

      function toggleTheme() {
        const isDark = document.body.classList.toggle('dark');
        localStorage.setItem('theme', isDark ? 'dark' : 'light');
        document.getElementById('theme-icon').textContent = isDark ? 'light_mode' : 'dark_mode';
        document.getElementById('theme-text').textContent = isDark ? 'Light mode' : 'Dark mode';
      }

      let hfSearchTimeout = null;
      async function searchHFModels() {
        const query = document.getElementById('model-search-input').value.trim();
        const resultsGroup = document.getElementById('hf-search-results');
        if (!query) {
          resultsGroup.style.display = 'none';
          resultsGroup.innerHTML = '';
          return;
        }
        
        clearTimeout(hfSearchTimeout);
        hfSearchTimeout = setTimeout(async () => {
          try {
            const res = await fetch(`https://huggingface.co/api/models?search=${encodeURIComponent(query)}&pipeline_tag=text-generation&sort=downloads&limit=15`);
            const data = await res.json();
            resultsGroup.innerHTML = '';
            if (data.length > 0) {
              data.forEach(model => {
                const opt = document.createElement('option');
                opt.value = model.modelId;
                opt.textContent = `${model.modelId} (${(model.downloads/1000).toFixed(1)}k DLs)`;
                resultsGroup.appendChild(opt);
              });
              resultsGroup.style.display = 'block';
              document.getElementById('settings-model').value = data[0].modelId; // Auto-select top result
            } else {
              resultsGroup.style.display = 'none';
            }
          } catch (e) {
            console.error('HF Search error', e);
          }
        }, 500); // Debounce to prevent API spam
      }

      function switchSettingsTab(tabId) {
        const tabs = document.querySelectorAll('.settings-tab');
        const contents = document.querySelectorAll('.settings-tab-content');
        
        tabs.forEach(tab => tab.classList.remove('active'));
        contents.forEach(content => content.classList.remove('active'));
        
        const targetContent = document.getElementById(tabId);
        if (targetContent) targetContent.classList.add('active');
        
        event.currentTarget.classList.add('active');
      }

      function openSettings() {
        // Reset tabs to default active view
        const firstTab = document.querySelector('.settings-tab');
        if (firstTab) {
          document.querySelectorAll('.settings-tab').forEach(t => t.classList.remove('active'));
          document.querySelectorAll('.settings-tab-content').forEach(c => c.classList.remove('active'));
          firstTab.classList.add('active');
          document.getElementById('tab-ai').classList.add('active');
        }

        const savedModel = localStorage.getItem('selected_model') || 'Qwen/Qwen2.5-72B-Instruct';
        const selectEl = document.getElementById('settings-model');
        
        // If the saved model isn't in the default list, add it dynamically
        if (!Array.from(selectEl.options).some(opt => opt.value === savedModel)) {
          const customOpt = document.createElement('option');
          customOpt.value = savedModel;
          customOpt.textContent = savedModel + ' (Custom)';
          selectEl.appendChild(customOpt);
        }
        
        selectEl.value = savedModel;
        document.getElementById('settings-modal').classList.add('show'); // Open immediately
        applyTheme(); // Refresh theme button active highlights
        
        const adminCheck = <?= $isAdmin ? 'true' : 'false' ?>;
        if (adminCheck) {
          fetch('?api=get_setting')
            .then(res => res.json())
            .then(data => {
              if (!data.error) document.getElementById('hf-token-input').value = data.value || '';
            }).catch(err => console.error(err));
        }
      }

      function closeSettings() {
        document.getElementById('settings-modal').classList.remove('show');
        document.getElementById('old-pass-input').value = '';
        document.getElementById('new-pass-input').value = '';
      }
      
      // Close modal when clicking/touching outside the container
      const handleModalOutClick = (e) => {
        const modal = document.getElementById('settings-modal');
        if (e.target === modal) closeSettings();
      };
      window.addEventListener('click', handleModalOutClick);
      window.addEventListener('touchstart', handleModalOutClick, {passive: true});

      async function saveSettings() {
        localStorage.setItem('selected_model', document.getElementById('settings-model').value);
        const adminCheck = <?= $isAdmin ? 'true' : 'false' ?>;
        if (adminCheck) {
          const token = document.getElementById('hf-token-input').value.trim();
          await fetch('?api=save_setting', {
            method: 'POST',
            body: JSON.stringify({value: token})
          });
        }
        closeSettings();
      }

      async function updatePassword() {
        const oldP = document.getElementById('old-pass-input').value.trim();
        const newP = document.getElementById('new-pass-input').value.trim();
        
        if (!oldP || !newP) {
          alert("Both old and new passwords are required to change your password.");
          return;
        }
        
        const res = await fetch('?api=change_password', {
          method: 'POST',
          body: JSON.stringify({old_password: oldP, new_password: newP})
        });
        const data = await res.json();
        if (data.error) {
          alert(data.error);
        } else {
          alert("Password updated successfully!");
          document.getElementById('old-pass-input').value = '';
          document.getElementById('new-pass-input').value = '';
        }
      }

      function exportData() {
        window.location.href = '?api=export_data';
      }

      function updateURL(id) {
        const currentUrl = new URL(window.location);
        if (id) currentUrl.searchParams.set('chat', id);
        else currentUrl.searchParams.delete('chat');
        history.pushState({chatId: id}, '', currentUrl.toString());
      }

      window.addEventListener('popstate', async (e) => {
        const urlParams = new URLSearchParams(window.location.search);
        const chatId = urlParams.get('chat');
        if (chatId) {
          await selectChat(chatId, false);
        } else {
          await startNewChat(false);
        }
      });

      async function loadChats() {
        const res = await fetch('?api=get_chats');
        chats = await res.json();
        renderChatList();
        
        const urlParams = new URLSearchParams(window.location.search);
        const chatIdFromUrl = urlParams.get('chat');
        
        // Stay on "New Chat" unless there is a specific chat requested in the URL params
        if (chatIdFromUrl && chats.some(c => c.id == chatIdFromUrl)) {
          await selectChat(chatIdFromUrl, false);
        } else {
          await startNewChat(false);
        }
      }

      function filterChats() {
        searchQuery = document.getElementById('search-input').value.toLowerCase().trim();
        renderChatList();
      }

      function renderChatList() {
        const list = document.getElementById('chat-list');
        list.innerHTML = '';
        const filtered = chats.filter(c => c.title.toLowerCase().includes(searchQuery));
        filtered.forEach(c => {
          const div = document.createElement('div');
          div.className = `chat-item ${c.id === currentChatId ? 'active' : ''}`;
          div.innerHTML = `
            <span class="material-symbols-outlined" style="font-size:16px; margin-right:8px;">${c.pinned == 1 ? 'keep' : 'chat'}</span>
            <span class="title" onclick="selectChat('${c.id}')">${c.title}</span>
            <div class="chat-actions">
              <button onclick="pinChat('${c.id}', ${c.pinned == 1 ? 0 : 1})" title="${c.pinned == 1 ? 'Unpin' : 'Pin'}">
                <span class="material-symbols-outlined" style="font-size:16px">${c.pinned == 1 ? 'keep_off' : 'keep'}</span>
              </button>
              <button onclick="renameChat('${c.id}')" title="Rename">
                <span class="material-symbols-outlined" style="font-size:16px">edit</span>
              </button>
              <button onclick="deleteChat('${c.id}')" title="Delete">
                <span class="material-symbols-outlined" style="font-size:16px">delete</span>
              </button>
            </div>
          `;
          list.appendChild(div);
        });
      }

      async function startNewChat(push = true) {
        currentChatId = null;
        if (push) updateURL(null);
        document.title = "PHPChatAI";
        document.getElementById('topbar-title').textContent = "PHPChatAI";
        messages = [];
        activeLeafId = null;
        renderChatList();
        renderMessages();
        if (window.innerWidth <= 768) document.getElementById('sidebar').classList.remove('open');
      }

      async function selectChat(id, push = true) {
        currentChatId = id;
        if (push) updateURL(id);
        
        // Dynamic title change matching active chat
        const activeChat = chats.find(c => c.id === id);
        const titleText = activeChat ? activeChat.title : "PHPChatAI";
        document.getElementById('topbar-title').textContent = titleText;
        
        if (activeChat) {
          document.title = `${activeChat.title} — PHPChatAI`;
        } else {
          document.title = "PHPChatAI";
        }

        const res = await fetch(`?api=get_messages&chat_id=${id}`);
        messages = await res.json();
        activeLeafId = messages.length > 0 ? messages[messages.length - 1].id : null;
        renderChatList();
        renderMessages();
        if (window.innerWidth <= 768) document.getElementById('sidebar').classList.remove('open');
      }

      async function deleteChat(id) {
        if (!confirm("Delete this chat?")) return;
        await fetch('?api=delete_chat', {
          method: 'POST',
          body: JSON.stringify({id})
        });
        if (currentChatId === id) {
          startNewChat(true);
        }
        await loadChats();
        
        // Auto-close sidebar on mobile
        if (window.innerWidth <= 768) {
          document.getElementById('sidebar').classList.remove('open');
          document.getElementById('overlay').classList.remove('show');
        }
      }

      async function renameChat(id) {
        let newTitle = prompt("Enter new chat name:");
        if (newTitle) {
          await fetch('?api=rename_chat', {
            method: 'POST',
            body: JSON.stringify({id: id, title: newTitle})
          });
          if (currentChatId === id) {
            document.title = `${newTitle} — PHPChatAI`;
            document.getElementById('topbar-title').textContent = newTitle;
          }
          await loadChats();
        }
      }

      async function pinChat(id, pinStatus) {
        await fetch('?api=pin_chat', {
          method: 'POST',
          body: JSON.stringify({id: id, pinned: pinStatus})
        });
        await loadChats();
      }

      function createId() {
        return Date.now().toString(36) + Math.random().toString(36).substr(2);
      }

      function getThreadPath(leafId) {
        let path = [];
        let currId = leafId;
        while (currId) {
          let msg = messages.find(m => m.id === currId);
          if (!msg) break;
          path.unshift(msg);
          currId = msg.parent_id;
        }
        return path;
      }

      function switchBranch(msgId, direction) {
        let msg = messages.find(m => m.id === msgId);
        let siblings = messages.filter(m => m.parent_id === msg.parent_id);
        let idx = siblings.findIndex(m => m.id === msgId);
        let targetIdx = idx + direction;
        if (targetIdx >= 0 && targetIdx < siblings.length) {
          activeLeafId = findDeepestLeaf(siblings[targetIdx].id);
          renderMessages();
        }
      }

      function findDeepestLeaf(startId) {
        let children = messages.filter(m => m.parent_id === startId);
        if (children.length === 0) return startId;
        return findDeepestLeaf(children[children.length - 1].id);
      }

      async function saveMessageToDB(msg) {
        await fetch('?api=save_message', {
          method: 'POST',
          body: JSON.stringify(msg)
        });
      }

      const inputEl = document.getElementById('msg-input');
      inputEl.addEventListener('input', function() {
        this.style.height = '24px';
        this.style.height = (this.scrollHeight) + 'px';
      });
      inputEl.addEventListener('keydown', function(e) {
        if (e.key === 'Enter' && !e.shiftKey) {
          e.preventDefault();
          if (!isWaiting) handleSend();
        }
      });

      function updateButtonUI() {
        const icon = document.getElementById('send-icon');
        icon.textContent = isWaiting ? 'stop' : 'arrow_upward';
      }

      function handleAction() {
        if (isWaiting) stopGeneration();
        else handleSend();
      }

      function stopGeneration() {
        if (abortController) {
          abortController.abort();
          abortController = null;
        }
        isWaiting = false;
        let aiMsg = messages.find(m => m.id === activeLeafId && m.role === 'assistant');
        if (aiMsg && aiMsg.content === '...') {
          aiMsg.content = '⚠️ Stopped by user.';
          aiMsg.isError = true;
          saveMessageToDB(aiMsg);
        }
        updateButtonUI();
        renderMessages();
      }

      async function handleSend() {
        let text = inputEl.value.trim();
        if (!text && attachedFiles.length === 0) return;
        
        const displayTitleText = inputEl.value.trim() || attachedFiles[0]?.name || "New Chat";
        
        // Append file contents to the text behind the scenes
        if (attachedFiles.length > 0) {
          let fileTexts = attachedFiles.map(f => `[File: ${f.name}]\n${f.content}\n[End of File]`).join('\n\n');
          text = text ? text + '\n\n' + fileTexts : fileTexts;
        }

        inputEl.value = '';
        inputEl.style.height = '24px';
        attachedFiles = [];
        renderAttachments();
        
        if (!currentChatId) {
          const res = await fetch('?api=create_chat', { method: 'POST' });
          const data = await res.json();
          currentChatId = data.id;
          updateURL(currentChatId);
          
          const title = displayTitleText.length > 25 ? displayTitleText.substring(0, 25) + '...' : displayTitleText;
          document.title = `${title} — PHPChatAI`;
          document.getElementById('topbar-title').textContent = title;
          await fetch('?api=rename_chat', {
            method: 'POST',
            body: JSON.stringify({id: currentChatId, title})
          });
          chats.unshift({id: currentChatId, title: title, pinned: 0});
          renderChatList();
        }
        
        let userMsg = {
          id: createId(),
          chat_id: currentChatId,
          parent_id: activeLeafId,
          role: 'user',
          content: text
        };
        messages.push(userMsg);
        activeLeafId = userMsg.id;
        saveMessageToDB(userMsg);
        renderMessages();
        await generateResponse(userMsg.id);
      }

      async function generateResponse(parentMsgId) {
        isWaiting = true;
        updateButtonUI();
        let aiMsg = {
          id: createId(),
          chat_id: currentChatId,
          parent_id: parentMsgId,
          role: 'assistant',
          content: '...'
        };
        messages.push(aiMsg);
        activeLeafId = aiMsg.id;
        renderMessages();
        
        let contextPath = getThreadPath(parentMsgId);
        // Deep clone contextPath for the API payload to avoid mutating UI message bubbles
        let apiMessages = contextPath.map(m => ({ role: m.role, content: m.content }));
        let selectedModel = localStorage.getItem('selected_model') || 'Qwen/Qwen2.5-72B-Instruct';
        abortController = new AbortController();
        
        try {
          if (isSearchActive) {
            // Exclude file contents from the search query so we only search the user's text
            const lastUserMsgClean = apiMessages[apiMessages.length - 1].content.replace(/\[File: .*?\]\n[\s\S]*?\n\[End of File\]/g, '').trim();
            const cleanQuery = cleanSearchQuery(lastUserMsgClean);
            
            let searchStart = Date.now();
            let searchInterval = setInterval(() => {
              let elapsed = ((Date.now() - searchStart) / 1000).toFixed(1);
              aiMsg.content = `<search>Searching for "${cleanQuery}"... (${elapsed}s)</search>`;
              updateMessageBubble(aiMsg.id, aiMsg.content);
            }, 100);
            
            const searchRes = await fetch('?api=search&q=' + encodeURIComponent(cleanQuery));
            const searchData = await searchRes.json();
            
            clearInterval(searchInterval);
            let searchDuration = ((Date.now() - searchStart) / 1000).toFixed(1);
            
            if (searchData.context) {
              apiMessages[apiMessages.length - 1].content = searchData.context + apiMessages[apiMessages.length - 1].content;
              searchUrls = searchData.urls || [];
              aiMsg.content = `<search>Analyzed ${searchUrls.length} web sources. (${searchDuration}s)</search>\n\n`;
            } else {
              aiMsg.content = `<search>Web search returned 0 sources. (${searchDuration}s)</search>\n\n`;
            }
            updateMessageBubble(aiMsg.id, aiMsg.content);
          } else {
            aiMsg.content = '';
          }

          let res = await fetch('?api=chat', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ 
              model: selectedModel, 
              messages: apiMessages,
              search: isSearchActive,
              think: isThinkActive
            }),
            signal: abortController.signal
          });

          // Start the AI thinking count-up timer
          let thinkStart = Date.now();
          let thinkInterval = setInterval(() => {
            if (!isWaiting) {
              clearInterval(thinkInterval);
              return;
            }
            let elapsed = ((Date.now() - thinkStart) / 1000).toFixed(1);
            let msg = messages.find(m => m.id === aiMsg.id);
            if (msg) {
              if (msg.content.includes('</think>')) {
                if (!thinkTimes[msg.id] || !thinkTimes[msg.id].done) {
                  thinkTimes[msg.id] = { elapsed: elapsed, done: true };
                }
              } else {
                thinkTimes[msg.id] = { elapsed: elapsed, done: false };
              }
              updateMessageBubble(msg.id, msg.content);
            }
          }, 100);

          const reader = res.body.getReader();
          const decoder = new TextDecoder("utf-8");
          aiMsg.content = ''; // Clear typing indicator

          while (true) {
            const { done, value } = await reader.read();
            if (done) break;
            
            const chunk = decoder.decode(value, { stream: true });
            const lines = chunk.split('\n');
            
            for (let line of lines) {
              if (line.trim() === '') continue;
              if (line.startsWith('data: ')) {
                const dataStr = line.substring(6).trim();
                if (dataStr === '[DONE]') continue;
                
                try {
                  const parsed = JSON.parse(dataStr);
                  if (parsed.error) {
                    let errMsg = typeof parsed.error === 'object' ? (parsed.error.message || JSON.stringify(parsed.error)) : parsed.error;
                    aiMsg.content = '⚠️ ' + errMsg;
                    aiMsg.isError = true;
                  } else if (parsed.choices && parsed.choices[0].delta && parsed.choices[0].delta.content) {
                    aiMsg.content += parsed.choices[0].delta.content;
                    updateMessageBubble(aiMsg.id, aiMsg.content);
                  }
                } catch(e) {}
              }
            }
          }
          
          // Append reference links cleanly below the output using hostname anchors
          if (isSearchActive && searchUrls.length > 0) {
            aiMsg.content += '\n\n---\n**Sources & References:**\n' + searchUrls.map(u => `- [${getDomainName(u)}](${u})`).join('\n');
            updateMessageBubble(aiMsg.id, aiMsg.content);
          }
        } catch (e) {
          if (e.name !== 'AbortError') {
            aiMsg.content = '⚠️ Connection Error.';
            aiMsg.isError = true;
          }
        }
        
        isWaiting = false;
        updateButtonUI();
        saveMessageToDB(aiMsg);
        renderMessages(); // Final render to add action controls (copy, regenerate)
      }

      function updateMessageBubble(id, content) {
        let el = document.getElementById('msg-bubble-' + id);
        if (el) {
          el.innerHTML = processContent(content, id);
          const container = document.getElementById('chat-container');
          // Autoscroll down naturally while it's generating
          if (container.scrollHeight - container.scrollTop - container.clientHeight < 150) {
            container.scrollTop = container.scrollHeight;
          }
        }
      }

      async function handleEditSave(msgId, newText) {
        let msg = messages.find(m => m.id === msgId);
        if (msg.content === newText) return renderMessages();
        
        // Create a new branch for the edited user message
        let newUserMsg = {
          id: createId(),
          chat_id: currentChatId,
          parent_id: msg.parent_id,
          role: 'user',
          content: newText
        };
        messages.push(newUserMsg);
        activeLeafId = newUserMsg.id;
        await saveMessageToDB(newUserMsg);
        generateResponse(newUserMsg.id);
      }

      function handleRegenerate(msgId) {
        let msg = messages.find(m => m.id === msgId);
        generateResponse(msg.parent_id);
      }

      function copyText(text) {
        navigator.clipboard.writeText(text);
      }

      function getDomainName(url) {
        try {
          const parsed = new URL(url);
          return parsed.hostname.replace('www.', '');
        } catch (e) {
          return 'Source';
        }
      }

      function cleanSearchQuery(query) {
        let clean = query
          .replace(/search on the internet about/gi, '')
          .replace(/search the web for/gi, '')
          .replace(/search the internet about/gi, '')
          .replace(/search about/gi, '')
          .replace(/google about/gi, '')
          .replace(/google for/gi, '')
          .replace(/look up/gi, '')
          .replace(/find info on/gi, '')
          .replace(/find information on/gi, '')
          .replace(/tell me about/gi, '')
          .replace(/who is/gi, '')
          .replace(/what is/gi, '')
          .replace(/please/gi, '')
          .replace(/[!?.()]/g, '')
          .trim();
        return clean || query;
      }

      function processContent(text, msgId = null) {
        let thinkContent = '';
        let searchContent = '';
        let mainContent = text;

        // Clean up raw paragraph markdown links where the link text is just a long raw URL
        mainContent = mainContent.replace(/\[(https?:\/\/[^\s\]]+)\]\(\1\)/gi, (match, url) => {
          return `[${getDomainName(url)}](${url})`;
        });

        // Clean up standalone URLs in parentheses, converting e.g. (https://reuters.com/xyz) to ([reuters.com](url))
        mainContent = mainContent.replace(/\((https?:\/\/[^\s\)]+)\)/gi, (match, url) => {
          return `([${getDomainName(url)}](${url}))`;
        });
        
        // Extract ALL search blocks
        const searchRegex = /<search>([\s\S]*?)(?:<\/search>|$)/gi;
        let sMatch;
        while ((sMatch = searchRegex.exec(mainContent)) !== null) {
          searchContent += sMatch[1].trim() + ' ';
        }
        mainContent = mainContent.replace(/<search>[\s\S]*?(?:<\/search>|$)/gi, '').trim();

        // Extract ALL think blocks
        const thinkRegex = /<think>([\s\S]*?)(?:<\/think>|$)/gi;
        let match;
        while ((match = thinkRegex.exec(mainContent)) !== null) {
          thinkContent += match[1].trim() + '\n\n';
        }
        mainContent = mainContent.replace(/<think>[\s\S]*?(?:<\/think>|$)/gi, '').trim();

        let html = '';

        // Render the Search indicator separately
        if (searchContent) {
          const isSearching = searchContent.includes('Searching for');
          const iconAnimation = isSearching ? 'animation: blink 1.2s infinite ease-in-out;' : '';
          
          html += `<style>@keyframes blink { 0% { opacity: 1; } 50% { opacity: 0.4; } 100% { opacity: 1; } }</style>
          <div style="margin-bottom: 12px; font-size: 0.85rem; color: var(--md-sys-color-primary); display: flex; align-items: center; gap: 6px; font-weight: 500;">
            <span class="material-symbols-outlined" style="font-size:16px; ${iconAnimation}">travel_explore</span>
            <span style="${iconAnimation}">${searchContent}</span>
          </div>`;
        }
        
        // Render the Thinking box separately
        if (thinkContent) {
          const isClosed = text.includes('</think>');
          let summary = isClosed ? 'Thought Process' : 'Thinking...';
          const openAttr = isClosed ? '' : 'open';

          // Assign dynamic time stamp summary if timer information exists
          if (msgId && thinkTimes[msgId]) {
            const t = thinkTimes[msgId];
            summary = t.done ? `Thought for ${t.elapsed}s` : `Thinking... (${t.elapsed}s)`;
          } else if (isClosed) {
            summary = 'Thought Process';
          }
          
          let parsedThink = DOMPurify.sanitize(marked.parse(thinkContent));
          
          html += `<details class="think-box" ${openAttr}>
            <summary><span class="material-symbols-outlined" style="font-size:16px; margin-right:6px;">psychology</span>${summary}</summary>
            <div class="think-content" style="color: var(--md-sys-color-outline); font-size: 0.95em;">${parsedThink}</div>
          </details>`;
        }
        
        // Render the main AI response below it
        if (mainContent) {
          html += DOMPurify.sanitize(marked.parse(mainContent));
        }
        
        return html;
      }

      function renderMessages() {
        const container = document.getElementById('chat-container');
        container.innerHTML = '';
        if (messages.length === 0) {
          container.innerHTML = `
            <div style="margin:auto; text-align:center; opacity:0.8; animation: fadeIn 0.5s ease; display: flex; flex-direction: column; align-items: center; justify-content: center;">
              <div style="color: #4d6bfe; font-size: 80px; margin-bottom: 16px; line-height: 1; display: flex; align-items: center; justify-content: center; width: 80px; height: 80px;">
                <svg xmlns="http://www.w3.org/2000/svg" width="1em" height="1em" viewBox="0 0 24 24" style="width: 100%; height: 100%;"><title>waving-hand-outline</title><path fill="currentColor" d="m10.75 11.5l7.075-7.075q.3-.3.7-.3t.7.3t.3.7t-.3.7l-7.05 7.075zm2.475 2.475l6.35-6.375q.3-.3.713-.3t.712.3t.3.713t-.3.712l-6.35 6.35zm-7.95 4.75Q3 16.45 3 13.25t2.275-5.475l3-3L9.75 6.25q.175.175.3.363T10.3 7L14 3.275q.3-.3.713-.3t.712.3t.3.712t-.3.713L11.1 9.025l-2.125 2.1l.475.475q1.15 1.15 1.1 2.75t-1.225 2.775l-1.425-1.4q.575-.575.638-1.362T8.025 13L6.85 11.85q-.3-.3-.3-.712t.3-.713l1.425-1.4q.3-.3.3-.713t-.3-.712l-1.6 1.6q-1.7 1.7-1.7 4.063t1.7 4.062t4.075 1.7t4.075-1.7l5.975-6q.3-.3.713-.3t.712.3t.3.713t-.3.712l-6 5.975Q13.95 21 10.75 21t-5.475-2.275M17 23.025V21q1.65 0 2.825-1.175T21 17h2.025q0 2.5-1.763 4.263T17 23.025M.975 7q0-2.5 1.763-4.262T7 .974V3Q5.35 3 4.175 4.175T3 7z"/></svg>
              </div>
              <h2 style="margin-bottom: 8px; font-weight: 500;">Hello, ${userName}!</h2>
              <p style="color: var(--md-sys-color-on-surface-variant);">How can I assist you today?</p>
            </div>`;
          return;
        }
        let path = getThreadPath(activeLeafId);
        path.forEach(msg => {
          let siblings = messages.filter(m => m.parent_id === msg.parent_id);
          let index = siblings.findIndex(m => m.id === msg.id);
          let div = document.createElement('div');
          div.className = `message-row ${msg.role}`;
          let wrapper = document.createElement('div');
          wrapper.className = 'message-content-wrapper';
          let bubble = document.createElement('div');
          bubble.className = 'message-bubble markdown-body';
          bubble.id = 'msg-bubble-' + msg.id; // Allow streaming UI targeted updates
          
          if (msg.isError) bubble.classList.add('error-text');
          
          if (msg.content === '...' && isWaiting && msg.id === activeLeafId) {
            bubble.innerHTML = '<div class="typing-indicator"><span></span><span></span><span></span></div>';
          } else {
            if (msg.role === 'user') {
              let safeText = msg.content
                .replace(/&/g, "&amp;")
                .replace(/</g, "&lt;")
                .replace(/>/g, "&gt;")
                .replace(/"/g, "&quot;")
                .replace(/'/g, "&#039;");
              
              // Parse files into pills instead of raw text
              safeText = safeText.replace(/\[File: (.*?)\]\n[\s\S]*?\n\[End of File\]/g, '<div class="file-pill" style="display:inline-flex; margin:4px 4px 4px 0; background:rgba(0,0,0,0.1); border:none;"><span class="material-symbols-outlined" style="font-size:16px;">description</span> $1</div>');
              bubble.innerHTML = safeText;
            }
            else bubble.innerHTML = processContent(msg.content, msg.id);
          }
          
          wrapper.appendChild(bubble);
          
          if (!isWaiting || msg.id !== activeLeafId) {
            let controls = document.createElement('div');
            controls.className = 'message-controls';
            if (siblings.length > 1) {
              let nav = document.createElement('div');
              nav.className = 'branch-nav';
              nav.innerHTML = `
                <button onclick="switchBranch('${msg.id}', -1)" ${index === 0 ? 'disabled' : ''}><span class="material-symbols-outlined" style="font-size:16px;">chevron_left</span></button>
                <span>${index + 1}/${siblings.length}</span>
                <button onclick="switchBranch('${msg.id}', 1)" ${index === siblings.length - 1 ? 'disabled' : ''}><span class="material-symbols-outlined" style="font-size:16px;">chevron_right</span></button>
              `;
              controls.appendChild(nav);
            }
            if (msg.role === 'user') {
              let copyUserBtn = document.createElement('button');
              copyUserBtn.innerHTML = '<span class="material-symbols-outlined" style="font-size:16px;">content_copy</span>';
              copyUserBtn.onclick = () => {
                copyText(msg.content);
                copyUserBtn.innerHTML = '<span class="material-symbols-outlined" style="font-size:16px;">check</span>';
                setTimeout(() => copyUserBtn.innerHTML = '<span class="material-symbols-outlined" style="font-size:16px;">content_copy</span>', 2000);
              };
              controls.appendChild(copyUserBtn);

              let editBtn = document.createElement('button');
              editBtn.innerHTML = '<span class="material-symbols-outlined" style="font-size:16px;">edit</span>';
              editBtn.onclick = () => {
                bubble.innerHTML = `<textarea style="width:100%; min-height:150px; background:var(--md-sys-color-primary-container); color:var(--md-sys-color-on-primary-container); padding:16px; border-radius:12px; border:1px solid var(--md-sys-color-primary); outline:none; font-family:inherit; font-size:1rem; font-weight:normal; resize:vertical;">${msg.content}</textarea>
                <div style="display:flex; justify-content:flex-end; gap:8px; margin-top:8px;">
                  <button onclick="renderMessages()" style="padding:8px 16px; border-radius:16px; background:var(--md-sys-color-surface-variant);">Cancel</button>
                  <button onclick="handleEditSave('${msg.id}', this.parentElement.previousElementSibling.value)" style="padding:8px 16px; border-radius:16px; background:var(--md-sys-color-primary); color:var(--md-sys-color-on-primary);">Save</button>
                </div>`;
                controls.style.display = 'none';
              };
              controls.appendChild(editBtn);
            } else {
              let copyBtn = document.createElement('button');
              copyBtn.innerHTML = '<span class="material-symbols-outlined" style="font-size:16px;">content_copy</span>';
              copyBtn.onclick = () => {
                // Remove both <think> and <search> blocks entirely when copying
                let textToCopy = msg.content.replace(/<think>[\s\S]*?<\/think>/gi, '').replace(/<search>[\s\S]*?<\/search>/gi, '').trim();
                copyText(textToCopy);
                copyBtn.innerHTML = '<span class="material-symbols-outlined" style="font-size:16px;">check</span>';
                setTimeout(() => copyBtn.innerHTML = '<span class="material-symbols-outlined" style="font-size:16px;">content_copy</span>', 2000);
              };
              controls.appendChild(copyBtn);
              let regenBtn = document.createElement('button');
              regenBtn.innerHTML = '<span class="material-symbols-outlined" style="font-size:16px;">refresh</span>';
              regenBtn.onclick = () => handleRegenerate(msg.id);
              controls.appendChild(regenBtn);
            }
            wrapper.appendChild(controls);
          }
          div.appendChild(wrapper);
          container.appendChild(div);
        });
        document.getElementById('msg-input').disabled = isWaiting;
        setTimeout(() => { container.scrollTop = container.scrollHeight; }, 50);
      }

      function renderAttachments() {
        const container = document.getElementById('attachments-container');
        container.innerHTML = '';
        attachedFiles.forEach((file, index) => {
          const pill = document.createElement('div');
          pill.className = 'file-pill';
          pill.innerHTML = `
            <span class="material-symbols-outlined" style="font-size:16px;">description</span>
            <span style="max-width: 150px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">${file.name}</span>
            <span class="material-symbols-outlined remove-file" style="font-size:16px;" onclick="removeAttachment(${index})">close</span>
          `;
          container.appendChild(pill);
        });
      }

      function removeAttachment(index) {
        attachedFiles.splice(index, 1);
        renderAttachments();
      }

      function handleFileUpload(event) {
        const file = event.target.files[0];
        if (!file) return;
        
        const reader = new FileReader();
        reader.onload = function(e) {
          attachedFiles.push({
            name: file.name,
            content: e.target.result
          });
          renderAttachments();
        };
        reader.readAsText(file);
        event.target.value = ''; 
      }

      function scrollToBottom() {
        const container = document.getElementById('chat-container');
        container.scrollTo({
          top: container.scrollHeight,
          behavior: 'smooth'
        });
      }

      let deferredPrompt = null;

      // Capture browser's installation event eligibility
      window.addEventListener('beforeinstallprompt', (e) => {
        e.preventDefault();
        deferredPrompt = e;
        const installBtn = document.getElementById('install-pwa-btn');
        if (installBtn) {
          installBtn.style.display = 'flex'; // Show button when installer is ready
        }
      });

      async function triggerPwaInstall() {
        if (!deferredPrompt) return;
        deferredPrompt.prompt();
        const { outcome } = await deferredPrompt.userChoice;
        deferredPrompt = null;
        const installBtn = document.getElementById('install-pwa-btn');
        if (installBtn) {
          installBtn.style.display = 'none';
        }
      }

      window.addEventListener('appinstalled', (event) => {
        deferredPrompt = null;
        const installBtn = document.getElementById('install-pwa-btn');
        if (installBtn) {
          installBtn.style.display = 'none';
        }
      });

      function toggleTokenVisibility() {
        const input = document.getElementById('hf-token-input');
        const icon = document.getElementById('token-visibility-icon');
        if (input && icon) {
          if (input.type === 'password') {
            input.type = 'text';
            icon.textContent = 'visibility';
          } else {
            input.type = 'password';
            icon.textContent = 'visibility_off';
          }
        }
      }

      function copyAdminToken() {
        const input = document.getElementById('hf-token-input');
        const icon = document.getElementById('token-copy-icon');
        if (input && input.value && icon) {
          navigator.clipboard.writeText(input.value);
          icon.textContent = 'check';
          setTimeout(() => {
            icon.textContent = 'content_copy';
          }, 2000);
        }
      }

      document.addEventListener("DOMContentLoaded", () => {
        // Register PWA Service Worker
        if ('serviceWorker' in navigator) {
          navigator.serviceWorker.register('?sw=1', { scope: './' })
            .catch(err => console.log('ServiceWorker registration failed:', err));
        }

        // Apply persisted search/think active classes on reload
        document.getElementById('btn-search').classList.toggle('active', isSearchActive);
        document.getElementById('btn-think').classList.toggle('active', isThinkActive);

        const chatContainer = document.getElementById('chat-container');
        if (chatContainer) {
          chatContainer.addEventListener('scroll', () => {
            const btn = document.getElementById('scroll-to-bottom-btn');
            const offset = chatContainer.scrollHeight - chatContainer.clientHeight - chatContainer.scrollTop;
            if (offset > 300) {
              btn.classList.add('show');
            } else {
              btn.classList.remove('show');
            }
          });
        }

        if (<?= $isLoggedIn ? 'true' : 'false' ?>) {
          const tokenMissing = <?= empty($hfToken) ? 'true' : 'false' ?>;
          const userIsAdmin = <?= $isAdmin ? 'true' : 'false' ?>;
          
          if (tokenMissing) {
            if (userIsAdmin) {
              openSettings();
            } else {
              alert("The administrator hasn't configured the AI API token yet. The chat may not respond correctly.");
            }
          }
          loadChats();
        }
      });
    </script>
  </body>
</html>
