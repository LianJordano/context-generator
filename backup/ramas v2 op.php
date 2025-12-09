<?php
// index.php - Unificador Ultra PRO v2
// Características: UI Dark mode, SSE Streaming, Selección precisa de archivos/carpetas raíz, Exclusiones dinámicas.
// Requisitos: PHP 7.0+, permisos de escritura en carpeta temporal.

ini_set('memory_limit', '4096M');
ini_set('max_execution_time', '0');
set_time_limit(0);
date_default_timezone_set('America/Bogota');

# -------------------------
# CONFIGURACIÓN POR DEFECTO
# -------------------------
$DEFAULT_EXCLUDE_EXT = ['jpg','jpeg','png','gif','webp','svg','ico','mp4','mp3','log','zip','tar','gz','rar','pdf','exe','dll'];
$DEFAULT_EXCLUDE_FOLDERS = ['vendor','node_modules','storage','bootstrap/cache','.git','.idea','.vscode','dist','build','coverage'];
$DEFAULT_EXCLUDE_FILES = ['.env', '.DS_Store', 'Thumbs.db', 'composer.lock', 'package-lock.json', 'yarn.lock'];

# -------------------------
# LÓGICA DEL SERVIDOR
# -------------------------
$action = $_GET['action'] ?? '';

// 1. LISTAR CONTENIDO (AJUSTADO: Escanea el nivel 1 real de la carpeta base)
if ($action === 'list') {
    $base = rtrim($_GET['base'] ?? __DIR__, '/\\');
    
    if (!is_dir($base) || !is_readable($base)) {
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Directorio no accesible o no existe', 'base' => $base]);
        exit;
    }
    
    header('Content-Type: application/json');

    $items = [];
    try {
        // Usamos DirectoryIterator para ver exactamente qué hay en la raíz (no recursivo aquí)
        $iterator = new DirectoryIterator($base);
        foreach ($iterator as $fileinfo) {
            if ($fileinfo->isDot()) continue;
            
            $items[] = [
                'name' => $fileinfo->getFilename(),
                'type' => $fileinfo->isDir() ? 'dir' : 'file',
                'size' => $fileinfo->isDir() ? '-' : formatBytes($fileinfo->getSize())
            ];
        }
        
        // Ordenar: Carpetas primero, luego archivos, alfabéticamente
        usort($items, function($a, $b) {
            if ($a['type'] === $b['type']) {
                return strnatcasecmp($a['name'], $b['name']);
            }
            return $a['type'] === 'dir' ? -1 : 1;
        });

    } catch (Exception $e) {
        echo json_encode(['error' => $e->getMessage(), 'base' => $base]);
        exit;
    }
    
    echo json_encode(['base' => $base, 'items' => $items], JSON_PRETTY_PRINT);
    exit;
}

// 2. DESCARGAR ARCHIVO GENERADO
if ($action === 'download') {
    $token = $_GET['token'] ?? '';
    // Sanitizar token para evitar transversal path traversal básico
    $token = preg_replace('/[^a-z0-9_-]/i', '', $token);
    $tmpdir = sys_get_temp_dir();
    $file = $tmpdir . DIRECTORY_SEPARATOR . "unified_{$token}.txt";
    
    if (!$token || !file_exists($file)) { 
        http_response_code(404); 
        echo "Archivo no encontrado o expirado."; 
        exit; 
    }
    
    header('Content-Description: File Transfer');
    header('Content-Type: text/plain');
    header('Content-Disposition: attachment; filename="proyecto_unificado_' . $token . '.txt"');
    header('Expires: 0');
    header('Cache-Control: must-revalidate');
    header('Pragma: public');
    header('Content-Length: ' . filesize($file));
    readfile($file);
    exit;
}

// 3. CANCELAR PROCESO
if ($action === 'cancel') {
    $token = $_GET['token'] ?? '';
    $token = preg_replace('/[^a-z0-9_-]/i', '', $token);
    if ($token) {
        $tmpdir = sys_get_temp_dir();
        file_put_contents($tmpdir . DIRECTORY_SEPARATOR . "cancel_{$token}.flag", "1");
        echo json_encode(['status' => 'cancelling']);
    }
    exit;
}

