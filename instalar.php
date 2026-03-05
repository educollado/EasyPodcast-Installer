<?php

declare(strict_types=1);

// ============================================================
// EasyPodcast — Instalador automático
// Fichero único; no requiere dependencias externas.
// ============================================================

define('INSTALLER',  basename(__FILE__));
define('INSTALL_DIR', __DIR__);
define('GH_API',     'https://api.github.com/repos/educollado/EasyPodcast/releases/latest');
define('UA',         'EasyPodcast-Installer/1.0');

set_time_limit(300);

// ── Helpers ──────────────────────────────────────────────────

function e(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function deleteRecursive(string $path): void
{
    if (is_link($path) || is_file($path)) {
        @unlink($path);
        return;
    }
    if (is_dir($path)) {
        foreach (array_diff((array) scandir($path), ['.', '..']) as $child) {
            deleteRecursive($path . DIRECTORY_SEPARATOR . $child);
        }
        @rmdir($path);
    }
}

// Copia recursiva de directorio (usado tras la extracción con PharData)
function copyDir(string $src, string $dst): void
{
    foreach (array_diff((array) scandir($src), ['.', '..']) as $item) {
        $s = $src . DIRECTORY_SEPARATOR . $item;
        $d = $dst . DIRECTORY_SEPARATOR . $item;
        if (is_dir($s)) {
            @mkdir($d, 0755, true);
            copyDir($s, $d);
        } else {
            copy($s, $d);
        }
    }
}

/**
 * Extrae un .tar.gz sobre $destDir, eliminando el directorio raíz del archivo
 * (equivalente a --strip-components=1).
 *
 * Método primario:  PharData (PHP nativo, no requiere exec).
 * Método de reserva: exec(tar) si PharData falla.
 *
 * @param array<array{ok:bool,msg:string}> $log
 */
function extractTarGz(string $tmpFile, string $destDir, array &$log): bool
{
    // ── Método 1: PharData ─────────────────────────────────────
    if (class_exists('PharData')) {
        $tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ep_' . uniqid();
        @mkdir($tempDir, 0755, true);
        try {
            $phar = new PharData($tmpFile);
            $phar->extractTo($tempDir, null, true);

            // Identificar el directorio raíz dentro del tar (strip-components=1)
            $items = array_values(array_diff((array) scandir($tempDir), ['.', '..']));
            $srcDir = (count($items) === 1 && is_dir($tempDir . DIRECTORY_SEPARATOR . $items[0]))
                ? $tempDir . DIRECTORY_SEPARATOR . $items[0]
                : $tempDir;

            copyDir($srcDir, $destDir);
            $log[] = ['ok' => true, 'msg' => 'Archivos extraídos con PharData.'];
            return true;
        } catch (Throwable $ex) {
            $log[] = ['ok' => false, 'msg' => 'PharData: ' . $ex->getMessage() . '. Probando con exec()…'];
        } finally {
            deleteRecursive($tempDir);
        }
    }

    // ── Método 2: exec(tar) como reserva ──────────────────────
    $disabled = array_map('trim', explode(',', (string)(ini_get('disable_functions') ?: '')));
    $canExec  = function_exists('exec') && function_exists('escapeshellarg')
                && !in_array('exec', $disabled, true)
                && !in_array('escapeshellarg', $disabled, true);
    if ($canExec) {
        $escapedTmp = escapeshellarg($tmpFile);
        $escapedDir = escapeshellarg($destDir);
        exec("tar -xzf {$escapedTmp} --strip-components=1 -C {$escapedDir} 2>&1", $out, $code);
        if ($code === 0) {
            $log[] = ['ok' => true, 'msg' => 'Archivos extraídos con tar (exec).'];
            return true;
        }
        $log[] = ['ok' => false, 'msg' => 'tar: ' . trim(implode(' ', $out))];
    }

    return false;
}

/**
 * Crea la base de datos ejecutando schema.sql.
 *
 * Método primario:  PDO (no requiere exec).
 * Método de reserva: exec(sqlite3 CLI).
 *
 * @param array<array{ok:bool,msg:string}> $log
 */
function createDatabase(string $schema, string $db, array &$log): bool
{
    // ── Método 1: PDO ──────────────────────────────────────────
    try {
        $pdo = new PDO('sqlite:' . $db);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $sql = (string) file_get_contents($schema);
        foreach (explode(';', $sql) as $stmt) {
            $stmt = trim($stmt);
            if ($stmt !== '') {
                try { $pdo->exec($stmt); } catch (Throwable $t) { /* ignora sentencias no críticas */ }
            }
        }
        $log[] = ['ok' => true, 'msg' => 'Base de datos creada con PDO.'];
        return true;
    } catch (Throwable $ex) {
        $log[] = ['ok' => false, 'msg' => 'PDO: ' . $ex->getMessage() . '. Probando sqlite3 CLI…'];
    }

    // ── Método 2: exec(sqlite3) como reserva ──────────────────
    $disabled = array_map('trim', explode(',', (string)(ini_get('disable_functions') ?: '')));
    $canExec  = function_exists('exec') && function_exists('escapeshellarg')
                && !in_array('exec', $disabled, true)
                && !in_array('escapeshellarg', $disabled, true);
    if ($canExec) {
        exec('sqlite3 ' . escapeshellarg($db) . ' < ' . escapeshellarg($schema) . ' 2>&1', $out, $code);
        if ($code === 0) {
            $log[] = ['ok' => true, 'msg' => 'Base de datos creada con sqlite3 CLI.'];
            return true;
        }
        $log[] = ['ok' => false, 'msg' => 'sqlite3 CLI: ' . trim(implode(' ', $out))];
    }

    return false;
}

function extraFiles(): array
{
    $skip  = [INSTALLER, '.', '..'];
    $files = [];
    foreach ((array) scandir(INSTALL_DIR) as $f) {
        if (!in_array($f, $skip, true)) {
            $files[] = $f;
        }
    }
    return $files;
}

function fetchJson(string $url): ?array
{
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_USERAGENT      => UA,
            CURLOPT_HTTPHEADER     => ['Accept: application/vnd.github+json'],
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if (!$body || $code !== 200) {
            return null;
        }
    } else {
        $ctx  = stream_context_create(['http' => [
            'method'  => 'GET',
            'header'  => 'User-Agent: ' . UA . "\r\nAccept: application/vnd.github+json\r\n",
            'timeout' => 15,
        ]]);
        $body = @file_get_contents($url, false, $ctx);
        if (!$body) {
            return null;
        }
    }
    return json_decode((string) $body, true) ?: null;
}

