<?php
/**
 * ╔══════════════════════════════════════════════════════════════╗
 * ║  KomposMeter IoT — API Endpoint                             ║
 * ║  File   : api/receive.php                                   ║
 * ║  Method : POST (JSON body)                                  ║
 * ║  Server : https://zipultekno.shop/api/receive.php           ║
 * ╚══════════════════════════════════════════════════════════════╝
 *
 * Cara pasang di server:
 *  1. Upload file ini ke folder  /public_html/api/receive.php
 *  2. Import db_komposmeter.sql ke phpMyAdmin / MySQL
 *  3. Isi kredensial DB di bagian KONFIGURASI DATABASE di bawah
 *  4. Buat file /public_html/api/.htaccess (ada di bawah)
 */

// ─── KONFIGURASI DATABASE ────────────────────────────────────────
define('DB_HOST', 'localhost');
define('DB_NAME', 'db_komposmeter');   // sesuaikan nama database Anda
define('DB_USER', 'root');             // sesuaikan username MySQL
define('DB_PASS', '');                 // sesuaikan password MySQL
define('DB_CHARSET', 'utf8mb4');

// ─── KONFIGURASI TELEGRAM NOTIFIKASI ──────────────────────────────
define('TELEGRAM_BOT_TOKEN', 'MASUKKAN_TOKEN_BOT_TELEGRAM_DISINI');
define('TELEGRAM_CHAT_ID', 'MASUKKAN_CHAT_ID_TELEGRAM_DISINI');

// ─── KONFIGURASI EMAIL NOTIFIKASI ───────────────────────────────
define('NOTIFICATION_EMAIL', 'use your email here');

// ─── KEAMANAN: Kunci API (opsional) ─────────────────────────────
// Jika ingin mengamankan endpoint, uncomment baris berikut dan
// tambahkan header "X-API-Key: kompos_secret_123" di kode Arduino
// define('API_KEY', 'kompos_secret_123');

// ─────────────────────────────────────────────────────────────────
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Device-ID, X-API-Key');

// Handle preflight CORS
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// ── Validasi metode request ──────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResp(405, 'error', 'Method not allowed. Gunakan POST.');
}

// ── Validasi API Key (jika aktif) ────────────────────────────────
/*
if (!defined('API_KEY')) {
    // API Key tidak dikonfigurasi, skip
} else {
    $apiKey = $_SERVER['HTTP_X_API_KEY'] ?? '';
    if ($apiKey !== API_KEY) {
        jsonResp(401, 'error', 'Unauthorized: API Key tidak valid.');
    }
}
*/

// ── Baca dan parse JSON body ─────────────────────────────────────
$rawBody = file_get_contents('php://input');
if (empty($rawBody)) {
    jsonResp(400, 'error', 'Body kosong. Kirim data JSON.');
}

$data = json_decode($rawBody, true);
if (json_last_error() !== JSON_ERROR_NONE) {
    jsonResp(400, 'error', 'JSON tidak valid: ' . json_last_error_msg());
}

// ── Validasi field wajib ─────────────────────────────────────────
$requiredFields = ['device_id', 'suhu', 'kelembapan', 'amonia', 'status_kematangan'];
foreach ($requiredFields as $field) {
    if (!array_key_exists($field, $data)) {
        jsonResp(422, 'error', "Field '{$field}' tidak ditemukan dalam payload.");
    }
}

// ── Sanitasi dan konversi nilai ──────────────────────────────────
$device_id         = trim(substr($data['device_id'], 0, 50));
$suhu              = (float) $data['suhu'];
$kelembapan        = (float) $data['kelembapan'];
$amonia            = (float) $data['amonia'];
$voltage_mq        = isset($data['voltage_mq'])   ? (float)  $data['voltage_mq']   : null;
$raw_soil          = isset($data['raw_soil'])      ? (int)    $data['raw_soil']      : null;
$raw_amonia        = isset($data['raw_amonia'])    ? (int)    $data['raw_amonia']    : null;
$status_kematangan = trim(substr($data['status_kematangan'] ?? 'Proses', 0, 20));
$fase_fermentasi   = trim(substr($data['fase_fermentasi']   ?? '',       0, 30));
$estimasi_hari     = isset($data['estimasi_hari']) ? (int)    $data['estimasi_hari'] : 0;
$rssi              = isset($data['rssi'])          ? (int)    $data['rssi']          : null;
$ip_address        = trim(substr($data['ip']       ?? '',     0, 45));

