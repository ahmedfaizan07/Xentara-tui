#!/usr/bin/env php
<?php
/**
 * Xentara WebSocket TUI Client
 * ============================
 * A terminal user interface for browsing and reading Xentara built-in skills
 * via the Xentara WebSocket API (CBOR over WSS).
 *
 * Requirements:
 *   - PHP 8.1+
 *   - Extensions: sockets, openssl, mbstring
 *
 * Usage:
 *   xentara-tui                         # uses saved config or prompts on first run
 *   xentara-tui --host h --port p --user u --pass p   # override saved config
 *
 * Config stored at: ~/.config/xentara-tui/config.json
 */

declare(strict_types=1);

// ─── CLI argument parsing ────────────────────────────────────────────────────
// We parse argv manually instead of using PHP's getopt() because getopt's ::
// (optional-value) mode greedily swallows the next positional argument.
// Supported formats:
//   --key=value        e.g. --host=192.168.1.10
//   --key value        e.g. --host 192.168.1.10
//   --debug            boolean flag (no value)
//   --reset-config     deletes saved credentials and re-prompts

/**
 * Parse CLI arguments into an associative array.
 *
 * @param  array $argv  The raw $argv from the PHP runtime
 * @return array        Parsed key-value pairs (e.g. ['host'=>'localhost', 'debug'=>true])
 */
function parseArgs(array $argv): array
{
    $result = [];
    $keys   = ['host', 'port', 'user', 'pass']; // allowed --key value pairs
    for ($i = 1; $i < count($argv); $i++) {
        $arg = $argv[$i];
        // Handle --key=value format
        if (preg_match('/^--([a-z]+)=(.*)$/', $arg, $m)) {
            $result[$m[1]] = $m[2];
        // Handle --debug boolean flag
        } elseif ($arg === '--debug') {
            $result['debug'] = true;
        // Handle --reset-config flag
        } elseif ($arg === '--reset-config') {
            $result['reset_config'] = true;
        // Handle --key value (two-token) format, only for whitelisted keys
        } elseif (preg_match('/^--([a-z]+)$/', $arg, $m) && in_array($m[1], $keys, true)) {
            $result[$m[1]] = $argv[++$i] ?? '';
        }
    }
    return $result;
}

// ─── Config file persistence ─────────────────────────────────────────────────
// On first run, the user is prompted for host/port/user/pass and the values
// are saved to ~/.config/xentara-tui/config.json (permissions 0600).
// On subsequent runs, saved config is used automatically.
// CLI flags (--host, --port, --user, --pass) override saved values for that session.
// Use --reset-config to delete the saved file and re-prompt.

/** @return string Absolute path to the JSON config file */
function configPath(): string
{
    // $HOME on Linux/macOS, $USERPROFILE on Windows, /tmp as last resort
    $home = getenv('HOME') ?: getenv('USERPROFILE') ?: '/tmp';
    return $home . '/.config/xentara-tui/config.json';
}

/**
 * Load saved config from disk.
 * @return array Associative array with keys: host, port, user, pass (or empty if no file)
 */
function loadConfig(): array
{
    $path = configPath();
    if (!file_exists($path)) return [];
    $json = file_get_contents($path);
    $data = json_decode($json, true);
    return is_array($data) ? $data : [];
}

/**
 * Save config to disk with restricted permissions.
 * Directory is created with 0700, file with 0600 to protect credentials.
 *
 * @param array $cfg Associative array with keys: host, port, user, pass
 */
