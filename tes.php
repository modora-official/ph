<?php
date_default_timezone_set('Asia/Jakarta');
set_time_limit(600); 

$folder_upload = __DIR__ . '/';
if (!file_exists($folder_upload)) {
    mkdir($folder_upload, 0755, true);
}

$protokol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http");
$base_url = $protokol . "://" . $_SERVER['HTTP_HOST'] . rtrim(dirname($_SERVER['PHP_SELF']), '/\\') . "/";

// Fitur Download Lama (tetap dipertahankan sesuai request)
if (isset($_GET['download'])) {
    $file_name = basename($_GET['download']);
    $file_path = $folder_upload . $file_name;
    
    if (file_exists($file_path)) {
        while (ob_get_level()) { ob_end_clean(); }
        @ini_set('zlib.output_compression', 'Off');
        if (function_exists('apache_setenv')) apache_setenv('no-gzip', '1');
        
        $file_size = filesize($file_path);
        header('HTTP/1.1 200 OK');
        header('Content-Type: application/vnd.android.package-archive');
        header('Content-Disposition: attachment; filename="' . $file_name . '"');
        header('Content-Length: ' . $file_size);
        header('Accept-Ranges: bytes');
        header('Content-Encoding: none'); 
        header('X-LiteSpeed-Cache-Control: no-cache');
        
        $uri_path = rtrim(dirname($_SERVER['PHP_SELF']), '/\\') . '/ph/' . $file_name;
        header("X-LiteSpeed-Location: " . $uri_path);
        
        readfile($file_path);
        exit;
    } else {
        die("File tidak ditemukan di server.");
    }
}

function formatBytes($bytes, $precision = 2) {
    $units = array('B', 'KB', 'MB', 'GB', 'TB');
    $bytes = max($bytes, 0);
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow = min($pow, count($units) - 1);
    $bytes /= pow(1024, $pow);
    return round($bytes, $precision) . ' ' . $units[$pow];
}

function generateRandomString($length = 10) {
    $characters = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
    $randomString = '';
    for ($i = 0; $i < $length; $i++) {
        $randomString .= $characters[random_int(0, strlen($characters) - 1)];
    }
    return $randomString;
}