// Validasi range nilai sensor (sanity check)
if ($suhu < -55 || $suhu > 150) {
    jsonResp(422, 'error', "Nilai suhu tidak valid: {$suhu}°C");
}
if ($kelembapan < 0 || $kelembapan > 100) {
    jsonResp(422, 'error', "Nilai kelembapan tidak valid: {$kelembapan}%");
}
if ($amonia < 0 || $amonia > 1000) {
    jsonResp(422, 'error', "Nilai amonia tidak valid: {$amonia} ppm");
}

// ── Koneksi Database ─────────────────────────────────────────────
try {
    $dsn = sprintf('mysql:host=%s;dbname=%s;charset=%s', DB_HOST, DB_NAME, DB_CHARSET);
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
} catch (PDOException $e) {
    // Jangan expose detail error ke klien
    error_log('[KomposMeter] DB Connect Error: ' . $e->getMessage());
    jsonResp(500, 'error', 'Database connection failed.');
}

// ── INSERT data sensor ───────────────────────────────────────────
try {
    $sqlInsert = "
        INSERT INTO sensor_data (
            device_id, suhu, kelembapan, amonia,
            voltage_mq, raw_soil, raw_amonia,
            status_kematangan, fase_fermentasi,
            estimasi_sisa_hari, rssi, ip_address,
            waktu_baca
        ) VALUES (
            :device_id, :suhu, :kelembapan, :amonia,
            :voltage_mq, :raw_soil, :raw_amonia,
            :status, :fase,
            :estimasi, :rssi, :ip,
            NOW()
        )
    ";

    $stmtInsert = $pdo->prepare($sqlInsert);
    $stmtInsert->execute([
        ':device_id'  => $device_id,
        ':suhu'       => $suhu,
        ':kelembapan' => $kelembapan,
        ':amonia'     => $amonia,
        ':voltage_mq' => $voltage_mq,
        ':raw_soil'   => $raw_soil,
        ':raw_amonia' => $raw_amonia,
        ':status'     => $status_kematangan,
        ':fase'       => $fase_fermentasi,
        ':estimasi'   => $estimasi_hari,
        ':rssi'       => $rssi,
        ':ip'         => $ip_address,
    ]);
    $insertedId = $pdo->lastInsertId();

} catch (PDOException $e) {
    error_log('[KomposMeter] INSERT Error: ' . $e->getMessage());
    jsonResp(500, 'error', 'Gagal menyimpan data sensor.');
}

// ── UPSERT tabel devices ─────────────────────────────────────────
// Simpan / update informasi perangkat (last seen, IP, status online)
try {
    $sqlUpsert = "
        INSERT INTO devices (device_id, ip_address, status, last_seen, created_at)
        VALUES (:id, :ip, 'online', NOW(), NOW())
        ON DUPLICATE KEY UPDATE
            ip_address = VALUES(ip_address),
            status     = 'online',
            last_seen  = NOW()
    ";
    $pdo->prepare($sqlUpsert)->execute([
        ':id' => $device_id,
        ':ip' => $ip_address,
    ]);
} catch (PDOException $e) {
    // Non-fatal — log saja
    error_log('[KomposMeter] Upsert devices Error: ' . $e->getMessage());
}

