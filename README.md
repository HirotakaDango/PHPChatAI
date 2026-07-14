# PHPChatAI

A lightweight, private, and modern AI chat interface built with PHP and SQLite. 

## Screenshot

<img width="720" height="1387" alt="Screenshot" src="https://github.com/user-attachments/assets/cc4927cf-b0f0-4373-85d0-108ad550bc33" />



## ✨ Features

- **DeepSeek Inspired UI:** Minimalist dark layout with smooth, responsive chat bubbles and dynamic tab titles.
- **Live Thinking & Search Timers:** Precise sub-second count-up timers for step-by-step reasoning and search.
- **Resilient Web Search:** Fast DuckDuckGo search with Wikipedia fallback to bypass rate limits on shared hosting.
- **File Attachments:** Upload `.txt`, `.md`, or `.csv` files shown as visual pills (keeping the text area clean).
- **Strict Privacy:** Account isolation secures chat data so users can only access and delete their own threads.
- **Multi-Model Options:** Integrated HuggingFace models including powerful reasoning, fast, and uncensored models.

## 🚀 Setup & Installation

1. **Deploy:** Upload the code to any PHP server (PHP 8.0+ required with `pdo_sqlite` enabled).
2. **Launch:** Access the script via your browser.
3. **Configure:** Log in, open **Settings**, and paste your HuggingFace API Token to start chatting.

### 🔒 Permissions

If you encounter database permission errors (e.g., on Replit), run the following commands in your terminal:

```bash
chmod 755 .
touch phpchat.sqlite && chmod 644 phpchat.sqlite
```

## 🛠️ Requirements

- PHP 8.0 or higher
- SQLite3 and `pdo_sqlite` extensions enabled
- A free HuggingFace API Token
