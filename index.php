<?php
// index.php - Unificador Ultra PRO v2.1 (Tree View Edition)
// Características: UI Dark mode, SSE Streaming, Selección de árbol (subcarpetas), Exclusiones dinámicas.
// Requisitos: PHP 7.0+, permisos de escritura en carpeta temporal.

ini_set('memory_limit', '4096M');
ini_set('max_execution_time', '0');
set_time_limit(0);
date_default_timezone_set('America/Bogota');

# -------------------------
# CONFIGURACIÓN POR DEFECTO
# -------------------------
$DEFAULT_EXCLUDE_EXT = ['jpg','jpeg','png','gif','webp','svg','ico','mp4','mp3','log','zip','tar','gz','rar','pdf','exe','dll','class'];
$DEFAULT_EXCLUDE_FOLDERS = ['vendor','node_modules','storage','bootstrap/cache','.git','.idea','.vscode','dist','build','coverage'];
$DEFAULT_EXCLUDE_FILES = ['.env', '.DS_Store', 'Thumbs.db', 'composer.lock', 'package-lock.json', 'yarn.lock'];

# -------------------------
# LÓGICA DEL SERVIDOR
# -------------------------
$action = $_GET['action'] ?? '';

// 1. LISTAR CONTENIDO (Soporta subcarpetas)
if ($action === 'list') {
    $base = rtrim($_GET['base'] ?? __DIR__, '/\\');
    // Subruta relativa solicitada por el frontend (vacío = raíz)
    $relPath = isset($_GET['path']) ? trim($_GET['path'], '/\\') : ''; 
    
    // Construir ruta real a escanear
    $scanPath = $base;
    if ($relPath !== '') {
        $scanPath .= DIRECTORY_SEPARATOR . $relPath;
    }
    
    // Seguridad básica: evitar salir del base directory
    if (strpos(realpath($scanPath), realpath($base)) !== 0 || !is_dir($scanPath)) {
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Ruta no válida o inaccesible']);
        exit;
    }
    
    header('Content-Type: application/json');

    $items = [];
    try {
        $iterator = new DirectoryIterator($scanPath);
        foreach ($iterator as $fileinfo) {
            if ($fileinfo->isDot()) continue;
            
            // Nombre del archivo/carpeta
            $filename = $fileinfo->getFilename();
            // Ruta relativa completa para el ID (ej: src/Controllers)
            $fullRelPath = $relPath ? $relPath . '/' . $filename : $filename;

            $items[] = [
                'name' => $filename,
                'path' => $fullRelPath, // Identificador único relativo
                'type' => $fileinfo->isDir() ? 'dir' : 'file',
                'size' => $fileinfo->isDir() ? '-' : formatBytes($fileinfo->getSize())
            ];
        }
        
        // Ordenar: Carpetas primero, luego archivos
        usort($items, function($a, $b) {
            if ($a['type'] === $b['type']) {
                return strnatcasecmp($a['name'], $b['name']);
            }
            return $a['type'] === 'dir' ? -1 : 1;
        });

    } catch (Exception $e) {
        echo json_encode(['error' => $e->getMessage()]);
        exit;
    }
    
    echo json_encode(['base' => $base, 'items' => $items], JSON_PRETTY_PRINT);
    exit;
}

// 2. DESCARGAR
if ($action === 'download') {
    $token = preg_replace('/[^a-z0-9_-]/i', '', $_GET['token'] ?? '');
    $tmpdir = sys_get_temp_dir();
    $file = $tmpdir . DIRECTORY_SEPARATOR . "unified_{$token}.txt";
    
    if (!$token || !file_exists($file)) { 
        http_response_code(404); echo "Archivo expirado."; exit; 
    }
    
    header('Content-Type: text/plain');
    header('Content-Disposition: attachment; filename="proyecto_unificado_' . $token . '.txt"');
    header('Content-Length: ' . filesize($file));
    readfile($file);
    exit;
}

// 3. CANCELAR
if ($action === 'cancel') {
    $token = preg_replace('/[^a-z0-9_-]/i', '', $_GET['token'] ?? '');
    if ($token) file_put_contents(sys_get_temp_dir() . "/cancel_{$token}.flag", "1");
    exit;
}