function downloadFile(string $url, string $dest): bool
{
    if (function_exists('curl_init')) {
        $fp = @fopen($dest, 'wb');
        if (!$fp) {
            return false;
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_FILE           => $fp,
            CURLOPT_TIMEOUT        => 180,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 10,
            CURLOPT_USERAGENT      => UA,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        fclose($fp);
        return $code === 200 && filesize($dest) > 100;
    }
    $ctx  = stream_context_create(['http' => [
        'method'          => 'GET',
        'header'          => 'User-Agent: ' . UA . "\r\n",
        'timeout'         => 180,
        'follow_location' => 1,
    ]]);
    $data = @file_get_contents($url, false, $ctx);
    if (!$data || strlen($data) < 100) {
        return false;
    }
    return file_put_contents($dest, $data) !== false;
}

// ── Comprobaciones del sistema ────────────────────────────────

function systemChecks(): array
{
    $checks = [];

    // PHP version
    $checks[] = [
        'group'    => 'PHP',
        'label'    => 'PHP ' . PHP_VERSION,
        'req'      => 'PHP 8+ recomendado',
        'ok'       => PHP_MAJOR_VERSION >= 8,
        'required' => true,
    ];

    // Extensiones
    foreach (['pdo_sqlite', 'sqlite3', 'fileinfo', 'xmlwriter', 'zip', 'gd'] as $ext) {
        $checks[] = [
            'group'    => 'Extensiones',
            'label'    => $ext,
            'req'      => 'extension_loaded',
            'ok'       => extension_loaded($ext),
            'required' => true,
        ];
    }

    // Apache mod_rewrite (heurística; null = no determinado)
    $mrOk = null;
    if (function_exists('apache_get_modules')) {
        $mrOk = in_array('mod_rewrite', apache_get_modules(), true);
    } elseif (!empty($_SERVER['HTTP_MOD_REWRITE'])) {
        $mrOk = strtolower($_SERVER['HTTP_MOD_REWRITE']) === 'on';
    }
    $checks[] = [
        'group'    => 'Servidor',
        'label'    => 'Apache mod_rewrite',
        'req'      => 'Rutas amigables de episodios',
        'ok'       => $mrOk,
        'required' => false,
    ];

    // PharData — extracción de .tar.gz sin exec() (recomendado)
    $pharOk = class_exists('PharData') && extension_loaded('phar');
    $checks[] = [
        'group'    => 'Servidor',
        'label'    => 'Extensión phar (PharData)',
        'req'      => 'Extracción del paquete sin exec()',
        'ok'       => $pharOk,
        'required' => false, // exec() es alternativa válida
    ];

    // exec() + escapeshellarg — alternativa de extracción si PharData no está disponible
    $disabled = array_map('trim', explode(',', (string)(ini_get('disable_functions') ?: '')));
    $execOk   = function_exists('exec') && function_exists('escapeshellarg')
                && !in_array('exec', $disabled, true)
                && !in_array('escapeshellarg', $disabled, true);
    $checks[] = [
        'group'    => 'Servidor',
        'label'    => 'Función exec() — alternativa',
        'req'      => 'Solo necesaria si PharData no está disponible',
        'ok'       => $execOk,
        'required' => false,
    ];

    // Al menos uno de los dos métodos de extracción debe estar disponible
    $checks[] = [
        'group'    => 'Servidor',
        'label'    => 'Método de extracción disponible',
        'req'      => 'PharData o exec(tar)',
        'ok'       => $pharOk || $execOk,
        'required' => true,
    ];

    // Directorio escribible
    $checks[] = [
        'group'    => 'Permisos',
        'label'    => 'Directorio escribible',
        'req'      => e(INSTALL_DIR),
        'ok'       => is_writable(INSTALL_DIR),
        'required' => true,
    ];

    return $checks;
}

function canProceed(array $checks): bool
{
    foreach ($checks as $c) {
        if ($c['required'] && $c['ok'] === false) {
            return false;
        }
    }
    return true;
}

// ── Lógica de instalación ─────────────────────────────────────

function runInstall(bool $deleteExtra): array
{
    $log   = [];
    $error = '';

    // 1. Borrar archivos previos
    if ($deleteExtra) {
        foreach (extraFiles() as $f) {
            deleteRecursive(INSTALL_DIR . DIRECTORY_SEPARATOR . $f);
        }
        $log[] = ['ok' => true, 'msg' => 'Archivos previos eliminados.'];
    }

    // 2. Obtener info de la última release
    $release = fetchJson(GH_API);
    if (!$release || !isset($release['tag_name'])) {
        return ['ok' => false, 'error' => 'No se pudo obtener la información de GitHub.', 'log' => $log];
    }

    $version = ltrim((string) $release['tag_name'], 'v');
    $tarUrl  = '';
    foreach ($release['assets'] ?? [] as $asset) {
        if (str_ends_with((string)($asset['name'] ?? ''), '.tar.gz')) {
            $tarUrl = (string)$asset['browser_download_url'];
            break;
        }
    }
    if (!$tarUrl) {
        $tarUrl = (string)($release['tarball_url'] ?? '');
    }
    if (!$tarUrl) {
        return ['ok' => false, 'error' => 'No se encontró el archivo .tar.gz en la release.', 'log' => $log];
    }

    $log[] = ['ok' => true, 'msg' => 'Release detectada: v' . $version];

    // 3. Descargar
    $tmpFile = INSTALL_DIR . '/ep_install.tar.gz';
    if (!downloadFile($tarUrl, $tmpFile)) {
        @unlink($tmpFile);
        return ['ok' => false, 'error' => 'No se pudo descargar el paquete de instalación.', 'log' => $log];
    }
    $log[] = ['ok' => true, 'msg' => 'Paquete descargado (' . round(filesize($tmpFile) / 1024) . ' KB).'];

    // 4. Extraer (PharData preferido; exec como reserva)
    $extracted = extractTarGz($tmpFile, INSTALL_DIR, $log);

    // 5. Borrar el tar.gz siempre
    @unlink($tmpFile);

    if (!$extracted) {
        return ['ok' => false, 'error' => 'No se pudo extraer el paquete (PharData y exec fallaron).', 'log' => $log];
    }

    // 6. Crear la base de datos (PDO preferido; sqlite3 CLI como reserva)
    $schema = INSTALL_DIR . '/schema.sql';
    $db     = INSTALL_DIR . '/podcast.sqlite';

    if (!file_exists($schema)) {
        return ['ok' => false, 'error' => 'schema.sql no encontrado tras la extracción.', 'log' => $log];
    }

    if (!createDatabase($schema, $db, $log)) {
        return ['ok' => false, 'error' => 'No se pudo crear la base de datos.', 'log' => $log];
    }

    // 7. Crear directorios de medios
    @mkdir(INSTALL_DIR . '/audios', 0755, true);
    @mkdir(INSTALL_DIR . '/images', 0755, true);
    $log[] = ['ok' => true, 'msg' => 'Directorios audios/ e images/ creados.'];

    return ['ok' => true, 'error' => '', 'log' => $log];
}

// ── Despacho de pasos ─────────────────────────────────────────

$step   = (string)($_GET['step'] ?? '1');
$done   = isset($_GET['done']);
$action = (string)($_POST['action'] ?? '');
$result = null;

if ($step === '3' && $action === 'install') {
    $result = runInstall(!empty($_POST['confirm_delete']));
    if ($result['ok']) {
        header('Location: ' . INSTALLER . '?done=1');
        exit;
    }
}

$checks  = systemChecks();
$canGo   = canProceed($checks);
$extras  = ($step === '2') ? extraFiles() : [];

// ── HTML ──────────────────────────────────────────────────────

$currentStep = $done ? 4 : (int)$step;

?><!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>EasyPodcast — Instalador</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Fraunces:ital,opsz,wght@0,9..144,400;0,9..144,600;0,9..144,700&family=Figtree:wght@400;500;600;700&display=swap" rel="stylesheet">
  <style>
    /* ── Variables (paleta EasyPodcast) ── */
    :root {
      --bg:          #f5f2eb;
      --card:        #fffefb;
      --text:        #1d2a33;
      --muted:       #6b6560;
      --accent:      #b5470e;
      --accent-d:    #8f3308;
      --danger:      #b02a37;
      --ok:          #2f855a;
      --warn:        #92400e;
      --border:      #d9d2c3;
      --f-display:   'Fraunces', Georgia, serif;
      --f-body:      'Figtree', system-ui, sans-serif;
      --radius:      14px;
    }
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    body {
      font-family: var(--f-body);
      background: var(--bg);
      background-image:
        radial-gradient(ellipse at 10% 0%,   rgba(181,71,14,.06) 0%, transparent 50%),
        radial-gradient(ellipse at 90% 100%, rgba(181,71,14,.03) 0%, transparent 40%);
      color: var(--text);
      min-height: 100vh;
      padding: 2.5rem 1rem 3rem;
    }
    /* ── Layout ── */
    .wrap {
      max-width: 680px;
      margin: 0 auto;
    }
    /* ── Brand ── */
    .brand {
      text-align: center;
      margin-bottom: 2rem;
    }
    .brand-title {
      font-family: var(--f-display);
      font-size: 2.1rem;
      font-weight: 700;
      color: var(--accent);
      letter-spacing: -.035em;
      display: block;
      margin-bottom: .2rem;
    }
    .brand-sub {
      color: var(--muted);
      font-size: .9rem;
    }
    /* ── Stepper ── */
    .stepper {
      display: flex;
      align-items: center;
      justify-content: center;
      gap: 0;
      margin-bottom: 1.75rem;
    }
    .step-item {
      display: flex;
      align-items: center;
      gap: .45rem;
      font-size: .82rem;
      font-weight: 600;
      color: var(--muted);
    }
    .step-item.active { color: var(--accent); }
    .step-item.done   { color: var(--ok); }
    .step-dot {
      width: 1.7rem;
      height: 1.7rem;
      border-radius: 50%;
      background: var(--border);
      color: var(--muted);
      font-size: .72rem;
      font-weight: 700;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      flex-shrink: 0;
      transition: background .15s, color .15s;
    }
    .step-item.active .step-dot { background: var(--accent); color: #fff; }
    .step-item.done   .step-dot { background: var(--ok);     color: #fff; }
    .step-sep {
      width: 2.5rem;
      height: 1px;
      background: var(--border);
      margin: 0 .1rem;
      flex-shrink: 0;
    }
    /* ── Card ── */
    .card {
      background: var(--card);
      border: 1px solid var(--border);
      border-radius: var(--radius);
      padding: 1.75rem;
      box-shadow: 0 2px 20px rgba(0,0,0,.06);
      margin-bottom: 1rem;
    }
    .card-title {
      font-family: var(--f-display);
      font-size: 1.2rem;
      font-weight: 600;
      letter-spacing: -.015em;
      margin-bottom: 1.1rem;
      display: flex;
      align-items: center;
      gap: .5rem;
    }
    /* ── Check list ── */
    .check-list {
      list-style: none;
      display: grid;
      gap: .4rem;
    }
    .check-item {
      display: grid;
      grid-template-columns: 1.4rem 1fr auto;
      align-items: center;
      gap: .5rem;
      padding: .5rem .8rem;
      border-radius: 8px;
      border: 1px solid var(--border);
      background: var(--bg);
      font-size: .875rem;
    }
    .ci-icon {
      font-size: 1rem;
      line-height: 1;
      font-style: normal;
    }
    .ci-ok   { color: var(--ok);     }
    .ci-fail { color: var(--danger); }
    .ci-warn { color: #b45309;       }
    .ci-label { flex: 1; font-weight: 500; }
    .ci-req   { font-size: .78rem; color: var(--muted); text-align: right; }
    /* ── Group label ── */
    .group-label {
      font-size: .73rem;
      text-transform: uppercase;
      letter-spacing: .08em;
      color: var(--muted);
      font-weight: 700;
      margin: .9rem 0 .35rem;
    }
    .group-label:first-child { margin-top: 0; }
    /* ── Alert boxes ── */
    .box {
      border-radius: 8px;
      padding: .75rem 1rem;
      font-size: .9rem;
      margin-bottom: 1rem;
      border: 1px solid;
    }
    .box.ok   { background: #f0fdf4; color: #166534; border-color: #86efac; }
    .box.fail { background: #fff0f0; color: #9b1c1c; border-color: #fca5a5; }
    .box.warn { background: #fffbeb; color: var(--warn); border-color: #fcd34d; }
    /* ── File list ── */
    .file-list {
      list-style: none;
      display: grid;
      gap: .3rem;
      margin: .75rem 0 1rem;
    }
    .file-list li {
      padding: .38rem .75rem;
      background: var(--bg);
      border: 1px solid var(--border);
      border-radius: 6px;
      font-family: monospace;
      font-size: .85rem;
      color: var(--muted);
    }
    .file-list .is-dir { color: var(--accent); }
    /* ── Install log ── */
    .install-log {
      list-style: none;
      display: grid;
      gap: .35rem;
      margin: .5rem 0 1rem;
    }
    .log-item {
      display: flex;
      align-items: center;
      gap: .55rem;
      padding: .45rem .8rem;
      border-radius: 7px;
      border: 1px solid var(--border);
      background: var(--bg);
      font-size: .875rem;
    }
    .log-icon { font-style: normal; font-size: .95rem; flex-shrink: 0; }
    /* ── Buttons ── */
    .actions { display: flex; gap: .65rem; flex-wrap: wrap; margin-top: 1.25rem; }
    .btn {
      display: inline-flex;
      align-items: center;
      gap: .4rem;
      background: var(--accent);
      color: #fff;
      border: 0;
      border-radius: 8px;
      padding: .65rem 1.3rem;
      font-size: .9rem;
      font-weight: 600;
      font-family: inherit;
      cursor: pointer;
      text-decoration: none;
      transition: background .12s;
      line-height: 1;
    }
    .btn:hover { background: var(--accent-d); }
    .btn.danger { background: var(--danger); }
    .btn.danger:hover { background: #8b1c27; }
    .btn.success { background: var(--ok); }
    .btn.success:hover { background: #236035; }
    .btn.ghost {
      background: transparent;
      color: var(--muted);
      border: 1px solid var(--border);
    }
    .btn.ghost:hover { background: rgba(0,0,0,.04); color: var(--text); }
    /* ── Success hero ── */
    .success-hero {
      text-align: center;
      padding: .75rem 0 1.25rem;
    }
    .hero-icon { font-size: 3.5rem; display: block; margin-bottom: .6rem; line-height: 1; }
    .hero-title {
      font-family: var(--f-display);
      font-size: 1.55rem;
      font-weight: 600;
      color: var(--ok);
      margin-bottom: .35rem;
    }
    .hero-sub { color: var(--muted); font-size: .92rem; }
    /* ── Footer ── */
    .foot {
      text-align: center;
      color: var(--muted);
      font-size: .8rem;
      margin-top: .75rem;
    }
    @media (max-width: 520px) {
      body { padding: 1.25rem .75rem 2rem; }
      .card { padding: 1.1rem; }
      .stepper { gap: 0; }
      .step-sep { width: 1.5rem; }
      .step-item span:last-child { display: none; }
    }
  </style>
</head>
<body>
<div class="wrap">

  <!-- Brand -->
  <div class="brand">
    <span class="brand-title">EasyPodcast</span>
    <span class="brand-sub">Instalador automático · GitHub Releases</span>
  </div>

  <!-- Stepper -->
  <?php
  $steps = ['Compatibilidad', 'Directorio', 'Instalación'];
  echo '<div class="stepper">';
  foreach ($steps as $i => $label) {
      $n = $i + 1;
      if ($n > 1) echo '<div class="step-sep"></div>';
      $cls = '';
      if ($done || ($currentStep > $n)) {
          $cls  = 'done';
          $icon = '✓';
      } elseif ($currentStep === $n) {
          $cls  = 'active';
          $icon = (string)$n;
      } else {
          $icon = (string)$n;
      }
      echo '<div class="step-item ' . $cls . '">'
          . '<span class="step-dot">' . $icon . '</span>'
          . '<span>' . e($label) . '</span>'
          . '</div>';
  }
  echo '</div>';
  ?>

  <?php if ($done):
      // Intentar borrar el propio instalador (funciona en Linux aunque el script esté en ejecución)
      $selfDeleted = @unlink(__FILE__);
  ?>
  <!-- ══════════════════════════════════════════ -->
  <!-- ÉXITO                                     -->
  <!-- ══════════════════════════════════════════ -->
  <div class="card">
    <div class="success-hero">
      <span class="hero-icon">🎙️</span>
      <div class="hero-title">¡Instalación completada!</div>
      <p class="hero-sub">EasyPodcast se ha instalado correctamente.</p>
    </div>
    <?php if ($selfDeleted): ?>
      <div class="box ok">
        El archivo <code><?= e(INSTALLER) ?></code> ha sido eliminado automáticamente.
      </div>
    <?php else: ?>
      <div class="box warn">
        No se pudo eliminar <code><?= e(INSTALLER) ?></code> automáticamente.
        Bórralo manualmente del servidor por seguridad.
      </div>
    <?php endif; ?>
    <div class="actions" style="justify-content: center;">
      <a class="btn success" href="/admin.php">Ir al panel de administración →</a>
    </div>
  </div>

  <?php elseif ($step === '3' && $result !== null && !$result['ok']): ?>
  <!-- ══════════════════════════════════════════ -->
  <!-- ERROR DE INSTALACIÓN                      -->
  <!-- ══════════════════════════════════════════ -->
  <div class="card">
    <div class="card-title">Instalación</div>
    <div class="box fail"><?= e($result['error']) ?></div>
    <?php if (!empty($result['log'])): ?>
      <ul class="install-log">
        <?php foreach ($result['log'] as $entry): ?>
          <li class="log-item">
            <i class="log-icon <?= $entry['ok'] ? 'ci-ok' : 'ci-fail' ?>"><?= $entry['ok'] ? '✓' : '✗' ?></i>
            <?= e($entry['msg']) ?>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
    <div class="actions">
      <a class="btn ghost" href="?step=2">← Volver</a>
    </div>
  </div>

  <?php elseif ($step === '2'): ?>
  <!-- ══════════════════════════════════════════ -->
  <!-- PASO 2: DIRECTORIO                        -->
  <!-- ══════════════════════════════════════════ -->
  <div class="card">
    <div class="card-title">Directorio de instalación</div>

    <?php if (empty($extras)): ?>
      <div class="box ok">El directorio está vacío. Listo para instalar.</div>
      <form method="post" action="?step=3">
        <input type="hidden" name="action" value="install">
        <div class="actions">
          <button class="btn" type="submit">Instalar EasyPodcast →</button>
        </div>
      </form>

    <?php else: ?>
      <div class="box warn">
        Se encontraron <strong><?= count($extras) ?></strong> elemento(s) en el directorio.
        Deben eliminarse antes de continuar.
      </div>
      <ul class="file-list">
        <?php foreach ($extras as $f): ?>
          <li class="<?= is_dir(INSTALL_DIR . DIRECTORY_SEPARATOR . $f) ? 'is-dir' : '' ?>">
            <?= e($f) ?><?= is_dir(INSTALL_DIR . DIRECTORY_SEPARATOR . $f) ? '/' : '' ?>
          </li>
        <?php endforeach; ?>
      </ul>
      <form method="post" action="?step=3"
            onsubmit="return confirm('Se eliminarán todos los archivos listados.\n¿Confirmas la instalación?');">
        <input type="hidden" name="action" value="install">
        <input type="hidden" name="confirm_delete" value="1">
        <div class="actions">
          <button class="btn danger" type="submit">Borrar todo e instalar →</button>
          <a class="btn ghost" href="?step=1">Cancelar</a>
        </div>
      </form>
    <?php endif; ?>
  </div>

  <?php else: ?>
  <!-- ══════════════════════════════════════════ -->
  <!-- PASO 1: COMPROBACIONES DEL SISTEMA        -->
  <!-- ══════════════════════════════════════════ -->
  <div class="card">
    <div class="card-title">Compatibilidad del sistema</div>

    <?php
    $lastGroup = '';
    foreach ($checks as $c) {
        if ($c['group'] !== $lastGroup) {
            echo '<p class="group-label">' . e($c['group']) . '</p>';
            $lastGroup = $c['group'];
        }

        if ($c['ok'] === true) {
            $iconClass = 'ci-ok';
            $icon      = '✓';
        } elseif ($c['ok'] === false) {
            $iconClass = 'ci-fail';
            $icon      = '✗';
        } else {
            // null → no determinado (mod_rewrite)
            $iconClass = 'ci-warn';
            $icon      = '?';
        }
        echo '<ul class="check-list" style="margin-bottom:.4rem;">'
            . '<li class="check-item">'
            . '<i class="ci-icon ' . $iconClass . '">' . $icon . '</i>'
            . '<span class="ci-label">' . e($c['label']) . '</span>'
            . '<span class="ci-req">'  . e($c['req'])   . '</span>'
            . '</li></ul>';
    }
    ?>

    <?php if ($canGo): ?>
      <div class="box ok" style="margin-top:1rem;">
        Todas las comprobaciones obligatorias han pasado.
      </div>
      <div class="actions">
        <a class="btn" href="?step=2">Continuar →</a>
      </div>
    <?php else: ?>
      <div class="box fail" style="margin-top:1rem;">
        Resuelve los errores marcados en rojo antes de continuar.
      </div>
    <?php endif; ?>
  </div>
  <?php endif; ?>

  <p class="foot">EasyPodcast &mdash; instalador automático</p>
</div>
</body>
</html>
