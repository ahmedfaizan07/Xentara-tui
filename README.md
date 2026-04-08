# ⚡ Xentara TUI

A terminal user interface for browsing and monitoring the [Xentara](https://www.xentara.io/) model tree in real time via the Xentara WebSocket API.

![PHP 8.1+](https://img.shields.io/badge/PHP-8.1%2B-blue)
![License](https://img.shields.io/badge/license-MIT-green)
![Platform](https://img.shields.io/badge/platform-Linux-lightgrey)

---

## Features

- **Model Tree Browser** — Navigate the full Xentara element hierarchy with keyboard controls
- **Real-Time Values** — Live attribute updates via WebSocket subscriptions (no polling)
- **Quality Indicators** — Color-coded quality badges (Good/Acceptable/Unreliable/Bad)
- **Category Icons** — Visual icons for Data Points, Devices, Timers, Groups, and more
- **Zero Dependencies** — Pure PHP, no Composer packages or external libraries required
- **Credential Persistence** — First-run setup wizard saves config to `~/.config/xentara-tui/config.json`
- **Flicker-Free Rendering** — Partial redraws and cursor-home technique for smooth updates

## Screenshots

```
  ⚡ Xentara TUI   Xentara Root › devices  ● LIVE
 ┌─ Model Tree ────────────────┐┌─ Element Detail ────────────────────────┐
 │ ⚙ myDevice                  ││ ⚙ myDevice.temperatures.sensor1        │
 │ ⚙ anotherDevice             ││ ─────────────────────────────────────── │
 │ ◆ sensor1               ◀  ││ UUID      550e8400-e29b-41d4-a716-4466 │
 │ ◆ sensor2                   ││ Category  Data Point                    │
 │ ⏱ updateTimer                ││                                        │
 │                              ││ ▸ Attributes ●                         │
 │                              ││ ······························         │
 │                              ││ name          Temperature Sensor 1     │
 │                              ││ type          xentara.io/temperature   │
 │                              ││ quality       ● Good                   │
 │                              ││ value         23.456789                │
 └──────────────────────────────┘└────────────────────────────────────────┘
  Ready.                              ↑↓ nav  ↵ enter  ⌫ back  r read  q quit
```

---

## Requirements

| Requirement       | Version   | Notes                                    |
|-------------------|-----------|------------------------------------------|
| **PHP**           | 8.1+      | CLI (`php-cli`)                          |
| **ext-sockets**   | —         | WebSocket communication                  |
| **ext-openssl**   | —         | TLS/SSL for secure WebSocket (WSS)       |
| **ext-mbstring**  | —         | Unicode text handling in the TUI          |
| **Terminal**      | VT100+    | Any modern terminal (gnome-terminal, kitty, alacritty, Windows Terminal) |
| **Xentara**       | 2.0+      | Running with WebSocket API enabled       |

### Checking Requirements

```bash
# Check PHP version
php -v

# Check installed extensions
php -m | grep -iE 'sockets|openssl|mbstring'
```

### Installing Requirements (Ubuntu/Debian)

```bash
sudo apt update
sudo apt install php-cli php-sockets php-mbstring
# openssl is usually included with php-cli
```

### Installing Requirements (Fedora/RHEL)

```bash
sudo dnf install php-cli php-sockets php-mbstring php-openssl
```

### Installing Requirements (Arch Linux)

```bash
sudo pacman -S php
# Extensions are typically included
```

---

## Quick Start

### Option 1: One-Line Install from GitHub

```bash
git clone https://github.com/ahmedfaizan07/xentara-tui.git
cd xentara-tui
./install.sh
```

Then run:

```bash
xentara-tui
```

### Option 2: Run Directly (No Install)

```bash
git clone https://github.com/ahmedfaizan07/xentara-tui.git
cd xentara-tui
php xentara-tui.php
```

### Option 3: curl One-Liner

```bash
curl -sL https://raw.githubusercontent.com/ahmedfaizan07/xentara-tui/main/install.sh | bash
```


---

## Installation

### Using the Installer Script

The installer checks PHP version, verifies required extensions, and copies the tool to your PATH:

```bash
# User install (no sudo needed) — installs to ~/.local/bin/
./install.sh

# System-wide install — installs to /usr/local/bin/
sudo ./install.sh
```

If `~/.local/bin` isn't in your PATH, the installer will tell you how to add it:

```bash
echo 'export PATH="$HOME/.local/bin:$PATH"' >> ~/.bashrc
source ~/.bashrc
```

### Manual Install

```bash
# Copy the script
sudo cp xentara-tui.php /usr/local/bin/xentara-tui
sudo chmod +x /usr/local/bin/xentara-tui

# Verify
xentara-tui --help
```

---

## Usage

### First Run (Interactive Setup)

On the first run, you'll be prompted for connection details:

```
── Xentara TUI Setup ──
No saved configuration found. Enter connection details:

  Host [localhost]: 192.168.1.100
  Port [8080]: 8443
  Username [xentara]: admin
  Password: ********

Config saved to /home/user/.config/xentara-tui/config.json
Connecting to wss://192.168.1.100:8443 as admin...
Connected!
```

Credentials are saved and reused automatically on subsequent runs.

### Subsequent Runs

```bash
# Uses saved config
xentara-tui

# Override specific settings for this session (does not change saved config)
xentara-tui --host 10.0.0.5

# Override all settings
xentara-tui --host 10.0.0.5 --port 8443 --user admin --pass secret

# Delete saved config and re-prompt
xentara-tui --reset-config

# Enable debug logging to /tmp/xentara-debug.log
xentara-tui --debug
```

### Command-Line Flags

| Flag              | Description                                   | Default      |
|-------------------|-----------------------------------------------|--------------|
| `--host <host>`   | Xentara server hostname or IP                 | `localhost`  |
| `--port <port>`   | WebSocket API port                            | `8080`       |
| `--user <user>`   | Authentication username                       | `xentara`    |
| `--pass <pass>`   | Authentication password                       | *(prompted)* |
| `--debug`         | Write debug log to `/tmp/xentara-debug.log`   | off          |
| `--reset-config`  | Delete saved config and re-prompt             | —            |

---

## Keyboard Controls

| Key          | Action                                           |
|--------------|--------------------------------------------------|
| `↑` / `↓`   | Move cursor up/down in the model tree            |
| `Enter`      | Drill into selected element (browse children)    |
| `Backspace`  | Go back up one level                             |
| `r`          | Read/refresh attributes for the selected element |
| `q`          | Quit the application                             |

---

## Configuration

Config is stored at `~/.config/xentara-tui/config.json` with permissions `0600` (owner read/write only).

```json
{
    "host": "localhost",
    "port": 8080,
    "user": "xentara",
    "pass": "your-password"
}
```

To change stored credentials, either:
- Edit the file directly
- Run `xentara-tui --reset-config` to re-prompt
- Run with `--host`/`--port`/`--user`/`--pass` flags to override for one session

---

## How It Works

### Architecture

```
┌──────────────┐     CBOR/WSS      ┌────────────┐
│  Xentara TUI │ ◀────────────────▶ │  Xentara   │
│  (PHP CLI)   │   WebSocket API    │  Server    │
└──────┬───────┘                    └────────────┘
       │
       ├── Cbor class          CBOR encoder/decoder (RFC 8949)
       ├── XentaraWsClient     WebSocket client with TLS, ping/pong, framing
       ├── XentaraApi          High-level API (browse, read, subscribe)
       └── App                 TUI rendering and navigation
```

### Protocol

The Xentara WebSocket API uses **CBOR** (Concise Binary Object Representation) over **WSS** (WebSocket Secure).

**Message format:**
```
Outer array: [ [packet1], [packet2], ... ]
Request:     [0, msgId, opcode, {params}]
Response:    [1, msgId, {result}]
Error:       [2, msgId, {0: errorCode, 1: errorMsg}]
Event:       [8, eventType, {0: timestamp, 1: elementUUID, 2: {attrId: value}}]
```

**Opcodes:**
| Code | Operation      | Description                          |
|------|----------------|--------------------------------------|
| 1    | Browse         | List children of an element          |
| 3    | Lookup         | Find element UUID by primary key     |
| 4    | Read           | Read attribute values                |
| 6    | Subscribe      | Subscribe to live attribute changes  |
| 7    | Unsubscribe    | Cancel a subscription                |
| 14   | Client Hello   | Protocol version negotiation         |
| 15   | Server Info    | Get server information               |

**CBOR Tags:**
| Tag | Meaning                               |
|-----|---------------------------------------|
| 37  | UUID (wraps 16-byte binary)           |
| 121 | Success alternative (attribute value) |
| 122 | Error alternative (attribute missing) |

### Xentara Element Categories

| ID | Category       | Icon |
|----|----------------|------|
| 0  | Root           | ◉    |
| 1  | Group          | ▸    |
| 2  | Data Point     | ◆    |
| 3  | Timer          | ⏱    |
| 4  | Exec Track     | ⟶    |
| 5  | Exec Pipeline  | ⇉    |
| 6  | Device         | ⚙    |
| 7  | Sub Device     | ⚙    |
| 8  | Device Group   | ◫    |
| 9  | DP Group       | ◫    |
| 10 | Transaction    | ⇄    |
| 11 | Microservice   | μ    |
| 12 | AI             | ⬡    |
| 13 | Data Storage   | ⛁    |
| 14 | Ext Interface  | ⇌    |
| 15 | Special        | ★    |

---

## Troubleshooting

### Connection Refused

```
Connection failed: TCP connect failed: Connection refused (111)
```

- Verify Xentara is running and the WebSocket API is enabled
- Check host and port: `curl -k https://localhost:8080/api/ws`
- Check firewall: `sudo ufw allow 8080/tcp`

### SSL Errors

```
Connection failed: SSL operation failed
```

- Xentara uses self-signed certificates by default — this client accepts them
- If using a custom CA, you may need to update the SSL context in the source

### Authentication Failed

```
WebSocket upgrade failed: HTTP/1.1 401 Unauthorized
```

- Check username and password
- Reset config: `xentara-tui --reset-config`

### Broken Pipe / Connection Drops

- Usually caused by missed ping/pong frames — this client handles them automatically
- If running through a proxy, ensure WebSocket connections aren't being terminated
- Enable debug mode to see raw traffic: `xentara-tui --debug`

### Missing PHP Extensions

```bash
# Check what's installed
php -m

# Install missing extensions (Ubuntu/Debian)
sudo apt install php-sockets php-mbstring

# Restart if needed
sudo systemctl restart php*-fpm
```

---

## Debug Mode

Enable with `--debug` to write a full protocol trace to `/tmp/xentara-debug.log`:

```bash
xentara-tui --debug

# In another terminal, watch the log:
tail -f /tmp/xentara-debug.log
```

The log includes hex dumps of all sent/received CBOR frames.

---

## Uninstall

```bash
# Remove the binary
rm ~/.local/bin/xentara-tui
# or if installed system-wide:
sudo rm /usr/local/bin/xentara-tui

# Remove saved config
rm -rf ~/.config/xentara-tui
```

---

## Publishing to GitHub

To make this available for others to install:

```bash
# Initialize a git repo
cd /path/to/xentara-tui
git init
git add xentara-tui.php install.sh README.md
git commit -m "Initial release: Xentara TUI client"

# Create repo on GitHub (using gh CLI)
gh repo create xentara-tui --public --source=. --push

# Or manually: create repo on github.com, then:
git remote add origin git@github.com:YOUR_USERNAME/xentara-tui.git
git branch -M main
git push -u origin main
```

Then anyone can install with:

```bash
git clone https://github.com/YOUR_USERNAME/xentara-tui.git
cd xentara-tui
./install.sh
```

---

## File Structure

```
xentara-tui/
├── xentara-tui.php    # Main application (single-file, no dependencies)
├── install.sh         # Linux installer script
└── README.md          # This file
```

---

## API Reference (for developers extending this)

| Class / Function     | Purpose                                                  |
|----------------------|----------------------------------------------------------|
| `Cbor`               | CBOR encoder/decoder (RFC 8949 subset)                   |
| `CborBytes`          | Wrapper for CBOR byte strings                            |
| `CborTag`            | Wrapper for CBOR tagged values (Tag 37, 121, 122)        |
| `CborMap`            | Force CBOR map encoding (integer keys)                   |
| `CborArray`          | Force CBOR array encoding                                |
| `XentaraWsClient`    | Low-level WebSocket client (connect, frame, ping/pong)   |
| `XentaraApi`         | High-level API (browse, read, subscribe, etc.)           |
| `App`                | TUI controller (navigation, rendering, event loop)       |
| `uuidToBytes()`      | UUID string → 16-byte binary                             |
| `bytesToUuid()`      | 16-byte binary → UUID string                             |
| `cborUuid()`         | UUID string → CBOR Tag 37                                |
| `categoryIcon()`     | Category ID → colored Unicode icon                       |
| `qualityBadge()`     | Quality value → colored status badge                     |

---

## License

GNU GENERAL PUBLIC LICENSE Version 3, 29 June 2007. See source file header for details.