// 4. PROCESAR
if ($action === 'process') {
    $base = rtrim($_GET['base'] ?? __DIR__, '/\\');
    header('Content-Type: text/event-stream');
    header('Cache-Control: no-cache');
    header('X-Accel-Buffering: no');
    
    // --- LÓGICA DE FILTRADO MEJORADA ---
    // Recibimos un array de rutas relativas seleccionadas (ej: ['src/Controllers', 'public/css/style.css'])
    $selectedPaths = $_GET['paths'] ?? []; 
    if (!is_array($selectedPaths)) $selectedPaths = [];
    
    // Normalizar rutas seleccionadas a '/'
    $selectedPaths = array_map(function($p){ return str_replace('\\', '/', $p); }, $selectedPaths);

    $ex_ext = array_filter(array_map('trim', explode(',', $_GET['exclude_ext'] ?? '')));
    $ex_folders = array_filter(array_map('trim', explode(',', $_GET['exclude_folders'] ?? '')));
    $ex_files = array_filter(array_map('trim', explode(',', $_GET['exclude_files'] ?? '')));
    $token = preg_replace('/[^a-z0-9_-]/i', '', ($_GET['token'] ?? bin2hex(random_bytes(6))));

    while (ob_get_level() > 0) ob_end_flush(); flush();

    $tmpdir = sys_get_temp_dir();
    $outFile = $tmpdir . DIRECTORY_SEPARATOR . "unified_{$token}.txt";
    $cancelFlag = $tmpdir . DIRECTORY_SEPARATOR . "cancel_{$token}.flag";
    
    if (file_exists($cancelFlag)) @unlink($cancelFlag);

    sse_emit('started', ['token' => $token]);

    // INDEXADO
    $filesToProcess = [];
    try {
        $dirIter = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS | FilesystemIterator::FOLLOW_SYMLINKS),
            RecursiveIteratorIterator::SELF_FIRST
        );
    } catch (Exception $e) {
        sse_emit('error', "Error: " . $e->getMessage());
        sse_emit('done', ['status' => 'error']);
        exit;
    }

    foreach ($dirIter as $file) {
        if (file_exists($cancelFlag)) exit;

        $pathAbs = $file->getPathname();
        $rel = ltrim(substr($pathAbs, strlen($base)), '/\\');
        $relUnified = str_replace('\\', '/', $rel); // Normalizar para comparar

        // --- FILTRO DE RUTAS (NUEVO) ---
        // Si el usuario seleccionó algo, verificamos si este archivo pertenece a esa selección.
        // Un archivo es válido si SU RUTA empieza con alguna de las rutas seleccionadas.
        if (!empty($selectedPaths)) {
            $matched = false;
            foreach ($selectedPaths as $sel) {
                // Comprobar si $relUnified empieza con $sel
                // Ejemplo: $sel = "src/App", $relUnified = "src/App/Config.php" -> Match
                // Agregamos '/' al final para asegurar coincidencia de directorio completa, a menos que sea coincidencia exacta
                if ($relUnified === $sel || strpos($relUnified, $sel . '/') === 0) {
                    $matched = true;
                    break;
                }
            }
            if (!$matched) continue; // No está en la selección
        }

        // Filtros de Exclusión (Globales)
        $parts = explode('/', $relUnified);
        if (array_intersect($parts, $ex_folders)) continue; // Carpeta excluida
        if ($file->isDir()) continue;
        if (in_array($file->getFilename(), $ex_files)) continue;
        $ext = strtolower($file->getExtension());
        if ($ext !== '' && in_array($ext, $ex_ext)) continue;

        $filesToProcess[] = $rel;
    }

    $total = count($filesToProcess);
    sse_emit('indexed', ['count' => $total]);

    if ($total === 0) {
        sse_emit('done', ['status' => 'empty']);
        exit;
    }

    // ESCRITURA
    $handleOut = fopen($outFile, 'w');
    // Header
    fwrite($handleOut, "# UNIFICADO AUTOMÁTICO - " . date('Y-m-d H:i') . "\n");
    fwrite($handleOut, "# TOTAL ARCHIVOS: {$total}\n\n");
    
    // Índice
    fwrite($handleOut, "# --- ÍNDICE ---\n");
    foreach ($filesToProcess as $f) fwrite($handleOut, "# {$f}\n");
    fwrite($handleOut, "\n");

    $processed = 0;
    foreach ($filesToProcess as $relPath) {
        if (file_exists($cancelFlag)) { fclose($handleOut); exit; }

        $absPath = $base . DIRECTORY_SEPARATOR . $relPath;
        $processed++;
        $pct = round(($processed / $total) * 100);

        sse_emit('filestart', ['file' => $relPath, 'index' => $processed, 'total' => $total]);

        fwrite($handleOut, str_repeat("=", 50) . "\nSTART_FILE: {$relPath}\n" . str_repeat("=", 50) . "\n");
        
        try {
            $content = file_get_contents($absPath);
            fwrite($handleOut, $content . "\n\n");
        } catch (Exception $e) {
            fwrite($handleOut, "[ERROR LEYENDO ARCHIVO]\n\n");
        }

        sse_emit('filedone', ['pct' => $pct]);
        if ($total < 2000) usleep(2000); 
    }

    fclose($handleOut);
    
    $downloadUrl = "?action=download&token={$token}";
    sse_emit('done', ['status' => 'ok', 'download' => $downloadUrl]);
    exit;
}