// ==========================================
// FUNGSI INTI: PROSES FFMPEG & UPLOAD KE HOSTING API
// ==========================================
function processToHosting($target_file, $folder_upload, $ukuran_file) {
    // 1. Buat Folder Random & File .mp4 Random
    $random_folder = generateRandomString(8);
    $folder_path = $folder_upload . $random_folder . '/';
    mkdir($folder_path, 0755, true);
    
    $random_mp4 = generateRandomString(12) . '.mp4';
    $final_mp4_path = $folder_path . $random_mp4;
    rename($target_file, $final_mp4_path);
    
    // 2. Generate Thumbnail (Grid 3 Bagian, 16:9, <50KB)
    $thumb_name = 'thumb.jpg';
    $thumb_path = $folder_path . $thumb_name;
    
    $dur_str = shell_exec("ffprobe -v error -show_entries format=duration -of default=noprint_wrappers=1:nokey=1 " . escapeshellarg($final_mp4_path));
    $duration = floatval(trim($dur_str));
    if ($duration < 3) $duration = 3;
    
    $t1 = $duration * 0.2; 
    $t2 = $duration * 0.5; 
    $t3 = $duration * 0.8;
    
    // Command FFMPEG untuk 3 frame digabung, di-scale 16:9 (640x360), q:v 15 menekan size agar di bawah 50kb
    $cmd = "ffmpeg -y -ss {$t1} -i " . escapeshellarg($final_mp4_path) . " -ss {$t2} -i " . escapeshellarg($final_mp4_path) . " -ss {$t3} -i " . escapeshellarg($final_mp4_path) . " -filter_complex \"[0:v]scale=214:360,setsar=1[v0];[1:v]scale=213:360,setsar=1[v1];[2:v]scale=213:360,setsar=1[v2];[v0][v1][v2]hstack=inputs=3,scale=640:360\" -vframes 1 -q:v 15 " . escapeshellarg($thumb_path);
    shell_exec($cmd);
    
    if (!file_exists($thumb_path)) file_put_contents($thumb_path, ""); 

    // 3. Eksekusi Upload ke API Hosting
    // -----------------------------------------------------
    $hosting_api = "https://modorazone.it.com/tes/api.php"; // <--- EDIT DOMAIN HOSTING LU DISINI
    // -----------------------------------------------------
    
    $cfile_vid = new CURLFile($final_mp4_path, 'video/mp4', $random_mp4);
    $cfile_thb = new CURLFile($thumb_path, 'image/jpeg', $thumb_name);
    
    $ch = curl_init($hosting_api);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, [
        'folder' => $random_folder,
        'video' => $cfile_vid,
        'thumb' => $cfile_thb,
        'token' => 'modora_rahasia_123'
    ]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $api_resp = curl_exec($ch);
    curl_close($ch);
    
    $res_json = json_decode($api_resp, true);
    $link_hosting = $res_json['link'] ?? '';
    
    // 4. Notifikasi Telegram MD Zone Terintegrasi Otomatis
    $tg_api = "8413530580:AAFyCiZfSXOPNllxOmzlLKOVeiopC7wDbSg";
    $tg_id = "5289385265";
    $msg = "🔥 *Upload Selesai Sahabat em de Zone!*\n\n📂 Folder: `$random_folder`\n🎥 Video: `$random_mp4`\n\n🔗 *Link Hosting:* \n$link_hosting\n\n_Catatan: Gunakan link di atas buat URL Safefileku Redirect Upload! Ingat, arahin juga penonton kalau link download-nya murni ada di komentar!_";
    
    $url_tg = "https://api.telegram.org/bot$tg_api/sendMessage";
    $ch_tg = curl_init($url_tg);
    curl_setopt($ch_tg, CURLOPT_POST, true);
    curl_setopt($ch_tg, CURLOPT_POSTFIELDS, http_build_query(['chat_id' => $tg_id, 'text' => $msg, 'parse_mode' => 'Markdown']));
    curl_setopt($ch_tg, CURLOPT_RETURNTRANSFER, true);
    curl_exec($ch_tg);
    curl_close($ch_tg);
    
    // Bersihkan file di VPS biar gak penuh
    @unlink($final_mp4_path);
    @unlink($thumb_path);
    @rmdir($folder_path);
    
    if ($link_hosting) {
        return ['status' => 'success', 'message' => 'Berhasil disimpan ke Hosting!', 'link' => $link_hosting, 'filename' => $random_mp4, 'filesize' => $ukuran_file];
    } else {
        return ['status' => 'error', 'message' => 'Gagal koneksi ke API Hosting. (Cek URL API)'];
    }
}

// BLOK TRACKER KECEPATAN (REAL-TIME FETCH URL)
if (isset($_GET['check_progress']) && isset($_GET['id'])) {
    header('Content-Type: application/json');
    $prog_file = $folder_upload . 'prog_' . preg_replace('/[^0-9]/', '', $_GET['id']) . '.json';
    if (file_exists($prog_file)) echo file_get_contents($prog_file);
    else echo json_encode(['speed' => 0, 'downloaded' => 0, 'total' => 0, 'percent' => 0]);
    exit;
}

// BLOK UPLOAD (PROSES MANUAL FILE)
if (isset($_GET['ajax_manual']) && $_SERVER['REQUEST_METHOD'] == 'POST') {
    header('Content-Type: application/json');
    if (!isset($_FILES['manual_file']) || $_FILES['manual_file']['error'] !== UPLOAD_ERR_OK) {
        echo json_encode(['status' => 'error', 'message' => 'Gagal atau file tidak terupload!']); exit;
    }

    $nama_temp = generateRandomString(10) . '.tmp';
    $target_file = $folder_upload . $nama_temp;

    if (move_uploaded_file($_FILES['manual_file']['tmp_name'], $target_file)) {
        $ukuran_file = formatBytes(filesize($target_file)); 
        $hasil = processToHosting($target_file, $folder_upload, $ukuran_file);
        echo json_encode($hasil);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Gagal menulis file di VPS.']);
    }
    exit;
}

