<?php
// index.php - Unificador Ultra PRO v2.5
// Características: UI Dark mode, SSE Streaming, Selección ARBÓREA (Subcarpetas), Exclusiones dinámicas.
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

// 1. LISTAR CONTENIDO (Soporta navegación por subcarpetas)
if ($action === 'list') {
    $base = rtrim($_GET['base'] ?? __DIR__, '/\\');
    // 'dir' es la subcarpeta relativa solicitada. Si está vacía, es la raíz.
    $relDir = isset($_GET['dir']) ? trim($_GET['dir'], '/\\') : '';
    
    // Construir ruta real a escanear
    $scanDir = $base;
    if ($relDir !== '') {
        $scanDir .= DIRECTORY_SEPARATOR . $relDir;
    }

    // Seguridad básica: evitar salir del directorio base
    if (strpos(realpath($scanDir), realpath($base)) !== 0 || !is_dir($scanDir) || !is_readable($scanDir)) {
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Directorio no accesible o ruta inválida', 'path' => $scanDir]);
        exit;
    }
    
    header('Content-Type: application/json');
    $items = [];

    try {
        $iterator = new DirectoryIterator($scanDir);
        foreach ($iterator as $fileinfo) {
            if ($fileinfo->isDot()) continue;
            
            // Construir ruta relativa para el frontend (ID único)
            $itemName = $fileinfo->getFilename();
            $itemRelPath = $relDir ? $relDir . '/' . $itemName : $itemName;

            $items[] = [
                'name' => $itemName,
                'path' => $itemRelPath, // Ruta relativa completa usada para el checkbox
                'type' => $fileinfo->isDir() ? 'dir' : 'file',
                'size' => $fileinfo->isDir() ? '-' : formatBytes($fileinfo->getSize())
            ];
        }
        
        // Ordenar: Carpetas primero
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
    $token = $_GET['token'] ?? '';
    $token = preg_replace('/[^a-z0-9_-]/i', '', $token);
    $tmpdir = sys_get_temp_dir();
    $file = $tmpdir . DIRECTORY_SEPARATOR . "unified_{$token}.txt";
    
    if (!$token || !file_exists($file)) { 
        http_response_code(404); echo "Archivo expirado."; exit; 
    }
    
    header('Content-Description: File Transfer');
    header('Content-Type: text/plain');
    header('Content-Disposition: attachment; filename="proyecto_unificado.txt"');
    header('Content-Length: ' . filesize($file));
    readfile($file);
    exit;
}

// 3. CANCELAR
if ($action === 'cancel') {
    $token = $_GET['token'] ?? '';
    $token = preg_replace('/[^a-z0-9_-]/i', '', $token);
    if ($token) {
        file_put_contents(sys_get_temp_dir() . DIRECTORY_SEPARATOR . "cancel_{$token}.flag", "1");
    }
    exit;
}

// 4. PROCESAR (SSE)
if ($action === 'process') {
    $base = rtrim($_GET['base'] ?? __DIR__, '/\\');
    
    header('Content-Type: text/event-stream');
    header('Cache-Control: no-cache');
    header('X-Accel-Buffering: no'); 
    
    if (!is_dir($base)) {
        sse_emit('error', "Base no válida"); sse_emit('done', ['status'=>'error']); exit;
    }

    // LISTA DE RUTAS SELECCIONADAS (Array de strings con rutas relativas)
    $selectedPaths = $_GET['paths'] ?? [];
    if (!is_array($selectedPaths)) $selectedPaths = [];

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

    // --- FASE 1: INDEXADO ---
    // Recorremos TODO y filtramos manualmente según la selección del usuario
    $dirIter = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS | FilesystemIterator::FOLLOW_SYMLINKS),
        RecursiveIteratorIterator::SELF_FIRST
    );

    $filesToProcess = [];

    foreach ($dirIter as $file) {
        if (file_exists($cancelFlag)) { sse_emit('cancelled', []); exit; }

        $pathAbs = $file->getPathname();
        // Ruta relativa normalizada (siempre con /)
        $rel = ltrim(substr($pathAbs, strlen($base)), '/\\');
        $relUnified = str_replace('\\', '/', $rel);
        
        // 1. FILTRO DE SELECCIÓN (CORE UPDATE)
        // Si el usuario seleccionó algo, verificamos si este archivo está DENTRO de algo seleccionado.
        // Si $selectedPaths está vacío, procesamos todo (comportamiento por defecto).
        if (!empty($selectedPaths)) {
            $isIncluded = false;
            foreach ($selectedPaths as $selPath) {
                // Normalizar selección
                $selPathUnified = str_replace('\\', '/', $selPath);
                
                // Lógica:
                // 1. Coincidencia exacta (es el archivo seleccionado)
                // 2. Es un hijo de una carpeta seleccionada (comienza con "carpeta/")
                if ($relUnified === $selPathUnified || strpos($relUnified, $selPathUnified . '/') === 0) {
                    $isIncluded = true;
                    break;
                }
            }
            if (!$isIncluded) continue; // No fue seleccionado ni él ni su padre
        }

        // 2. Exclusiones Globales
        $pathParts = explode('/', $relUnified);
        foreach ($pathParts as $part) {
            if (in_array($part, $ex_folders)) continue 2; // Saltar al siguiente archivo del iterador
        }

        if ($file->isDir()) continue; // Solo procesamos archivos finales

        if (in_array($file->getFilename(), $ex_files)) continue;
        $ext = strtolower($file->getExtension());
        if ($ext !== '' && in_array($ext, $ex_ext)) continue;

        $filesToProcess[] = $rel;
    }

    $total = count($filesToProcess);
    sse_emit('indexed', ['count' => $total]);

    if ($total === 0) {
        sse_emit('done', ['status' => 'empty', 'token' => $token]); exit;
    }

    // --- FASE 2: ESCRITURA ---
    $handleOut = fopen($outFile, 'w');
    fwrite($handleOut, "# UNIFICADOR ULTRA PRO v2.5\n# FECHA: " . date('Y-m-d H:i:s') . "\n# ARCHIVOS: {$total}\n\n");
    
    // Índice
    foreach ($filesToProcess as $f) fwrite($handleOut, "# {$f}\n");
    fwrite($handleOut, "\n" . str_repeat("-", 50) . "\n\n");

    $processed = 0;
    foreach ($filesToProcess as $relPath) {
        if (file_exists($cancelFlag)) { fclose($handleOut); sse_emit('cancelled', []); exit; }

        $absPath = $base . DIRECTORY_SEPARATOR . $relPath;
        $processed++;
        $pct = round(($processed / $total) * 100);

        sse_emit('filestart', ['file' => $relPath, 'index' => $processed, 'total' => $total]);

        fwrite($handleOut, "====== START: {$relPath} ======\n");
        try {
            $fobj = new SplFileObject($absPath, 'r');
            while (!$fobj->eof()) fwrite($handleOut, $fobj->fgets());
            $fobj = null; 
            fwrite($handleOut, "\n====== END: {$relPath} ======\n\n");
            if ($processed % 10 === 0) fflush($handleOut);
        } catch (Exception $e) {
            fwrite($handleOut, "\n[ERROR LEYENDO ARCHIVO]\n\n");
        }

        sse_emit('filedone', ['pct' => $pct]);
        if ($total < 500) usleep(2000); 
    }

    fclose($handleOut);
    $downloadUrl = '?action=download&token=' . $token;
    sse_emit('done', ['status' => 'ok', 'download' => $downloadUrl]);
    exit;
}