// Helpers
function formatBytes($bytes, $precision = 2) { 
    if ($bytes === 0) return '0 B';
    $u = ['B','KB','MB','GB']; 
    $p = min(floor(log($bytes)/log(1024)), count($u)-1); 
    return round($bytes/pow(1024,$p), $precision).' '.$u[$p]; 
}
function sse_emit($ev, $data) {
    echo "event: {$ev}\n";
    echo "data: " . json_encode($data) . "\n\n";
    while (ob_get_level() > 0) ob_end_flush(); flush();
}

$baseDefault = str_replace('\\', '/', __DIR__);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Unificador Ultra PRO v2.1</title>
    <style>
        :root { --bg: #0d1117; --panel: #161b22; --border: #30363d; --text: #c9d1d9; --accent: #238636; }
        body { margin:0; font-family:sans-serif; background:var(--bg); color:var(--text); height:100vh; display:flex; flex-direction:column; overflow:hidden;}
        .app { display:grid; grid-template-columns: 400px 1fr; gap:1rem; padding:1rem; height:100%; box-sizing:border-box; }
        .panel { background:var(--panel); border:1px solid var(--border); border-radius:6px; display:flex; flex-direction:column; overflow:hidden; }
        .head { padding:10px; border-bottom:1px solid var(--border); font-weight:bold; background:rgba(255,255,255,0.03); display:flex; justify-content:space-between; align-items:center; }
        .body { padding:15px; overflow-y:auto; flex:1; }
        input[type="text"] { width:100%; background:#0d1117; border:1px solid var(--border); color:var(--text); padding:6px; margin-bottom:10px; box-sizing:border-box; }
        .btn { cursor:pointer; background:#21262d; border:1px solid var(--border); color:var(--text); padding:5px 10px; border-radius:4px; }
        .btn-green { background:var(--accent); color:#fff; width:100%; padding:10px; margin-top:10px; border:none;}
        .btn-green:disabled { opacity:0.5; cursor:not-allowed; }
        
        /* TREE VIEW STYLES */
        .tree-container { border:1px solid var(--border); background:#0d1117; height:350px; overflow:auto; padding:5px; }
        .node { display: flex; flex-direction: column; }
        .node-row { display:flex; align-items:center; padding:2px 4px; cursor:default; }
        .node-row:hover { background:rgba(255,255,255,0.05); }
        .toggle { width:20px; height:20px; display:flex; align-items:center; justify-content:center; cursor:pointer; font-family:monospace; color:#8b949e; user-select:none; }
        .toggle:hover { color:#fff; }
        .chk { margin:0 5px; }
        .icon { margin-right:5px; font-size:14px; }
        .name { font-size:13px; white-space:nowrap; }
        .children { padding-left:22px; display:none; border-left: 1px solid rgba(255,255,255,0.05); }
        .children.open { display:block; }
        .meta { font-size:10px; color:#666; margin-left:auto; padding-left:10px;}
        
        /* LOGS */
        .term { font-family:monospace; font-size:12px; }
        .log-ok { color:var(--accent); } .log-err { color:#da3633; }
        
        /* PROGRESS */
        .bar-wrap { height:6px; background:#21262d; margin:10px 0; border-radius:3px; }
        .bar-fill { height:100%; background:var(--accent); width:0%; transition:width 0.2s; }
    </style>
</head>
<body>
<div class="app">
    <!-- PANEL CONFIG -->
    <div class="panel">
        <div class="head">Configuración</div>
        <div class="body">
            <label>Carpeta Base:</label>
            <div style="display:flex; gap:5px; margin-bottom:10px;">
                <input type="text" id="basePath" value="<?php echo htmlspecialchars($baseDefault); ?>" style="margin:0;">
                <button class="btn" onclick="initTree()">Cargar</button>
            </div>

            <label>Selección (Árbol):</label>
            <div style="font-size:10px; color:#8b949e; margin-bottom:5px;">Expande las carpetas para ser específico.</div>
            <div class="tree-container" id="treeRoot">
                <div style="padding:10px; color:#666; text-align:center;">Carga una ruta...</div>
            </div>
            <div style="margin-top:5px;">
                <button class="btn" style="font-size:10px" onclick="checkAll()">Marcar Todo</button>
                <button class="btn" style="font-size:10px" onclick="uncheckAll()">Desmarcar</button>
            </div>

            <div style="margin-top:15px;">
                <label>Excluir Carpetas (Global):</label>
                <input type="text" id="exFolders" value="<?php echo implode(',', $DEFAULT_EXCLUDE_FOLDERS); ?>">
                <label>Excluir Extensiones:</label>
                <input type="text" id="exExt" value="<?php echo implode(',', $DEFAULT_EXCLUDE_EXT); ?>">
            </div>

            <button id="btnStart" class="btn btn-green">UNIFICAR ARCHIVOS</button>
            <a id="btnDown" class="btn" style="display:none; text-align:center; background:#1f6feb; color:white; margin-top:10px; text-decoration:none;">Descargar Resultado</a>
        </div>
    </div>

    <!-- PANEL LOGS -->
    <div class="panel">
        <div class="head">
            <span>Progreso</span>
            <span id="badge" style="font-size:11px; background:#333; padding:2px 6px; border-radius:10px;">Esperando</span>
        </div>
        <div class="body">
            <div style="display:flex; justify-content:space-between; font-size:12px;">
                <span id="txtFile">...</span>
                <span id="txtPct">0%</span>
            </div>
            <div class="bar-wrap"><div class="bar-fill" id="bar"></div></div>
            <hr style="border:0; border-top:1px solid var(--border);">
            <div class="term" id="term"></div>
        </div>
    </div>
</div>

<script>
// --- LOGICA DEL ÁRBOL ---
async function fetchItems(path) {
    const base = document.getElementById('basePath').value;
    const res = await fetch(`?action=list&base=${encodeURIComponent(base)}&path=${encodeURIComponent(path)}`);
    return await res.json();
}

async function renderNode(container, path) {
    container.innerHTML = '<div style="font-size:11px; padding:5px; color:#666;">Cargando...</div>';
    
    try {
        const data = await fetchItems(path);
        container.innerHTML = '';
        
        if (data.error) {
            container.innerHTML = `<div style="color:red; padding:5px;">${data.error}</div>`;
            return;
        }

        if (data.items.length === 0) {
            container.innerHTML = `<div style="padding-left:10px; font-style:italic; color:#444; font-size:11px;">(Vacío)</div>`;
            return;
        }

        data.items.forEach(item => {
            const node = document.createElement('div');
            node.className = 'node';
            
            // Iconos
            const isDir = item.type === 'dir';
            const icon = isDir ? '📁' : '📄';
            const color = isDir ? '#58a6ff' : '#8b949e';
            
            // HTML del Row
            let html = `
                <div class="node-row">
                    <span class="toggle">${isDir ? '▸' : ''}</span>
                    <input type="checkbox" class="chk item-chk" value="${item.path}" data-type="${item.type}">
                    <span class="icon" style="color:${color}">${icon}</span>
                    <span class="name">${item.name}</span>
                    <span class="meta">${item.size}</span>
                </div>
            `;
            
            // Contenedor hijos
            if (isDir) {
                html += `<div class="children" id="child-${btoa(item.path).replace(/=/g,'')}"></div>`;
            }
            
            node.innerHTML = html;
            container.appendChild(node);

            // Event Listener para expandir carpeta
            if (isDir) {
                const toggleBtn = node.querySelector('.toggle');
                const childrenContainer = node.querySelector('.children');
                
                toggleBtn.addEventListener('click', (e) => {
                    e.stopPropagation();
                    if (childrenContainer.classList.contains('open')) {
                        childrenContainer.classList.remove('open');
                        toggleBtn.textContent = '▸';
                    } else {
                        childrenContainer.classList.add('open');
                        toggleBtn.textContent = '▾';
                        // Cargar contenido solo si está vacío
                        if (!childrenContainer.hasChildNodes()) {
                            renderNode(childrenContainer, item.path);
                        }
                    }
                });
            }
        });

    } catch(e) {
        container.innerHTML = '<div style="color:red;">Error de conexión</div>';
    }
}

function initTree() {
    renderNode(document.getElementById('treeRoot'), '');
}

function checkAll() { document.querySelectorAll('.item-chk').forEach(c => c.checked = true); }
function uncheckAll() { document.querySelectorAll('.item-chk').forEach(c => c.checked = false); }

// --- LOGICA DE PROCESO ---
const btnStart = document.getElementById('btnStart');
const term = document.getElementById('term');
const bar = document.getElementById('bar');
let es = null;

function log(msg, cls='') {
    const d = document.createElement('div');
    d.textContent = `> ${msg}`;
    if(cls) d.className = cls;
    term.appendChild(d);
    term.scrollTop = term.scrollHeight;
}

btnStart.onclick = () => {
    // Recolectar rutas seleccionadas
    const checkboxes = document.querySelectorAll('.item-chk:checked');
    const paths = Array.from(checkboxes).map(c => c.value);

    // UX
    term.innerHTML = '';
    btnStart.disabled = true;
    document.getElementById('btnDown').style.display = 'none';
    bar.style.width = '0%';
    document.getElementById('badge').textContent = 'Procesando...';
    document.getElementById('badge').style.background = '#1f6feb';

    // Params
    const url = new URL(window.location.href);
    url.searchParams.set('action', 'process');
    url.searchParams.set('base', document.getElementById('basePath').value);
    url.searchParams.set('exclude_folders', document.getElementById('exFolders').value);
    url.searchParams.set('exclude_ext', document.getElementById('exExt').value);
    
    // Agregar paths[] a la URL manualmente porque URLSearchParams puede ser tricky con arrays php
    let finalUrl = url.toString();
    if (paths.length > 0) {
        paths.forEach(p => finalUrl += `&paths[]=${encodeURIComponent(p)}`);
    }

    log("Iniciando...");
    
    if (es) es.close();
    es = new EventSource(finalUrl);

    es.addEventListener('started', e => log("Token generado."));
    es.addEventListener('indexed', e => {
        const d = JSON.parse(e.data);
        log(`Archivos encontrados: ${d.count}`, 'log-ok');
        if(d.count == 0) log("ADVERTENCIA: 0 archivos. Revisa la selección.", 'log-err');
    });
    
    es.addEventListener('filestart', e => {
        const d = JSON.parse(e.data);
        document.getElementById('txtFile').textContent = d.file;
        document.getElementById('txtPct').textContent = Math.round((d.index/d.total)*100) + '%';
    });

    es.addEventListener('filedone', e => {
        const d = JSON.parse(e.data);
        bar.style.width = d.pct + '%';
    });

    es.addEventListener('error', e => {
        const d = JSON.parse(e.data);
        log(d.message, 'log-err');
    });

    es.addEventListener('done', e => {
        const d = JSON.parse(e.data);
        es.close();
        btnStart.disabled = false;
        
        if (d.status === 'ok') {
            document.getElementById('badge').textContent = 'Completado';
            document.getElementById('badge').style.background = '#238636';
            document.getElementById('btnDown').href = d.download;
            document.getElementById('btnDown').style.display = 'block';
            log("Finalizado con éxito.", 'log-ok');
        } else {
            document.getElementById('badge').textContent = 'Error/Vacío';
            document.getElementById('badge').style.background = '#da3633';
        }
    });

    es.onerror = () => {
       // A veces cierra sin evento done
       btnStart.disabled = false;
    };
};

// Auto carga inicial
initTree();
</script>
</body>
</html>