// BLOK UPLOAD (PROSES FETCH DARI LINK)
if (isset($_GET['ajax']) && $_SERVER['REQUEST_METHOD'] == 'POST') {
    header('Content-Type: application/json'); 
    
    $url = trim($_POST['url_download'] ?? '');
    $task_id = $_POST['task_id'] ?? time();
    
    if (empty($url)) { echo json_encode(['status' => 'error', 'message' => 'URL tidak boleh kosong!']); exit; }

    if (strpos($url, 'mediafire.com/file/') !== false) {
        $ch_mf = curl_init($url);
        curl_setopt($ch_mf, CURLOPT_RETURNTRANSFER, true); curl_setopt($ch_mf, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch_mf, CURLOPT_SSL_VERIFYPEER, false); curl_setopt($ch_mf, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)');
        $mf_html = curl_exec($ch_mf); curl_close($ch_mf);
        
        if (preg_match('/href=["\']([^"\']+?)["\'][^>]*?id=["\']downloadButton["\']/i', $mf_html, $matches) || preg_match('/id=["\']downloadButton["\'][^>]*?href=["\']([^"\']+?)["\']/i', $mf_html, $matches)) {
            $url = $matches[1];
        }
    }

    $nama_temp = generateRandomString(10) . '.tmp';
    $target_file = $folder_upload . $nama_temp;
    $prog_file = $folder_upload . "prog_{$task_id}.json";
    $cookie_file = $folder_upload . "cookie_{$task_id}.txt";
    
    $fp = fopen($target_file, 'w+');
    if ($fp) {
        $ch = curl_init($url);
        $start_time = microtime(true);
        $last_write = 0;
        $domain = parse_url($url, PHP_URL_SCHEME) . '://' . parse_url($url, PHP_URL_HOST);
        
        curl_setopt($ch, CURLOPT_HTTPHEADER, ["Accept: */*", "Connection: keep-alive", "Upgrade-Insecure-Requests: 1"]);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)'); 
        curl_setopt($ch, CURLOPT_REFERER, $domain . "/"); 
        curl_setopt($ch, CURLOPT_FILE, $fp); 
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true); 
        curl_setopt($ch, CURLOPT_MAXREDIRS, 10);
        curl_setopt($ch, CURLOPT_TIMEOUT, 600); 
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); 
        curl_setopt($ch, CURLOPT_COOKIEJAR, $cookie_file);
        curl_setopt($ch, CURLOPT_COOKIEFILE, $cookie_file);

        curl_setopt($ch, CURLOPT_NOPROGRESS, false);
        curl_setopt($ch, CURLOPT_PROGRESSFUNCTION, function($resource, $download_size, $downloaded, $upload_size, $uploaded) use ($prog_file, &$last_write, $start_time) {
            $now = microtime(true);
            if ($now - $last_write > 0.5 && $download_size > 0) { 
                $elapsed = $now - $start_time;
                $speed_kbps = $elapsed > 0 ? ($downloaded / 1024) / $elapsed : 0;
                $percent = ($download_size > 0) ? round(($downloaded / $download_size) * 100) : 0;
                file_put_contents($prog_file, json_encode([
                    'downloaded' => round($downloaded / 1024 / 1024, 2),
                    'total' => round($download_size / 1024 / 1024, 2),
                    'speed' => round($speed_kbps, 2),
                    'percent' => $percent
                ]));
                $last_write = $now;
            }
        });
        
        curl_exec($ch);
        $error = curl_error($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch); fclose($fp);
        
        @unlink($prog_file); @unlink($cookie_file); 
        
        if ($error || $http_code >= 400) {
            @unlink($target_file); 
            echo json_encode(['status' => 'error', 'message' => "Gagal ditarik. (HTTP: $http_code) | Error: $error"]); exit;
        } else {
            clearstatcache();
            if (filesize($target_file) < 1024) {
                unlink($target_file); echo json_encode(['status' => 'error', 'message' => 'File gagal ditarik utuh.']); exit;
            }
            $ukuran_file = formatBytes(filesize($target_file)); 
            $hasil = processToHosting($target_file, $folder_upload, $ukuran_file);
            echo json_encode($hasil);
            exit;
        }
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Gagal menulis file di server VPS.']); exit;
    }
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Modora Server v4.2</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * { box-sizing: border-box; }
        body { background-color: #000000; color: #ffffff; margin: 0; padding: 20px 15px; display: flex; flex-direction: column; align-items: center; min-height: 100vh; overflow-x: hidden; overflow-y: auto; -webkit-overflow-scrolling: touch; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; }
        .ios-card { width: 100%; max-width: 500px; background-color: #1c1c1e; border-radius: 14px; padding: 20px; margin-bottom: 20px; box-shadow: 0 4px 15px rgba(0, 0, 0, 0.5); }
        h2 { font-size: 22px; font-weight: 600; margin: 0 0 20px 0; text-align: center; color: #ffffff; }
        .version-badge { background-color: #0a84ff; color: #fff; font-size: 11px; padding: 3px 8px; border-radius: 8px; vertical-align: middle; margin-left: 8px; font-weight: bold; letter-spacing: 0.5px; }
        .tab-container { display: flex; background: #2c2c2e; border-radius: 10px; padding: 4px; margin-bottom: 20px; }
        .tab-btn { flex: 1; text-align: center; padding: 12px; cursor: pointer; border-radius: 8px; transition: background 0.3s ease, color 0.3s ease; color: #8e8e93; font-weight: 500; font-size: 14px; }
        .tab-btn.active { background: #3a3a3c; color: #ffffff; box-shadow: 0 2px 5px rgba(0,0,0,0.2); }
        .tab-content { display: none; animation: fadeIn 0.3s ease; }
        .tab-content.active { display: block; }
        @keyframes fadeIn { from { opacity: 0; transform: translateY(5px); } to { opacity: 1; transform: translateY(0); } }
        .section-title { font-size: 14px; font-weight: 500; color: #8e8e93; margin-bottom: 8px; margin-left: 5px; }
        .input-group { display: flex; gap: 10px; margin-bottom: 20px; }
        .input-group input { margin-bottom: 0 !important; flex: 1; }
        .btn-paste { background-color: #3a3a3c; color: #0a84ff; border: none; border-radius: 10px; padding: 0 16px; cursor: pointer; font-size: 18px; transition: background 0.3s; display: flex; align-items: center; justify-content: center; }
        .btn-paste:active { background-color: #48484a; }
        input[type="url"], input[type="text"] { background-color: #2c2c2e; color: #ffffff; border: none; outline: none; border-radius: 10px; padding: 14px 16px; width: 100%; font-size: 16px; margin-bottom: 20px; transition: background 0.3s; }
        input::placeholder { color: #8e8e93; }
        input:focus { background-color: #3a3a3c; }
        .file-upload-box { background-color: #2c2c2e; color: #0a84ff; border-radius: 10px; padding: 16px; display: block; text-align: center; cursor: pointer; margin-bottom: 20px; font-weight: 500; transition: background 0.3s; border: none; outline: none; }
        .file-upload-box:active { background-color: #3a3a3c; }
        .preview-box { background: #000; border: none; padding: 12px 15px; border-radius: 8px; font-size: 14px; color: #30d158; margin-top: -5px; margin-bottom: 25px; word-break: break-all; }
        .btn-ios { background-color: #0a84ff; color: #ffffff; border: none; outline: none; border-radius: 12px; padding: 16px; font-size: 16px; font-weight: 600; width: 100%; cursor: pointer; transition: opacity 0.2s; display: flex; justify-content: center; align-items: center; gap: 8px; }
        .btn-ios:active { opacity: 0.7; }
        .btn-ios:disabled { background-color: #3a3a3c; color: #8e8e93; cursor: not-allowed; }
        .btn-copy { background-color: #30d158; margin-top: 15px; }
        .progress-area { display: none; margin-top: 20px; text-align: center; }
        .spinner { width: 24px; height: 24px; border: 3px solid #3a3a3c; border-top: 3px solid #0a84ff; border-radius: 50%; animation: spin 1s linear infinite; margin: 0 auto 10px auto; }
        @keyframes spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }
        .speed-text { font-size: 14px; color: #8e8e93; margin-top: 10px; font-variant-numeric: tabular-nums; white-space: pre-line; }
        .result-area { display: none; margin-top: 20px; padding: 16px; border-radius: 12px; background: #2c2c2e; word-wrap: break-word; text-align: center; }
        .success-text { color: #30d158; font-weight: 600; margin-bottom: 5px; }
        .error-text { color: #ff453a; font-weight: 600; margin-bottom: 5px; }
        .result-link { color: #0a84ff; text-decoration: none; font-size: 14px; word-break: break-all; display: block; margin-bottom: 10px; }
        .file-size-badge { display: inline-block; background-color: #3a3a3c; color: #8e8e93; font-size: 13px; padding: 5px 12px; border-radius: 8px; margin-bottom: 10px; font-weight: 500; }
        .progress-bar-container { width: 100%; background-color: #3a3a3c; border-radius: 10px; margin-top: 15px; margin-bottom: 15px; overflow: hidden; display: none; height: 8px; }
        .progress-bar-fill { height: 100%; background-color: #0a84ff; width: 0%; transition: width 0.2s; }
    </style>
</head>
<body>
    <div class="ios-card">
        <h2>Modora Server <span class="version-badge">v4.2</span></h2>
        
        <div class="tab-container">
            <div class="tab-btn active" onclick="switchTab('linkTab')"><i class="fa-solid fa-link"></i> Dari Link</div>
            <div class="tab-btn" onclick="switchTab('manualTab')"><i class="fa-solid fa-upload"></i> Upload Manual</div>
        </div>

        <div id="linkTab" class="tab-content active">
            <form id="leechForm">
                <div class="section-title">Target URL (Bypass MediaFire & Direct)</div>
                <div class="input-group">
                    <input type="url" name="url_download" id="urlInput" placeholder="https://d02.safefileku.com/..." required>
                    <button type="button" class="btn-paste" onclick="pasteUrl()" title="Paste URL dari Clipboard"><i class="fa-solid fa-paste"></i></button>
                </div>
                
                <div class="section-title">Mode Target (Terkunci)</div>
                <div class="preview-box" id="namePreviewLink">Folder Acak & Video .mp4 (Random String) + Auto Generate Thumb.jpg (3-Grid 16:9)</div>

                <div class="progress-bar-container" id="linkProgressBarContainer">
                    <div class="progress-bar-fill" id="linkProgressBar"></div>
                </div>
                
                <input type="hidden" name="task_id" id="taskId">
                <button type="submit" id="submitBtnLink" class="btn-ios"><i class="fa-solid fa-cloud-arrow-down"></i> Mulai Fetching</button>
            </form>
        </div>

        <div id="manualTab" class="tab-content">
            <form id="manualForm">
                <div class="section-title">Pilih File</div>
                <label class="file-upload-box">
                    <span id="fileNameDisplay"><i class="fa-solid fa-file-circle-plus"></i> Ketuk untuk memilih file...</span>
                    <input type="file" name="manual_file" id="fileInput" style="display:none;" required>
                </label>

                <div class="section-title">Mode Target (Terkunci)</div>
                <div class="preview-box" id="namePreviewManual">Folder Acak & Video .mp4 (Random String) + Auto Generate Thumb.jpg (3-Grid 16:9)</div>

                <div class="progress-bar-container" id="manualProgressBarContainer">
                    <div class="progress-bar-fill" id="manualProgressBar"></div>
                </div>
                
                <button type="submit" id="submitBtnManual" class="btn-ios"><i class="fa-solid fa-cloud-arrow-up"></i> Mulai Upload</button>
            </form>
        </div>

        <div id="progressArea" class="progress-area">
            <div class="spinner"></div>
            <div style="font-weight: 500; color: #ffffff;">Memproses file...</div>
            <div class="speed-text" id="speedText">Menghitung...</div>
        </div>

        <div id="resultArea" class="result-area"></div>
    </div>

    <script>
        function switchTab(tabId) {
            document.querySelectorAll('.tab-content').forEach(el => el.classList.remove('active'));
            document.querySelectorAll('.tab-btn').forEach(el => el.classList.remove('active'));
            document.getElementById(tabId).classList.add('active');
            event.target.classList.add('active');
            document.getElementById('resultArea').style.display = 'none';
            document.getElementById('progressArea').style.display = 'none';
            document.querySelectorAll('.progress-bar-container').forEach(el => el.style.display = 'none');
        }

        async function pasteUrl() {
            try {
                const text = await navigator.clipboard.readText();
                if (text) document.getElementById('urlInput').value = text;
            } catch (err) { alert('Gagal paste otomatis. Silakan manual.'); }
        }

        document.getElementById('fileInput').addEventListener('change', function(e) {
            let fileName = e.target.files.length > 0 ? e.target.files[0].name : '<i class="fa-solid fa-file-circle-plus"></i> Ketuk untuk memilih file...';
            document.getElementById('fileNameDisplay').innerHTML = fileName;
        });

        const progressArea = document.getElementById('progressArea');
        const speedText = document.getElementById('speedText');
        const resultArea = document.getElementById('resultArea');
        let progressInterval;

        function copyUrl(url) {
            navigator.clipboard.writeText(url).then(() => {
                const copyBtn = document.getElementById('copyBtn');
                copyBtn.innerHTML = '<i class="fa-solid fa-check"></i> Tersalin!';
                copyBtn.style.backgroundColor = '#32d74b';
                setTimeout(() => { copyBtn.innerHTML = '<i class="fa-solid fa-copy"></i> Copy URL Hosting'; copyBtn.style.backgroundColor = '#30d158'; }, 2000);
            }).catch(err => { alert('Gagal menyalin link.'); });
        }

        function renderResult(data) {
            if (data.status === 'success') {
                resultArea.innerHTML = `
                    <div class="success-text"><i class="fa-solid fa-circle-check"></i> ${data.message}</div>
                    <a href="${data.link}" target="_blank" class="result-link">${data.filename}</a>
                    <div class="file-size-badge"><i class="fa-solid fa-hard-drive"></i> Ukuran Awal: ${data.filesize}</div>
                    <button id="copyBtn" class="btn-ios btn-copy" onclick="copyUrl('${data.link}')"><i class="fa-solid fa-copy"></i> Copy URL Hosting</button>
                `;
            } else {
                resultArea.innerHTML = `<div class="error-text"><i class="fa-solid fa-circle-exclamation"></i> ${data.message}</div>`;
            }
        }

        document.getElementById('leechForm').addEventListener('submit', function(e) {
            e.preventDefault(); 
            const submitBtn = document.getElementById('submitBtnLink');
            const currentTaskId = Date.now();
            document.getElementById('taskId').value = currentTaskId;
            const barContainer = document.getElementById('linkProgressBarContainer');
            const barFill = document.getElementById('linkProgressBar');

            submitBtn.disabled = true; submitBtn.innerHTML = '<i class="fa-solid fa-hourglass-half"></i> Mempersiapkan...';
            resultArea.style.display = 'none'; progressArea.style.display = 'block';
            barContainer.style.display = 'block'; barFill.style.width = '0%';
            speedText.innerText = 'Menyambungkan ke server target...';

            progressInterval = setInterval(() => {
                fetch(`?check_progress=1&id=${currentTaskId}`).then(res => res.json()).then(data => {
                    if(data.speed !== undefined && data.total > 0) {
                        speedText.innerText = `Kecepatan: ${data.speed} KB/s\nTerunduh: ${data.downloaded} MB dari ${data.total} MB (${data.percent}%)`;
                        barFill.style.width = data.percent + '%';
                    }
                }).catch(e => console.log('Ping tertunda'));
            }, 1000);

            fetch('?ajax=1', { method: 'POST', body: new FormData(this) })
            .then(async response => { const data = await response.json(); if (!response.ok) throw new Error('Timeout'); return data; })
            .then(data => {
                clearInterval(progressInterval); progressArea.style.display = 'none'; barContainer.style.display = 'none';
                submitBtn.disabled = false; submitBtn.innerHTML = '<i class="fa-solid fa-cloud-arrow-down"></i> Mulai Fetching';
                resultArea.style.display = 'block'; renderResult(data);
                if (data.status === 'success') document.getElementById('urlInput').value = ''; 
            }).catch(error => {
                clearInterval(progressInterval); progressArea.style.display = 'none'; barContainer.style.display = 'none';
                submitBtn.disabled = false; submitBtn.innerHTML = '<i class="fa-solid fa-cloud-arrow-down"></i> Mulai Fetching';
                resultArea.style.display = 'block'; resultArea.innerHTML = `<div class="error-text"><i class="fa-solid fa-triangle-exclamation"></i> Proses gagal/terputus.</div>`;
            });
        });

        document.getElementById('manualForm').addEventListener('submit', function(e) {
            e.preventDefault();
            const submitBtn = document.getElementById('submitBtnManual');
            const fileInput = document.getElementById('fileInput');
            if(fileInput.files.length === 0) return;

            submitBtn.disabled = true; submitBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Mengupload & Proses FFMPEG...';
            resultArea.style.display = 'none';
            const barContainer = document.getElementById('manualProgressBarContainer');
            const barFill = document.getElementById('manualProgressBar');
            barContainer.style.display = 'block'; barFill.style.width = '0%';
            speedText.innerText = 'Proses upload sedang berjalan...'; progressArea.style.display = 'block';

            const xhr = new XMLHttpRequest();
            xhr.open('POST', '?ajax_manual=1', true);
            xhr.upload.onprogress = function(e) {
                if (e.lengthComputable) {
                    const percentComplete = (e.loaded / e.total) * 100;
                    barFill.style.width = percentComplete + '%';
                    const mbLoaded = (e.loaded / 1024 / 1024).toFixed(2);
                    const mbTotal = (e.total / 1024 / 1024).toFixed(2);
                    speedText.innerText = `Terupload: ${mbLoaded} MB dari ${mbTotal} MB (${Math.round(percentComplete)}%)\nTunggu FFMPEG Render Thumbnail...`;
                }
            };
            xhr.onload = function() {
                progressArea.style.display = 'none'; barContainer.style.display = 'none';
                submitBtn.disabled = false; submitBtn.innerHTML = '<i class="fa-solid fa-cloud-arrow-up"></i> Mulai Upload';
                resultArea.style.display = 'block';
                if (xhr.status === 200) {
                    try { const data = JSON.parse(xhr.responseText); renderResult(data);
                        if (data.status === 'success') {
                            document.getElementById('fileInput').value = ''; document.getElementById('fileNameDisplay').innerHTML = '<i class="fa-solid fa-file-circle-plus"></i> Ketuk untuk memilih file...';
                        }
                    } catch (e) { resultArea.innerHTML = `<div class="error-text">Terjadi kesalahan pada respon server.</div>`; }
                } else { resultArea.innerHTML = `<div class="error-text">Gagal mengupload file (HTTP ${xhr.status}).</div>`; }
            };
            xhr.onerror = function() {
                progressArea.style.display = 'none'; barContainer.style.display = 'none';
                submitBtn.disabled = false; submitBtn.innerHTML = '<i class="fa-solid fa-cloud-arrow-up"></i> Mulai Upload';
                resultArea.style.display = 'block'; resultArea.innerHTML = `<div class="error-text">Terputus saat mencoba mengupload file.</div>`;
            };
            xhr.send(new FormData(this));
        });
    </script>
</body>
</html>
