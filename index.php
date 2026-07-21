<?php
// Set session cookie lifetime to 1 year (31,536,000 seconds)
session_set_cookie_params([
  'lifetime' => 31536000,
  'path' => '/',
  'secure' => isset($_SERVER['HTTPS']), // Uses secure cookies if HTTPS is enabled
  'httponly' => true,
  'samesite' => 'Lax'
]);
session_start();

// Outbound Link Redirector
if (isset($_GET['redirect'])) {
  header('Location: ' . $_GET['redirect']);
  exit;
}

$dbFile = __DIR__ . '/phpchat.sqlite';
try {
  $db = new PDO('sqlite:' . $dbFile, null, null, [
    PDO::ATTR_PERSISTENT => true,
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
  ]);
  $db->exec("PRAGMA busy_timeout = 5000");
  $db->exec("PRAGMA journal_mode = WAL");
  $db->exec("PRAGMA synchronous = NORMAL");
} catch (Exception $e) {
  die("Database connection failed.");
}

// Ensure tables exist
$db->exec("CREATE TABLE IF NOT EXISTS users (id INTEGER PRIMARY KEY AUTOINCREMENT, password TEXT)");
$db->exec("CREATE TABLE IF NOT EXISTS chats (id TEXT PRIMARY KEY, user_id INTEGER, title TEXT, pinned INTEGER DEFAULT 0, created_at DATETIME DEFAULT CURRENT_TIMESTAMP)");
$db->exec("CREATE TABLE IF NOT EXISTS messages (id TEXT PRIMARY KEY, chat_id TEXT, parent_id TEXT, role TEXT, content TEXT, created_at DATETIME DEFAULT CURRENT_TIMESTAMP)");
$db->exec("CREATE TABLE IF NOT EXISTS settings (key TEXT PRIMARY KEY, value TEXT)");