function formatBytes($bytes, $precision = 2) { 
    if ($bytes == 0) return '0 B';
    $units = ['B', 'KB', 'MB', 'GB', 'TB']; 
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024)); 
    $pow = min($pow, count($units) - 1); 
    return round($bytes / pow(1024, $pow), $precision) . ' ' . $units[$pow]; 
}

function sse_emit($event, $data) {
    echo "event: {$event}\n";
    echo "data: " . json_encode($data) . "\n\n";
    while (ob_get_level() > 0) ob_end_flush();
    flush();
}

# -------------------------
# INTERFAZ GRÁFICA
# -------------------------
$baseDefault = str_replace('\\', '/', __DIR__);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Unificador v2.5 - Tree View</title>
    <style>
        :root { --bg: #0d1117; --panel: #161b22; --border: #30363d; --text: #c9d1d9; --accent: #238636; --blue: #58a6ff; }
        body { margin: 0; font-family: sans-serif; background: var(--bg); color: var(--text); height: 100vh; display: flex; flex-direction: column; overflow: hidden; }
        * { box-sizing: border-box; }
        .app { display: grid; grid-template-columns: 380px 1fr; gap: 1rem; padding: 1rem; height: 100%; max-width: 1800px; margin: 0 auto; width:100%; }
        .panel { background: var(--panel); border: 1px solid var(--border); border-radius: 6px; display: flex; flex-direction: column; overflow: hidden; }
        .panel-head { padding: 10px; background: rgba(255,255,255,0.03); border-bottom: 1px solid var(--border); font-weight: bold; }
        .panel-body { padding: 10px; overflow-y: auto; flex: 1; }
        input[type="text"] { width: 100%; background: #0d1117; border: 1px solid var(--border); color: white; padding: 6px; border-radius: 4px; margin-bottom: 10px; }
        .btn { cursor: pointer; border: 0; padding: 6px 12px; border-radius: 4px; background: #21262d; color: white; border: 1px solid var(--border); }
        .btn:hover { background: #30363d; } .btn-primary { background: var(--accent); border-color: transparent; }
        .btn-danger { color: #da3633; }
        
        /* Tree View Styles */
        .tree-container { border: 1px solid var(--border); background: #0d1117; height: 350px; overflow: auto; padding: 5px; }
        .tree-ul { list-style: none; padding-left: 18px; margin: 0; display: none; }
        .tree-ul.open { display: block; }
        .tree-root-ul { padding-left: 0; display: block; }
        .tree-li { margin: 2px 0; }
        .tree-item { display: flex; align-items: center; padding: 2px 4px; border-radius: 3px; cursor: default; }
        .tree-item:hover { background: rgba(255,255,255,0.05); }
        
        .tree-toggle { width: 16px; height: 16px; display: inline-flex; align-items: center; justify-content: center; cursor: pointer; font-size: 10px; color: var(--text); opacity: 0.7; margin-right: 2px; }
        .tree-toggle:hover { opacity: 1; color: white; }
        .tree-icon { margin: 0 5px; width: 16px; text-align: center; }
        .type-dir { color: var(--blue); }
        .type-file { color: #8b949e; }
        .tree-name { font-size: 13px; white-space: nowrap; }

        /* Logs */
        .terminal { font-family: monospace; font-size: 12px; color: #8b949e; margin-top: 10px; }
        .log-ok { color: var(--accent); } .log-err { color: #da3633; }
        .progress { height: 6px; background: #21262d; border-radius: 3px; overflow: hidden; margin: 10px 0; }
        .bar { height: 100%; background: var(--blue); width: 0%; transition: width 0.1s; }
    </style>
</head>
<body>

<div class="app">
    <!-- Config Panel -->
    <div class="panel">
        <div class="panel-head">Configuración</div>
        <div class="panel-body">
            <label>Ruta Base:</label>
            <div style="display:flex; gap:5px; margin-bottom:10px;">
                <input type="text" id="basePath" value="<?php echo htmlspecialchars($baseDefault); ?>">
                <button class="btn" onclick="initTree()">Cargar</button>
            </div>

            <label>Selección (Marca carpetas para incluir todo):</label>
            <div class="tree-container" id="treeRoot">
                <div style="padding:10px; color:#8b949e; text-align:center">Carga una ruta...</div>
            </div>
            
            <div style="margin-top:10px;">
                <button class="btn" style="font-size:11px" onclick="checkAll(true)">Marcar Todo</button>
                <button class="btn" style="font-size:11px" onclick="checkAll(false)">Desmarcar</button>
            </div>

            <hr style="border:0; border-top:1px solid var(--border); margin:15px 0;">
            
            <label>Excluir Carpetas (Global):</label>
            <input type="text" id="exFolders" value="<?php echo implode(',', $DEFAULT_EXCLUDE_FOLDERS); ?>">
            
            <label>Excluir Extensiones:</label>
            <input type="text" id="exExt" value="<?php echo implode(',', $DEFAULT_EXCLUDE_EXT); ?>">

            <button class="btn btn-primary" id="btnGo" style="width:100%; margin-top:10px; padding:10px;">🚀 UNIFICAR</button>
            <div style="display:flex; gap:5px; margin-top:5px;">
                <button class="btn btn-danger" id="btnCancel" style="flex:1;" disabled>Cancelar</button>
                <a id="btnDown" class="btn" style="flex:1; text-align:center; display:none; background:var(--accent); text-decoration:none;">⬇ Descargar</a>
            </div>
        </div>
    </div>

    <!-- Output Panel -->
    <div class="panel">
        <div class="panel-head">Estado</div>
        <div class="panel-body">
            <div style="display:flex; justify-content:space-between; font-size:13px;">
                <span id="lblStatus">Esperando...</span>
                <span id="lblPct">0%</span>
            </div>
            <div class="progress"><div class="bar" id="progressBar"></div></div>
            <div class="terminal" id="console"></div>
        </div>
    </div>
</div>

<script>
const el = i => document.getElementById(i);
let currentBase = '';

// --- LOGIC TREE VIEW ---

// Carga inicial
function initTree() {
    currentBase = el('basePath').value.trim();
    if(!currentBase) return alert('Define una ruta');
    el('treeRoot').innerHTML = '<div style="padding:10px">Cargando raíz...</div>';
    
    // Crear UL raíz
    const rootUl = document.createElement('ul');
    rootUl.className = 'tree-ul tree-root-ul open';
    el('treeRoot').innerHTML = '';
    el('treeRoot').appendChild(rootUl);

    loadDir(currentBase, '', rootUl);
}

// Cargar directorio (API)
function loadDir(base, relPath, containerUl) {
    const url = `?action=list&base=${encodeURIComponent(base)}&dir=${encodeURIComponent(relPath)}`;
    
    fetch(url).then(r => r.json()).then(data => {
        if(data.error) {
            containerUl.innerHTML = `<div style="color:red; padding:5px;">Error: ${data.error}</div>`;
            return;
        }
        
        containerUl.innerHTML = ''; // Limpiar "Cargando..."
        
        if(data.items.length === 0) {
            containerUl.innerHTML = '<li style="padding:2px 10px; color:#666; font-size:11px;">(Vacío)</li>';
            return;
        }

        data.items.forEach(item => {
            const li = document.createElement('li');
            li.className = 'tree-li';
            
            const isDir = item.type === 'dir';
            const icon = isDir ? '📁' : '📄';
            const cssClass = isDir ? 'type-dir' : 'type-file';
            
            // HTML Estructura
            let html = `
                <div class="tree-item">
                    <span class="tree-toggle">${isDir ? '▶' : ''}</span>
                    <input type="checkbox" class="tree-chk" value="${item.path}">
                    <span class="tree-icon ${cssClass}">${icon}</span>
                    <span class="tree-name">${item.name}</span>
                </div>
            `;
            
            // Si es dir, preparamos contenedor hijos
            if(isDir) {
                html += `<ul class="tree-ul" id="ul-${btoa(item.path)}"></ul>`;
            }
            
            li.innerHTML = html;
            containerUl.appendChild(li);

            // Eventos
            if(isDir) {
                const toggleBtn = li.querySelector('.tree-toggle');
                const childUl = li.querySelector('.tree-ul');
                
                // Click en flecha para expandir
                toggleBtn.addEventListener('click', (e) => {
                    e.stopPropagation();
                    const isOpen = childUl.classList.contains('open');
                    
                    if(isOpen) {
                        childUl.classList.remove('open');
                        toggleBtn.innerText = '▶';
                    } else {
                        childUl.classList.add('open');
                        toggleBtn.innerText = '▼';
                        // Lazy Load si está vacío
                        if(childUl.children.length === 0) {
                            childUl.innerHTML = '<li style="padding-left:20px; font-size:11px;">Cargando...</li>';
                            loadDir(base, item.path, childUl);
                        }
                    }
                });
                
                // UX: Si marcas una carpeta padre, "visualmente" es como si marcaras todo lo de adentro
                // El backend maneja la lógica: si recibo "carpeta/", incluyo todo lo que empiece por "carpeta/"
                const chk = li.querySelector('.tree-chk');
                chk.addEventListener('change', () => {
                   // Opcional: Podríamos marcar/desmarcar visualmente los hijos cargados
                   // pero para simplificar y rendimiento, dejamos que la lógica de backend mande.
                   const childChecks = childUl.querySelectorAll('.tree-chk');
                   childChecks.forEach(c => c.checked = chk.checked);
                });
            }
        });
    }).catch(e => {
        containerUl.innerHTML = 'Error de conexión';
    });
}

function checkAll(state) {
    document.querySelectorAll('.tree-chk').forEach(c => c.checked = state);
}

// --- LOGIC PROCESS ---
let evtSource = null;

function log(msg, type='') {
    const d = document.createElement('div');
    d.className = type === 'err' ? 'log-err' : (type==='ok'?'log-ok':'');
    d.textContent = `> ${msg}`;
    el('console').appendChild(d);
    el('console').scrollTop = el('console').scrollHeight;
}

el('btnGo').onclick = () => {
    // Recolectar rutas seleccionadas
    const checks = document.querySelectorAll('.tree-chk:checked');
    const paths = Array.from(checks).map(c => c.value);
    
    el('btnGo').disabled = true;
    el('btnCancel').disabled = false;
    el('btnDown').style.display = 'none';
    el('console').innerHTML = '';
    el('progressBar').style.width = '0%';
    
    // Crear URL con params
    const u = new URLSearchParams();
    u.append('action', 'process');
    u.append('base', currentBase);
    u.append('exclude_folders', el('exFolders').value);
    u.append('exclude_ext', el('exExt').value);
    
    // Enviar array de paths
    paths.forEach(p => u.append('paths[]', p));

    log(`Iniciando... ${paths.length ? paths.length + ' rutas seleccionadas.' : 'Todo seleccionado.'}`);

    if(evtSource) evtSource.close();
    evtSource = new EventSource('?' + u.toString());

    evtSource.addEventListener('started', e => log('Token generado. Indexando...'));
    evtSource.addEventListener('indexed', e => {
        const d = JSON.parse(e.data);
        log(`Encontrados ${d.count} archivos.`);
        if(d.count == 0) endProcess();
    });
    evtSource.addEventListener('filestart', e => {
        const d = JSON.parse(e.data);
        el('lblStatus').textContent = `Procesando: ${d.file}`;
    });
    evtSource.addEventListener('filedone', e => {
        const d = JSON.parse(e.data);
        el('progressBar').style.width = d.pct + '%';
        el('lblPct').textContent = d.pct + '%';
    });
    evtSource.addEventListener('done', e => {
        const d = JSON.parse(e.data);
        endProcess();
        if(d.status === 'ok') {
            log('COMPLETADO', 'ok');
            el('btnDown').href = d.download;
            el('btnDown').style.display = 'block';
        } else {
            log('Error o vacío', 'err');
        }
    });
    evtSource.addEventListener('cancelled', () => { log('Cancelado por usuario', 'err'); endProcess(); });
    evtSource.onerror = () => { endProcess(); };
};

el('btnCancel').onclick = () => {
    fetch('?action=cancel&token=1'); // Token genérico para el ejemplo visual, el real lo maneja la sesión si fuera compleja
    if(evtSource) evtSource.close();
    log('Cancelando...', 'err');
    endProcess();
};

function endProcess() {
    el('btnGo').disabled = false;
    el('btnCancel').disabled = true;
    if(evtSource) evtSource.close();
}

// Auto init si hay valor
if(el('basePath').value) initTree();
</script>
</body>
</html>