// 4. PROCESAR (SSE STREAMING)
if ($action === 'process') {
    $base = rtrim($_GET['base'] ?? __DIR__, '/\\');
    
    // Config headers para Server Sent Events
    header('Content-Type: text/event-stream');
    header('Cache-Control: no-cache');
    header('X-Accel-Buffering: no'); // Nginx fix
    
    if (!is_dir($base) || !is_readable($base)) {
        sse_emit('error', "Directorio no accesible: {$base}");
        sse_emit('done', ['status' => 'error']);
        exit;
    }

    // Parámetros
    // selected_roots: array con los nombres de archivos/carpetas del nivel 1 que el usuario eligió
    $selectedRoots = $_GET['roots'] ?? []; 
    if (!is_array($selectedRoots)) $selectedRoots = [];

    $ex_ext = array_filter(array_map('trim', explode(',', $_GET['exclude_ext'] ?? '')));
    $ex_folders = array_filter(array_map('trim', explode(',', $_GET['exclude_folders'] ?? '')));
    $ex_files = array_filter(array_map('trim', explode(',', $_GET['exclude_files'] ?? '')));
    $token = preg_replace('/[^a-z0-9_-]/i', '', ($_GET['token'] ?? bin2hex(random_bytes(6))));

    // Limpiar buffers
    while (ob_get_level() > 0) ob_end_flush();
    flush();

    // Archivos temporales
    $tmpdir = sys_get_temp_dir();
    $outFile = $tmpdir . DIRECTORY_SEPARATOR . "unified_{$token}.txt";
    $cancelFlag = $tmpdir . DIRECTORY_SEPARATOR . "cancel_{$token}.flag";
    
    if (file_exists($cancelFlag)) @unlink($cancelFlag);
    if (file_exists($outFile)) @unlink($outFile);

    sse_emit('started', ['token' => $token, 'base' => $base]);

    // --- FASE 1: INDEXADO (Recursivo filtrado) ---
    $index = [];
    try {
        $dirIter = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS | FilesystemIterator::FOLLOW_SYMLINKS),
            RecursiveIteratorIterator::SELF_FIRST
        );
    } catch (Exception $e) {
        sse_emit('error', "Error abriendo directorio: " . $e->getMessage());
        sse_emit('done', ['status' => 'error']);
        exit;
    }

    $filesToProcess = [];

    foreach ($dirIter as $file) {
        // Verificar cancelación durante el indexado (por si es muy grande)
        if (file_exists($cancelFlag)) {
            sse_emit('cancelled', ['processed' => 0, 'total' => 0]);
            exit;
        }

        // Obtener ruta relativa limpia
        $pathAbs = $file->getPathname();
        $rel = ltrim(substr($pathAbs, strlen($base)), '/\\');
        
        // Normalizar separadores a '/' para comparaciones
        $relUnified = str_replace('\\', '/', $rel);
        $pathParts = explode('/', $relUnified);
        $rootSegment = $pathParts[0] ?? '';

        // 1. Filtro de Raíz (Lo que el usuario seleccionó en el checkbox)
        // Si el usuario no seleccionó nada, asumimos que quiere TODO.
        // Si seleccionó algo, verificamos que el rootSegment esté en la lista.
        if (!empty($selectedRoots) && !in_array($rootSegment, $selectedRoots)) {
            continue; // Saltar si no está en la selección raíz
        }

        // 2. Exclusión de Carpetas (Globales, ej: node_modules en cualquier nivel)
        // Verificamos si algún segmento del path está en la lista negra de carpetas
        $isExcludedFolder = false;
        foreach ($pathParts as $part) {
            if (in_array($part, $ex_folders)) {
                $isExcludedFolder = true;
                break;
            }
        }
        if ($isExcludedFolder) continue;

        // Si es directorio, no lo añadimos a la lista de "archivos a leer", solo continuamos escaneando
        if ($file->isDir()) continue;

        // 3. Exclusión de Archivos específicos
        if (in_array($file->getFilename(), $ex_files)) continue;

        // 4. Exclusión de Extensiones
        $ext = strtolower($file->getExtension());
        if ($ext !== '' && in_array($ext, $ex_ext)) continue;

        // ¡Pasó los filtros!
        $filesToProcess[] = $rel;
    }

    $total = count($filesToProcess);
    sse_emit('indexed', ['count' => $total]);

    if ($total === 0) {
        sse_emit('done', ['status' => 'empty', 'token' => $token]);
        exit;
    }

    // --- FASE 2: ESCRITURA ---
    $handleOut = fopen($outFile, 'w');
    if (!$handleOut) {
        sse_emit('error', 'No se puede escribir en: ' . $outFile);
        sse_emit('done', ['status' => 'error']);
        exit;
    }

    // Cabecera del archivo unificado
    fwrite($handleOut, "################################################################################\n");
    fwrite($handleOut, "# PROYECTO UNIFICADO GENERADO AUTOMÁTICAMENTE\n");
    fwrite($handleOut, "# FECHA: " . date('Y-m-d H:i:s') . "\n");
    fwrite($handleOut, "# ARCHIVOS INCLUIDOS: {$total}\n");
    fwrite($handleOut, "################################################################################\n\n");
    
    // Tabla de contenidos
    fwrite($handleOut, "# --- ÍNDICE DE ARCHIVOS ---\n");
    foreach ($filesToProcess as $fPath) {
        fwrite($handleOut, "# {$fPath}\n");
    }
    fwrite($handleOut, "\n# ------------------------------------------------------------------------------\n\n");
    fflush($handleOut);

    $processed = 0;
    foreach ($filesToProcess as $relPath) {
        // Check cancel
        if (file_exists($cancelFlag)) {
            fclose($handleOut);
            sse_emit('cancelled', ['processed' => $processed, 'total' => $total]);
            exit;
        }

        $absPath = $base . DIRECTORY_SEPARATOR . $relPath;
        $processed++;
        $pct = round(($processed / $total) * 100);

        sse_emit('filestart', ['file' => $relPath, 'index' => $processed, 'total' => $total, 'pct' => $pct]);

        fwrite($handleOut, str_repeat("=", 80) . "\n");
        fwrite($handleOut, "START_FILE: {$relPath}\n");
        fwrite($handleOut, str_repeat("=", 80) . "\n");

        try {
            $fobj = new SplFileObject($absPath, 'r');
            // Copia eficiente línea a línea
            while (!$fobj->eof()) {
                fwrite($handleOut, $fobj->fgets());
            }
            // Asegurar salto de línea al final
            fwrite($handleOut, "\n"); 
            fwrite($handleOut, "END_FILE: {$relPath}\n\n");
            
            // Liberar memoria del objeto
            $fobj = null; 
            
            // Flush periódico para no llenar memoria de PHP
            if ($processed % 10 === 0) fflush($handleOut);

        } catch (Exception $e) {
            fwrite($handleOut, "\n[ERROR LEYENDO ARCHIVO: " . $e->getMessage() . "]\n\n");
            sse_emit('fileerror', ['file' => $relPath, 'msg' => $e->getMessage()]);
        }

        sse_emit('filedone', ['file' => $relPath, 'pct' => $pct]);
        
        // Pequeña pausa para no saturar navegador si son archivos muy pequeños
        if ($total < 1000) usleep(5000); 
    }

    fclose($handleOut);

    $downloadUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http')
        . '://' . $_SERVER['HTTP_HOST'] . $_SERVER['PHP_SELF']
        . '?action=download&token=' . $token;

    sse_emit('done', ['status' => 'ok', 'download' => $downloadUrl]);
    exit;
}