// ── Kirim Notifikasi Telegram (jika token & chat_id sudah diisi) ──
if (defined('TELEGRAM_BOT_TOKEN') && defined('TELEGRAM_CHAT_ID') && 
    TELEGRAM_BOT_TOKEN !== 'MASUKKAN_TOKEN_BOT_TELEGRAM_DISINI' && 
    TELEGRAM_CHAT_ID !== 'MASUKKAN_CHAT_ID_TELEGRAM_DISINI') {
    
    $msg = "📢 *LAPORAN KOMPOSMETER*\n"
         . "---------------------------------------\n"
         . "📍 *Device:* `{$device_id}`\n"
         . "🌡️ *Suhu:* *{$suhu} °C* ({$fase_fermentasi})\n"
         . "💧 *Kelembapan:* *{$kelembapan} %*\n"
         . "💨 *Amonia:* *{$amonia} ppm*\n"
         . "📋 *Status:* *{$status_kematangan}*\n"
         . "⏳ *Sisa Hari:* *{$estimasi_hari} hari*\n"
         . "---------------------------------------\n"
         . "🕒 _Waktu: " . date('Y-m-d H:i:s') . "_";
         
    sendTelegramMessage(TELEGRAM_BOT_TOKEN, TELEGRAM_CHAT_ID, $msg);
}

// ── Kirim Notifikasi Email (jika email sudah diisi) ──
if (defined('NOTIFICATION_EMAIL') && filter_var(NOTIFICATION_EMAIL, FILTER_VALIDATE_EMAIL)) {
    $subject = "📢 LAPORAN KOMPOSMETER: [{$status_kematangan}]";
    $headers = "From: KomposMeter IoT <noreply@zipultekno.shop>\r\n"
             . "Content-Type: text/plain; charset=utf-8\r\n"
             . "X-Mailer: PHP/" . phpversion();
             
    $emailBody = "📢 LAPORAN DATA KOMPOSMETER\n"
               . "---------------------------------------\n"
               . "📍 Device ID: {$device_id}\n"
               . "🌡️ Suhu: {$suhu} °C ({$fase_fermentasi})\n"
               . "💧 Kelembapan: {$kelembapan} %\n"
               . "💨 Amonia: {$amonia} ppm\n"
               . "📋 Status: {$status_kematangan}\n"
               . "⏳ Sisa Hari: {$estimasi_hari} hari\n"
               . "---------------------------------------\n"
               . "🕒 Waktu: " . date('Y-m-d H:i:s') . "\n";
               
    @mail(NOTIFICATION_EMAIL, $subject, $emailBody, $headers);
}

// ── Respons sukses ───────────────────────────────────────────────
jsonResp(200, 'ok', 'Data tersimpan.', [
    'id'               => (int) $insertedId,
    'device_id'        => $device_id,
    'suhu'             => $suhu,
    'kelembapan'       => $kelembapan,
    'amonia'           => $amonia,
    'status_kematangan'=> $status_kematangan,
    'fase_fermentasi'  => $fase_fermentasi,
    'estimasi_hari'    => $estimasi_hari,
    'server_time'      => date('Y-m-d H:i:s'),
]);

// ─────────────────────────────────────────────────────────────────
//  HELPER FUNGSI
// ─────────────────────────────────────────────────────────────────
function jsonResp(int $httpCode, string $status, string $msg, array $extra = []): void {
    http_response_code($httpCode);
    echo json_encode(array_merge(
        ['status' => $status, 'msg' => $msg],
        $extra
    ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/**
 * Fungsi untuk mengirim notifikasi pesan ke Telegram Bot
 */
function sendTelegramMessage(string $token, string $chatId, string $message): bool {
    $url = "https://api.telegram.org/bot" . $token . "/sendMessage";
    $data = [
        'chat_id' => $chatId,
        'text' => $message,
        'parse_mode' => 'Markdown'
    ];
    
    // Gunakan cURL jika tersedia, jika tidak gunakan file_get_contents
    if (function_exists('curl_version')) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        $result = curl_exec($ch);
        curl_close($ch);
        return $result !== false;
    } else {
        $options = [
            'http' => [
                'header'  => "Content-type: application/x-www-form-urlencoded\r\n",
                'method'  => 'POST',
                'content' => http_build_query($data),
                'timeout' => 5
            ],
            'ssl' => [
                'verify_peer' => false,
                'verify_peer_name' => false
            ]
        ];
        $context  = stream_context_create($options);
        $result = @file_get_contents($url, false, $context);
        return $result !== false;
    }
}
