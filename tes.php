<?php
date_default_timezone_set('Asia/Jakarta');
set_time_limit(600); 

$folder_upload = __DIR__ . '/';
if (!file_exists($folder_upload)) {
    mkdir($folder_upload, 0755, true);
}

$protokol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http");
$base_url = $protokol . "://" . $_SERVER['HTTP_HOST'] . rtrim(dirname($_SERVER['PHP_SELF']), '/\\') . "/";

// Domain & Target Hosting Tujuan
$hosting_domain = "https://modorazone.it.com";
$hosting_api_url = $hosting_domain . "/tes/api.php";

// ==========================================
// 0. BLOK DOWNLOADER (LITESPEED / RUMAHWEB FIX)
// ==========================================
if (isset($_GET['download'])) {
    $file_name = basename($_GET['download']);
    $file_path = $folder_upload . $file_name;
    
    if (file_exists($file_path)) {
        while (ob_get_level()) { ob_end_clean(); }
        
        @ini_set('zlib.output_compression', 'Off');
        if (function_exists('apache_setenv')) {
            apache_setenv('no-gzip', '1');
        }
        
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

// Fungsi Format Byte ke MB/KB/GB
function formatBytes($bytes, $precision = 2) {
    $units = array('B', 'KB', 'MB', 'GB', 'TB');
    $bytes = max($bytes, 0);
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow = min($pow, count($units) - 1);
    $bytes /= pow(1024, $pow);
    return round($bytes, $precision) . ' ' . $units[$pow];
}

// Fungsi Generate Nama Random
function generateRandomString($length = 10) {
    $characters = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
    $randomString = '';
    for ($i = 0; $i < $length; $i++) {
        $randomString .= $characters[random_int(0, strlen($characters) - 1)];
    }
    return $randomString;
}

// Fungsi untuk Format Nama File (Custom / Random)
function generateModoraName($custom_name, $ekstensi, $folder_upload) {
    $custom_name = preg_replace('/[\/\\\:\*\?\"\<\>\|]/', '', $custom_name);
    $custom_name = ucwords(strtolower(trim($custom_name)));
    
    if(empty($custom_name)) {
        $base_name = generateRandomString(12);
    } else {
        $base_name = $custom_name . " (PLAYHUB Official)";
    }

    $final_name = $base_name . "." . $ekstensi;
    return $final_name;
}

// Fungsi Pembuat Thumbnail Grid 3 Bagian (16:9 & < 50KB) via FFmpeg VPS
function generateGridThumbnail($video_path, $output_thumb_path) {
    $duration = 10;
    exec("ffprobe -v error -show_entries format=duration -of default=noprint_wrappers=1:nokey=1 " . escapeshellarg($video_path), $output_dur);
    if (!empty($output_dur[0]) && is_numeric($output_dur[0])) {
        $duration = (float)$output_dur[0];
    }
    
    // Ambil 3 frame di posisi persentase durasi video
    $t1 = max(1, round($duration * 0.25));
    $t2 = max(2, round($duration * 0.50));
    $t3 = max(3, round($duration * 0.75));
    
    // Skema hstack: 3 buah frame vertikal (rasio 320:540 dikali 3 = 960x540 berasio pas 16:9) 
    // Lalu dikompresi ke resolusi 640x360 dengan kualitas q:v 15 agar ukuran file di bawah 50KB secara konsisten
    $ffmpeg_cmd = "ffmpeg -y -ss $t1 -i " . escapeshellarg($video_path) . " -ss $t2 -i " . escapeshellarg($video_path) . " -ss $t3 -i " . escapeshellarg($video_path) . " -filter_complex \"[0:v]scale=320:540[v1];[1:v]scale=320:540[v2];[2:v]scale=320:540[v3];[v1][v2][v3]hstack=inputs=3,scale=640:360\" -vframes 1 -q:v 15 " . escapeshellarg($output_thumb_path);
    exec($ffmpeg_cmd);
}

// Fungsi Pengirim Data dari VPS ke Hosting API
function uploadToHosting($api_url, $folder_name, $video_path, $video_name, $thumb_path) {
    $ch = curl_init($api_url);
    $cfile_video = curl_file_create($video_path, 'video/mp4', $video_name);
    $cfile_thumb = curl_file_create($thumb_path, 'image/jpeg', 'thumb.jpg');
    
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, [
        'folder_name' => $folder_name,
        'video_file' => $cfile_video,
        'thumb_file' => $cfile_thumb
    ]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    
    $response = curl_exec($ch);
    $error = curl_error($ch);
    curl_close($ch);
    
    if ($error) {
        return ['status' => 'error', 'message' => 'Gagal jembatan cURL VPS ke Hosting: ' . $error];
    }
    return json_decode($response, true);
}

// ==========================================
// 1. BLOK TRACKER KECEPATAN (REAL-TIME FETCH URL)
// ==========================================
if (isset($_GET['check_progress']) && isset($_GET['id'])) {
    header('Content-Type: application/json');
    $prog_file = $folder_upload . 'prog_' . preg_replace('/[^0-9]/', '', $_GET['id']) . '.json';
    if (file_exists($prog_file)) {
        echo file_get_contents($prog_file);
    } else {
        echo json_encode(['speed' => 0, 'downloaded' => 0, 'total' => 0, 'percent' => 0]);
    }
    exit;
}

// ==========================================
// 2. BLOK UPLOAD (PROSES MANUAL FILE)
// ==========================================
if (isset($_GET['ajax_manual']) && $_SERVER['REQUEST_METHOD'] == 'POST') {
    header('Content-Type: application/json');
    
    if (!isset($_FILES['manual_file']) || $_FILES['manual_file']['error'] !== UPLOAD_ERR_OK) {
        echo json_encode(['status' => 'error', 'message' => 'Gagal atau file tidak terupload!']);
        exit;
    }

    $random_folder = generateRandomString(8);
    $nama_file = generateRandomString(12) . ".mp4";
    $target_file = $folder_upload . $nama_file;
    $thumb_file = $folder_upload . "thumb_" . $random_folder . ".jpg";

    if (move_uploaded_file($_FILES['manual_file']['tmp_name'], $target_file)) {
        
        // Generate Gambar Grid Thumbnail di VPS
        generateGridThumbnail($target_file, $thumb_file);
        
        // Kirim hasil pemrosesan ke hosting
        $res_hosting = uploadToHosting($hosting_api_url, $random_folder, $target_file, $nama_file, $thumb_file);
        
        $ukuran_file = formatBytes(filesize($target_file));
        
        // Bersihkan file sisa di VPS agar hemat storage
        if(file_exists($target_file)) unlink($target_file);
        if(file_exists($thumb_file)) unlink($thumb_file);
        
        if ($res_hosting && $res_hosting['status'] === 'success') {
            $link_final = $hosting_domain . "/tes/" . $random_folder . "/" . $nama_file;
            echo json_encode([
                'status' => 'success', 
                'message' => 'Berhasil disimpan ke Hosting!',
                'link' => $link_final,
                'filename' => $nama_file,
                'filesize' => $ukuran_file
            ]);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Gagal upload dari VPS ke Hosting API. ' . ($res_hosting['message'] ?? '')]);
        }
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Gagal menulis file di server VPS.']);
    }
    exit;
}

// ==========================================
// 3. BLOK UPLOAD (PROSES FETCH DARI LINK)
// ==========================================
if (isset($_GET['ajax']) && $_SERVER['REQUEST_METHOD'] == 'POST') {
    header('Content-Type: application/json'); 
    
    $url = trim($_POST['url_download'] ?? '');
    $task_id = $_POST['task_id'] ?? time();
    
    if (empty($url)) {
        echo json_encode(['status' => 'error', 'message' => 'URL tidak boleh kosong!']);
        exit;
    }

    if (strpos($url, 'mediafire.com/file/') !== false) {
        $ch_mf = curl_init($url);
        curl_setopt($ch_mf, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch_mf, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch_mf, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch_mf, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch_mf, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)');
        $mf_html = curl_exec($ch_mf);
        curl_close($ch_mf);
        
        if (preg_match('/href=["\']([^"\']+?)["\'][^>]*?id=["\']downloadButton["\']/i', $mf_html, $matches) || preg_match('/id=["\']downloadButton["\'][^>]*?href=["\']([^"\']+?)["\']/i', $mf_html, $matches)) {
            $url = $matches[1];
        }
    }

    $random_folder = generateRandomString(8);
    $nama_file = generateRandomString(12) . ".mp4";
    $target_file = $folder_upload . $nama_file;
    $thumb_file = $folder_upload . "thumb_" . $random_folder . ".jpg";
    
    $prog_file = $folder_upload . "prog_{$task_id}.json";
    $cookie_file = $folder_upload . "cookie_{$task_id}.txt";
    
    $fp = fopen($target_file, 'w+');
    if ($fp) {
        $ch = curl_init($url);
        $start_time = microtime(true);
        $last_write = 0;

        $domain = parse_url($url, PHP_URL_SCHEME) . '://' . parse_url($url, PHP_URL_HOST);
        
        $headers = [
            "Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8",
            "Accept-Language: en-US,en;q=0.5",
            "Connection: keep-alive",
            "Upgrade-Insecure-Requests: 1",
            "Sec-Fetch-Dest: document",
            "Sec-Fetch-Mode: navigate",
            "Sec-Fetch-Site: cross-site",
            "Sec-Fetch-User: ?1"
        ];

        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)'); 
        curl_setopt($ch, CURLOPT_REFERER, $domain . "/"); 
        
        curl_setopt($ch, CURLOPT_FILE, $fp); 
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true); 
        curl_setopt($ch, CURLOPT_MAXREDIRS, 10);
        curl_setopt($ch, CURLOPT_TIMEOUT, 600); 
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); 
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0); 
        curl_setopt($ch, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4); 
        curl_setopt($ch, CURLOPT_FAILONERROR, true); 
        curl_setopt($ch, CURLOPT_AUTOREFERER, true);
        curl_setopt($ch, CURLOPT_COOKIEJAR, $cookie_file);
        curl_setopt($ch, CURLOPT_COOKIEFILE, $cookie_file);
        curl_setopt($ch, CURLOPT_ENCODING, ""); 
        curl_setopt($ch, CURLOPT_TCP_KEEPALIVE, 1); 
        curl_setopt($ch, CURLOPT_BUFFERSIZE, 1048576); 

        curl_setopt($ch, CURLOPT_NOPROGRESS, false);
        curl_setopt($ch, CURLOPT_PROGRESSFUNCTION, function($resource, $download_size, $downloaded, $upload_size, $uploaded) use ($prog_file, &$last_write, $start_time) {
            $now = microtime(true);
            if ($now - $last_write > 0.5 && $download_size > 0) { 
                $elapsed = $now - $start_time;
                $speed_kbps = $elapsed > 0 ? ($downloaded / 1024) / $elapsed : 0;
                $percent = ($download_size > 0) ? round(($downloaded / $download_size) * 100) : 0;
                
                $data = [
                    'downloaded' => round($downloaded / 1024 / 1024, 2),
                    'total' => round($download_size / 1024 / 1024, 2),
                    'speed' => round($speed_kbps, 2),
                    'percent' => $percent
                ];
                file_put_contents($prog_file, json_encode($data));
                $last_write = $now;
            }
        });
        
        curl_exec($ch);
        $error = curl_error($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        fclose($fp);
        
        if (file_exists($prog_file)) unlink($prog_file); 
        if (file_exists($cookie_file)) unlink($cookie_file); 
        
        if ($error || $http_code >= 400) {
            if(file_exists($target_file)) unlink($target_file); 
            echo json_encode(['status' => 'error', 'message' => "Gagal ditarik VPS. (HTTP: $http_code) | Error: $error"]);
            exit;
        } else {
            clearstatcache();
            if (filesize($target_file) < 1024) {
                unlink($target_file);
                echo json_encode(['status' => 'error', 'message' => 'File video gagal ditarik utuh oleh VPS.']);
                exit;
            }
            
            // Proses Grid Thumbnail & Transfer Target File ke Hosting
            generateGridThumbnail($target_file, $thumb_file);
            $res_hosting = uploadToHosting($hosting_api_url, $random_folder, $target_file, $nama_file, $thumb_file);
            
            $ukuran_file = formatBytes(filesize($target_file));
            
            if(file_exists($target_file)) unlink($target_file);
            if(file_exists($thumb_file)) unlink($thumb_file);
            
            if ($res_hosting && $res_hosting['status'] === 'success') {
                $link_final = $hosting_domain . "/tes/" . $random_folder . "/" . $nama_file;
                echo json_encode([
                    'status' => 'success', 
                    'message' => 'Berhasil disimpan ke Hosting!',
                    'link' => $link_final,
                    'filename' => $nama_file,
                    'filesize' => $ukuran_file
                ]);
            } else {
                echo json_encode(['status' => 'error', 'message' => 'Gagal melempar data ke hosting.']);
            }
            exit;
        }
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Gagal menulis file di server VPS.']);
        exit;
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
            <div class="tab-btn active" onclick="switchTab('linkTab')">
                <i class="fa-solid fa-link"></i> Dari Link
            </div>
            <div class="tab-btn" onclick="switchTab('manualTab')">
                <i class="fa-solid fa-upload"></i> Upload Manual
            </div>
        </div>

        <div id="linkTab" class="tab-content active">
            <form id="leechForm">
                <div class="section-title">Target URL (Bypass MediaFire & Direct Video)</div>
                <div class="input-group">
                    <input type="url" name="url_download" id="urlInput" placeholder="https://domain.com/video.mp4" required>
                    <button type="button" class="btn-paste" onclick="pasteUrl()" title="Paste URL dari Clipboard">
                        <i class="fa-solid fa-paste"></i>
                    </button>
                </div>
                
                <div class="section-title">Nama File (Otomatis Random Folder & .mp4)</div>
                <input type="text" name="custom_name" id="customNameLink" placeholder="Nama asli akan diacak penuh..." disabled value="Auto Generated Random">
                
                <div class="section-title">Preview Folder & Format Target</div>
                <div class="preview-box" id="namePreviewLink">[Folder Acak]/[Nama Acak].mp4 + thumb.jpg</div>

                <div class="progress-bar-container" id="linkProgressBarContainer">
                    <div class="progress-bar-fill" id="linkProgressBar"></div>
                </div>
                
                <input type="hidden" name="task_id" id="taskId">
                <button type="submit" id="submitBtnLink" class="btn-ios">
                    <i class="fa-solid fa-cloud-arrow-down"></i> Mulai Fetching
                </button>
            </form>
        </div>

        <div id="manualTab" class="tab-content">
            <form id="manualForm">
                <div class="section-title">Pilih Video</div>
                <label class="file-upload-box">
                    <span id="fileNameDisplay"><i class="fa-solid fa-file-circle-plus"></i> Ketuk untuk memilih file video...</span>
                    <input type="file" name="manual_file" id="fileInput" accept="video/*" style="display:none;" required>
                </label>

                <div class="section-title">Nama File (Otomatis Random Folder & .mp4)</div>
                <input type="text" name="custom_name_manual" id="customNameManual" placeholder="Nama asli akan diacak penuh..." disabled value="Auto Generated Random">
                
                <div class="section-title">Preview Folder & Format Target</div>
                <div class="preview-box" id="namePreviewManual">[Folder Acak]/[Nama Acak].mp4 + thumb.jpg</div>

                <div class="progress-bar-container" id="manualProgressBarContainer">
                    <div class="progress-bar-fill" id="manualProgressBar"></div>
                </div>
                
                <button type="submit" id="submitBtnManual" class="btn-ios">
                    <i class="fa-solid fa-cloud-arrow-up"></i> Mulai Upload
                </button>
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
                if (text) {
                    document.getElementById('urlInput').value = text;
                }
            } catch (err) {
                alert('Gagal paste otomatis. Silakan paste secara manual.');
            }
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
                setTimeout(() => {
                    copyBtn.innerHTML = '<i class="fa-solid fa-copy"></i> Copy URL';
                    copyBtn.style.backgroundColor = '#30d158';
                }, 2000);
            }).catch(err => { alert('Gagal menyalin link.'); });
        }

        function renderResult(data) {
            if (data.status === 'success') {
                resultArea.innerHTML = `
                    <div class="success-text"><i class="fa-solid fa-circle-check"></i> ${data.message}</div>
                    <a href="${data.link}" target="_blank" class="result-link">${data.filename}</a>
                    <div class="file-size-badge"><i class="fa-solid fa-hard-drive"></i> Ukuran: ${data.filesize}</div>
                    <button id="copyBtn" class="btn-ios btn-copy" onclick="copyUrl('${data.link}')"><i class="fa-solid fa-copy"></i> Copy URL</button>
                `;
            } else {
                resultArea.innerHTML = `<div class="error-text"><i class="fa-solid fa-circle-exclamation"></i> ${data.message}</div>`;
            }
        }

        // --- Fetch Proses ---
        document.getElementById('leechForm').addEventListener('submit', function(e) {
            e.preventDefault(); 
            const submitBtn = document.getElementById('submitBtnLink');
            const currentTaskId = Date.now();
            document.getElementById('taskId').value = currentTaskId;
            const barContainer = document.getElementById('linkProgressBarContainer');
            const barFill = document.getElementById('linkProgressBar');

            submitBtn.disabled = true;
            submitBtn.innerHTML = '<i class="fa-solid fa-hourglass-half"></i> Mempersiapkan...';
            resultArea.style.display = 'none';
            progressArea.style.display = 'block';
            barContainer.style.display = 'block';
            barFill.style.width = '0%';
            speedText.innerText = 'Menyambungkan ke server target...';

            progressInterval = setInterval(() => {
                fetch(`?check_progress=1&id=${currentTaskId}`)
                .then(res => res.json())
                .then(data => {
                    if(data.speed !== undefined && data.total > 0) {
                        speedText.innerText = `Kecepatan: ${data.speed} KB/s\nTerunduh ke VPS: ${data.downloaded} MB dari ${data.total} MB (${data.percent}%)`;
                        barFill.style.width = data.percent + '%';
                    }
                }).catch(e => console.log('Ping tertunda'));
            }, 1000);

            fetch('?ajax=1', { method: 'POST', body: new FormData(this) })
            .then(async response => {
                const data = await response.json();
                if (!response.ok) throw new Error('Timeout');
                return data;
            })
            .then(data => {
                clearInterval(progressInterval); 
                progressArea.style.display = 'none'; 
                barContainer.style.display = 'none';
                submitBtn.disabled = false;
                submitBtn.innerHTML = '<i class="fa-solid fa-cloud-arrow-down"></i> Mulai Fetching';
                
                resultArea.style.display = 'block';
                renderResult(data);
                
                if (data.status === 'success') {
                    document.getElementById('urlInput').value = ''; 
                }
            })
            .catch(error => {
                clearInterval(progressInterval);
                progressArea.style.display = 'none';
                barContainer.style.display = 'none';
                submitBtn.disabled = false;
                submitBtn.innerHTML = '<i class="fa-solid fa-cloud-arrow-down"></i> Mulai Fetching';
                resultArea.style.display = 'block';
                resultArea.innerHTML = `<div class="error-text"><i class="fa-solid fa-triangle-exclamation"></i> Proses gagal, terputus, atau API Hosting lambat.</div>`;
            });
        });

        // --- Upload Proses ---
        document.getElementById('manualForm').addEventListener('submit', function(e) {
            e.preventDefault();
            const submitBtn = document.getElementById('submitBtnManual');
            const fileInput = document.getElementById('fileInput');
            
            if(fileInput.files.length === 0) return;

            submitBtn.disabled = true;
            submitBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Mengupload...';
            resultArea.style.display = 'none';
            
            const barContainer = document.getElementById('manualProgressBarContainer');
            const barFill = document.getElementById('manualProgressBar');
            barContainer.style.display = 'block';
            barFill.style.width = '0%';

            speedText.innerText = 'Proses upload sedang berjalan...';
            progressArea.style.display = 'block';

            const xhr = new XMLHttpRequest();
            xhr.open('POST', '?ajax_manual=1', true);
            
            xhr.upload.onprogress = function(e) {
                if (e.lengthComputable) {
                    const percentComplete = (e.loaded / e.total) * 100;
                    barFill.style.width = percentComplete + '%';
                    const mbLoaded = (e.loaded / 1024 / 1024).toFixed(2);
                    const mbTotal = (e.total / 1024 / 1024).toFixed(2);
                    speedText.innerText = `Terupload ke VPS: ${mbLoaded} MB dari ${mbTotal} MB (${Math.round(percentComplete)}%)\nSetelah ini VPS memotong grid thumb & mengirim ke hosting...`;
                }
            };

            xhr.onload = function() {
                progressArea.style.display = 'none';
                barContainer.style.display = 'none';
                submitBtn.disabled = false;
                submitBtn.innerHTML = '<i class="fa-solid fa-cloud-arrow-up"></i> Mulai Upload';
                resultArea.style.display = 'block';

                if (xhr.status === 200) {
                    try {
                        const data = JSON.parse(xhr.responseText);
                        renderResult(data);
                        if (data.status === 'success') {
                            document.getElementById('fileInput').value = '';
                            document.getElementById('fileNameDisplay').innerHTML = '<i class="fa-solid fa-file-circle-plus"></i> Ketuk untuk memilih file video...';
                        }
                    } catch (e) {
                        resultArea.innerHTML = `<div class="error-text"><i class="fa-solid fa-triangle-exclamation"></i> Terjadi kesalahan respon balik hosting.</div>`;
                    }
                } else {
                    resultArea.innerHTML = `<div class="error-text"><i class="fa-solid fa-circle-xmark"></i> Gagal mengupload file (HTTP ${xhr.status}).</div>`;
                }
            };

            xhr.onerror = function() {
                progressArea.style.display = 'none';
                barContainer.style.display = 'none';
                submitBtn.disabled = false;
                submitBtn.innerHTML = '<i class="fa-solid fa-cloud-arrow-up"></i> Mulai Upload';
                resultArea.style.display = 'block';
                resultArea.innerHTML = `<div class="error-text"><i class="fa-solid fa-link-slash"></i> Terputus saat mencoba koneksi.</div>`;
            };

            xhr.send(new FormData(this));
        });
    </script>
</body>
</html>