// Create Indexes to make chat loading lightning fast
$db->exec("CREATE INDEX IF NOT EXISTS idx_messages_chat_id ON messages(chat_id)");
$db->exec("CREATE INDEX IF NOT EXISTS idx_chats_user_id ON chats(user_id)");

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
      // If auto-fix fails, show specific instructions for the device workspace
      die("<div style='font-family:sans-serif; padding:40px; text-align:center; background:#121212; color:#e3e3e3; height:100vh; box-sizing:border-box;'>
        <h2 style='color:#ff5252;'>Database Permission Denied</h2>
        <p>The PHP process does not have permission to write to the database folder or file on device.</p>
        <p><strong>To fix this on device:</strong></p>
        <ol style='text-align:left; max-width:500px; margin:0 auto 20px; line-height:1.6;'>
          <li>Open the <strong>Shell</strong> tab on the right side of device.</li>
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
  header('Content-Type: application/json; charset=utf-8');
  header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
  header('Pragma: no-cache');
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

$stmt = $db->query("SELECT key, value FROM settings WHERE key IN ('hf_token', 'gemini_token')");
$tokens = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
$hfToken = $tokens['hf_token'] ?? '';
$geminiToken = $tokens['gemini_token'] ?? '';

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
    $page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
    $limit = 25;
    $offset = ($page - 1) * $limit;
    $stmt = $db->prepare("SELECT * FROM chats WHERE user_id = ? ORDER BY pinned DESC, created_at DESC LIMIT $limit OFFSET $offset");
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
    // Security: Strip HTML tags and strictly enforce character limit on the database side
    $safeTitle = mb_substr(strip_tags($req['title'] ?? 'New Chat'), 0, 100);
    $db->prepare("UPDATE chats SET title = ? WHERE id = ? AND user_id = ?")->execute([$safeTitle, $req['id'], $userId]);
    jsonResponse(['status' => 'success']);
  }

  if ($api === 'pin_chat') {
    $req = json_decode(file_get_contents('php://input'), true);
    $db->prepare("UPDATE chats SET pinned = ? WHERE id = ? AND user_id = ?")->execute([$req['pinned'], $req['id'], $userId]);
    jsonResponse(['status' => 'success']);
  }

  if ($api === 'get_messages') {
    $chatId = $_GET['chat_id'] ?? '';
    
    $stmt = $db->prepare("SELECT id FROM chats WHERE id = ? AND user_id = ?");
    $stmt->execute([$chatId, $userId]);
    if (!$stmt->fetch()) jsonResponse(['error' => 'Unauthorized']);
    
    // Fetch all messages for the chat sorted by rowid (preserves exact insertion order)
    $stmt = $db->prepare("SELECT * FROM messages WHERE chat_id = ? ORDER BY rowid ASC");
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
    
    // Bump the chat timestamp so it moves to the top of the sidebar dynamically
    $db->prepare("UPDATE chats SET created_at = CURRENT_TIMESTAMP WHERE id = ? AND user_id = ?")->execute([$req['chat_id'], $userId]);
    
    jsonResponse(['status' => 'success']);
  }

  if ($api === 'change_username') {
    $req = json_decode(file_get_contents('php://input'), true);
    $newUsername = trim($req['username'] ?? '');
    
    if (empty($newUsername)) jsonResponse(['error' => 'Username cannot be empty.']);
    
    $stmt = $db->prepare("SELECT id FROM users WHERE username = ? AND id != ?");
    $stmt->execute([$newUsername, $userId]);
    if ($stmt->fetch()) jsonResponse(['error' => 'Username already taken.']);
    
    $db->prepare("UPDATE users SET username = ? WHERE id = ?")->execute([$newUsername, $userId]);
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
    jsonResponse(['hf_value' => $hfToken, 'gemini_value' => $geminiToken]);
  }

  if ($api === 'save_setting') {
    if (!$isAdmin) jsonResponse(['error' => 'Unauthorized']);
    $req = json_decode(file_get_contents('php://input'), true);
    $stmt = $db->prepare("INSERT INTO settings (key, value) VALUES (?, ?) ON CONFLICT(key) DO UPDATE SET value = excluded.value");
    $stmt->execute(['hf_token', $req['hf_value'] ?? '']);
    $stmt->execute(['gemini_token', $req['gemini_value'] ?? '']);
    jsonResponse(['status' => 'success']);
  }

  if ($api === 'search') {
    $req = json_decode(file_get_contents('php://input'), true);
    $query = $req['q'] ?? ($_GET['q'] ?? '');
    $searchContext = "";
    $urls = [];
    $snippets = [];

    // Attempt 1: SearXNG Public Rotator (Fast, No RSS, Clean URLs)
    $searxInstances = [
      'https://searx.be', 'https://paulgo.io', 'https://search.mdosch.de',
      'https://searx.tiekoetter.com', 'https://search.inetol.net'
    ];
    shuffle($searxInstances);
    
    $searxOpts = [
      'http' => [
        'method'  => 'GET',
        'header'  => "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64)\r\nAccept: application/json\r\n",
        'timeout' => 1.2 // Reduced timeout to prevent thread blocks on slow servers
      ]
    ];

    $searxAttempts = 0;
    foreach ($searxInstances as $instance) {
      if ($searxAttempts >= 2) break; // Cap at 2 attempts to maintain fast overall response cycles
      $searxAttempts++;
      $searxJson = @file_get_contents($instance . '/search?q=' . urlencode($query) . '&format=json', false, stream_context_create($searxOpts));
      if ($searxJson) {
        $searxData = json_decode($searxJson, true);
        if (!empty($searxData['results'])) {
          foreach ($searxData['results'] as $result) {
            if (count($urls) >= 15) break; // Top 15 results
            $url = $result['url'] ?? '';
            $title = $result['title'] ?? '';
            $content = $result['content'] ?? '';
            if ($url && $content && !in_array($url, $urls)) {
              $snippets[] = "- [Source: $url]\n  Title: $title\n  Snippet: $content";
              $urls[] = $url;
            }
          }
          break; // Stop loop once we get successful results
        }
      }
    }

    // Attempt 2: DuckDuckGo Lite POST (Fast fallback if SearXNG fails)
    if (count($urls) < 5) {
      $ddgOpts = [
        'http' => [
          'method'  => 'POST',
          'header'  => "User-Agent: Mozilla/5.0 (Windows NT 10.0)\r\nContent-type: application/x-www-form-urlencoded\r\nReferer: https://lite.duckduckgo.com/\r\n",
          'content' => 'q=' . urlencode($query),
          'timeout' => 2.5
        ]
      ];
      $html = @file_get_contents('https://lite.duckduckgo.com/lite/', false, stream_context_create($ddgOpts));
      if ($html) {
        preg_match_all('/<a rel="nofollow" href="([^"]+)".*?>(.*?)<\/a>/is', $html, $linkMatches);
        preg_match_all('/<td class=\'result-snippet\'>(.*?)<\/td>/is', $html, $descMatches);
        $limit = min(15, count($linkMatches[1]));
        for ($i = 0; $i < $limit; $i++) {
          $url = $linkMatches[1][$i];
          if (filter_var($url, FILTER_VALIDATE_URL) && strpos($url, 'duckduckgo.com') === false && !in_array($url, $urls)) {
            $title = trim(strip_tags($linkMatches[2][$i]));
            $text = isset($descMatches[1][$i]) ? trim(strip_tags($descMatches[1][$i])) : '';
            $snippets[] = "- [Source: $url]\n  Title: $title\n  Snippet: $text";
            $urls[] = $url;
          }
        }
      }
    }

    if (!empty($snippets)) {
      $snippets = array_slice($snippets, 0, 25);
      $urls = array_slice($urls, 0, 25);
      $searchContext = "[REAL-TIME WEB SEARCH RESULTS (Found " . count($urls) . " sources):]\n" . implode("\n\n", $snippets) . "\n\n[END OF WEB SEARCH RESULTS]\n\n";
    }

    jsonResponse(['context' => $searchContext, 'urls' => $urls]);
  }

  if ($api === 'chat') {
    $req = json_decode(file_get_contents('php://input'), true);
    $messages = $req['messages'] ?? [];
    $model = $req['model'] ?? 'gemini-3.1-flash-lite';
    $provider = $req['provider'] ?? 'gemini';
    
    $isGemini = ($provider === 'gemini');
    
    if ($isGemini && empty($geminiToken)) jsonResponse(['error' => 'Google AI Studio (Gemini) API token not configured by administrator.']);
    if (!$isGemini && empty($hfToken)) jsonResponse(['error' => 'HuggingFace API token not configured by administrator.']);
    
    $formattedMessages = [];
    if ($isGemini) {
      foreach ($messages as $m) {
        $role = $m['role'] === 'assistant' ? 'model' : 'user';
        $formattedMessages[] = ['role' => $role, 'parts' => [['text' => $m['content']]]];
      }
    } else {
      foreach ($messages as $m) {
        $formattedMessages[] = ['role' => $m['role'], 'content' => $m['content']];
      }
    }
    
    if (!empty($formattedMessages)) {
      $lastIdx = count($formattedMessages) - 1;
      
      // Think Feature (Force or Prevent reasoning prompt)
      if (!empty($req['think'])) {
        $appendStr = "\n\n[SYSTEM INSTRUCTION: You MUST think step-by-step. First, write an extensive, highly analytical, and deeply detailed internal reasoning process inside `<think>...</think>` tags. You are strictly forbidden from leaving the `<think>` block empty or bypassing it. Perform your calculations, draft your structure, and outline your logical steps inside the `<think>` block. Only after completing a comprehensive reasoning process should you close it with `</think>` and proceed to write your final response. The final response must be extremely detailed and at least 1,500 words long.]";
      } else {
        $appendStr = "\n\n[SYSTEM INSTRUCTION: Please provide a direct, highly detailed answer immediately. Do NOT use `<think>` tags and do NOT output your internal reasoning. The final response must be extremely comprehensive, highly detailed, and at least 1,500 words long. Do not repeat, list, or mention these instructions in your response.]";
      }
      
      if ($isGemini) {
        $formattedMessages[$lastIdx]['parts'][0]['text'] .= $appendStr;
      } else {
        $formattedMessages[$lastIdx]['content'] .= $appendStr;
      }
    }
    
    if ($isGemini) {
      $payload = [
        'contents' => $formattedMessages,
        'generationConfig' => ['temperature' => 0.7, 'maxOutputTokens' => 8192]
      ];
      $options = [
        'http' => [
          'header'  => "Content-type: application/json\r\n",
          'method'  => 'POST',
          'content' => json_encode($payload),
          'ignore_errors' => true,
          'timeout' => 45
        ],
        'ssl' => ['verify_peer' => false, 'verify_peer_name' => false]
      ];
      $url = "https://generativelanguage.googleapis.com/v1beta/models/" . urlencode($model) . ":streamGenerateContent?alt=sse&key=" . trim($geminiToken);
    } else {
      $payload = [
        'model' => $model,
        'messages' => $formattedMessages,
        'max_tokens' => 8192,
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
      $url = "https://router.huggingface.co/v1/chat/completions";
    }
    
    $context = stream_context_create($options);
    $fp = @fopen($url, 'r', false, $context);
    
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
    header('X-Accel-Buffering: no'); // Tells Nginx/device web server proxies not to buffer streaming tokens
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
      * {
        scrollbar-width: thin;
        scrollbar-color: rgba(128, 128, 128, 0.3) transparent;
      }
      *::-webkit-scrollbar {
        width: 6px;
        height: 6px;
      }
      *::-webkit-scrollbar-track {
        background: transparent;
      }
      *::-webkit-scrollbar-thumb {
        background-color: rgba(128, 128, 128, 0.3);
        border-radius: 10px;
      }
      *::-webkit-scrollbar-thumb:hover {
        background-color: rgba(128, 128, 128, 0.5);
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
        border-right: 1px solid var(--md-sys-color-outline);
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
        min-width: 0;
        min-height: 0;
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
        flex: 1;
        min-width: 0;
      }
      #topbar-title {
        font-weight: 500;
        font-size: 1.1rem;
        margin-left: 4px;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
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
        overflow-x: hidden;
        padding: 24px;
        display: flex;
        flex-direction: column;
        align-items: center;
        scroll-behavior: smooth;
        width: 100%;
      }
      #chat-container::after {
        content: "";
        display: block;
        min-height: 24px;
        width: 100%;
        flex-shrink: 0;
      }
      .message-row {
        width: 100%;
        max-width: 800px;
        display: flex;
        margin-bottom: 24px;
        animation: fadeIn 0.3s ease;
        min-width: 0;
        flex-shrink: 0;
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
        min-width: 0;
        width: 100%; 
        overflow-x: hidden;
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
        overflow-wrap: break-word;
        width: 100%;
        overflow-x: hidden;
        min-width: 0;
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
        max-width: 100%;
      }
      .markdown-body pre code {
        font-family: monospace;
      }
      .markdown-body pre {
        position: relative;
      }
      .code-copy-btn {
        position: absolute;
        top: 6px;
        right: 6px;
        background: rgba(255, 255, 255, 0.08);
        border: 1px solid rgba(255, 255, 255, 0.15);
        color: #a3a3a3;
        border-radius: 6px;
        padding: 4px 8px;
        font-size: 0.75rem;
        display: flex;
        align-items: center;
        gap: 4px;
        cursor: pointer;
        opacity: 0;
        transition: opacity 0.2s, background 0.2s, color 0.2s;
        z-index: 10;
      }
      .markdown-body pre:hover .code-copy-btn {
        opacity: 1;
      }
      .code-copy-btn:hover {
        background: rgba(255, 255, 255, 0.18);
        color: #ffffff;
      }
      .code-copy-btn span {
        font-size: 14px !important;
      }
      .markdown-body p {
        margin-bottom: 16px;
        line-height: 1.6;
      }
      .markdown-body p:last-child {
        margin-bottom: 0;
      }
      .markdown-body strong {
        font-weight: 600;
        color: var(--md-sys-color-on-surface);
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
        background: var(--md-sys-color-background);
        position: relative;
        z-index: 10;
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
        width: 100%;
        background: transparent;
        border: none;
        color: var(--md-sys-color-on-background);
        font-family: inherit;
        font-size: 1rem;
        line-height: 1.5;
        resize: none;
        height: 52px;
        max-height: 250px;
        padding: 14px 8px 14px 0;
        outline: none;
        overflow-y: auto;
        box-sizing: border-box;
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
        overflow-x: auto;
        overflow-wrap: break-word;
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
        bottom: 160px;
        right: max(24px, calc(50% - 400px + 12px));
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
        #chat-container {
          padding: 16px 12px;
        }
        .input-area {
          padding: 12px 12px 16px;
        }
        .message-content-wrapper {
          max-width: 100%;
        }
        #scroll-to-bottom-btn {
          bottom: 160px;
          right: 16px;
        }
        #sidebar.open {
          transform: translateX(0);
        }
        .mobile-menu-btn {
          display: block;
        }
        .sidebar-close-btn {
          display: flex !important;
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
      .spinner {
        width: 24px;
        height: 24px;
        border: 3px solid var(--md-sys-color-surface-variant);
        border-top: 3px solid var(--md-sys-color-primary);
        border-radius: 50%;
        animation: spin 1s linear infinite;
        margin: 8px auto;
      }
      @keyframes spin {
        0% { transform: rotate(0deg); }
        100% { transform: rotate(360deg); }
      }
      .error-text {
        color: #ff5252 !important;
        font-weight: 500;
        background: rgba(255, 82, 82, 0.1) !important;
        padding: 16px !important;
        border-radius: 12px !important;
        border: 1px solid rgba(255, 82, 82, 0.3) !important;
      }
      .error-text * {
        color: inherit !important;
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
      .message-file-pill {
        display: inline-flex;
        align-items: center;
        gap: 12px;
        padding: 10px 16px;
        background: var(--md-sys-color-surface);
        border: 1px solid var(--md-sys-color-outline);
        border-radius: 12px;
        margin: 8px 8px 8px 0;
        cursor: pointer;
        transition: background 0.2s, border-color 0.2s;
        max-width: 300px;
        vertical-align: top;
      }
      .message-file-pill:hover {
        background: var(--md-sys-color-surface-variant);
        border-color: var(--md-sys-color-primary);
      }
      .message-file-pill-icon {
        display: flex;
        align-items: center;
        justify-content: center;
        background: var(--md-sys-color-primary-container);
        color: var(--md-sys-color-on-primary-container);
        padding: 8px;
        border-radius: 8px;
      }
      .message-file-pill-name {
        font-size: 0.95rem;
        font-weight: 500;
        color: var(--md-sys-color-on-surface);
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
        flex: 1;
      }
    </style>
  </head>
  <body class="dark">
    <div id="auth-screen">
      <form class="auth-box" onsubmit="event.preventDefault(); handleAuth();">
        <h1 id="auth-title">Welcome back</h1>
        <input type="text" id="auth-name" placeholder="Name" autocomplete="name" style="display: none;">
        <input type="email" id="auth-email" placeholder="Email" autocomplete="email" required>
        <input type="password" id="auth-pass" placeholder="Password" autocomplete="current-password" required>
        <button type="submit">Continue</button>
        <div class="auth-switch" onclick="toggleAuthMode()" id="auth-switch-text">Don't have an account? Sign up</div>
      </form>
    </div>
    
    <div id="app">
      <div id="overlay" onclick="toggleSidebar()"></div>
      <nav id="sidebar">
        <div class="sidebar-header">
          <div style="display: flex; gap: 8px; align-items: center; width: 100%;">
            <button class="new-chat-btn" onclick="startNewChat()" style="flex: 1;">
              <span class="material-symbols-outlined">add</span> New chat
            </button>
            <button class="sidebar-close-btn" onclick="toggleSidebar()" style="display: none; padding: 0.85em; border-radius: 0.75em; align-items: center; justify-content: center; background: var(--md-sys-color-background); border: 1px solid var(--md-sys-color-outline); cursor: pointer;" title="Close menu">
              <span class="material-symbols-outlined">close</span>
            </button>
          </div>
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

    <!-- Sources Modal -->
    <div id="sources-modal" class="modal">
      <div class="modal-content" style="max-width: 600px;">
        <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 16px;">
          <h2 style="margin: 0; font-size: 1.1rem; font-weight: 500; color: var(--md-sys-color-on-surface);">Sources & References</h2>
          <button onclick="closeSourcesModal()" style="padding: 6px; border-radius: 50%; background: var(--md-sys-color-surface-variant); display: flex; align-items: center; justify-content: center; cursor: pointer; border: 1px solid var(--md-sys-color-outline);">
            <span class="material-symbols-outlined" style="font-size: 20px;">close</span>
          </button>
        </div>
        <div id="sources-modal-content" style="display: flex; flex-direction: column; gap: 8px; max-height: 60vh; overflow-y: auto; margin-bottom: 8px;"></div>
      </div>
    </div>

    <!-- File Preview Modal -->
    <div id="file-modal" class="modal">
      <div class="modal-content" style="max-width: 800px;">
        <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 16px;">
          <h2 id="file-modal-title" style="margin: 0; font-size: 1.1rem; font-weight: 500; word-break: break-all; color: var(--md-sys-color-on-surface);">File Name</h2>
          <button onclick="closeFileModal()" style="padding: 6px; border-radius: 50%; background: var(--md-sys-color-surface-variant); display: flex; align-items: center; justify-content: center; cursor: pointer; border: 1px solid var(--md-sys-color-outline);">
            <span class="material-symbols-outlined" style="font-size: 20px;">close</span>
          </button>
        </div>
        <pre id="file-modal-content" style="background: var(--md-sys-color-surface); padding: 16px; border-radius: 12px; max-height: 60vh; overflow-y: auto; font-size: 0.9rem; white-space: pre-wrap; word-break: break-word; border: 1px solid var(--md-sys-color-outline); margin-bottom: 16px; color: var(--md-sys-color-on-surface); font-family: monospace;"></pre>
        <div style="display: flex; justify-content: flex-end;">
          <button id="file-modal-download" class="btn-primary" style="display: inline-flex; align-items: center; gap: 8px;">
            <span class="material-symbols-outlined" style="font-size: 20px;">download</span> Download File
          </button>
        </div>
      </div>
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
            <h3>API Provider</h3>
            <div style="display: flex; gap: 8px; margin-bottom: 16px;">
              <button id="provider-btn-huggingface" class="theme-select-btn active" onclick="setProviderMode('huggingface')">
                <span class="material-symbols-outlined">api</span> HuggingFace
              </button>
              <button id="provider-btn-gemini" class="theme-select-btn" onclick="setProviderMode('gemini')">
                <span class="material-symbols-outlined">science</span> Google AI Studio
              </button>
            </div>
            
            <h3 id="model-label-hf">HuggingFace Model</h3>
            <div id="hf-model-container">
              <input type="text" id="model-search-input" placeholder="Search HuggingFace models..." oninput="searchHFModels()" style="margin-bottom: 8px;">
              <select id="settings-model-hf">
                <optgroup label="Search Results" id="hf-search-results" style="display:none;"></optgroup>
                <optgroup label="HuggingFace Models">
                  <option value="google/gemma-4-31b-it">Gemma 4 31B Instruct</option>
                  <option value="google/gemma-4-12b-it">Gemma 4 12B Instruct</option>
                  <option value="google/gemma-3-27b-it">Gemma 3 27B Instruct</option>
                  <option value="google/gemma-3-4b-it">Gemma 3 4B Instruct</option>
                  <option value="deepseek-ai/DeepSeek-R1">DeepSeek R1 (Ultimate Reasoning)</option>
                  <option value="meta-llama/Llama-3.3-70B-Instruct">Llama 3.3 70B Instruct</option>
                  <option value="Qwen/Qwen3-4B-Thinking-2507">Qwen 3 4B Thinking</option>
                  <option value="Qwen/Qwen2.5-72B-Instruct">Qwen 2.5 72B Instruct</option>
                </optgroup>
              </select>
            </div>
            
            <div id="gemini-model-container" style="display: none;">
              <select id="settings-model-gemini">
                <optgroup label="Google Gemini Models">
                  <option value="gemini-3.6-flash">Gemini 3.6 Flash (Latest/Flagship)</option>
                  <option value="gemini-3.5-flash">Gemini 3.5 Flash (Performance Optimized)</option>
                  <option value="gemini-3.5-flash-lite">Gemini 3.5 Flash-Lite (Cost/Speed Optimized)</option>
                  <option value="gemini-3.1-pro-preview">Gemini 3.1 Pro (Preview)</option>
                  <option value="gemini-3.1-flash-lite">Gemini 3.1 Flash-Lite</option>
                </optgroup>
                <optgroup label="Google Gemma Models (AI Studio)">
                  <option value="gemma-4-31b-it">Gemma 4 31B Instruct</option>
                  <option value="gemma-4-12b-it">Gemma 4 12B Instruct</option>
                  <option value="gemma-3-27b-it">Gemma 3 27B Instruct</option>
                  <option value="gemma-3-4b-it">Gemma 3 4B Instruct</option>
                  <option value="gemma-2-27b-it">Gemma 2 27B Instruct</option>
                </optgroup>
              </select>
            </div>
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
            <h3>Account Settings</h3>
            <div style="display: flex; flex-direction: column; gap: 12px; margin-top: 12px;">
              <button class="new-chat-btn" onclick="openUsernameModal()" style="width: 100%;">
                <span class="material-symbols-outlined" style="font-size: 20px;">badge</span> Change Username
              </button>
              <button class="new-chat-btn" onclick="openPasswordModal()" style="width: 100%;">
                <span class="material-symbols-outlined" style="font-size: 20px;">lock_reset</span> Update Password
              </button>
            </div>
          </div>
        </div>

        <!-- Admin Tab -->
        <?php if ($isAdmin): ?>
          <div id="tab-admin" class="settings-tab-content">
            <div class="settings-section" style="border: none; padding-top: 0; margin-top: 0;">
              <h3>Admin API Tokens</h3>
              <div style="position: relative; display: flex; align-items: center; margin-bottom: 12px;">
                <input type="text" id="hf-token-input" placeholder="HuggingFace API Token" autocomplete="off" spellcheck="false" data-lpignore="true" style="-webkit-text-security: disc; margin-bottom: 0; padding-right: 80px;">
                <div style="position: absolute; right: 16px; display: flex; gap: 12px; align-items: center; z-index: 10;">
                  <button type="button" onclick="toggleTokenVisibility('hf-token-input', 'hf-token-visibility-icon')" style="display: flex; align-items: center; justify-content: center; color: var(--md-sys-color-on-surface-variant); cursor: pointer;" title="Toggle Visibility">
                    <span class="material-symbols-outlined" id="hf-token-visibility-icon" style="font-size: 20px;">visibility_off</span>
                  </button>
                  <button type="button" onclick="copyAdminToken('hf-token-input', 'hf-token-copy-icon')" style="display: flex; align-items: center; justify-content: center; color: var(--md-sys-color-on-surface-variant); cursor: pointer;" title="Copy Token">
                    <span class="material-symbols-outlined" id="hf-token-copy-icon" style="font-size: 20px;">content_copy</span>
                  </button>
                </div>
              </div>
              <div style="position: relative; display: flex; align-items: center;">
                <input type="text" id="gemini-token-input" placeholder="Gemini API Token (AI Studio)" autocomplete="off" spellcheck="false" data-lpignore="true" style="-webkit-text-security: disc; margin-bottom: 0; padding-right: 80px;">
                <div style="position: absolute; right: 16px; display: flex; gap: 12px; align-items: center; z-index: 10;">
                  <button type="button" onclick="toggleTokenVisibility('gemini-token-input', 'gemini-token-visibility-icon')" style="display: flex; align-items: center; justify-content: center; color: var(--md-sys-color-on-surface-variant); cursor: pointer;" title="Toggle Visibility">
                    <span class="material-symbols-outlined" id="gemini-token-visibility-icon" style="font-size: 20px;">visibility_off</span>
                  </button>
                  <button type="button" onclick="copyAdminToken('gemini-token-input', 'gemini-token-copy-icon')" style="display: flex; align-items: center; justify-content: center; color: var(--md-sys-color-on-surface-variant); cursor: pointer;" title="Copy Token">
                    <span class="material-symbols-outlined" id="gemini-token-copy-icon" style="font-size: 20px;">content_copy</span>
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
            <div style="margin-top: 12px;">
              <button class="new-chat-btn" style="width:100%;" onclick="exportData()">
                <span class="material-symbols-outlined" style="font-size: 20px;">download</span> Export Chats (JSON)
              </button>
            </div>
          </div>
        </div>

        <div class="btn-group" style="margin-top: 24px; border-top: 1px solid var(--md-sys-color-outline); padding-top: 16px;">
          <button class="btn-secondary" onclick="closeSettings()">Cancel</button>
          <button class="btn-primary" onclick="saveSettings()">Save</button>
        </div>
      </div>
    </div>

    <!-- Password Modal -->
    <div id="password-modal" class="modal">
      <div class="modal-content" style="max-width: 400px;">
        <h2 style="margin-top: 0;">Update Password</h2>
        <input type="password" id="old-pass-input" placeholder="Old Password" autocomplete="new-password">
        <input type="password" id="new-pass-input" placeholder="New Password" autocomplete="new-password">
        <div class="btn-group">
          <button class="btn-secondary" onclick="closePasswordModal()">Cancel</button>
          <button class="btn-primary" onclick="updatePassword()">Update</button>
        </div>
      </div>
    </div>

    <!-- Username Modal -->
    <div id="username-modal" class="modal">
      <div class="modal-content" style="max-width: 400px;">
        <h2 style="margin-top: 0;">Change Username</h2>
        <input type="text" id="new-username-input" placeholder="New Username" autocomplete="off">
        <div class="btn-group">
          <button class="btn-secondary" onclick="closeUsernameModal()">Cancel</button>
          <button class="btn-primary" onclick="updateUsername()">Update</button>
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
      let chatPage = 1;
      let hasMoreChats = false;
      
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

      function setProviderMode(provider) {
        localStorage.setItem('selected_provider', provider);
        document.getElementById('provider-btn-huggingface').classList.toggle('active', provider === 'huggingface');
        document.getElementById('provider-btn-gemini').classList.toggle('active', provider === 'gemini');
        
        if (provider === 'gemini') {
          document.getElementById('hf-model-container').style.display = 'none';
          document.getElementById('model-label-hf').textContent = 'Google AI Studio Model';
          document.getElementById('gemini-model-container').style.display = 'block';
        } else {
          document.getElementById('hf-model-container').style.display = 'block';
          document.getElementById('model-label-hf').textContent = 'HuggingFace Model';
          document.getElementById('gemini-model-container').style.display = 'none';
        }
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
        
        const nameInput = document.getElementById('auth-name');
        const passInput = document.getElementById('auth-pass');
        
        if (isLoginMode) {
          nameInput.style.display = 'none';
          nameInput.removeAttribute('required');
          passInput.setAttribute('autocomplete', 'current-password');
        } else {
          nameInput.style.display = 'block';
          nameInput.setAttribute('required', 'true');
          passInput.setAttribute('autocomplete', 'new-password');
        }
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
              document.getElementById('settings-model-hf').value = data[0].modelId; // Auto-select top result
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

        const savedProvider = localStorage.getItem('selected_provider') || 'gemini';
        setProviderMode(savedProvider);

        const savedModelHF = localStorage.getItem('selected_model_hf') || 'Qwen/Qwen2.5-72B-Instruct';
        const savedModelGemini = localStorage.getItem('selected_model_gemini') || 'gemini-3.1-flash-lite';
        
        const selectHF = document.getElementById('settings-model-hf');
        if (!Array.from(selectHF.options).some(opt => opt.value === savedModelHF)) {
          const customOpt = document.createElement('option');
          customOpt.value = savedModelHF;
          customOpt.textContent = savedModelHF + ' (Custom)';
          selectHF.appendChild(customOpt);
        }
        selectHF.value = savedModelHF;

        const selectGemini = document.getElementById('settings-model-gemini');
        if (!Array.from(selectGemini.options).some(opt => opt.value === savedModelGemini)) {
          const customOpt = document.createElement('option');
          customOpt.value = savedModelGemini;
          customOpt.textContent = savedModelGemini + ' (Custom)';
          selectGemini.appendChild(customOpt);
        }
        selectGemini.value = savedModelGemini;
        document.getElementById('settings-modal').classList.add('show'); // Open immediately
        applyTheme(); // Refresh theme button active highlights
        
        const adminCheck = <?= $isAdmin ? 'true' : 'false' ?>;
        if (adminCheck) {
          fetch('?api=get_setting')
            .then(res => res.json())
            .then(data => {
              if (!data.error) {
                document.getElementById('hf-token-input').value = data.hf_value || '';
                document.getElementById('gemini-token-input').value = data.gemini_value || '';
              }
            }).catch(err => console.error(err));
        }
      }

      function closeSettings() {
        document.getElementById('settings-modal').classList.remove('show');
      }

      function openPasswordModal() {
        document.getElementById('old-pass-input').value = '';
        document.getElementById('new-pass-input').value = '';
        document.getElementById('password-modal').classList.add('show');
      }

      function closePasswordModal() {
        document.getElementById('password-modal').classList.remove('show');
      }

      function openUsernameModal() {
        document.getElementById('new-username-input').value = '';
        document.getElementById('username-modal').classList.add('show');
      }

      function closeUsernameModal() {
        document.getElementById('username-modal').classList.remove('show');
      }
      
      function openFileModal(name, content, isBase64 = false) {
        let finalName = name;
        let finalContent = content;
        if (isBase64) {
          finalName = decodeURIComponent(escape(window.atob(name)));
          finalContent = decodeURIComponent(escape(window.atob(content)));
        }
        
        // Prevent UI freezing by strictly limiting text length rendered in the modal preview
        let previewContent = finalContent;
        if (previewContent.length > 3000) {
          previewContent = previewContent.substring(0, 3000) + '\n\n... [File truncated for UI preview. Download to view the full file.]';
        }
        
        document.getElementById('file-modal-title').textContent = finalName;
        document.getElementById('file-modal-content').textContent = previewContent;
        
        const downloadBtn = document.getElementById('file-modal-download');
        const newDownloadBtn = downloadBtn.cloneNode(true);
        downloadBtn.parentNode.replaceChild(newDownloadBtn, downloadBtn);
        
        newDownloadBtn.addEventListener('click', () => {
          const blob = new Blob([finalContent], { type: 'text/plain;charset=utf-8' });
          const url = URL.createObjectURL(blob);
          const a = document.createElement('a');
          a.href = url;
          a.download = finalName;
          document.body.appendChild(a);
          a.click();
          document.body.removeChild(a);
          URL.revokeObjectURL(url);
        });
        
        document.getElementById('file-modal').classList.add('show');
      }

      function closeFileModal() {
        document.getElementById('file-modal').classList.remove('show');
      }
      
      function openSourcesModal(b64Urls) {
        let urls = JSON.parse(decodeURIComponent(escape(window.atob(b64Urls))));
        let container = document.getElementById('sources-modal-content');
        container.innerHTML = '';
        
        urls.forEach((url, index) => {
          let domain = getDomainName(url);
          let a = document.createElement('a');
          a.href = `?redirect=${encodeURIComponent(url)}`;
          a.target = '_blank';
          a.style.display = 'flex';
          a.style.alignItems = 'center';
          a.style.gap = '12px';
          a.style.padding = '12px';
          a.style.background = 'var(--md-sys-color-surface-variant)';
          a.style.border = '1px solid var(--md-sys-color-outline)';
          a.style.borderRadius = '12px';
          a.style.textDecoration = 'none';
          a.style.color = 'var(--md-sys-color-on-surface)';
          a.style.transition = 'background 0.2s';
          
          a.onmouseover = () => a.style.background = 'var(--md-sys-color-primary-container)';
          a.onmouseout = () => a.style.background = 'var(--md-sys-color-surface-variant)';
          
          a.innerHTML = `
            <div style="background: var(--md-sys-color-background); width: 28px; height: 28px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 0.8rem; font-weight: bold; flex-shrink: 0;">${index + 1}</div>
            <div style="flex: 1; min-width: 0;">
              <div style="font-weight: 500; font-size: 0.95rem; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">${domain}</div>
              <div style="font-size: 0.8rem; color: var(--md-sys-color-outline); white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">${url}</div>
            </div>
            <span class="material-symbols-outlined" style="font-size: 18px; color: var(--md-sys-color-outline);">open_in_new</span>
          `;
          container.appendChild(a);
        });
        
        document.getElementById('sources-modal').classList.add('show');
      }

      function closeSourcesModal() {
        document.getElementById('sources-modal').classList.remove('show');
      }

      // Close modals when clicking/touching outside the container
      const handleModalOutClick = (e) => {
        const settingsModal = document.getElementById('settings-modal');
        const fileModal = document.getElementById('file-modal');
        const sourcesModal = document.getElementById('sources-modal');
        const passwordModal = document.getElementById('password-modal');
        const usernameModal = document.getElementById('username-modal');
        if (e.target === settingsModal) closeSettings();
        if (e.target === fileModal) closeFileModal();
        if (e.target === sourcesModal) closeSourcesModal();
        if (e.target === passwordModal) closePasswordModal();
        if (e.target === usernameModal) closeUsernameModal();
      };
      window.addEventListener('click', handleModalOutClick);
      window.addEventListener('touchstart', handleModalOutClick, {passive: true});

      async function saveSettings() {
        localStorage.setItem('selected_model_hf', document.getElementById('settings-model-hf').value);
        localStorage.setItem('selected_model_gemini', document.getElementById('settings-model-gemini').value);
        const adminCheck = <?= $isAdmin ? 'true' : 'false' ?>;
        if (adminCheck) {
          const hfToken = document.getElementById('hf-token-input').value.trim();
          const geminiToken = document.getElementById('gemini-token-input').value.trim();
          await fetch('?api=save_setting', {
            method: 'POST',
            body: JSON.stringify({hf_value: hfToken, gemini_value: geminiToken})
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
          closePasswordModal();
        }
      }

      async function updateUsername() {
        const newUsername = document.getElementById('new-username-input').value.trim();
        if (!newUsername) {
          alert("Username cannot be empty.");
          return;
        }
        const res = await fetch('?api=change_username', {
          method: 'POST',
          body: JSON.stringify({username: newUsername})
        });
        const data = await res.json();
        if (data.error) {
          alert(data.error);
        } else {
          alert("Username updated successfully!");
          closeUsernameModal();
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

      async function loadChats(append = false) {
        if (!append) chatPage = 1;
        const res = await fetch(`?api=get_chats&page=${chatPage}`);
        const fetchedChats = await res.json();
        
        hasMoreChats = fetchedChats.length === 25;
        
        if (append) {
          chats = chats.concat(fetchedChats);
        } else {
          chats = fetchedChats;
        }
        
        renderChatList();
        
        if (!append) {
          const urlParams = new URLSearchParams(window.location.search);
          const chatIdFromUrl = urlParams.get('chat');
          
          if (chatIdFromUrl) {
            await selectChat(chatIdFromUrl, false);
          } else {
            await startNewChat(false);
          }
        }
      }

      function loadMoreChats() {
        chatPage++;
        loadChats(true);
      }

      function filterChats() {
        searchQuery = document.getElementById('search-input').value.toLowerCase().trim();
        renderChatList();
      }

      function renderChatList() {
        const list = document.getElementById('chat-list');
        list.innerHTML = '';
        
        // Sort: Pinned first, then current active chat, then the rest
        let sortedChats = [...chats].sort((a, b) => {
          if (a.pinned != b.pinned) return b.pinned - a.pinned;
          if (a.id === currentChatId) return -1;
          if (b.id === currentChatId) return 1;
          return 0;
        });

        const filtered = sortedChats.filter(c => c.title.toLowerCase().includes(searchQuery));
        filtered.forEach(c => {
          // XSS Prevention: Sanitize title to prevent HTML/JS injection in the sidebar
          const safeTitle = c.title
            .replace(/&/g, "&amp;")
            .replace(/</g, "&lt;")
            .replace(/>/g, "&gt;")
            .replace(/"/g, "&quot;")
            .replace(/'/g, "&#039;");
            
          const div = document.createElement('div');
          div.className = `chat-item ${c.id === currentChatId ? 'active' : ''}`;
          div.innerHTML = `
            <span class="material-symbols-outlined" style="font-size:16px; margin-right:8px;">${c.pinned == 1 ? 'keep' : 'chat'}</span>
            <span class="title" onclick="selectChat('${c.id}')">${safeTitle}</span>
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
        
        // Append Load More Button if necessary
        if (hasMoreChats && searchQuery === '') {
          const loadMoreBtn = document.createElement('button');
          loadMoreBtn.className = 'chat-item';
          loadMoreBtn.style.justifyContent = 'center';
          loadMoreBtn.style.color = 'var(--md-sys-color-primary)';
          loadMoreBtn.style.fontWeight = '500';
          loadMoreBtn.innerHTML = '<span class="material-symbols-outlined" style="font-size:18px; margin-right:6px;">expand_more</span> Load More';
          loadMoreBtn.onclick = loadMoreChats;
          list.appendChild(loadMoreBtn);
        }
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
        if (window.innerWidth <= 768) {
          document.getElementById('sidebar').classList.remove('open');
          document.getElementById('overlay').classList.remove('show');
        }
      }

      async function selectChat(id, push = true) {
        currentChatId = id;
        if (push) updateURL(id);
        
        const container = document.getElementById('chat-container');
        container.innerHTML = `
          <div style="margin: auto; display: flex; flex-direction: column; align-items: center; justify-content: center;">
            <div class="spinner" style="width: 40px; height: 40px; border-width: 4px;"></div>
            <p style="margin-top: 12px; color: var(--md-sys-color-on-surface-variant); font-size: 0.9rem;">Loading messages...</p>
          </div>
        `;

        const activeChat = chats.find(c => c.id === id);
        let titleText = activeChat ? activeChat.title : "PHPChatAI";
        
        if (titleText.length > 60) {
          titleText = titleText.substring(0, 60) + '...';
        }
        
        document.getElementById('topbar-title').textContent = titleText;
        
        if (activeChat) {
          document.title = `${titleText} — PHPChatAI`;
        } else {
          document.title = "PHPChatAI";
        }

        try {
          // Fetch all messages for the current chat thread
          const res = await fetch(`?api=get_messages&chat_id=${id}`);
          const data = await res.json();
          
          if (data.error === 'Unauthorized') {
            return startNewChat(true); // Redirect immediately
          }
          
          messages = data;
          // The last element is chronologically the latest message, acting as the starting active leaf node
          activeLeafId = messages.length > 0 ? messages[messages.length - 1].id : null;
        } catch (e) {
          console.error("Failed to fetch messages:", e);
        }

        renderChatList();
        renderMessages(true); // Force scroll to the bottom on initial chat load
        if (window.innerWidth <= 768) {
          document.getElementById('sidebar').classList.remove('open');
          document.getElementById('overlay').classList.remove('show');
        }
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
          newTitle = newTitle.trim().substring(0, 60); // Prevent excessively long manual names
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
        let msgMap = new Map();
        
        for (let i = 0; i < messages.length; i++) {
          msgMap.set(messages[i].id, messages[i]);
        }
        
        while (currId) {
          let msg = msgMap.get(currId);
          if (!msg) break;
          path.unshift(msg);
          currId = msg.parent_id;
        }
        return path;
      }

      function switchBranch(msgId, direction) {
        let msg = messages.find(m => m.id === msgId);
        if (!msg) return;
        
        let siblings = messages.filter(m => m.parent_id === msg.parent_id);
        let idx = siblings.findIndex(m => m.id === msgId);
        let targetIdx = idx + direction;
        
        if (targetIdx >= 0 && targetIdx < siblings.length) {
          activeLeafId = findDeepestLeaf(siblings[targetIdx].id);
          renderMessages();
        }
      }

      function findDeepestLeaf(startId) {
        let childrenMap = new Map();
        
        for (let i = 0; i < messages.length; i++) {
          let pid = messages[i].parent_id;
          if (!childrenMap.has(pid)) childrenMap.set(pid, []);
          childrenMap.get(pid).push(messages[i]);
        }
        
        function traverse(id) {
          let children = childrenMap.get(id) || [];
          if (children.length === 0) return id;
          return traverse(children[children.length - 1].id);
        }
        
        return traverse(startId);
      }

      async function saveMessageToDB(msg) {
        await fetch('?api=save_message', {
          method: 'POST',
          body: JSON.stringify(msg)
        });
        
        // Update latest chat in sidebar via server silently
        fetch(`?api=get_chats&page=1`)
          .then(res => res.json())
          .then(data => {
            const existingCurrentChat = chats.find(c => c.id === currentChatId);
            chats = data;
            if (currentChatId && !chats.find(c => c.id === currentChatId) && existingCurrentChat) {
              chats.push(existingCurrentChat);
            }
            renderChatList();
          });
      }

      const inputEl = document.getElementById('msg-input');
      const adjustInputHeight = () => {
        inputEl.style.height = '52px'; // Shrink back to baseline first
        inputEl.style.height = inputEl.scrollHeight + 'px'; // Expand perfectly to fit text
      };
      inputEl.addEventListener('input', adjustInputHeight);
      inputEl.addEventListener('keydown', function(e) {
        const isMobile = window.innerWidth <= 768;
        
        // On desktop, Enter sends. On mobile, Enter creates a new line (unless Shift is held).
        if (e.key === 'Enter' && !e.shiftKey && !isMobile) {
          e.preventDefault();
          if (!isWaiting) handleSend();
        } else {
          setTimeout(adjustInputHeight, 0);
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

      function getDynamicCharLimit() {
        let provider = localStorage.getItem('selected_provider') || 'gemini';
        let selectedModel = provider === 'gemini' 
          ? (localStorage.getItem('selected_model_gemini') || 'gemini-3.1-flash-lite')
          : (localStorage.getItem('selected_model_hf') || 'Qwen/Qwen2.5-72B-Instruct');
        
        // Define max input tokens dynamically based on the model's capacity
        let maxTokens = 8192; // default safe baseline
        const modelLower = selectedModel.toLowerCase();
        
        if (modelLower.includes('gemini')) maxTokens = 128000; // Gemini supports massive contexts
        else if (modelLower.includes('deepseek-r1') || modelLower.includes('llama-3.3')) maxTokens = 32000;
        else if (modelLower.includes('qwen2.5-72b')) maxTokens = 32000;
        
        return maxTokens * 4; // 1 Token ≈ 4 Characters
      }

      async function handleSend() {
        let text = inputEl.value.trim();
        if (!text && attachedFiles.length === 0) return;
        
        // Clean up title: replace newlines with spaces and limit to 60 characters to prevent UI breaks
        let rawTitle = inputEl.value.trim() || attachedFiles[0]?.name || "New Chat";
        const displayTitleText = rawTitle.replace(/[\r\n]+/g, ' ').substring(0, 60);

        // Enforce Dynamic Character Limit based on Model Token Limit
        const MAX_CHARS = getDynamicCharLimit();
        let totalLength = text.length;
        if (attachedFiles.length > 0) {
          totalLength += attachedFiles.reduce((sum, f) => sum + f.content.length, 0);
        }
        if (totalLength > MAX_CHARS) {
          const maxTokens = MAX_CHARS / 4;
          alert(`Message is too long! (${totalLength} chars). Please limit to ${MAX_CHARS} characters (~${maxTokens} tokens) for the selected model.`);
          return;
        }
        
        // Append file contents to the text behind the scenes
        if (attachedFiles.length > 0) {
          let fileTexts = attachedFiles.map(f => `[File: ${f.name}]\n${f.content}\n[End of File]`).join('\n\n');
          text = text ? text + '\n\n' + fileTexts : fileTexts;
        }

        inputEl.value = '';
        inputEl.style.height = '52px'; // Snap back to default height after sending
        attachedFiles = [];
        renderAttachments();
        
        if (!currentChatId) {
          const res = await fetch('?api=create_chat', { method: 'POST' });
          const data = await res.json();
          currentChatId = data.id;
          updateURL(currentChatId);
          
          const title = displayTitleText;
          document.title = `${title} — PHPChatAI`;
          document.getElementById('topbar-title').textContent = title;
          await fetch('?api=rename_chat', {
            method: 'POST',
            body: JSON.stringify({id: currentChatId, title})
          });
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
        renderMessages(true); // Force scroll down on new prompt
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
        renderMessages(true); // Ensure AI response bubble starts at the bottom
        
        // Extract the exact timeline path for the current branch
        let contextPath = getThreadPath(parentMsgId);
        
        // Dynamically calculate allowed context limit instead of a hardcoded 15 message limit
        const MAX_CONTEXT_CHARS = getDynamicCharLimit();
        let accumulatedChars = 0;
        let keepCount = 0;
        
        // Iterate backwards to keep the most recent messages up to the model's token capacity
        for (let i = contextPath.length - 1; i >= 0; i--) {
          accumulatedChars += contextPath[i].content.length;
          if (accumulatedChars > MAX_CONTEXT_CHARS && keepCount > 0) break;
          keepCount++;
        }
        
        // Extract older messages that will be trimmed off (The Database History)
        let trimmedMessages = [];
        if (keepCount < contextPath.length) {
          trimmedMessages = contextPath.slice(0, contextPath.length - keepCount);
          contextPath = contextPath.slice(-keepCount);
        }
        
        // Strictly ensure the first message is ALWAYS a 'user' message 
        // (Google Gemini and strict HF models will throw a 400 error otherwise)
        if (contextPath.length > 0 && contextPath[0].role !== 'user') {
          if (contextPath.length > 1) {
            trimmedMessages.push(contextPath.shift());
          } else {
            contextPath[0].role = 'user'; // Fallback if only 1 message exists
          }
        }

        // Deep clone contextPath for the API payload to avoid mutating UI message bubbles
        let apiMessages = contextPath.map(m => ({ role: m.role, content: m.content }));

        // Implement Persistent Memory (RAG-style Database Recall)
        // This explicitly bypasses the context window limit by querying the trimmed history
        if (trimmedMessages.length > 0 && apiMessages.length > 0) {
          let lastUserText = apiMessages[apiMessages.length - 1].content.toLowerCase();
          
          // Extract keywords (words with 5+ characters to filter out common words like 'the', 'and')
          let keywords = lastUserText.match(/\b[a-z]{5,}\b/g) || [];
          keywords = [...new Set(keywords)];
          
          if (keywords.length > 0) {
            // Score the old database messages based on keyword hits
            let scoredMemories = trimmedMessages.map((m, idx) => {
              let score = 0;
              let lowerContent = m.content.toLowerCase();
              keywords.forEach(kw => {
                if (lowerContent.includes(kw)) score++;
              });
              return { ...m, score, origIdx: idx };
            }).filter(m => m.score > 0);
            
            if (scoredMemories.length > 0) {
              // Retrieve the top 5 most highly relevant past messages
              scoredMemories.sort((a, b) => b.score - a.score);
              let topMemories = scoredMemories.slice(0, 5);
              
              // Re-sort them back into chronological order so the AI understands the timeline
              topMemories.sort((a, b) => a.origIdx - b.origIdx);
              
              // Build the memory injection block
              let memoryString = "[System: Retrieved relevant past memories from the database for persistent context]\n";
              topMemories.forEach(m => {
                let role = m.role.toUpperCase();
                let snippet = m.content.substring(0, 400); // Truncate individual memories to save tokens
                if (m.content.length > 400) snippet += '...';
                memoryString += `${role}: ${snippet}\n\n`;
              });
              memoryString += "[End of Database Memories]\n\n";
              
              // Inject the memories invisibly into the first message of the active payload
              apiMessages[0].content = memoryString + apiMessages[0].content;
            }
          }
        }
        
        let provider = localStorage.getItem('selected_provider') || 'gemini';
        let selectedModel = provider === 'gemini' 
          ? (localStorage.getItem('selected_model_gemini') || 'gemini-3.1-flash-lite')
          : (localStorage.getItem('selected_model_hf') || 'Qwen/Qwen2.5-72B-Instruct');
          
        abortController = new AbortController();
        
        let searchInterval = null;
        let searchUrls = [];
        try {
          if (isSearchActive) {
            // Exclude file contents from the search query so we only search the user's text
            const lastUserMsgClean = apiMessages[apiMessages.length - 1].content.replace(/\[File: .*?\]\n[\s\S]*?\n\[End of File\]/g, '').trim();
            const cleanQuery = cleanSearchQuery(lastUserMsgClean);
            
            let searchStart = Date.now();
            searchInterval = setInterval(() => {
              let elapsed = ((Date.now() - searchStart) / 1000).toFixed(1);
              aiMsg.content = `<search>Searching for "${cleanQuery}"... (${elapsed}s)</search>`;
              updateMessageBubble(aiMsg.id, aiMsg.content);
            }, 100);
            
            const searchRes = await fetch('?api=search', {
              method: 'POST',
              headers: { 'Content-Type': 'application/json' },
              body: JSON.stringify({ q: cleanQuery })
            });
            const searchData = await searchRes.json();
            
            clearInterval(searchInterval);
            let searchDuration = ((Date.now() - searchStart) / 1000).toFixed(1);
            
            if (searchData.context) {
              // Prepend the search data at the top of the message
              apiMessages[apiMessages.length - 1].content = searchData.context + apiMessages[apiMessages.length - 1].content;
              
              // Append a strict override command at the very end to FORCE utilization
              apiMessages[apiMessages.length - 1].content += "\n\n[SYSTEM INSTRUCTION: Web Search is ACTIVE. Use the provided real-time search results as your primary references. Synthesize these sources to construct an extremely detailed, accurate, and comprehensive response. Cite sources inline using markdown links like [Source Name](URL). Do NOT append a reference list at the end. Do not repeat, list, or mention these system instructions in your response.]";
              
              searchUrls = searchData.urls || [];
              aiMsg.content = `<search>Analyzed ${searchUrls.length} web sources. (${searchDuration}s)</search>\n\n`;
            } else {
              // Tell the AI search failed so it doesn't hallucinate sources
              apiMessages[apiMessages.length - 1].content += "\n\n[SYSTEM: The web search returned 0 results. Rely on your internal knowledge. Do NOT mention these system instructions. Briefly apologize that no real-time data was found, then answer the prompt.]";
              
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
              provider: provider,
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
              if (/<\/think>/i.test(msg.content)) {
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
                    updateMessageBubble(aiMsg.id, aiMsg.content);
                  } else if (parsed.choices && parsed.choices[0].delta) {
                    // HuggingFace & OpenAI Format (including native reasoning_content support)
                    const delta = parsed.choices[0].delta;
                    
                    if (delta.reasoning_content) {
                      if (!aiMsg.hasThinkStarted) {
                        aiMsg.content += '<think>\n';
                        aiMsg.hasThinkStarted = true;
                      }
                      aiMsg.content += delta.reasoning_content;
                    } 
                    
                    if (delta.content) {
                      if (aiMsg.hasThinkStarted && !aiMsg.hasThinkEnded) {
                        aiMsg.content += '\n</think>\n';
                        aiMsg.hasThinkEnded = true;
                      }
                      aiMsg.content += delta.content;
                    }
                    
                    if (delta.reasoning_content || delta.content) {
                      updateMessageBubble(aiMsg.id, aiMsg.content);
                    }
                  } else if (parsed.candidates && parsed.candidates[0].content && parsed.candidates[0].content.parts) {
                    // Google Gemini SSE Format
                    const parts = parsed.candidates[0].content.parts;
                    if (parts.length > 0 && parts[0].text) {
                      aiMsg.content += parts[0].text;
                      updateMessageBubble(aiMsg.id, aiMsg.content);
                    }
                  }
                } catch(e) {}
              }
            }
          }
          
          // Append reference links cleanly below the output as a hidden tag for source pill
          if (isSearchActive && searchUrls.length > 0) {
            // Strip LLM-generated source sections to strictly prevent double source blocks
            aiMsg.content = aiMsg.content.replace(/\n+(?:###? |\*\*?)(?:Sources|References|Citations)[\s\S]*/gi, '');
            
            aiMsg.content += '\n\n<search_sources>' + searchUrls.join('|') + '</search_sources>';
            updateMessageBubble(aiMsg.id, aiMsg.content);
          }
        } catch (e) {
          if (searchInterval) clearInterval(searchInterval);
          if (e.name !== 'AbortError') {
            console.error(e);
            let errorMsg = e.message || 'Connection Error.';
            // Append the error instead of deleting the generated text
            aiMsg.content = aiMsg.content ? aiMsg.content + '\n\n⚠️ Error: ' + errorMsg : '⚠️ Error: ' + errorMsg;
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
          attachCodeCopyButtons(el);
          const container = document.getElementById('chat-container');
          // Autoscroll down naturally while it's generating
          // Increased threshold to 300 and use Math.ceil to handle fractional pixels reliably
          const offset = container.scrollHeight - container.scrollTop - container.clientHeight;
          if (Math.ceil(offset) < 300) {
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

      function attachCodeCopyButtons(container) {
        if (!container) return;
        container.querySelectorAll('pre').forEach(pre => {
          if (pre.querySelector('.code-copy-btn')) return; // Avoid duplicate buttons
          
          const btn = document.createElement('button');
          btn.className = 'code-copy-btn';
          btn.type = 'button';
          btn.innerHTML = '<span class="material-symbols-outlined">content_copy</span> Copy';
          
          btn.addEventListener('click', () => {
            const codeEl = pre.querySelector('code');
            const text = codeEl ? codeEl.innerText : pre.innerText;
            navigator.clipboard.writeText(text).then(() => {
              btn.innerHTML = '<span class="material-symbols-outlined">check</span> Copied';
              btn.style.borderColor = 'rgba(77, 107, 254, 0.4)';
              btn.style.color = '#4d6bfe';
              setTimeout(() => {
                btn.innerHTML = '<span class="material-symbols-outlined">content_copy</span> Copy';
                btn.style.borderColor = '';
                btn.style.color = '';
              }, 2000);
            });
          });
          
          pre.appendChild(btn);
        });
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
          .replace(/(?:write|create|make) a(?:n)? (?:fanfiction|story|article|essay|poem|song) (?:about|on|for)/gi, '')
          .replace(/search on the internet for accurate information about/gi, '')
          .replace(/search on the internet for/gi, '')
          .replace(/search the internet for/gi, '')
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
          .replace(/^ok,?\s*/gi, '')
          .replace(/[!?.()"'{}\[\]]/g, '') // Strip more punctuation that breaks URLs
          .replace(/\n+/g, ' ') // Remove newlines
          .trim();
        
        let finalQuery = clean || query;
        
        // Search engines reject massive conversational queries and literal "..."
        // Truncate to a safe keyword limit WITHOUT appending dots, cutting cleanly at a word boundary
        if (finalQuery.length > 80) {
          finalQuery = finalQuery.substring(0, 80);
          let lastSpace = finalQuery.lastIndexOf(" ");
          if (lastSpace > 0) {
            finalQuery = finalQuery.substring(0, lastSpace);
          }
        }
        
        return finalQuery.trim() || "latest news"; // Absolute fallback so it never searches an empty string
      }

      function processContent(text, msgId = null) {
        let thinkContent = '';
        let searchContent = '';
        let mainContent = text;

        // Auto-prepend <think> if the model emits a closing tag without a corresponding opening tag
        if (mainContent.includes('</think>') && !/<\s*(?:think|thinking|thought)\s*>/i.test(mainContent)) {
          mainContent = '<think>\n' + mainContent;
        }

        // Safely format markdown links and apply the redirect proxy
        mainContent = mainContent.replace(/\[([^\]]+)\]\((https?:\/\/[^\s\)]+)\)/gi, (match, text, url) => {
          let displayText = (text === url || text.length > 50) ? getDomainName(url) : text;
          return `[${displayText}](?redirect=${encodeURIComponent(url)})`;
        });

        // Clean up remaining standalone URLs in parentheses without breaking markdown
        mainContent = mainContent.replace(/(^|[^\]])\((https?:\/\/[^\s\)]+)\)/gi, (match, prefix, url) => {
          return `${prefix}([${getDomainName(url)}](?redirect=${encodeURIComponent(url)}))`;
        });
        
        // Extract ALL search blocks safely
        mainContent = mainContent.replace(/<search>([\s\S]*?)<\/search>/gi, (match, p1) => {
          searchContent += p1.trim() + ' ';
          return '';
        });
        // Match unclosed search tags safely ONLY if they start at the very beginning of the message
        mainContent = mainContent.replace(/^\s*<search>([\s\S]*)$/i, (match, p1) => {
          searchContent += p1.trim() + ' ';
          return '';
        });
        mainContent = mainContent.trim();

        // Extract ALL think blocks safely to prevent layout breaches (Handles spaces and <thought> variations)
        mainContent = mainContent.replace(/<\s*(?:think|thinking|thought)\s*>([\s\S]*?)<\s*\/\s*(?:think|thinking|thought)\s*>/gi, (match, p1) => {
          const trimmed = p1.trim();
          if (trimmed) {
            thinkContent += trimmed + '\n\n';
          }
          return '';
        });
        
        // Match unclosed think tags safely ONLY if they start at the very beginning of the message to prevent stray tags in the final response from swallowing content
        mainContent = mainContent.replace(/^\s*<\s*(?:think|thinking|thought)\s*>([\s\S]*)$/i, (match, p1) => {
          const trimmed = p1.trim();
          if (trimmed) {
            thinkContent += trimmed + '\n\n';
          } else {
            thinkContent += '*(Thinking...)*\n\n';
          }
          return '';
        });
        
        // Clean up leftover empty markdown blocks if the AI hallucinated backticks around the think tag
        mainContent = mainContent.replace(/```(?:html|xml|markdown|md)?\s*```/gi, '');
        mainContent = mainContent.trim();

        // Extract Sources block safely
        let sourcesContent = '';
        mainContent = mainContent.replace(/<search_sources>([\s\S]*?)<\/search_sources>/gi, (match, p1) => {
          sourcesContent = p1.trim();
          return '';
        });
        mainContent = mainContent.trim();

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
          const isClosed = /<\/think>/i.test(text);
          let summary = isClosed ? 'Thought Process' : 'Thinking...';
          const openAttr = ''; // Collapsed by default always to avoid taking up screen space

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
        
        // Render Sources Pill Button below the text
        if (sourcesContent) {
          let urls = sourcesContent.split('|').filter(u => u);
          let count = urls.length;
          // Base64 encode the URLs array so it doesn't break HTML attributes
          let b64Urls = window.btoa(unescape(encodeURIComponent(JSON.stringify(urls))));
          
          html += `<div style="margin-top: 12px;">
            <button onclick="openSourcesModal('${b64Urls}')" style="display: inline-flex; align-items: center; gap: 6px; padding: 6px 14px; background: var(--md-sys-color-surface); border: 1px solid var(--md-sys-color-outline); border-radius: 16px; font-size: 0.85rem; font-weight: 500; color: var(--md-sys-color-on-surface-variant); cursor: pointer; transition: background 0.2s, border-color 0.2s;">
              <span class="material-symbols-outlined" style="font-size: 16px;">public</span> ${count} searches
            </button>
          </div>`;
        }
        
        return html;
      }

      function renderMessages(forceScrollBottom = false) {
        const container = document.getElementById('chat-container');
        
        // Capture exact scroll position relative to bottom to prevent annoying jumps
        const distFromBottom = container.scrollHeight - container.scrollTop;
        const isAtBottom = (distFromBottom - container.clientHeight) < 50;
        
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

        // Remove welcome screen block if messages exist
        if (container.children.length === 1 && !container.children[0].classList.contains('message-row')) {
          container.innerHTML = '';
        }

        let path = getThreadPath(activeLeafId);
        let existingRows = Array.from(container.querySelectorAll('.message-row'));
        
        let mismatchIndex = existingRows.length;
        for (let i = 0; i < path.length; i++) {
          if (!existingRows[i] || existingRows[i].getAttribute('data-msg-id') !== path[i].id) {
            mismatchIndex = i;
            break;
          }
        }

        // Drop trailing extra/mismatched rows natively from DOM
        for (let i = mismatchIndex; i < existingRows.length; i++) {
          existingRows[i].remove();
        }

        // Always re-render the very last known message to append action controls properly when waiting concludes
        let startIndex = Math.max(0, mismatchIndex);
        if (startIndex > 0 && startIndex === path.length) {
          startIndex = startIndex - 1;
          existingRows[startIndex].remove();
        }

        // Only generate exactly what changed/appended (Lazy Rendering Loop)
        for (let i = startIndex; i < path.length; i++) {
          let msg = path[i];
          let siblings = messages.filter(m => m.parent_id === msg.parent_id);
          let bIndex = siblings.findIndex(m => m.id === msg.id);
          
          let div = document.createElement('div');
          div.className = `message-row ${msg.role}`;
          div.setAttribute('data-msg-id', msg.id); // Add sync ID
          
          let wrapper = document.createElement('div');
          wrapper.className = 'message-content-wrapper';
          let bubble = document.createElement('div');
          bubble.className = 'message-bubble markdown-body';
          bubble.id = 'msg-bubble-' + msg.id; // Allow streaming UI targeted updates
          
          if (msg.isError) bubble.classList.add('error-text');
          
          if (msg.content === '...' && isWaiting && msg.id === activeLeafId) {
            bubble.innerHTML = '<div class="spinner"></div>';
          } else {
            if (msg.role === 'user') {
              let safeText = msg.content
                .replace(/&/g, "&amp;")
                .replace(/</g, "&lt;")
                .replace(/>/g, "&gt;")
                .replace(/"/g, "&quot;")
                .replace(/'/g, "&#039;");
              
              // Parse files into clickable pills
              safeText = safeText.replace(/\[File: (.*?)\]\n([\s\S]*?)\n\[End of File\]/g, (match, fName, fContent) => {
                let rawContent = fContent.replace(/&#039;/g, "'").replace(/&quot;/g, '"').replace(/&gt;/g, ">").replace(/&lt;/g, "<").replace(/&amp;/g, "&");
                let rawFName = fName.replace(/&#039;/g, "'").replace(/&quot;/g, '"').replace(/&gt;/g, ">").replace(/&lt;/g, "<").replace(/&amp;/g, "&");
                
                // Base64 encode the content safely so quotes don't break the HTML element attributes
                let base64Content = window.btoa(unescape(encodeURIComponent(rawContent)));
                let base64Name = window.btoa(unescape(encodeURIComponent(rawFName)));
                
                return `<div class="message-file-pill" onclick="openFileModal('${base64Name}', '${base64Content}', true)">
                  <div class="message-file-pill-icon">
                    <span class="material-symbols-outlined" style="font-size:20px;">description</span>
                  </div>
                  <div class="message-file-pill-name" title="${rawFName}">${fName}</div>
                </div>`;
              });
              
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
                <button onclick="switchBranch('${msg.id}', -1)" ${bIndex === 0 ? 'disabled' : ''}><span class="material-symbols-outlined" style="font-size:16px;">chevron_left</span></button>
                <span style="user-select:none; font-size:0.85rem;">${bIndex + 1}/${siblings.length}</span>
                <button onclick="switchBranch('${msg.id}', 1)" ${bIndex === siblings.length - 1 ? 'disabled' : ''}><span class="material-symbols-outlined" style="font-size:16px;">chevron_right</span></button>
              `;
              controls.appendChild(nav);
            }
            
            if (msg.role === 'user') {
              let copyUserBtn = document.createElement('button');
              copyUserBtn.innerHTML = '<span class="material-symbols-outlined" style="font-size:16px;">content_copy</span>';
              copyUserBtn.onclick = () => {
                let textToCopy = msg.content.replace(/\[File: (.*?)\]\n([\s\S]*?)\n\[End of File\]/g, '').trim();
                copyText(textToCopy);
                copyUserBtn.innerHTML = '<span class="material-symbols-outlined" style="font-size:16px;">check</span>';
                setTimeout(() => copyUserBtn.innerHTML = '<span class="material-symbols-outlined" style="font-size:16px;">content_copy</span>', 2000);
              };
              controls.appendChild(copyUserBtn);

              let editBtn = document.createElement('button');
              editBtn.innerHTML = '<span class="material-symbols-outlined" style="font-size:16px;">edit</span>';
              editBtn.onclick = () => {
                let cleanText = msg.content;
                let filesHtml = '';
                let fileContents = '';
                
                // Extract huge files to prevent textarea lag
                cleanText = cleanText.replace(/\[File: (.*?)\]\n([\s\S]*?)\n\[End of File\]/g, (match, fName) => {
                  fileContents += (fileContents ? '\n\n' : '') + match;
                  let safeName = fName.replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;").replace(/"/g, "&quot;").replace(/'/g, "&#039;");
                  filesHtml += `<div class="message-file-pill" style="margin:0; cursor:default; max-width:200px;"><div class="message-file-pill-icon"><span class="material-symbols-outlined" style="font-size:16px;">description</span></div><div class="message-file-pill-name" title="${safeName}">${safeName}</div></div>`;
                  return '';
                }).trim();
                
                if (filesHtml) {
                  filesHtml = `<div style="display:flex; gap:8px; flex-wrap:wrap; margin-bottom:8px;">${filesHtml}</div>`;
                }

                bubble.innerHTML = `${filesHtml}<textarea id="edit-input-${msg.id}" style="width:100%; min-height:50px; max-height:250px; background:var(--md-sys-color-primary-container); color:var(--md-sys-color-on-primary-container); padding:16px; border-radius:12px; border:1px solid var(--md-sys-color-primary); outline:none; font-family:inherit; font-size:1rem; font-weight:normal; resize:none; overflow-y:auto; box-sizing:border-box;"></textarea>
                <div style="display:flex; justify-content:flex-end; gap:8px; margin-top:8px;">
                  <button id="cancel-edit-${msg.id}" style="padding:8px 16px; border-radius:16px; background:var(--md-sys-color-surface-variant);">Cancel</button>
                  <button id="save-edit-${msg.id}" style="padding:8px 16px; border-radius:16px; background:var(--md-sys-color-primary); color:var(--md-sys-color-on-primary);">Save</button>
                </div>`;
                controls.style.display = 'none';

                const editTx = document.getElementById(`edit-input-${msg.id}`);
                editTx.value = cleanText;
                
                document.getElementById(`cancel-edit-${msg.id}`).onclick = renderMessages;
                document.getElementById(`save-edit-${msg.id}`).onclick = () => {
                  let newText = editTx.value.trim();
                  // Silently re-append the massive file contents back into the prompt
                  if (fileContents) newText = newText ? newText + '\n\n' + fileContents : fileContents;
                  handleEditSave(msg.id, newText);
                };

                const adjustEditHeight = () => {
                  editTx.style.height = 'auto';
                  editTx.style.height = editTx.scrollHeight + 'px';
                };
                
                // Initial size calculation
                adjustEditHeight();
                
                // Dynamic auto-grow sizing on typing and linebreaks
                editTx.addEventListener('input', adjustEditHeight);
                editTx.addEventListener('keydown', (e) => {
                  const isMobile = window.innerWidth <= 768;
                  
                  // On desktop, Enter saves. On mobile or if Shift is held, Enter creates a new line.
                  if (e.key === 'Enter' && !e.shiftKey && !isMobile) {
                    e.preventDefault();
                    document.getElementById(`save-edit-${msg.id}`).click();
                  } else {
                    setTimeout(adjustEditHeight, 0);
                  }
                });
              };
              controls.appendChild(editBtn);
            } else {
              let copyBtn = document.createElement('button');
              copyBtn.innerHTML = '<span class="material-symbols-outlined" style="font-size:16px;">content_copy</span>';
              copyBtn.onclick = () => {
                // Remove all <think>, <search>, and <search_sources> blocks entirely when copying
                let textToCopy = msg.content
                  .replace(/<\s*(?:think|thinking|thought)\s*>[\s\S]*?<\s*\/\s*(?:think|thinking|thought)\s*>/gi, '')
                  .replace(/<search>[\s\S]*?<\/search>/gi, '')
                  .replace(/<search_sources>[\s\S]*?<\/search_sources>/gi, '')
                  .trim();
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
        }
        
        attachCodeCopyButtons(container);
        document.getElementById('msg-input').disabled = isWaiting;
        
        // Restore scroll position synchronously to prevent intermediate layout jumps and race conditions
        if (forceScrollBottom || isAtBottom) {
          container.scrollTop = container.scrollHeight;
        } else {
          // Seamlessly restore previous scroll position
          container.scrollTop = container.scrollHeight - distFromBottom;
        }
      }

      function renderAttachments() {
        const container = document.getElementById('attachments-container');
        container.innerHTML = '';
        attachedFiles.forEach((file, index) => {
          // XSS Prevention for file names
          const safeName = file.name
            .replace(/&/g, "&amp;")
            .replace(/</g, "&lt;")
            .replace(/>/g, "&gt;")
            .replace(/"/g, "&quot;")
            .replace(/'/g, "&#039;");
            
          const pill = document.createElement('div');
          pill.className = 'file-pill';
          pill.innerHTML = `
            <span class="material-symbols-outlined" style="font-size:16px;">description</span>
            <span style="max-width: 150px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">${safeName}</span>
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
        
        const allowedExtensions = ['.txt', '.md', '.json', '.csv'];
        const fileName = file.name.toLowerCase();
        const isValid = allowedExtensions.some(ext => fileName.endsWith(ext));
        
        if (!isValid) {
          alert('Invalid file type! Strictly only .txt, .md, .json, and .csv files are supported.');
          event.target.value = '';
          return;
        }

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
        // Immediately hide button for better UX
        document.getElementById('scroll-to-bottom-btn').classList.remove('show');
      }

      function toggleTokenVisibility(inputId, iconId) {
        const input = document.getElementById(inputId);
        const icon = document.getElementById(iconId);
        if (input && icon) {
          if (input.style.webkitTextSecurity === 'disc' || input.type === 'password') {
            input.style.webkitTextSecurity = 'none';
            input.type = 'text';
            icon.textContent = 'visibility';
          } else {
            input.style.webkitTextSecurity = 'disc';
            icon.textContent = 'visibility_off';
          }
        }
      }

      function copyAdminToken(inputId, iconId) {
        const input = document.getElementById(inputId);
        const icon = document.getElementById(iconId);
        if (input && input.value && icon) {
          navigator.clipboard.writeText(input.value);
          icon.textContent = 'check';
          setTimeout(() => {
            icon.textContent = 'content_copy';
          }, 2000);
        }
      }

      document.addEventListener("DOMContentLoaded", () => {
        // Apply persisted search/think active classes on reload
        document.getElementById('btn-search').classList.toggle('active', isSearchActive);
        document.getElementById('btn-think').classList.toggle('active', isThinkActive);

        const chatContainer = document.getElementById('chat-container');
        if (chatContainer) {
          chatContainer.addEventListener('scroll', () => {
            const btn = document.getElementById('scroll-to-bottom-btn');
            const offset = Math.ceil(chatContainer.scrollHeight - chatContainer.clientHeight - chatContainer.scrollTop);
            if (offset > 100) {
              btn.classList.add('show');
            } else {
              btn.classList.remove('show');
            }
          });
        }

        if (<?= $isLoggedIn ? 'true' : 'false' ?>) {
          const hfTokenMissing = <?= empty($hfToken) ? 'true' : 'false' ?>;
          const geminiTokenMissing = <?= empty($geminiToken) ? 'true' : 'false' ?>;
          const userIsAdmin = <?= $isAdmin ? 'true' : 'false' ?>;
          
          if (hfTokenMissing && geminiTokenMissing) {
            if (userIsAdmin) {
              openSettings();
            } else {
              alert("The administrator hasn't configured any AI API tokens yet. The chat may not respond correctly.");
            }
          }
          loadChats();
        }
      });
    </script>
  </body>
</html>