function saveConfig(array $cfg): void
{
    $path = configPath();
    $dir  = dirname($path);
    if (!is_dir($dir)) {
        mkdir($dir, 0700, true); // only owner can access
    }
    file_put_contents($path, json_encode($cfg, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
    chmod($path, 0600); // read/write only for owner — protects password
}

function promptInput(string $prompt, string $default = ''): string
{
    $display = $default !== '' ? " [{$default}]" : '';
    echo $prompt . $display . ': ';
    $val = trim(fgets(STDIN));
    return $val !== '' ? $val : $default;
}

function promptPassword(string $prompt): string
{
    echo $prompt . ': ';
    if (PHP_OS_FAMILY !== 'Windows') {
        system('stty -echo');
        $pass = trim(fgets(STDIN));
        system('stty echo');
        echo "\n";
    } else {
        $pass = trim(fgets(STDIN));
    }
    return $pass;
}

$opts  = parseArgs($argv);
$debug = !empty($opts['debug']);
$hasCliFlags = isset($opts['host']) || isset($opts['port']) || isset($opts['user']) || isset($opts['pass']);

// Delete saved config if --reset-config was passed
if (!empty($opts['reset_config'])) {
    $cp = configPath();
    if (file_exists($cp)) unlink($cp);
    echo "Config reset.\n";
}

$saved = loadConfig();

if ($hasCliFlags) {
    // CLI flags override saved config
    $host = $opts['host'] ?? $saved['host'] ?? 'localhost';
    $port = (int)($opts['port'] ?? $saved['port'] ?? 8080);
    $user = $opts['user'] ?? $saved['user'] ?? 'xentara';
    $pass = $opts['pass'] ?? $saved['pass'] ?? null;
} elseif (!empty($saved)) {
    // Use saved config
    $host = $saved['host'] ?? 'localhost';
    $port = (int)($saved['port'] ?? 8080);
    $user = $saved['user'] ?? 'xentara';
    $pass = $saved['pass'] ?? null;
    echo "\033[36mUsing saved config: \033[97m{$user}@{$host}:{$port}\033[0m\n";
} else {
    // First run — interactive setup
    echo "\033[1;36m── Xentara TUI Setup ──\033[0m\n";
    echo "No saved configuration found. Enter connection details:\n\n";
    $host = promptInput('  Host', 'localhost');
    $port = (int)promptInput('  Port', '8080');
    $user = promptInput('  Username', 'xentara');
    $pass = promptPassword('  Password');
    echo "\n";

    // Save for next time
    saveConfig(['host' => $host, 'port' => $port, 'user' => $user, 'pass' => $pass]);
    echo "\033[32mConfig saved to " . configPath() . "\033[0m\n";
}

// If still no password, prompt for it
if ($pass === null || $pass === '') {
    $pass = promptPassword("Password for user '{$user}'");
}

// ─── CBOR encoder/decoder (minimal, covers what Xentara needs) ───────────────
//
// CBOR (Concise Binary Object Representation, RFC 8949) is a compact binary
// data format similar to JSON but more efficient. Xentara uses CBOR for all
// WebSocket message payloads.
//
// CBOR structure:
//   - Each value starts with 1 byte: upper 3 bits = major type, lower 5 bits = additional info
//   - Major types: 0=unsigned int, 1=negative int, 2=byte string, 3=text string,
//                  4=array, 5=map, 6=tag, 7=float/simple (true/false/null)
//   - Tags (major type 6) attach semantic meaning:
//       Tag 37  = UUID (wraps a 16-byte byte string)
//       Tag 121 = Success alternative (Xentara uses for successful attribute values)
//       Tag 122 = Error alternative (Xentara uses for missing/errored attributes)
//
// This implementation covers only the subset needed by the Xentara WebSocket API.
// It does NOT support indefinite-length items or half-precision float encoding.

/**
 * Minimal CBOR encoder/decoder for Xentara WebSocket API.
 *
 * Encoding: PHP types map to CBOR as follows:
 *   null → CBOR null (0xf6)      bool → CBOR true/false
 *   int  → CBOR unsigned/negative float → CBOR float64
 *   string → CBOR text string     array → CBOR array or map (auto-detected)
 *   CborBytes → CBOR byte string  CborTag → CBOR tagged value
 *   CborMap → CBOR map (forced)   CborArray → CBOR array (forced)
 *
 * Decoding: CBOR types map back to the same PHP types/classes.
 */
class Cbor
{
    // ── Encode ─────────────────────────────────────────────────────────────

    /**
     * Encode any supported PHP value to a CBOR binary string.
     * @throws RuntimeException if the value type is unsupported
     */
    public static function encode(mixed $value): string
    {
        if ($value === null)               return "\xf6";
        if ($value === true)               return "\xf5";
        if ($value === false)              return "\xf4";
        if (is_int($value))                return self::encodeInt($value);
        if (is_float($value))              return self::encodeFloat($value);
        if (is_string($value))             return self::encodeText($value);
        if ($value instanceof CborBytes)   return self::encodeBytes($value->data);
        if ($value instanceof CborTag)     return self::encodeTag($value->tag, $value->value);
        if ($value instanceof CborMap)     return self::encodeMap($value->pairs);
        if ($value instanceof CborArray)   return self::encodeArray($value->items);
        // Plain PHP arrays: only encode as CBOR array if sequential 0-based int keys
        if (is_array($value)) {
            $keys = array_keys($value);
            if ($keys === range(0, count($keys) - 1)) {
                return self::encodeArray($value);
            }
            return self::encodeMap($value);
        }
        throw new \RuntimeException('Cannot CBOR-encode value of type ' . gettype($value));
    }

    /**
     * Encode the initial byte of a CBOR item.
     * Format: bits 7-5 = major type (0-7), bits 4-0 = additional info (0-27)
     */
    private static function encodeHead(int $major, int $info): string
    {
        $byte = ($major << 5) | $info;
        return chr($byte);
    }

    /**
     * Encode a CBOR head byte + variable-length integer.
     * CBOR uses compact encoding: values 0-23 fit in the initial byte,
     * 24-255 use 1 extra byte, up to 8 extra bytes for 64-bit values.
     */
    private static function encodeLength(int $major, int $len): string
    {
        if ($len <= 23)        return self::encodeHead($major, $len);
        if ($len <= 0xFF)      return self::encodeHead($major, 24) . chr($len);
        if ($len <= 0xFFFF)    return self::encodeHead($major, 25) . pack('n', $len);
        if ($len <= 0xFFFFFFFF)return self::encodeHead($major, 26) . pack('N', $len);
        return self::encodeHead($major, 27) . pack('J', $len);
    }

    private static function encodeInt(int $v): string
    {
        if ($v >= 0) return self::encodeLength(0, $v);
        return self::encodeLength(1, -1 - $v);
    }

    /** Encode a float as CBOR float64 (major type 7, info 27). Always uses 64-bit for precision. */
    private static function encodeFloat(float $v): string
    {
        // 0xfb = major 7, info 27 (float64). Pack as little-endian double, then reverse for big-endian.
        return "\xfb" . strrev(pack('d', $v));
    }

    private static function encodeBytes(string $bytes): string
    {
        return self::encodeLength(2, strlen($bytes)) . $bytes;
    }

    private static function encodeText(string $s): string
    {
        $utf8 = mb_convert_encoding($s, 'UTF-8', 'UTF-8');
        return self::encodeLength(3, strlen($utf8)) . $utf8;
    }

    private static function encodeArray(array $arr): string
    {
        $out = self::encodeLength(4, count($arr));
        foreach ($arr as $item) $out .= self::encode($item);
        return $out;
    }

    private static function encodeMap(array $map): string
    {
        $out = self::encodeLength(5, count($map));
        foreach ($map as $k => $v) {
            $out .= self::encode($k);
            $out .= self::encode($v);
        }
        return $out;
    }

    private static function encodeTag(int $tag, mixed $value): string
    {
        return self::encodeLength(6, $tag) . self::encode($value);
    }

    // ── Decode ─────────────────────────────────────────────────────────────

    /**
     * Decode a CBOR binary string into a PHP value.
     * @param  string $data  Raw CBOR bytes
     * @return mixed         Decoded PHP value (int, float, string, array, CborTag, etc.)
     */
    public static function decode(string $data): mixed
    {
        $pos = 0;
        return self::decodeValue($data, $pos);
    }

    /**
     * Recursively decode one CBOR value from $data starting at $pos.
     * $pos is advanced past the consumed bytes (passed by reference).
     *
     * Special handling for major type 7 (floats/simple values):
     * When info >= 25, we must NOT call decodeAdditional() because that would
     * consume the float bytes as an integer. Instead, we read the float bytes
     * directly with the correct unpack format.
     */
    private static function decodeValue(string $data, int &$pos): mixed
    {
        if ($pos >= strlen($data)) throw new \RuntimeException('Unexpected end of CBOR data');
        $byte = ord($data[$pos++]);
        $major = ($byte >> 5) & 0x07;
        $info  = $byte & 0x1F;

        // For major type 7 (floats/simple), don't consume float bytes as integer
        if ($major === 7 && $info >= 25) {
            $len = 0; // not used for floats
        } else {
            $len = self::decodeAdditional($data, $pos, $info);
        }

        switch ($major) {
            case 0: return $len; // unsigned int
            case 1: return -1 - $len; // negative int
            case 2: // bytes
                $bytes = substr($data, $pos, $len);
                $pos += $len;
                return new CborBytes($bytes);
            case 3: // text
                $text = substr($data, $pos, $len);
                $pos += $len;
                return $text;
            case 4: // array
                $arr = [];
                for ($i = 0; $i < $len; $i++) $arr[] = self::decodeValue($data, $pos);
                return $arr;
            case 5: // map
                $map = [];
                for ($i = 0; $i < $len; $i++) {
                    $k = self::decodeValue($data, $pos);
                    $v = self::decodeValue($data, $pos);
                    $map[$k] = $v;
                }
                return $map;
            case 6: // tag
                $tagNum = $len;
                $tagVal = self::decodeValue($data, $pos);
                return new CborTag($tagNum, $tagVal);
            case 7: // float / simple
                if ($info === 20) return false;
                if ($info === 21) return true;
                if ($info === 22) return null;
                if ($info === 25) { // float16
                    if ($pos + 2 > strlen($data)) throw new \RuntimeException('Unexpected end of CBOR float16');
                    $raw = unpack('n', substr($data, $pos, 2))[1];
                    $pos += 2;
                    return self::float16($raw);
                }
                if ($info === 26) { // float32
                    if ($pos + 4 > strlen($data)) throw new \RuntimeException('Unexpected end of CBOR float32');
                    $val = unpack('G', substr($data, $pos, 4))[1]; // big-endian float
                    $pos += 4;
                    return (float)$val;
                }
                if ($info === 27) { // float64
                    if ($pos + 8 > strlen($data)) throw new \RuntimeException('Unexpected end of CBOR float64');
                    $val = unpack('E', substr($data, $pos, 8))[1]; // big-endian double
                    $pos += 8;
                    return (float)$val;
                }
                return $len; // simple value
            default:
                throw new \RuntimeException("Unknown CBOR major type $major");
        }
    }

    /**
     * Decode the "additional information" integer that follows the initial byte.
     * CBOR packs small values (0-23) directly in the 5-bit info field.
     * Larger values use 1/2/4/8 additional bytes (info=24/25/26/27).
     *
     * @param  string $data  Raw CBOR bytes
     * @param  int    &$pos  Current read position (advanced past consumed bytes)
     * @param  int    $info  The 5-bit additional info from the initial byte
     * @return int           The decoded integer value
     */
    private static function decodeAdditional(string $data, int &$pos, int $info): int
    {
        if ($info <= 23) return $info;                                                          // inline
        if ($info === 24) return ord($data[$pos++]);                                            // 1 byte
        if ($info === 25) { $v = unpack('n', substr($data, $pos, 2))[1]; $pos += 2; return $v; } // 2 bytes (uint16 big-endian)
        if ($info === 26) { $v = unpack('N', substr($data, $pos, 4))[1]; $pos += 4; return $v; } // 4 bytes (uint32 big-endian)
        if ($info === 27) { $v = unpack('J', substr($data, $pos, 8))[1]; $pos += 8; return $v; } // 8 bytes (uint64 big-endian)
        return $info;
    }

    /**
     * Decode an IEEE 754 half-precision (16-bit) float.
     * Xentara sometimes sends float16 for small/zero values.
     * Layout: 1 sign bit, 5 exponent bits, 10 mantissa bits.
     */
    private static function float16(int $raw): float
    {
        $exp  = ($raw >> 10) & 0x1F;
        $mant = $raw & 0x3FF;
        $sign = ($raw >> 15) ? -1.0 : 1.0;
        if ($exp === 0)  return $sign * pow(2, -14) * ($mant / 1024.0);
        if ($exp === 31) return $mant ? NAN : ($sign * INF);
        return $sign * pow(2, $exp - 15) * (1 + $mant / 1024.0);
    }
}

/** Wrapper for CBOR byte strings (major type 2). Distinguishes raw bytes from UTF-8 text. */
class CborBytes { public function __construct(public readonly string $data) {} }

/**
 * Wrapper for CBOR tagged values (major type 6).
 * Common tags in Xentara:
 *   37  = UUID (value is CborBytes with 16 bytes)
 *   121 = Success alternative (value is the actual attribute value)
 *   122 = Error alternative (value describes why the attribute is unavailable)
 */
class CborTag   { public function __construct(public readonly int $tag, public readonly mixed $value) {} }

/**
 * Force encoding a PHP array as a CBOR map (major type 5) regardless of key types.
 * Needed because Xentara uses integer keys in maps (e.g. {0: uuid, 1: attrIds})
 * and PHP would otherwise encode sequential int-keyed arrays as CBOR arrays.
 */
class CborMap   { public function __construct(public readonly array $pairs) {} }

/** Force encoding a PHP array as a CBOR array (major type 4). */
class CborArray { public function __construct(public readonly array $items) {} }

// ─── UUID helpers ─────────────────────────────────────────────────────────────
// Xentara identifies every element by a UUID. These are transmitted as CBOR
// Tag 37 wrapping a 16-byte binary value. These helpers convert between the
// human-readable string form (e.g. "550e8400-e29b-41d4-a716-446655440000")
// and the compact binary form used on the wire.

/** Convert a UUID string (with dashes) to 16 raw bytes */
function uuidToBytes(string $uuid): string
{
    return hex2bin(str_replace('-', '', $uuid));
}

/** Convert 16 raw bytes back to a UUID string with dashes */
function bytesToUuid(string $bytes): string
{
    $hex = bin2hex($bytes);
    return sprintf('%s-%s-%s-%s-%s',
        substr($hex, 0, 8), substr($hex, 8, 4),
        substr($hex, 12, 4), substr($hex, 16, 4),
        substr($hex, 20));
}

/** Encode a UUID string as CBOR Tag 37 (the standard UUID tag per RFC 9562) */
function cborUuid(string $uuid): CborTag
{
    return new CborTag(37, new CborBytes(uuidToBytes($uuid)));
}

/** The nil UUID represents the root of the Xentara model tree */
const NIL_UUID = '00000000-0000-0000-0000-000000000000';

// ─── WebSocket client (native PHP sockets, WSS/TLS) ──────────────────────────
//
// This class implements a WebSocket client from scratch using PHP's stream
// socket functions (no external library needed). It handles:
//
//   1. TLS/SSL connection with self-signed certificate support
//   2. HTTP 101 Upgrade handshake (RFC 6455) with Basic auth
//   3. WebSocket binary frame encoding/decoding with masking
//   4. Automatic Ping/Pong keepalive (server sends pings, we reply with pongs)
//   5. CBOR message encoding/decoding via the Cbor class
//   6. Separation of response packets from event packets (subscription data)
//
// Xentara protocol framing (inside each WebSocket binary message):
//   - Every message is wrapped in an outer CBOR array: [[packet1], [packet2], ...]
//   - Request packet:  [0, msgId, opcode, {params}]   (type 0 = request)
//   - Response packet: [1, msgId, {result}]            (type 1 = success response)
//   - Error packet:    [2, msgId, {0: code, 1: msg}]  (type 2 = error response)
//   - Event packet:    [8, eventType, {params}]        (type 8 = push event)

class XentaraWsClient
{
    /** @var resource|null The SSL stream socket */
    private mixed $socket = null;
    /** @var int Auto-incrementing message ID for request/response correlation */
    private int   $msgId  = 1;
    private bool  $debug  = false;
    /** @var string Path to debug log file (only written when --debug is used) */
    private string $debugFile = '/tmp/xentara-debug.log';
    /** @var string Persistent receive buffer for incomplete WebSocket frames */
    private string $recvBuf = '';
    /** @var array Queue of event packets (type 8) received via subscriptions */
    private array $eventQueue = [];

    public function setDebug(bool $debug): void { $this->debug = $debug; }

    private function log(string $msg): void
    {
        if ($this->debug) file_put_contents($this->debugFile, date('H:i:s') . " $msg\n", FILE_APPEND);
    }

    /**
     * Connect to the Xentara WebSocket API over SSL/TLS.
     *
     * Steps:
     *   1. Open a TLS socket (self-signed certs accepted for local dev)
     *   2. Send HTTP Upgrade request with Basic auth + Sec-WebSocket-Protocol: xentara-v1
     *   3. Verify server responds with HTTP 101 Switching Protocols
     *   4. Switch socket to non-blocking mode for multiplexed I/O
     *
     * @throws RuntimeException if TCP connect or WebSocket upgrade fails
     */
    public function connect(string $host, int $port, string $user, string $pass): void
    {
        // ── Step 1: DNS resolution ────────────────────────────────────────
        $this->emitStatus('Resolving hostname...');
        $ip = @gethostbyname($host);
        if ($ip === $host && !filter_var($host, FILTER_VALIDATE_IP)) {
            throw new \RuntimeException(
                "DNS resolution failed: cannot resolve '{$host}'\n"
                . "  \033[33m→ Check that the hostname is correct\n"
                . "  → Verify your DNS settings or try using an IP address instead\033[0m"
            );
        }
        $this->emitStatus("Resolved {$host} → {$ip}");

        // ── Step 2: TCP port reachability (plain socket, no SSL) ──────────
        $this->emitStatus("Checking port {$port} on {$ip}...");
        $tcpTest = @stream_socket_client(
            "tcp://{$ip}:{$port}", $tcpErrno, $tcpErrstr, 5,
            STREAM_CLIENT_CONNECT
        );
        if ($tcpTest === false) {
            $hint = match (true) {
                $tcpErrno === 110, $tcpErrno === 10060,
                str_contains(strtolower($tcpErrstr), 'timed out') =>
                    "Connection timed out — port {$port} is likely blocked by a firewall\n"
                    . "  \033[33m→ Check that port {$port} is open on the target system (e.g. sudo ufw allow {$port}/tcp)\n"
                    . "  → Verify you are on the same network or VPN as {$host}\n"
                    . "  → Check if Xentara's WebSocket API uses a different port\033[0m",
                $tcpErrno === 111, $tcpErrno === 10061,
                str_contains(strtolower($tcpErrstr), 'refused') =>
                    "Connection refused — port {$port} is reachable but nothing is listening\n"
                    . "  \033[33m→ Verify that Xentara is running on {$host}\n"
                    . "  → Check that the WebSocket API is enabled and bound to port {$port}\n"
                    . "  → Try: nc -zv {$host} {$port}\033[0m",
                $tcpErrno === 113, $tcpErrno === 10065,
                str_contains(strtolower($tcpErrstr), 'no route') =>
                    "No route to host — the network path to {$host} does not exist\n"
                    . "  \033[33m→ Check your network connection and routing\n"
                    . "  → If {$host} is on a private network, ensure VPN is connected\033[0m",
                default =>
                    "TCP connect failed: {$tcpErrstr} (errno {$tcpErrno})\n"
                    . "  \033[33m→ Try: nc -zv {$host} {$port}\n"
                    . "  → Check firewall rules on both client and server\033[0m",
            };
            throw new \RuntimeException($hint);
        }
        fclose($tcpTest);
        $this->emitStatus("Port {$port} is open");

        // ── Step 3: SSL/TLS handshake ─────────────────────────────────────
        $this->emitStatus('Establishing SSL/TLS connection...');
        $ctx = stream_context_create([
            'ssl' => [
                'verify_peer'       => false,
                'verify_peer_name'  => false,
                'allow_self_signed' => true,
            ]
        ]);

        $uri    = "ssl://{$host}:{$port}";
        $socket = @stream_socket_client($uri, $errno, $errstr, 10, STREAM_CLIENT_CONNECT, $ctx);
        if ($socket === false) {
            $hint = match (true) {
                str_contains(strtolower($errstr), 'ssl') ||
                str_contains(strtolower($errstr), 'tls') =>
                    "SSL/TLS handshake failed: {$errstr}\n"
                    . "  \033[33m→ The port is open but may not be serving SSL/TLS\n"
                    . "  → Check if Xentara is configured for HTTPS on port {$port}\n"
                    . "  → Try: openssl s_client -connect {$host}:{$port}\033[0m",
                default =>
                    "SSL connection failed: {$errstr} (errno {$errno})\n"
                    . "  \033[33m→ TCP port is open but SSL setup failed\n"
                    . "  → Try: openssl s_client -connect {$host}:{$port}\033[0m",
            };
            throw new \RuntimeException($hint);
        }
        stream_set_blocking($socket, true);
        $this->socket = $socket;
        $this->emitStatus('SSL/TLS established');

        // ── Step 4: WebSocket HTTP upgrade handshake ──────────────────────
        $this->emitStatus('WebSocket handshake...');
        $key    = base64_encode(random_bytes(16));
        $auth   = base64_encode("{$user}:{$pass}");
        $req    = "GET /api/ws HTTP/1.1\r\n"
                . "Host: {$host}:{$port}\r\n"
                . "Upgrade: websocket\r\n"
                . "Connection: Upgrade\r\n"
                . "Sec-WebSocket-Key: {$key}\r\n"
                . "Sec-WebSocket-Version: 13\r\n"
                . "Authorization: Basic {$auth}\r\n"
                . "Sec-WebSocket-Protocol: xentara-v1\r\n"
                . "\r\n";

        fwrite($this->socket, $req);
        $response = '';
        while (!str_contains($response, "\r\n\r\n")) {
            $chunk = fread($this->socket, 4096);
            if ($chunk === false || $chunk === '') break;
            $response .= $chunk;
        }

        if (!str_contains($response, '101')) {
            fclose($this->socket);
            $this->socket = null;
            $status = strtok($response, "\r\n");
            $hint = match (true) {
                str_contains($response, '401') || str_contains($response, 'Unauthorized') =>
                    "Authentication failed (HTTP 401)\n"
                    . "  \033[33m→ Check username and password\n"
                    . "  → Run with --reset-config to re-enter credentials\033[0m",
                str_contains($response, '403') || str_contains($response, 'Forbidden') =>
                    "Access forbidden (HTTP 403)\n"
                    . "  \033[33m→ User '{$user}' may not have permission to access the WebSocket API\n"
                    . "  → Check Xentara user roles and permissions\033[0m",
                str_contains($response, '404') =>
                    "WebSocket endpoint not found (HTTP 404)\n"
                    . "  \033[33m→ The server is responding but /api/ws was not found\n"
                    . "  → Verify the Xentara WebSocket API is enabled\n"
                    . "  → Check if the API path has changed in your Xentara version\033[0m",
                $response === '' =>
                    "Server closed connection without responding\n"
                    . "  \033[33m→ The server may not support WebSocket on this port\n"
                    . "  → Try a different port or check Xentara configuration\033[0m",
                default =>
                    "WebSocket upgrade failed: {$status}\n"
                    . "  \033[33m→ Server responded but did not accept the WebSocket upgrade\033[0m",
            };
            throw new \RuntimeException($hint);
        }

        stream_set_blocking($this->socket, false);
        $this->emitStatus('WebSocket connected');
    }

    /** Emit a status message during connection (callable set externally) */
    private ?\Closure $statusCallback = null;

    public function onStatus(\Closure $cb): void { $this->statusCallback = $cb; }

    private function emitStatus(string $msg): void
    {
        if ($this->statusCallback) ($this->statusCallback)($msg);
    }

    public function disconnect(): void
    {
        if ($this->socket) {
            fclose($this->socket);
            $this->socket = null;
        }
    }

    /**
     * Send a request to Xentara and wait for the matching response.
     *
     * Protocol format:
     *   Outer: [ [0, msgId, opcode, {params}] ]    ← array of packets
     *   Inner: packetType=0 (request), auto-incrementing msgId, opcode, params map
     *
     * Xentara opcodes:
     *    1 = Browse (list children)     3 = Lookup (find by primary key)
     *    4 = Read (read attributes)     6 = Subscribe (live updates)
     *    7 = Unsubscribe                14 = Client Hello (handshake)
     *   15 = Server Info
     *
     * @param  int   $opcode  The Xentara operation code
     * @param  array $params  Integer-keyed parameter map (will be wrapped in CborMap)
     * @return mixed          Decoded response packet array
     */
    public function request(int $opcode, array $params): mixed
    {
        $id      = $this->msgId++;
        // Xentara protocol: every message must be wrapped in an outer CBOR array.
        // Inner packet: [packetType=0 (request), msgId, opcode, paramsMap]
        // Params have integer keys → CborMap forces map encoding (not array)
        $inner   = new CborArray([0, $id, $opcode, new CborMap($params)]);
        $outer   = new CborArray([$inner]);
        $payload = Cbor::encode($outer);
        $this->log("SEND op={$opcode} id={$id} hex=" . bin2hex($payload));
        $this->wsSend($payload);
        return $this->waitForResponse($id);
    }

    /**
     * Send raw bytes as a masked WebSocket binary frame (RFC 6455 §5.2).
     *
     * WebSocket frame format:
     *   Byte 0: FIN(1) + RSV(000) + Opcode(0010=binary) = 0x82
     *   Byte 1: MASK(1) + PayloadLen (client→server frames MUST be masked)
     *   Bytes 2+: Extended length (if needed) + 4-byte mask key + masked payload
     *
     * Masking XORs each payload byte with mask[i % 4] — required by RFC 6455
     * for client-to-server frames to prevent cache poisoning attacks.
     */
    private function wsSend(string $payload): void
    {
        $len    = strlen($payload);
        $mask   = random_bytes(4); // 4-byte random masking key
        $masked = '';
        for ($i = 0; $i < $len; $i++) {
            $masked .= chr(ord($payload[$i]) ^ ord($mask[$i % 4]));
        }

        $frame  = "\x82"; // FIN=1, opcode=2 (binary frame)
        if ($len <= 125) {
            $frame .= chr(0x80 | $len);
        } elseif ($len <= 65535) {
            $frame .= chr(0x80 | 126) . pack('n', $len);
        } else {
            $frame .= chr(0x80 | 127) . pack('J', $len);
        }
        $frame .= $mask . $masked;
        $written = @fwrite($this->socket, $frame);
        if ($written === false || $written === 0) {
            throw new \RuntimeException('WebSocket write failed (connection lost)');
        }
    }

    /**
     * Send a WebSocket Pong frame in reply to a server Ping (RFC 6455 §5.5.3).
     * The pong payload must be identical to the ping payload.
     * Without this, the server will close the connection after a timeout.
     */
    private function wsPong(string $pingPayload): void
    {
        $len  = strlen($pingPayload);
        $mask = random_bytes(4);
        $masked = '';
        for ($i = 0; $i < $len; $i++) {
            $masked .= chr(ord($pingPayload[$i]) ^ ord($mask[$i % 4]));
        }
        $frame = "\x8a"; // FIN + pong opcode (0x0A)
        $frame .= chr(0x80 | $len);
        $frame .= $mask . $masked;
        @fwrite($this->socket, $frame);
    }

    /**
     * Try to parse one complete WebSocket frame from the receive buffer.
     *
     * WebSocket frames may arrive fragmented across TCP reads. This method
     * checks if recvBuf has enough bytes for a complete frame. If so, it
     * extracts and returns the frame; if not, it returns null (try again later).
     *
     * Frame header format:
     *   Byte 0: FIN(1 bit) + RSV(3 bits) + Opcode(4 bits)
     *   Byte 1: MASK(1 bit) + Payload length(7 bits)
     *   If len=126: next 2 bytes = actual length (uint16)
     *   If len=127: next 8 bytes = actual length (uint64)
     *   If MASK=1: next 4 bytes = masking key
     *   Remaining bytes: payload (XOR with mask if masked)
     *
     * @return array|null ['opcode'=>int, 'payload'=>string] or null if incomplete
     */
    private function parseOneFrame(): ?array
    {
        if (strlen($this->recvBuf) < 2) return null; // need at least the 2-byte header

        $byte0  = ord($this->recvBuf[0]);
        $byte1  = ord($this->recvBuf[1]);
        $opcode = $byte0 & 0x0F;       // frame type: 0=continuation, 1=text, 2=binary, 8=close, 9=ping, 0xA=pong
        $masked = ($byte1 & 0x80) !== 0; // server frames are usually unmasked
        $len    = $byte1 & 0x7F;        // 7-bit payload length or extended length indicator
        $offset = 2;

        if ($len === 126) {
            if (strlen($this->recvBuf) < 4) return null;
            $len = unpack('n', substr($this->recvBuf, 2, 2))[1];
            $offset = 4;
        } elseif ($len === 127) {
            if (strlen($this->recvBuf) < 10) return null;
            $len = unpack('J', substr($this->recvBuf, 2, 8))[1];
            $offset = 10;
        }

        if ($masked) {
            if (strlen($this->recvBuf) < $offset + 4) return null;
            $offset += 4; // skip mask key space for length calc
        }
        $totalLen = $offset + $len;
        if (strlen($this->recvBuf) < $totalLen) return null;

        // Extract payload
        $maskOffset = $masked ? ($offset - 4 - $len) : $offset - $len;
        // Re-calculate cleanly
        $hdrLen = 2;
        if (($byte1 & 0x7F) === 126) $hdrLen = 4;
        elseif (($byte1 & 0x7F) === 127) $hdrLen = 10;
        $maskBytes = '';
        $payloadStart = $hdrLen;
        if ($masked) { $maskBytes = substr($this->recvBuf, $hdrLen, 4); $payloadStart = $hdrLen + 4; }
        $payload = substr($this->recvBuf, $payloadStart, $len);
        $this->recvBuf = substr($this->recvBuf, $payloadStart + $len);

        if ($masked) {
            $unmasked = '';
            for ($i = 0; $i < strlen($payload); $i++) {
                $unmasked .= chr(ord($payload[$i]) ^ ord($maskBytes[$i % 4]));
            }
            $payload = $unmasked;
        }

        return ['opcode' => $opcode, 'payload' => $payload];
    }

    /**
     * Non-blocking drain: read all available data from the socket, parse
     * complete WebSocket frames, and sort them into responses vs events.
     *
     * Control frames are handled automatically:
     *   - Opcode 8 (Close): throws RuntimeException
     *   - Opcode 9 (Ping):  immediately replies with Pong
     *   - Opcode 0xA (Pong): ignored
     *
     * Data frames (opcode 0/1/2) are CBOR-decoded. The outer array is
     * unpacked and each inner packet is classified:
     *   - Type 8 packets → pushed to $eventQueue (subscription events)
     *   - All others → returned as responses for request/response matching
     *
     * @return array Array of response packets (each wrapped in outer array)
     */
    public function drainFrames(): array
    {
        if (!$this->socket) return [];
        stream_set_blocking($this->socket, false);
        $chunk = @fread($this->socket, 65536); // read up to 64KB at once
        if ($chunk !== false && $chunk !== '') $this->recvBuf .= $chunk;

        $responses = [];
        while (($frame = $this->parseOneFrame()) !== null) {
            $opcode  = $frame['opcode'];
            $payload = $frame['payload'];

            if ($opcode === 8) { throw new \RuntimeException('Server closed connection'); }
            if ($opcode === 9) { $this->wsPong($payload); continue; }
            if ($opcode === 0xA) { continue; } // pong, ignore
            if ($opcode === 0 || $opcode === 1 || $opcode === 2) {
                $this->log("RECV frame hex=" . bin2hex($payload));
                $decoded = Cbor::decode($payload);
                $this->log("RECV decoded type=" . gettype($decoded) . " count=" . (is_array($decoded) ? count($decoded) : 'n/a'));
                // Outer array may contain responses or events
                if (is_array($decoded)) {
                    foreach ($decoded as $packet) {
                        if (is_array($packet) && ($packet[0] ?? -1) === 8) {
                            // Event packet: [8, eventType, {params}]
                            $this->eventQueue[] = $packet;
                        } else {
                            // Wrap back in outer array for response matching
                            $responses[] = [$packet];
                        }
                    }
                } else {
                    $responses[] = $decoded;
                }
            }
        }
        return $responses;
    }

    /** Pop all queued events */
    public function popEvents(): array
    {
        $events = $this->eventQueue;
        $this->eventQueue = [];
        return $events;
    }

    /** Get the raw socket resource for stream_select */
    public function getSocket(): mixed
    {
        return $this->socket;
    }

    /** Read WebSocket frames, returning the decoded CBOR of the first matching msgId */
    private function waitForResponse(int $expectedId, float $timeout = 5.0): mixed
    {
        $deadline = microtime(true) + $timeout;

        while (microtime(true) < $deadline) {
            $responses = $this->drainFrames();
            foreach ($responses as $decoded) {
                if (is_array($decoded)) {
                    foreach ($decoded as $resp) {
                        if (is_array($resp) && isset($resp[1]) && $resp[1] === $expectedId) {
                            return $resp;
                        }
                    }
                }
            }
            usleep(5000);
        }
        throw new \RuntimeException("Timeout waiting for response to msgId={$expectedId}");
    }

    /**
     * Subscribe to real-time data changes (Opcode 6).
     *
     * Xentara will push event packets (type 8) whenever the subscribed
     * attributes change. Events contain:
     *   [8, eventType, {0: timestamp, 1: Tag37(elementUUID), 2: {attrId: Tag121(value), ...}}]
     *
     * @param  array  $entries  Array of ['uuid' => elementUUID, 'attrs' => [attrId, ...]]
     * @return string           Subscription UUID (needed to unsubscribe later)
     * @throws RuntimeException on protocol errors
     */
    public function subscribe(array $entries): string
    {
        // Build the subscription array: each entry is a CBOR map {0: uuid, 1: [attrIds]}
        $subArray = [];
        foreach ($entries as $e) {
            $subArray[] = new CborMap([
                0 => cborUuid($e['uuid']),
                1 => new CborArray($e['attrs']),
            ]);
        }
        $params = [0 => new CborArray($subArray)];
        $resp = $this->request(6, $params);
        if (!is_array($resp)) throw new \RuntimeException('Subscribe failed: no response');
        if (($resp[0] ?? -1) === 2) {
            $err = $resp[2] ?? [];
            $msg = is_array($err) ? ($err[1] ?? 'unknown') : 'unknown';
            throw new \RuntimeException('Subscribe error: ' . $msg);
        }
        // Response: [1, msgId, Tag37(subscriptionUUID)]
        $tag = $resp[2] ?? null;
        if ($tag instanceof CborTag && $tag->tag === 37) {
            return bytesToUuid($tag->value->data);
        }
        throw new \RuntimeException('Subscribe: unexpected response');
    }

    /** Unsubscribe. Opcode 7. */
    public function unsubscribe(string $subscriptionUuid): void
    {
        $params = [0 => cborUuid($subscriptionUuid)];
        $this->request(7, $params);
    }

    /** Read one frame if available (non-blocking). Returns decoded CBOR or null. */
    public function poll(): mixed
    {
        $responses = $this->drainFrames();
        foreach ($responses as $decoded) {
            if (is_array($decoded) && !empty($decoded) && is_array($decoded[0] ?? null)) {
                return $decoded[0];
            }
            return $decoded;
        }
        return null;
    }
}

// ─── Xentara API wrapper ──────────────────────────────────────────────────────

class XentaraApi
{
    private XentaraWsClient $ws;

    public function __construct(XentaraWsClient $ws)
    {
        $this->ws = $ws;
    }

    /**
     * Mandatory first command after WebSocket connection.
     * Negotiates the protocol version with the server.
     * Opcode 14. Params: {0: minVersion, 1: maxVersion}
     */
    public function clientHello(): array
    {
        // Request protocol version range 0-1
        $resp = $this->ws->request(14, [0 => 0, 1 => 1]);
        return $resp;
    }

    /**
     * Browse the model tree from a given element UUID.
     * Returns an array of child elements with their primary keys, UUIDs, and categories.
     *
     * Opcode 1. Params: {0: Tag37(parentUUID), 1?: depth}
     *
     * @param  string   $uuid   Parent element UUID (NIL_UUID for root)
     * @param  int|null $depth  How many levels deep to browse (null = server default)
     * @return array            Array of [primaryKey, Tag37(uuid), category] tuples
     */
    public function browse(string $uuid = NIL_UUID, ?int $depth = null): array
    {
        $params = [0 => cborUuid($uuid)];
        if ($depth !== null) $params[1] = $depth;
        // params keys 0,1 look sequential — request() wraps in CborMap so it's fine
        $resp = $this->ws->request(1, $params);
        if (!isset($resp[2]) || !is_array($resp[2])) return [];
        return $resp[2];
    }

    /**
     * Look up an element by its primary key string.
     * Opcode 3. Params: {0: primaryKeyString}
     *
     * @param  string      $primaryKey  e.g. "Xentara.devices.myDevice"
     * @return string|null UUID string if found, null otherwise
     */
    public function lookup(string $primaryKey): ?string
    {
        $resp = $this->ws->request(3, [0 => $primaryKey]);
        if (!is_array($resp) || ($resp[0] ?? -1) === 2) return null;
        $tag = $resp[2] ?? null;
        if ($tag instanceof CborTag && $tag->tag === 37) {
            return bytesToUuid($tag->value->data);
        }
        return null;
    }

    /** Read attributes for a given element UUID.
     *  $attrIds: array of integer attribute IDs (e.g. [1, 9, 11])
     *  Must be sent as a CBOR array (key 1 in params map) */
    public function read(string $uuid, array $attrIds): array
    {
        // key 1 = attribute_ids: must be CBOR array, so wrap in CborArray
        $params = [0 => cborUuid($uuid), 1 => new CborArray($attrIds)];
        $resp   = $this->ws->request(4, $params);
        if (!is_array($resp)) return [];
        // Error response: [2, msgId, {0: errorCode, 1: errorMsg}]
        if (($resp[0] ?? -1) === 2) {
            $err = $resp[2] ?? [];
            $msg = is_array($err) ? ($err[1] ?? 'unknown error') : 'unknown error';
            $code = is_array($err) ? ($err[0] ?? '?') : '?';
            throw new \RuntimeException("Server error {$code}: {$msg}");
        }
        return $resp[2] ?? [];
    }

    /** Get server info */
    public function serverInfo(): array
    {
        $resp = $this->ws->request(15, []);
        return $resp[2] ?? [];
    }

    /** Subscribe to attribute changes on an element. Returns subscription UUID. */
    public function subscribe(string $elementUuid, array $attrIds): string
    {
        return $this->ws->subscribe([['uuid' => $elementUuid, 'attrs' => $attrIds]]);
    }

    /** Unsubscribe from a subscription */
    public function unsubscribe(string $subscriptionUuid): void
    {
        $this->ws->unsubscribe($subscriptionUuid);
    }
}

// ─── TUI helpers ─────────────────────────────────────────────────────────────
//
// Terminal rendering utilities using ANSI escape sequences.
// These work in any terminal that supports ECMA-48 / VT100 codes
// (virtually all modern Linux/macOS terminals, Windows Terminal, etc.)
//
// Color constants use SGR (Select Graphic Rendition) codes:
//   ESC[<n>m  where <n> is the SGR parameter number.
//   38;5;N = 256-color foreground,  48;5;N = 256-color background

// ── ANSI escape code constants ──────────────────────────────────────────
const ESC  = "\033";
const RESET = ESC . "[0m";       // reset all attributes
const BOLD  = ESC . "[1m";       // bold/bright
const DIM   = ESC . "[2m";       // dim/faint
const ITALIC= ESC . "[3m";       // italic (not all terminals)
const ULINE = ESC . "[4m";       // underline
const REV   = ESC . "[7m";       // reverse video (swap fg/bg)
const RED   = ESC . "[31m";
const GREEN = ESC . "[32m";
const YELLOW= ESC . "[33m";
const BLUE  = ESC . "[34m";
const MAGENTA = ESC . "[35m";
const CYAN  = ESC . "[36m";
const WHITE = ESC . "[97m";      // bright white
const GRAY  = ESC . "[90m";      // bright black (dark gray)
const BG_DARK  = ESC . "[48;5;235m";  // dark gray background
const BG_SEL   = ESC . "[48;5;24m";   // blue highlight for selected items
const BG_TITLE = ESC . "[48;5;17m";   // dark blue title bar
const BG_STATUS= ESC . "[48;5;236m";  // dark gray status bar
const FG_LIVE  = ESC . "[38;5;48m";   // bright green for live/real-time values
const FG_LABEL = ESC . "[38;5;244m";  // medium gray for field labels
const FG_BOX   = ESC . "[38;5;60m";   // muted blue for box-drawing characters

/** Get terminal dimensions using tput. Fallback: 80x24 */
function termSize(): array
{
    $cols = (int)(shell_exec('tput cols')  ?: 80);
    $rows = (int)(shell_exec('tput lines') ?: 24);
    return [$cols, $rows];
}

function clearScreen(): void { echo ESC . "[2J" . ESC . "[H"; }  // clear screen + cursor home
function moveTo(int $row, int $col): void { echo ESC . "[{$row};{$col}H"; } // move cursor to row,col
function hideCursor(): void { echo ESC . "[?25l"; } // hide blinking cursor during drawing
function showCursor(): void { echo ESC . "[?25h"; } // restore cursor visibility on exit

/**
 * Draw a Unicode box-drawing border at the given position.
 * Uses ┌─┐│└┘ characters with optional title in the top border.
 *
 * @param int    $row        Top-left row (1-based)
 * @param int    $col        Top-left column (1-based)
 * @param int    $w          Total width including borders
 * @param int    $h          Total height including borders
 * @param string $title      Optional title text in the top border
 * @param string $titleColor ANSI color for the title (default: FG_BOX+BOLD)
 */
function drawBox(int $row, int $col, int $w, int $h, string $title = '', string $titleColor = ''): void
{
    $inner = $w - 2;
    $tc = $titleColor ?: (FG_BOX . BOLD);
    if ($title) {
        $titleLen = mb_strlen($title);
        $top = FG_BOX . "┌─ " . $tc . $title . FG_BOX . " " . str_repeat("─", max(0, $inner - $titleLen - 3)) . "┐" . RESET;
    } else {
        $top = FG_BOX . "┌" . str_repeat("─", $inner) . "┐" . RESET;
    }
    $bot = FG_BOX . "└" . str_repeat("─", $inner) . "┘" . RESET;
    moveTo($row, $col);      echo $top;
    for ($r = $row+1; $r < $row+$h-1; $r++) {
        moveTo($r, $col);    echo FG_BOX . "│" . RESET . str_repeat(' ', $inner) . FG_BOX . "│" . RESET;
    }
    moveTo($row+$h-1, $col); echo $bot;
}

/**
 * Draw a single line of text within a box, with optional selection highlight.
 * Handles ANSI escape sequences correctly when calculating visible text width
 * (strips escape codes before measuring, so colored text doesn't overflow).
 */
function drawLine(int $row, int $col, int $w, string $text, bool $selected = false): void
{
    $visLen = mb_strlen(preg_replace('/\033\[[0-9;]*m/', '', $text));
    $pad  = str_repeat(' ', max(0, $w - 2 - $visLen));
    moveTo($row, $col + 1);
    if ($selected) {
        echo BG_SEL . WHITE . BOLD . " " . $text . $pad . RESET;
    } else {
        echo " " . $text . str_repeat(' ', max(0, $w - 3 - $visLen)) . RESET;
    }
}

/**
 * Format a CBOR attribute value for display with color coding.
 * Tag 121 (success) = bright green value, Tag 122 (error) = red ✗ message.
 */
function colorValue(mixed $v): string
{
    if ($v instanceof CborTag) {
        if ($v->tag === 121) return FG_LIVE . BOLD . formatValue($v->value) . RESET;
        if ($v->tag === 122) {
            $err = $v->value;
            $msg = is_array($err) ? ($err[1] ?? 'error') : 'error';
            return RED . "✗ " . $msg . RESET;
        }
        return YELLOW . "tag({$v->tag})" . RESET;
    }
    return FG_LIVE . formatValue($v) . RESET;
}

/** Format a raw PHP value as a human-readable string */
function formatValue(mixed $v): string
{
    if ($v === null)              return 'null';
    if ($v === true)              return 'true';
    if ($v === false)             return 'false';
    if ($v instanceof CborBytes)  return '0x' . strtoupper(bin2hex($v->data));
    if ($v instanceof CborTag && $v->tag === 37) return bytesToUuid($v->value->data);
    if (is_array($v))             return '{array[' . count($v) . ']}';
    if (is_float($v))             return number_format($v, 6);
    return (string)$v;
}

/**
 * Map Xentara attribute integer IDs to human-readable names.
 * These IDs are defined in the Xentara WebSocket API specification.
 * See: https://docs.xentara.io/xentara-websocket-api/
 */
function attrName(int|string $id): string
{
    $names = [
        0  => 'primary_key',   // unique dotted path, e.g. "Xentara.devices.myDevice"
        1  => 'name',          // short display name
        2  => 'uuid',          // element UUID
        3  => 'type',          // element type identifier
        4  => 'category',      // category enum (0=Root, 1=Group, 2=DataPoint, etc.)
        5  => 'source_time',   // timestamp from the data source
        6  => 'update_time',   // last update timestamp
        7  => 'change_time',   // last value-change timestamp
        8  => 'write_time',    // last write timestamp
        9  => 'quality',       // data quality: 0=Good, 1=Acceptable, 2=Unreliable, 3=Bad
        10 => 'device_state',  // device connection state
        11 => 'value',         // the actual data value
        12 => 'error',         // error information
        13 => 'write_error',   // write operation error
    ];
    return $names[$id] ?? "attr({$id})";
}

/**
 * Return a colored Unicode icon for each Xentara element category.
 * Categories are integer IDs defined by the Xentara model tree.
 * Each category type gets a distinctive icon and color for quick visual identification.
 */
function categoryIcon(int $cat): string
{
    return match($cat) {
        0  => CYAN  . '◉' . RESET,
        1  => BLUE  . '▸' . RESET,
        2  => GREEN . '◆' . RESET,
        3  => YELLOW. '⏱' . RESET,
        4  => MAGENTA.'⟶' . RESET,
        5  => MAGENTA.'⇉' . RESET,
        6  => YELLOW. '⚙' . RESET,
        7  => YELLOW. '⚙' . RESET,
        8  => BLUE  . '◫' . RESET,
        9  => GREEN . '◫' . RESET,
        10 => CYAN  . '⇄' . RESET,
        11 => MAGENTA.'μ' . RESET,
        12 => RED   . '⬡' . RESET,
        13 => CYAN  . '⛁' . RESET,
        14 => YELLOW. '⇌' . RESET,
        15 => GRAY  . '★' . RESET,
        default => GRAY . '·' . RESET,
    };
}

/** Return the ANSI color code associated with an element category */
function categoryColor(int $cat): string
{
    return match($cat) {
        0  => CYAN,
        1  => BLUE,
        2  => GREEN,
        3  => YELLOW,
        4, 5 => MAGENTA,
        6, 7 => YELLOW,
        8  => BLUE,
        9  => GREEN,
        10 => CYAN,
        11 => MAGENTA,
        12 => RED,
        13 => CYAN,
        14 => YELLOW,
        15 => GRAY,
        default => WHITE,
    };
}

/**
 * Render a quality value as a colored badge string.
 * Quality is a Xentara attribute (ID 9) with values:
 *   0 = Good (green)        1 = Acceptable (yellow)
 *   2 = Unreliable (bold yellow)  3 = Bad (red)
 */
function qualityBadge(int $q): string
{
    return match($q) {
        0 => GREEN . '● Good' . RESET,
        1 => YELLOW . '● Acceptable' . RESET,
        2 => YELLOW . BOLD . '● Unreliable' . RESET,
        3 => RED . '● Bad' . RESET,
        default => GRAY . "● Unknown({$q})" . RESET,
    };
}

/**
 * Map category integer IDs to human-readable display names.
 * These match the Xentara element category enumeration.
 */
function elementCategory(int $cat): string
{
    return match($cat) {
        0  => 'Root',
        1  => 'Group',
        2  => 'Data Point',
        3  => 'Timer',
        4  => 'Exec Track',
        5  => 'Exec Pipeline',
        6  => 'Device',
        7  => 'Sub Device',
        8  => 'Device Group',
        9  => 'DP Group',
        10 => 'Transaction',
        11 => 'Microservice',
        12 => 'AI',
        13 => 'Data Storage',
        14 => 'Ext Interface',
        15 => 'Special',
        default => "Cat({$cat})"
    };
}

// ─── Main TUI App ─────────────────────────────────────────────────────────────
//
// The App class is the main TUI (Terminal User Interface) controller.
// It implements a two-pane layout:
//
//   Left pane:  Model tree browser with scrollable list of elements
//   Right pane: Detail view showing attributes of the selected element
//
// Architecture:
//   - Navigation is stack-based: entering a child pushes onto navStack,
//     backspace pops. Each stack entry stores the browsed children.
//   - The main loop uses stream_select() to multiplex between keyboard
//     input (STDIN) and WebSocket data (server events/pings).
//   - When an element is selected, static attributes are read once (opcode 4)
//     and live attributes (value, quality) are subscribed (opcode 6).
//   - Subscription events trigger partial redraws (drawDetailValues) to
//     avoid full-screen flicker on every value change.
//   - Full redraws use cursor-home (ESC[H) instead of clear-screen (ESC[2J)
//     to eliminate visible flashing.

class App
{
    private XentaraApi $api;
    private XentaraWsClient $ws;

    /**
     * Navigation stack: each entry is ['uuid'=>string, 'name'=>string, 'items'=>array]
     * Items are arrays of ['key'=>primaryKey, 'uuid'=>uuid, 'cat'=>categoryInt]
     */
    private array $navStack = [];
    /** Currently highlighted item index within the current level */
    private int   $cursor   = 0;
    /** Scroll offset (first visible item index) for the left pane list */
    private int   $scroll   = 0;

    /** @var array Attribute values for the currently selected element (attrId => value) */
    private array  $detail    = [];
    /** UUID of the element whose details are currently displayed */
    private string $detailUuid = '';
    /** Active subscription UUID for live updates (empty string = no subscription) */
    private string $subscriptionId = '';

    /** Status bar message shown at the bottom of the screen */
    private string $status = 'Connected. Use ↑↓ to navigate, Enter to drill down, Backspace to go up, r to read, q to quit.';

    /** Current terminal dimensions (updated on each draw cycle) */
    private int $cols = 80;
    private int $rows = 24;

    public function __construct(XentaraApi $api, XentaraWsClient $ws)
    {
        $this->api = $api;
        $this->ws  = $ws;
    }

    /**
     * Main application loop.
     *
     * 1. Sends Client Hello to negotiate protocol version
     * 2. Browses the root of the model tree
     * 3. Enters the event loop: reads keyboard input, processes WebSocket
     *    events, and redraws the screen as needed
     * 4. On exit (q key), cancels subscriptions and restores terminal state
     */
    public function run(): void
    {
        [$this->cols, $this->rows] = termSize();

        // 1. Client Hello
        $this->setStatus('Sending Client Hello...');
        try {
            $this->api->clientHello();
        } catch (\Throwable $e) {
            $this->setStatus('Client Hello failed: ' . $e->getMessage());
        }

        // 2. Browse root
        $this->setStatus('Browsing root model tree...');
        $items = $this->browseLevel(NIL_UUID);
        $this->navStack[] = ['uuid' => NIL_UUID, 'name' => 'Xentara Root', 'items' => $items];
        $this->setStatus('Ready. Found ' . count($items) . ' top-level elements.');

        // 3. Main loop
        hideCursor();
        system('stty -icanon -echo');

        try {
            $this->draw();
            while (true) {
                $key = $this->readKey();
                // Process any incoming subscription events
                if ($this->processEvents()) {
                    $this->drawDetailValues();
                }
                if ($key === null) continue;
                if ($key === 'q') break;
                $this->handleKey($key);
                $this->draw();
            }
        } finally {
            $this->cancelSubscription();
            system('stty icanon echo');
            showCursor();
            clearScreen();
            echo "Goodbye!\n";
        }
    }

    /**
     * Browse children of an element and return a normalized array.
     * Each child: ['key' => primaryKey, 'uuid' => uuidString, 'cat' => categoryInt]
     */
    private function browseLevel(string $uuid): array
    {
        try {
            $raw = $this->api->browse($uuid, 1);
            $items = [];
            foreach ($raw as $entry) {
                if (!is_array($entry)) continue;
                $pk   = $entry[0] ?? '?';
                $uTag = $entry[1] ?? null;
                $cat  = $entry[2] ?? 0;
                $uid  = ($uTag instanceof CborTag && $uTag->tag === 37)
                        ? bytesToUuid($uTag->value->data) : NIL_UUID;
                $items[] = ['key' => $pk, 'uuid' => $uid, 'cat' => (int)$cat];
            }
            return $items;
        } catch (\Throwable $e) {
            $this->setStatus('Browse error: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Read static attributes and subscribe to live attributes for an element.
     *
     * Static read: attributes 1 (name), 3 (type), 4 (category) — read once
     * Subscription: attributes 9 (quality), 11 (value) — pushed on change
     *
     * Any previous subscription is cancelled before creating a new one.
     */
    private function readDetail(string $uuid): void
    {
        // Cancel previous subscription
        $this->cancelSubscription();
        $this->detailUuid = $uuid;

        try {
            // Initial read for static attributes: 1=name, 3=type, 4=category
            $raw = $this->api->read($uuid, [1, 3, 4]);
            $this->detail = $raw;
        } catch (\Throwable $e) {
            $this->detail = [];
        }

        // Subscribe to live attributes: 9=quality, 11=value
        try {
            $subId = $this->api->subscribe($uuid, [9, 11]);
            $this->subscriptionId = $subId;
            $this->setStatus("Subscribed to live updates: {$subId}");
        } catch (\Throwable $e) {
            $this->setStatus('Subscribe error: ' . $e->getMessage());
        }
    }

    /** Cancel the active subscription (if any). Safe to call multiple times. */
    private function cancelSubscription(): void
    {
        if ($this->subscriptionId !== '') {
            try {
                $this->api->unsubscribe($this->subscriptionId);
            } catch (\Throwable $e) {
                // ignore unsubscribe errors
            }
            $this->subscriptionId = '';
        }
    }

    /**
     * Process event packets from the WebSocket event queue.
     * Events arrive via subscriptions and contain updated attribute values.
     *
     * Event format: [8, eventType, {0: timestamp, 1: Tag37(elementUUID), 2: {attrId: taggedValue}}]
     *
     * Tag 121 wraps successful values, Tag 122 wraps errors.
     * Only events matching our subscribed element UUID are processed.
     *
     * @return bool True if the detail pane was updated (caller should redraw values)
     */
    private function processEvents(): bool
    {
        $events = $this->ws->popEvents();
        if (empty($events)) return false;

        $updated = false;
        foreach ($events as $event) {
            // Event: [8, eventType, {0: timestamp, 1: elementUUID, 2: {attrId: tag121(val), ...}}]
            if (!is_array($event) || ($event[0] ?? -1) !== 8) continue;
            $params = $event[2] ?? [];
            if (!is_array($params)) continue;

            // Check element UUID matches our subscribed element
            $elemTag = $params[1] ?? null;
            if ($elemTag instanceof CborTag && $elemTag->tag === 37) {
                $eventUuid = bytesToUuid($elemTag->value->data);
                if ($eventUuid !== $this->detailUuid) continue;
            }

            // Merge attribute values into detail
            $attrValues = $params[2] ?? [];
            if (is_array($attrValues)) {
                foreach ($attrValues as $attrId => $val) {
                    $this->detail[$attrId] = $val;
                }
                $updated = true;
            }
        }
        return $updated;
    }

    private function setStatus(string $msg): void
    {
        $this->status = $msg;
    }

    private function currentLevel(): array
    {
        return end($this->navStack)['items'] ?? [];
    }

    /**
     * Handle a keyboard input and update application state.
     *
     * Keys:
     *   UP/DOWN    → move cursor, auto-scroll, read selected element
     *   ENTER      → drill into children (push navStack) or read if leaf
     *   BACKSPACE  → go up one level (pop navStack)
     *   r          → read/refresh attributes for current selection
     *   RESIZE     → recalculate terminal dimensions
     */
    private function handleKey(string $key): void
    {
        $items    = $this->currentLevel();
        $count    = count($items);
        $listH    = $this->rows - 6; // visible list rows

        switch ($key) {
            case 'UP':
                if ($this->cursor > 0) $this->cursor--;
                if ($this->cursor < $this->scroll) $this->scroll = $this->cursor;
                $this->readDetailSelected();
                break;
            case 'DOWN':
                if ($this->cursor < $count - 1) $this->cursor++;
                if ($this->cursor >= $this->scroll + $listH) $this->scroll = $this->cursor - $listH + 1;
                $this->readDetailSelected();
                break;
            case 'ENTER':
                if (isset($items[$this->cursor])) {
                    $sel  = $items[$this->cursor];
                    $sub  = $this->browseLevel($sel['uuid']);
                    if (count($sub) > 0) {
                        $this->cancelSubscription();
                        $this->navStack[] = ['uuid' => $sel['uuid'], 'name' => $sel['key'], 'items' => $sub];
                        $this->cursor = 0;
                        $this->scroll = 0;
                        $this->detail = [];
                        $this->detailUuid = '';
                        $this->setStatus("Entered: {$sel['key']}");
                    } else {
                        $this->readDetailSelected();
                        $this->setStatus("No children for: {$sel['key']}");
                    }
                }
                break;
            case 'BACKSPACE':
            case 'BS':
                if (count($this->navStack) > 1) {
                    $this->cancelSubscription();
                    array_pop($this->navStack);
                    $this->cursor = 0;
                    $this->scroll = 0;
                    $this->detail = [];
                    $this->detailUuid = '';
                    $name = end($this->navStack)['name'];
                    $this->setStatus("Back to: {$name}");
                }
                break;
            case 'r':
                $this->readDetailSelected();
                break;
            case 'RESIZE':
                [$this->cols, $this->rows] = termSize();
                break;
        }
    }

    private function readDetailSelected(): void
    {
        $items = $this->currentLevel();
        if (isset($items[$this->cursor])) {
            $this->readDetail($items[$this->cursor]['uuid']);
        }
    }

    private function draw(): void
    {
        [$this->cols, $this->rows] = termSize();
        echo ESC . "[H";

        $leftW  = min((int)($this->cols * 0.38), 50);
        $rightW = $this->cols - $leftW;
        $listH  = $this->rows - 4;

        // ── Title bar ──────────────────────────────────────────────────────
        moveTo(1, 1);
        $pathParts = array_column($this->navStack, 'name');
        $path = implode(GRAY . ' › ' . WHITE, $pathParts);
        $liveTag = $this->subscriptionId !== '' ? (FG_LIVE . ' ● LIVE') : (GRAY . ' ○ idle');
        echo BG_TITLE . BOLD . CYAN . "  ⚡ Xentara TUI  " . RESET . BG_TITLE . WHITE . $path . '  ' . $liveTag . RESET . BG_TITLE;
        $visTitle = 18 + mb_strlen(implode(' › ', $pathParts)) + 2 + ($this->subscriptionId !== '' ? 6 : 6);
        echo str_repeat(' ', max(0, $this->cols - $visTitle)) . RESET;

        // ── Left pane: model tree ──────────────────────────────────────────
        drawBox(2, 1, $leftW, $listH + 2, 'Model Tree', CYAN . BOLD);
        $items   = $this->currentLevel();
        $visible = array_slice($items, $this->scroll, $listH);
        foreach ($visible as $i => $item) {
            $abs      = $i + $this->scroll;
            $selected = ($abs === $this->cursor);
            $icon     = categoryIcon($item['cat']);
            $catColor = categoryColor($item['cat']);
            $name = $item['key'];
            if (str_contains($name, '.')) {
                $name = substr($name, strrpos($name, '.') + 1);
            }
            if ($selected) {
                $label = $icon . ' ' . WHITE . $name;
            } else {
                $label = $icon . ' ' . $catColor . $name . RESET;
            }
            drawLine(3 + $i, 1, $leftW, $label, $selected);
        }
        // Clear remaining list rows
        for ($i = count($visible); $i < $listH; $i++) {
            moveTo(3 + $i, 2);
            echo str_repeat(' ', $leftW - 2);
        }
        // Scroll bar
        $totalItems = count($items);
        if ($totalItems > $listH && $listH > 0) {
            $barH = max(1, (int)($listH * $listH / $totalItems));
            $barTop = (int)($this->scroll * ($listH - $barH) / max(1, $totalItems - $listH));
            for ($i = 0; $i < $listH; $i++) {
                moveTo(3 + $i, $leftW - 1);
                echo ($i >= $barTop && $i < $barTop + $barH) ? (FG_BOX . '▐' . RESET) : ' ';
            }
        }

        // ── Right pane: element detail ─────────────────────────────────────
        drawBox(2, $leftW + 1, $rightW, $listH + 2, 'Element Detail', YELLOW . BOLD);
        $sel  = $items[$this->cursor] ?? null;
        $rCol = $leftW + 3;
        $rW   = $rightW - 4;
        $row  = 3;

        if ($sel) {
            $catColor = categoryColor($sel['cat']);
            // Element name with icon
            moveTo($row, $rCol);
            echo categoryIcon($sel['cat']) . ' ' . BOLD . WHITE . $sel['key'] . RESET;
            echo str_repeat(' ', max(0, $rW - mb_strlen($sel['key']) - 2));
            $row++;

            // Separator
            moveTo($row, $rCol);
            echo FG_BOX . str_repeat('─', $rW) . RESET;
            $row++;

            // UUID
            moveTo($row, $rCol);
            echo FG_LABEL . 'UUID      ' . RESET . GRAY . $sel['uuid'] . RESET;
            echo str_repeat(' ', max(0, $rW - 10 - 36));
            $row++;

            // Category
            moveTo($row, $rCol);
            $catName = elementCategory($sel['cat']);
            echo FG_LABEL . 'Category  ' . RESET . $catColor . $catName . RESET;
            echo str_repeat(' ', max(0, $rW - 10 - mb_strlen($catName)));
            $row++;
            $row++; // blank line

            if (!empty($this->detail)) {
                // Section header
                moveTo($row, $rCol);
                $liveIcon = $this->subscriptionId !== '' ? (FG_LIVE . ' ●' . RESET) : '';
                echo BOLD . YELLOW . '▸ Attributes' . $liveIcon . RESET;
                echo str_repeat(' ', max(0, $rW - 14));
                $row++;

                moveTo($row, $rCol);
                echo FG_BOX . str_repeat('·', min(30, $rW)) . RESET;
                echo str_repeat(' ', max(0, $rW - min(30, $rW)));
                $row++;

                foreach ($this->detail as $attrId => $val) {
                    if ($row >= $this->rows - 2) break;
                    if ($val instanceof CborTag && $val->tag === 122) continue;
                    $name = attrName((int)$attrId);
                    moveTo($row, $rCol);
                    echo str_repeat(' ', $rW);
                    moveTo($row, $rCol);
                    if ($attrId === 9) {
                        $rawQ = ($val instanceof CborTag && $val->tag === 121) ? $val->value : $val;
                        echo FG_LABEL . sprintf('%-12s', $name) . RESET . '  ' . qualityBadge((int)$rawQ);
                    } else {
                        echo FG_LABEL . sprintf('%-12s', $name) . RESET . '  ' . colorValue($val);
                    }
                    $row++;
                }
            } else {
                moveTo($row, $rCol);
                echo GRAY . ITALIC . 'Select an element and press ' . WHITE . 'r' . GRAY . ' to read attributes' . RESET;
                echo str_repeat(' ', max(0, $rW - 48));
            }
            // Clear remaining rows in detail pane
            for (; $row < 2 + $listH + 1; $row++) {
                moveTo($row, $rCol);
                echo str_repeat(' ', $rW);
            }
        } else {
            moveTo($row, $rCol);
            echo GRAY . '(no item selected)' . RESET;
            echo str_repeat(' ', max(0, $rW - 18));
        }

        // ── Status bar ─────────────────────────────────────────────────────
        moveTo($this->rows - 1, 1);
        $keys = BOLD . WHITE . '↑↓' . RESET . BG_STATUS . GRAY . ' nav  '
              . BOLD . WHITE . '↵' . RESET . BG_STATUS . GRAY . ' enter  '
              . BOLD . WHITE . '⌫' . RESET . BG_STATUS . GRAY . ' back  '
              . BOLD . WHITE . 'r' . RESET . BG_STATUS . GRAY . ' read  '
              . BOLD . WHITE . 'q' . RESET . BG_STATUS . GRAY . ' quit';
        $status = mb_substr($this->status, 0, (int)($this->cols / 2));
        echo BG_STATUS . FG_LIVE . ' ' . $status . RESET . BG_STATUS;
        $visStatus = mb_strlen($status) + 1;
        echo str_repeat(' ', max(0, $this->cols - $visStatus - 42));
        echo $keys . ' ' . RESET;
    }

    /** Redraw only the attribute values in the right pane (no full redraw) */
    private function drawDetailValues(): void
    {
        $leftW  = min((int)($this->cols * 0.38), 50);
        $rightW = $this->cols - $leftW;
        $items  = $this->currentLevel();
        $sel    = $items[$this->cursor] ?? null;
        if (!$sel || empty($this->detail)) return;

        $rCol = $leftW + 3;
        $rW   = $rightW - 4;
        // Values start after: name(3) + sep(4) + uuid(5) + cat(6) + blank(7) + header(8) + dots(9) → row 10
        $row = 10;
        foreach ($this->detail as $attrId => $val) {
            if ($row >= $this->rows - 2) break;
            if ($val instanceof CborTag && $val->tag === 122) continue;
            $name = attrName((int)$attrId);
            moveTo($row, $rCol);
            echo str_repeat(' ', $rW);
            moveTo($row, $rCol);
            if ($attrId === 9) {
                $rawQ = ($val instanceof CborTag && $val->tag === 121) ? $val->value : $val;
                echo FG_LABEL . sprintf('%-12s', $name) . RESET . '  ' . qualityBadge((int)$rawQ);
            } else {
                echo FG_LABEL . sprintf('%-12s', $name) . RESET . '  ' . colorValue($val);
            }
            $row++;
        }
    }

    /**
     * Read a single keypress from STDIN using non-blocking I/O.
     *
     * Uses stream_select() to multiplex between STDIN and the WebSocket
     * socket with a 200ms timeout. This ensures:
     *   - Server pings are replied to even when the user isn't pressing keys
     *   - Subscription events are processed promptly
     *   - The UI remains responsive
     *
     * Arrow keys arrive as 3-byte escape sequences: ESC [ A/B/C/D
     *
     * @return string|null Key name ('UP','DOWN','ENTER','BACKSPACE','q', etc.) or null if no input
     */
    private function readKey(): ?string
    {
        // Multiplex STDIN + WebSocket socket so we don't block on keyboard
        // input while the server is trying to ping us or push events
        $reads = [STDIN];
        $wsSocket = $this->ws->getSocket();
        if ($wsSocket) $reads[] = $wsSocket;
        $w = null;
        $e = null;
        $ready = @stream_select($reads, $w, $e, 0, 200000); // 200ms timeout

        // Always drain WebSocket frames (handles pings)
        try {
            $this->ws->drainFrames();
        } catch (\Throwable $ex) {
            $this->setStatus('Connection error: ' . $ex->getMessage());
        }

        if ($ready === false || $ready === 0) return null;
        if (!in_array(STDIN, $reads, true)) return null;

        stream_set_blocking(STDIN, false);
        $c = fread(STDIN, 1);
        if ($c === false || $c === '') { stream_set_blocking(STDIN, true); return null; }
        if ($c === "\033") {
            $seq = fread(STDIN, 6);
            stream_set_blocking(STDIN, true);
            if ($seq === '[A') return 'UP';
            if ($seq === '[B') return 'DOWN';
            if ($seq === '[C') return 'RIGHT';
            if ($seq === '[D') return 'LEFT';
            return 'ESC';
        }
        stream_set_blocking(STDIN, true);
        if ($c === "\n" || $c === "\r") return 'ENTER';
        if ($c === "\x7f" || $c === "\x08") return 'BACKSPACE';
        return $c;
    }
}

// ─── Entrypoint ───────────────────────────────────────────────────────────────
// Everything above is class/function definitions. Execution starts here.
// 1. Connect to Xentara via WebSocket (SSL)
// 2. Create API wrapper and TUI App
// 3. Run the interactive TUI loop
// 4. Disconnect cleanly on exit

echo CYAN . "Connecting to wss://{$host}:{$port} as {$user}..." . RESET . "\n";

$ws  = new XentaraWsClient();
$ws->setDebug($debug);

// Show step-by-step connection progress
$ws->onStatus(function (string $msg) {
    echo GRAY . "  ▸ " . $msg . RESET . "\n";
});

try {
    $ws->connect($host, $port, $user, $pass);
} catch (\Throwable $e) {
    echo RED . "\n✗ Connection failed:\n  " . $e->getMessage() . RESET . "\n";
    exit(1);
}

echo GREEN . "Connected!" . RESET . "\n";

$api = new XentaraApi($ws);
$app = new App($api, $ws);

try {
    $app->run();
} finally {
    $ws->disconnect();
}
