<?php
session_start();
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
    
    $stmt = $db->prepare("INSERT INTO messages (id, chat_id, parent_id, role, content) VALUES (?, ?, ?, ?, ?)");
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
    $model = $req['model'] ?? 'Qwen/Qwen2.5-7B-Instruct';
    
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
          $eMsg = $errDecoded['error'] ?? 'Unknown API Error';
          if (isset($errDecoded['estimated_time'])) $eMsg = "Model is warming up. Try again in " . ceil($errDecoded['estimated_time']) . "s.";
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
    <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='%234d6bfe'><path d='M23 12c0-2.38-.85-4.56-2.26-6.26l-1.41 1.41C20.45 8.52 21 10.18 21 12s-.55 3.48-1.67 4.85l1.41 1.41C22.15 16.56 23 14.38 23 12zM2 12c0 2.38.85 4.56 2.26 6.26l1.41-1.41C4.55 15.48 4 13.82 4 12s.55-3.48 1.67-4.85l-1.41-1.41C2.15 7.44 2 9.62 2 12zm16.5 0c0-1.38-.5-2.63-1.31-3.61l-1.41 1.41c.45.57.72 1.28.72 2.2s-.27 1.63-.72 2.2l1.41 1.41c.81-.98 1.31-2.23 1.31-3.61zm-11 0c0 1.38.5 2.63 1.31 3.61l1.41-1.41c-.45-.57-.72-1.28-.72-2.2s.27-1.63.72-2.2L8.81 8.39C8 9.37 7.5 10.62 7.5 12zm8.5-7.5c0-.83-.67-1.5-1.5-1.5s-1.5.67-1.5 1.5v6c0 .28-.22.5-.5.5s-.5-.22-.5-.5V1.5C12 .67 11.33 0 10.5 0S9 .67 9 1.5v9.5c0 .28-.22.5-.5.5s-.5-.22-.5-.5V3C8 2.17 7.33 1.5 6.5 1.5S5 2.17 5 3v10.5c0 .28-.22.5-.5.5s-.5-.22-.5-.5V6C4 5.17 3.33 4.5 2.5 4.5S1 5.17 1 6v10.5c0 4.14 3.36 7.5 7.5 7.5h4c4.14 0 7.5-3.36 7.5-7.5V11c0-.83-.67-1.5-1.5-1.5s-1.5.67-1.5 1.5v-3c0-.83-.67-1.5-1.5-1.5s-1.5.67-1.5 1.5v-2z'/></svg>">
    
    <!-- Open Graph / Facebook -->
    <meta property="og:type" content="website">
    <meta property="og:title" content="PHPChatAI">
    <meta property="og:description" content="A private, modern AI chat assistant powered by HuggingFace.">
    <meta property="og:image" content="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' width='1200' height='630'><rect width='100%25' height='100%25' fill='%23131415'/><g transform='translate(480,100) scale(10)' fill='%234d6bfe'><path d='M23 12c0-2.38-.85-4.56-2.26-6.26l-1.41 1.41C20.45 8.52 21 10.18 21 12s-.55 3.48-1.67 4.85l1.41 1.41C22.15 16.56 23 14.38 23 12zM2 12c0 2.38.85 4.56 2.26 6.26l1.41-1.41C4.55 15.48 4 13.82 4 12s.55-3.48 1.67-4.85l-1.41-1.41C2.15 7.44 2 9.62 2 12zm16.5 0c0-1.38-.5-2.63-1.31-3.61l-1.41 1.41c.45.57.72 1.28.72 2.2s-.27 1.63-.72 2.2l1.41 1.41c.81-.98 1.31-2.23 1.31-3.61zm-11 0c0 1.38.5 2.63 1.31 3.61l1.41-1.41c-.45-.57-.72-1.28-.72-2.2s.27-1.63.72-2.2L8.81 8.39C8 9.37 7.5 10.62 7.5 12zm8.5-7.5c0-.83-.67-1.5-1.5-1.5s-1.5.67-1.5 1.5v6c0 .28-.22.5-.5.5s-.5-.22-.5-.5V1.5C12 .67 11.33 0 10.5 0S9 .67 9 1.5v9.5c0 .28-.22.5-.5.5s-.5-.22-.5-.5V3C8 2.17 7.33 1.5 6.5 1.5S5 2.17 5 3v10.5c0 .28-.22.5-.5.5s-.5-.22-.5-.5V6C4 5.17 3.33 4.5 2.5 4.5S1 5.17 1 6v10.5c0 4.14 3.36 7.5 7.5 7.5h4c4.14 0 7.5-3.36 7.5-7.5V11c0-.83-.67-1.5-1.5-1.5s-1.5.67-1.5 1.5v-3c0-.83-.67-1.5-1.5-1.5s-1.5.67-1.5 1.5v-2z'/></g><text x='50%25' y='85%25' font-family='sans-serif' font-size='50' fill='%234d6bfe' font-weight='bold' text-anchor='middle'>PHPChatAI</text></svg>">
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
            <div style="font-weight: 500; font-size: 1.1rem; margin-left: 4px;">PHPChatAI</div>
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
      <div class="modal-content">
        <h2>Settings</h2>
        
        <div class="settings-section">
          <h3>AI Model</h3>
          <select id="settings-model">
            <optgroup label="Top Tier Models">
              <option value="deepseek-ai/DeepSeek-R1-Distill-Qwen-32B">DeepSeek R1 32B (Thinking)</option>
              <option value="Qwen/Qwen2.5-72B-Instruct">Qwen 2.5 72B (Powerful)</option>
              <option value="meta-llama/Llama-3.3-70B-Instruct">Llama 3.3 70B</option>
              <option value="cohere/c4ai-command-r-plus-08-2024">Command R+</option>
            </optgroup>
            <optgroup label="Fast & Efficient">
              <option value="Qwen/Qwen2.5-7B-Instruct">Qwen 2.5 7B</option>
              <option value="Qwen/Qwen2.5-14B-Instruct">Qwen 2.5 14B</option>
              <option value="meta-llama/Llama-3.2-11B-Vision-Instruct">Llama 3.2 11B</option>
              <option value="mistralai/Mistral-7B-Instruct-v0.3">Mistral 7B</option>
              <option value="microsoft/Phi-3.5-mini-instruct">Phi-3.5 Mini</option>
              <option value="google/gemma-2-9b-it">Gemma 2 9B</option>
            </optgroup>
            <optgroup label="Uncensored / Creative (NSFW Allowed)">
              <option value="NousResearch/Nous-Hermes-2-Mixtral-8x7B-DPO">Nous Hermes 2 (Uncensored)</option>
              <option value="cognitivecomputations/dolphin-2.9-llama3-8b">Dolphin 2.9 Llama 3 8B (Uncensored)</option>
              <option value="Gryphe/MythoMax-L2-13b">MythoMax L2 13B (Roleplay)</option>
              <option value="Undi95/Toppy-M-7B">Toppy M 7B (Creative/Roleplay)</option>
              <option value="Sao10K/L3-8B-Stheno-v3.2">Stheno L3 8B (Roleplay)</option>
            </optgroup>
          </select>
        </div>

        <div class="settings-section">
          <h3>Appearance</h3>
          <button class="btn-secondary" style="width: 100%; display: flex; align-items: center; justify-content: center; gap: 8px;" onclick="toggleTheme()">
            <span class="material-symbols-outlined" id="theme-icon">light_mode</span> <span id="theme-text">Light mode</span>
          </button>
        </div>

        <?php if ($isAdmin): ?>
          <div class="settings-section">
            <h3>Admin HuggingFace Token</h3>
            <input type="password" id="hf-token-input" placeholder="HuggingFace API Token">
          </div>
        <?php endif; ?>

        <div class="settings-section">
          <h3>Change Password</h3>
          <input type="password" id="old-pass-input" placeholder="Old Password">
          <input type="password" id="new-pass-input" placeholder="New Password">
        </div>
        <div class="settings-section">
          <h3>Backup</h3>
          <button class="btn-secondary" style="width:100%;" onclick="exportData()">Export Chats (JSON)</button>
        </div>
        <div class="btn-group">
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

      if (localStorage.getItem('theme') === 'light') {
        document.body.classList.remove('dark');
        document.getElementById('theme-icon').textContent = 'dark_mode';
        document.getElementById('theme-text').textContent = 'Dark mode';
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

      function openSettings() {
        document.getElementById('settings-model').value = localStorage.getItem('selected_model') || 'Qwen/Qwen2.5-7B-Instruct';
        document.getElementById('settings-modal').classList.add('show'); // Open immediately
        
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
        const oldP = document.getElementById('old-pass-input').value;
        const newP = document.getElementById('new-pass-input').value;
        if (oldP && newP) {
          const res = await fetch('?api=change_password', {
            method: 'POST',
            body: JSON.stringify({old_password: oldP, new_password: newP})
          });
          const data = await res.json();
          if (data.error) {
            alert(data.error);
            return;
          }
        }
        closeSettings();
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
        let selectedModel = localStorage.getItem('selected_model') || 'Qwen/Qwen2.5-7B-Instruct';
        abortController = new AbortController();
        
        try {
          if (isSearchActive) {
            let searchStart = Date.now();
            let searchInterval = setInterval(() => {
              let elapsed = ((Date.now() - searchStart) / 1000).toFixed(1);
              aiMsg.content = `<think>🔍 Searching the web... (${elapsed}s)</think>`;
              updateMessageBubble(aiMsg.id, aiMsg.content);
            }, 100);
            
            // Exclude file contents from the search query so we only search the user's text
            const lastUserMsgClean = apiMessages[apiMessages.length - 1].content.replace(/\[File: .*?\]\n[\s\S]*?\n\[End of File\]/g, '').trim();
            const cleanQuery = cleanSearchQuery(lastUserMsgClean);
            const searchRes = await fetch('?api=search&q=' + encodeURIComponent(cleanQuery));
            const searchData = await searchRes.json();
            
            clearInterval(searchInterval);
            let searchDuration = ((Date.now() - searchStart) / 1000).toFixed(1);
            
            if (searchData.context) {
              apiMessages[apiMessages.length - 1].content = searchData.context + apiMessages[apiMessages.length - 1].content;
              searchUrls = searchData.urls || [];
              aiMsg.content = `<think>🔍 Web search completed. (${searchDuration}s)</think>\n\n`;
            } else {
              aiMsg.content = `<think>🔍 Web search returned no matches. (${searchDuration}s)</think>\n\n`;
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
                    aiMsg.content = '⚠️ ' + parsed.error;
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
        
        let newUserMsg = {
          id: createId(),
          chat_id: currentChatId,
          parent_id: msg.parent_id,
          role: 'user',
          content: newText
        };
        messages.push(newUserMsg);
        activeLeafId = newUserMsg.id;
        saveMessageToDB(newUserMsg);
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
        let mainContent = text;

        // Clean up raw paragraph markdown links where the link text is just a long raw URL
        mainContent = mainContent.replace(/\[(https?:\/\/[^\s\]]+)\]\(\1\)/gi, (match, url) => {
          return `[${getDomainName(url)}](${url})`;
        });

        // Clean up standalone URLs in parentheses, converting e.g. (https://reuters.com/xyz) to ([reuters.com](url))
        mainContent = mainContent.replace(/\((https?:\/\/[^\s\)]+)\)/gi, (match, url) => {
          return `([${getDomainName(url)}](${url}))`;
        });
        
        // Extract ALL think blocks and combine them (Search + AI thought)
        const thinkRegex = /<think>([\s\S]*?)(?:<\/think>|$)/gi;
        let match;
        while ((match = thinkRegex.exec(mainContent)) !== null) {
          thinkContent += match[1].trim() + '\n\n';
        }
        
        // Remove all think blocks from the main visible content
        mainContent = mainContent.replace(/<think>[\s\S]*?(?:<\/think>|$)/gi, '').trim();

        let html = '';
        
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
            <div style="margin:auto; text-align:center; opacity:0.8; animation: fadeIn 0.5s ease;">
              <span class="material-symbols-outlined" style="font-size: 56px; margin-bottom: 16px; color: var(--md-sys-color-primary);">waving_hand</span>
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
                bubble.innerHTML = `<textarea style="width:100%; min-height:80px; background:var(--md-sys-color-primary-container); color:var(--md-sys-color-on-primary-container); padding:12px; border-radius:12px; border:none; outline:none; font-family:inherit; resize:vertical;">${msg.content}</textarea>
                <div style="display:flex; justify-content:flex-end; gap:8px; margin-top:8px;">
                  <button onclick="renderMessages()" style="padding:6px 12px; border-radius:16px; background:var(--md-sys-color-surface-variant);">Cancel</button>
                  <button onclick="handleEditSave('${msg.id}', this.parentElement.previousElementSibling.value)" style="padding:6px 12px; border-radius:16px; background:var(--md-sys-color-primary); color:var(--md-sys-color-on-primary);">Save</button>
                </div>`;
                controls.style.display = 'none';
              };
              controls.appendChild(editBtn);
            } else {
              let copyBtn = document.createElement('button');
              copyBtn.innerHTML = '<span class="material-symbols-outlined" style="font-size:16px;">content_copy</span>';
              copyBtn.onclick = () => {
                // Remove the <think> block entirely when copying
                let textToCopy = msg.content.replace(/<think>[\s\S]*?<\/think>/gi, '').trim();
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

      document.addEventListener("DOMContentLoaded", () => {
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