// Helpers
function formatBytes($bytes, $precision = 2) { 
    if ($bytes === 0) return '0 B';
    $units = array('B', 'KB', 'MB', 'GB', 'TB'); 
    $bytes = max($bytes, 0); 
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024)); 
    $pow = min($pow, count($units) - 1); 
    $bytes /= pow(1024, $pow); 
    return round($bytes, $precision) . ' ' . $units[$pow]; 
}

function sse_emit($event, $data) {
    echo "event: {$event}\n";
    echo "data: " . (is_array($data) ? json_encode($data) : $data) . "\n\n";
    while (ob_get_level() > 0) ob_end_flush();
    flush();
}

# -------------------------
# INTERFAZ GRÁFICA (HTML/JS)
# -------------------------
$baseDefault = str_replace('\\', '/', __DIR__);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Unificador Ultra PRO v2</title>
    <style>
        :root {
            --bg-dark: #0d1117;
            --bg-panel: #161b22;
            --border: #30363d;
            --text-main: #c9d1d9;
            --text-muted: #8b949e;
            --accent: #238636;
            --accent-hover: #2ea043;
            --danger: #da3633;
            --highlight: #1f6feb;
        }
        body { margin: 0; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Helvetica, Arial, sans-serif; background: var(--bg-dark); color: var(--text-main); height: 100vh; display: flex; flex-direction: column; }
        * { box-sizing: border-box; }
        
        /* Layout Grid */
        .app-container { display: grid; grid-template-columns: 400px 1fr; grid-template-rows: auto 1fr; gap: 1rem; padding: 1rem; height: 100%; max-width: 1600px; margin: 0 auto; width: 100%; }
        
        /* Header */
        .header { grid-column: 1 / -1; display: flex; justify-content: space-between; align-items: center; padding: 0.5rem 0; border-bottom: 1px solid var(--border); }
        .logo { font-weight: bold; font-size: 1.2rem; display: flex; align-items: center; gap: 10px; }
        .logo span { background: var(--highlight); padding: 2px 8px; border-radius: 4px; font-size: 0.8rem; color: #fff; }

        /* Panels */
        .panel { background: var(--bg-panel); border: 1px solid var(--border); border-radius: 6px; display: flex; flex-direction: column; overflow: hidden; }
        .panel-header { padding: 10px 15px; background: rgba(255,255,255,0.03); border-bottom: 1px solid var(--border); font-weight: 600; font-size: 0.9rem; display: flex; justify-content: space-between; align-items: center; }
        .panel-body { padding: 15px; overflow-y: auto; flex: 1; }

        /* Inputs & Controls */
        input[type="text"], select { width: 100%; background: #0d1117; border: 1px solid var(--border); color: var(--text-main); padding: 8px; border-radius: 4px; font-size: 0.9rem; outline: none; }
        input[type="text"]:focus { border-color: var(--highlight); }
        
        .btn { cursor: pointer; border: 1px solid rgba(240,246,252,0.1); border-radius: 6px; padding: 6px 14px; font-size: 13px; font-weight: 500; background: #21262d; color: var(--text-main); transition: 0.2s; }
        .btn:hover { background: #30363d; }
        .btn-primary { background: var(--accent); color: white; border-color: rgba(240,246,252,0.1); }
        .btn-primary:hover { background: var(--accent-hover); }
        .btn-danger { color: var(--danger); border-color: var(--border); }
        .btn-danger:hover { background: rgba(218,54,51,0.1); }
        .btn:disabled { opacity: 0.5; cursor: not-allowed; }

        .row { display: flex; gap: 8px; margin-bottom: 10px; }
        .flex-center { display: flex; align-items: center; }

        /* File Tree Selector */
        .file-selector { border: 1px solid var(--border); border-radius: 4px; background: #0d1117; height: 300px; overflow-y: auto; padding: 5px; }
        .fs-item { display: flex; align-items: center; padding: 4px 6px; border-radius: 3px; cursor: pointer; user-select: none; }
        .fs-item:hover { background: rgba(255,255,255,0.05); }
        .fs-item input { margin-right: 8px; }
        .fs-icon { margin-right: 6px; width: 16px; text-align: center; }
        .fs-name { flex: 1; font-size: 0.9rem; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .fs-meta { font-size: 0.75rem; color: var(--text-muted); margin-left: 10px; }
        .type-dir { color: #58a6ff; }
        .type-file { color: #8b949e; }

        /* Progress Bar */
        .progress-container { margin-top: 15px; }
        .progress-bar { height: 10px; background: #21262d; border-radius: 10px; overflow: hidden; margin-bottom: 5px; }
        .progress-fill { height: 100%; background: var(--highlight); width: 0%; transition: width 0.2s; }
        .progress-text { font-size: 0.8rem; color: var(--text-muted); display: flex; justify-content: space-between; }

        /* Terminal Logs */
        .terminal { font-family: 'SFMono-Regular', Consolas, 'Liberation Mono', Menlo, monospace; font-size: 12px; color: #e6edf3; }
        .log-entry { padding: 2px 0; border-bottom: 1px solid rgba(255,255,255,0.02); }
        .log-success { color: var(--accent); }
        .log-error { color: var(--danger); }
        .log-info { color: var(--highlight); }

        /* Settings area */
        .settings-group { margin-bottom: 15px; }
        .settings-label { display: block; font-size: 0.8rem; font-weight: 600; color: var(--text-muted); margin-bottom: 5px; }

        /* Helper badge */
        .badge { display: inline-block; padding: 2px 6px; font-size: 11px; border-radius: 10px; background: #30363d; color: #c9d1d9; }
    </style>
</head>
<body>

<div class="app-container">
    <div class="header">
        <div class="logo">Unificador Ultra <span>PRO v2</span></div>
        <div style="font-size: 0.85rem; color: var(--text-muted);">
            PHP <?php echo phpversion(); ?> | Mem: <?php echo ini_get('memory_limit'); ?>
        </div>
    </div>

    <!-- LEFT PANEL: CONFIG -->
    <div class="panel">
        <div class="panel-header">Configuración</div>
        <div class="panel-body">
            
            <div class="settings-group">
                <label class="settings-label">Carpeta Base (Ruta absoluta)</label>
                <div class="row">
                    <input type="text" id="basePath" value="<?php echo htmlspecialchars($baseDefault); ?>">
                    <button class="btn" id="btnLoad">Cargar</button>
                </div>
            </div>

            <div class="settings-group">
                <div style="display:flex; justify-content:space-between; margin-bottom:5px;">
                    <label class="settings-label">Selección (Raíz)</label>
                    <div>
                        <button class="btn" style="padding:2px 6px; font-size:10px;" id="btnAll">Todos</button>
                        <button class="btn" style="padding:2px 6px; font-size:10px;" id="btnNone">Ninguno</button>
                    </div>
                </div>
                <div class="file-selector" id="fileList">
                    <div style="padding:10px; color:var(--text-muted); text-align:center;">Carga una carpeta para ver su contenido...</div>
                </div>
                <div style="font-size:0.75rem; color:var(--text-muted); margin-top:5px;">
                    * Marca carpetas o archivos para incluirlos. Si no marcas nada, se procesará todo excepto exclusiones.
                </div>
            </div>

            <div class="settings-group">
                <label class="settings-label">Excluir Extensiones (csv)</label>
                <input type="text" id="exExt" value="<?php echo implode(',', $DEFAULT_EXCLUDE_EXT); ?>">
            </div>

            <div class="settings-group">
                <label class="settings-label">Excluir Nombres de Carpeta (Global)</label>
                <input type="text" id="exFolders" value="<?php echo implode(',', $DEFAULT_EXCLUDE_FOLDERS); ?>">
            </div>

            <div class="settings-group">
                <label class="settings-label">Excluir Nombres de Archivo</label>
                <input type="text" id="exFiles" value="<?php echo implode(',', $DEFAULT_EXCLUDE_FILES); ?>">
            </div>

            <div style="margin-top: 20px;">
                <button class="btn btn-primary" id="btnProcess" style="width:100%; padding: 10px; font-size:1rem;">🚀 UNIFICAR ARCHIVOS</button>
                <div class="row" style="margin-top:10px;">
                    <button class="btn btn-danger" id="btnCancel" style="width:50%" disabled>Cancelar</button>
                    <a id="btnDownload" class="btn" style="width:50%; text-align:center; display:none; background:#238636; color:white; text-decoration:none;">⬇ Descargar</a>
                </div>
            </div>

        </div>
    </div>

    <!-- RIGHT PANEL: LOGS & PROGRESS -->
    <div class="panel">
        <div class="panel-header">
            <span>Progreso & Logs</span>
            <span id="statusBadge" class="badge">Esperando</span>
        </div>
        <div class="panel-body" style="display:flex; flex-direction:column;">
            
            <div class="progress-container">
                <div class="progress-text">
                    <span id="progLabel">Listo para empezar</span>
                    <span id="progPercent">0%</span>
                </div>
                <div class="progress-bar">
                    <div class="progress-fill" id="progFill"></div>
                </div>
                <div style="font-size:0.8rem; color:var(--highlight); margin-top:4px;" id="currentFile">...</div>
            </div>

            <hr style="border:0; border-top:1px solid var(--border); width:100%; margin:15px 0;">

            <div class="terminal" id="terminal" style="flex:1; overflow-y:auto;">
                <!-- Logs go here -->
                <div class="log-entry">Bienvenido al Unificador Ultra PRO.</div>
                <div class="log-entry">Carga una carpeta en el panel izquierdo para comenzar.</div>
            </div>

        </div>
    </div>
</div>

<script>
// Referencias DOM
const basePathIn = document.getElementById('basePath');
const fileList = document.getElementById('fileList');
const terminal = document.getElementById('terminal');
const progFill = document.getElementById('progFill');
const progLabel = document.getElementById('progLabel');
const progPercent = document.getElementById('progPercent');
const currentFile = document.getElementById('currentFile');
const btnProcess = document.getElementById('btnProcess');
const btnCancel = document.getElementById('btnCancel');
const btnDownload = document.getElementById('btnDownload');
const statusBadge = document.getElementById('statusBadge');

let eventSource = null;
let currentToken = null;

// Logger
function log(msg, type = '') {
    const div = document.createElement('div');
    div.className = 'log-entry ' + (type ? 'log-'+type : '');
    const time = new Date().toLocaleTimeString().split(' ')[0];
    div.textContent = `[${time}] ${msg}`;
    terminal.appendChild(div);
    terminal.scrollTop = terminal.scrollHeight;
}

// Cargar Estructura
function loadStructure() {
    const path = basePathIn.value.trim();
    if(!path) return alert('Ingresa una ruta');

    fileList.innerHTML = '<div style="padding:10px;text-align:center;">Cargando...</div>';
    log(`Escaneando ruta: ${path}...`, 'info');

    fetch(`?action=list&base=${encodeURIComponent(path)}`)
    .then(r => r.json())
    .then(data => {
        if(data.error) {
            fileList.innerHTML = `<div style="padding:10px; color:var(--danger)">Error: ${data.error}</div>`;
            log(data.error, 'error');
            return;
        }

        fileList.innerHTML = '';
        if(data.items.length === 0) {
            fileList.innerHTML = '<div style="padding:10px;">Carpeta vacía.</div>';
            return;
        }

        data.items.forEach(item => {
            const row = document.createElement('label');
            row.className = 'fs-item';
            
            const icon = item.type === 'dir' ? '📁' : '📄';
            const typeClass = item.type === 'dir' ? 'type-dir' : 'type-file';

            row.innerHTML = `
                <input type="checkbox" value="${item.name}" class="root-chk">
                <span class="fs-icon ${typeClass}">${icon}</span>
                <span class="fs-name ${typeClass}">${item.name}</span>
                <span class="fs-meta">${item.size}</span>
            `;
            fileList.appendChild(row);
        });
        log(`Estructura cargada: ${data.items.length} elementos en raíz.`, 'success');
    })
    .catch(e => {
        log('Error de conexión al listar.', 'error');
        fileList.innerHTML = 'Error de conexión.';
    });
}

// Botones de selección
document.getElementById('btnLoad').addEventListener('click', loadStructure);
document.getElementById('btnAll').addEventListener('click', () => {
    document.querySelectorAll('.root-chk').forEach(c => c.checked = true);
});
document.getElementById('btnNone').addEventListener('click', () => {
    document.querySelectorAll('.root-chk').forEach(c => c.checked = false);
});

// Iniciar Proceso
btnProcess.addEventListener('click', () => {
    const path = basePathIn.value.trim();
    const exExt = document.getElementById('exExt').value;
    const exFolders = document.getElementById('exFolders').value;
    const exFiles = document.getElementById('exFiles').value;

    // Obtener seleccionados
    const checked = Array.from(document.querySelectorAll('.root-chk:checked')).map(c => c.value);
    
    // UI Reset
    terminal.innerHTML = '';
    btnProcess.disabled = true;
    btnCancel.disabled = false;
    btnDownload.style.display = 'none';
    progFill.style.width = '0%';
    statusBadge.textContent = 'Procesando...';
    statusBadge.style.background = 'var(--highlight)';
    statusBadge.style.color = '#fff';

    // Generar params
    const params = new URLSearchParams();
    params.set('action', 'process');
    params.set('base', path);
    params.set('exclude_ext', exExt);
    params.set('exclude_folders', exFolders);
    params.set('exclude_files', exFiles);
    
    // Pasar array de raíces seleccionadas
    checked.forEach(r => params.append('roots[]', r));

    // Token
    currentToken = Math.random().toString(36).substring(2);
    params.set('token', currentToken);

    log('Iniciando proceso de unificación...', 'info');

    // Iniciar SSE
    if(eventSource) eventSource.close();
    eventSource = new EventSource('?' + params.toString());

    eventSource.addEventListener('started', e => {
        const d = JSON.parse(e.data);
        log(`Token asignado: ${d.token}`);
    });

    eventSource.addEventListener('indexed', e => {
        const d = JSON.parse(e.data);
        log(`Indexado completado: ${d.count} archivos encontrados para procesar.`, 'success');
        if(d.count == 0) log('¡No hay nada que procesar! Revisa tus filtros.', 'error');
    });

    eventSource.addEventListener('filestart', e => {
        const d = JSON.parse(e.data);
        currentFile.textContent = d.file;
        progLabel.textContent = `${d.index} / ${d.total}`;
    });

    eventSource.addEventListener('filedone', e => {
        const d = JSON.parse(e.data);
        progFill.style.width = d.pct + '%';
        progPercent.textContent = d.pct + '%';
        // log(`Procesado: ${d.file}`); // Comentado para no saturar log visual
    });

    eventSource.addEventListener('fileerror', e => {
        const d = JSON.parse(e.data);
        log(`Error leyendo ${d.file}: ${d.msg}`, 'error');
    });

    eventSource.addEventListener('done', e => {
        const d = JSON.parse(e.data);
        finishProcess(d);
    });
    
    eventSource.addEventListener('cancelled', e => {
        log('Proceso cancelado por el usuario.', 'error');
        finishProcess({status: 'cancelled'});
    });

    eventSource.onerror = (e) => {
        // A veces SSE cierra la conexión y el navegador tira error antes del evento done
        if(btnProcess.disabled) {
             // log('Conexión cerrada.', 'error');
             // finishProcess({status: 'error'});
        }
    };
});

function finishProcess(data) {
    if(eventSource) {
        eventSource.close();
        eventSource = null;
    }
    btnProcess.disabled = false;
    btnCancel.disabled = true;
    
    if(data.status === 'ok') {
        statusBadge.textContent = 'Completado';
        statusBadge.style.background = 'var(--accent)';
        log('¡Proceso finalizado con éxito!', 'success');
        btnDownload.href = data.download;
        btnDownload.style.display = 'block';
        progFill.style.width = '100%';
        progPercent.textContent = '100%';
    } else {
        statusBadge.textContent = 'Detenido';
        statusBadge.style.background = 'var(--border)';
    }
}

btnCancel.addEventListener('click', () => {
    if(currentToken) {
        fetch(`?action=cancel&token=${currentToken}`).then(() => {
            log('Solicitando cancelación...', 'info');
        });
    }
});

// Cargar ruta inicial
loadStructure();

</script>
</body>
</html>