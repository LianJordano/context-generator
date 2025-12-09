<?php
// index.php - Unificador Ultra PRO con UI/UX + barras reales + exclusiones dinámicas + selector de carpetas
// Para usar: coloca este archivo en la raíz del proyecto y abre en el navegador.
// Nota: requiere PHP con output buffering disabled/flush working (ob_flush/flush) y permisos de escritura en sys_get_temp_dir().

ini_set('memory_limit', '4096M');
ini_set('max_execution_time', '0');
set_time_limit(0);
date_default_timezone_set('America/Bogota');

# -------------------------
# CONFIG DEFAULT (puedes editar)
# -------------------------
$DEFAULT_EXCLUDE_EXT = ['jpg','jpeg','png','gif','webp','svg','ico','mp4','mp3','log','zip','tar','gz'];
$DEFAULT_EXCLUDE_FOLDERS = ['vendor','node_modules','storage','bootstrap/cache','.git','.idea','.vscode'];
$DEFAULT_EXCLUDE_FILES = ['.env', '.DS_Store', 'Thumbs.db'];

# -------------------------
# UTIL: safe join path
# -------------------------
function join_path(...$parts) {
    $clean = [];
    foreach ($parts as $p) {
        if ($p === '') continue;
        $clean[] = rtrim($p, '/\\');
    }
    return implode(DIRECTORY_SEPARATOR, $clean);
}

# -------------------------
# ROUTING: action param
# -------------------------
$action = $_GET['action'] ?? '';

if ($action === 'list') {
    // devuelve estructura del proyecto (solo carpetas y archivos mínimos) en JSON
    $base = rtrim($_GET['base'] ?? __DIR__, '/');
    
    // Validar que la ruta existe y es accesible
    if (!is_dir($base) || !is_readable($base)) {
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Directorio no accesible o no existe', 'base' => $base]);
        exit;
    }
    
    header('Content-Type: application/json');

    $structure = [];
    try {
        $rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS));
        foreach ($rii as $file) {
            $path = str_replace($base . '/', '', $file->getPathname());
            $structure[] = $path;
        }
    } catch (Exception $e) {
        echo json_encode(['error' => $e->getMessage(), 'base' => $base]);
        exit;
    }
    
    echo json_encode(['base' => $base, 'items' => $structure], JSON_PRETTY_PRINT);
    exit;
}

if ($action === 'download') {
    $token = $_GET['token'] ?? '';
    $tmpdir = sys_get_temp_dir();
    $file = $tmpdir . DIRECTORY_SEPARATOR . "unified_{$token}.txt";
    if (!file_exists($file)) { http_response_code(404); echo "Archivo no encontrado"; exit; }
    header('Content-Type: text/plain');
    header('Content-Disposition: attachment; filename="proyecto_unificado_' . $token . '.txt"');
    readfile($file);
    exit;
}

if ($action === 'cancel') {
    $token = $_GET['token'] ?? '';
    if (!$token) { http_response_code(400); echo "token required"; exit; }
    $tmpdir = sys_get_temp_dir();
    file_put_contents($tmpdir . DIRECTORY_SEPARATOR . "cancel_{$token}.flag", "1");
    echo json_encode(['status' => 'cancelling']);
    exit;
}

if ($action === 'process') {
    // SSE streaming: procesar y emitir eventos
    // Parámetros via GET:
    // base - carpeta base
    // folders[] - lista de carpetas raíz a incluir
    // exclude_ext (csv), exclude_folders (csv), exclude_files (csv)
    // token - opcional (si no hay, se genera)
    $base = rtrim($_GET['base'] ?? __DIR__, '/');
    
    // Validar que la ruta existe
    if (!is_dir($base) || !is_readable($base)) {
        header('Content-Type: text/event-stream');
        header('Cache-Control: no-cache');
        echo "event: error\n";
        echo "data: Directorio no accesible: {$base}\n\n";
        echo "event: done\n";
        echo "data: " . json_encode(['status' => 'error']) . "\n\n";
        exit;
    }
    
    $folders = $_GET['folders'] ?? [];
    if (!is_array($folders)) $folders = [$folders];
    $ex_ext = array_filter(array_map('trim', explode(',', $_GET['exclude_ext'] ?? implode(',', $DEFAULT_EXCLUDE_EXT))));
    $ex_folders = array_filter(array_map('trim', explode(',', $_GET['exclude_folders'] ?? implode(',', $DEFAULT_EXCLUDE_FOLDERS))));
    $ex_files = array_filter(array_map('trim', explode(',', $_GET['exclude_files'] ?? implode(',', $DEFAULT_EXCLUDE_FILES))));
    $token = preg_replace('/[^a-z0-9_-]/i', '', ($_GET['token'] ?? bin2hex(random_bytes(6))));

    // prepare SSE
    header('Content-Type: text/event-stream');
    header('Cache-Control: no-cache');
    header('X-Accel-Buffering: no'); // for nginx: disable buffering
    @ob_end_flush();
    @ob_flush();
    flush();

    $tmpdir = sys_get_temp_dir();
    $outFile = $tmpdir . DIRECTORY_SEPARATOR . "unified_{$token}.txt";
    $cancelFlag = $tmpdir . DIRECTORY_SEPARATOR . "cancel_{$token}.flag";
    if (file_exists($cancelFlag)) @unlink($cancelFlag);
    if (file_exists($outFile)) @unlink($outFile);

    // helper SSE emit
    function sse_emit($event, $data) {
        echo "event: {$event}\n";
        $payload = is_string($data) ? $data : json_encode($data);
        $lines = explode("\n", trim($payload));
        foreach ($lines as $line) echo "data: {$line}\n";
        echo "\n";
        @ob_flush(); flush();
    }

    sse_emit('started', ['token' => $token, 'base' => $base]);

    // FIRST pass: build index list (mem light: only store paths)
    $index = [];
    try {
        $dirIter = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS));
    } catch (UnexpectedValueException $e) {
        sse_emit('error', "No se puede abrir directorio: " . $e->getMessage());
        sse_emit('done', ['status' => 'error']);
        exit;
    }

    foreach ($dirIter as $file) {
        if ($file->isDir()) continue;
        $pathAbs = $file->getPathname();
        $rel = ltrim(str_replace($base, '', $pathAbs), '/\\');

        // exclude folder patterns
        $skip = false;
        foreach ($ex_folders as $ef) {
            if ($ef === '') continue;
            if (stripos($rel, trim($ef, '/').'/' ) === 0 || stripos($rel, trim($ef, '/')) !== false) { $skip = true; break; }
        }
        if ($skip) continue;

        // root folder filter (only include files whose first path segment is in $folders, if folders provided)
        if (!empty($folders) && !in_array('', $folders)) {
            $root = explode('/', $rel)[0] ?? '';
            if (!in_array($root, $folders)) continue;
        }

        // exclude by filename
        $basename = basename($rel);
        if (in_array($basename, $ex_files)) continue;

        // exclude by extension
        $ext = strtolower(pathinfo($rel, PATHINFO_EXTENSION));
        if ($ext !== '' && in_array($ext, $ex_ext)) continue;

        $index[] = $rel;
    }

    $total = count($index);
    sse_emit('indexed', ['count' => $total]);

    if ($total === 0) {
        sse_emit('done', ['status' => 'empty', 'token' => $token, 'download' => null]);
        exit;
    }

    // write header + index to output file (stream)
    $handleOut = fopen($outFile, 'c');
    if (!$handleOut) {
        sse_emit('error', 'No se puede crear archivo de salida en temp dir.');
        sse_emit('done', ['status' => 'error']);
        exit;
    }
    // truncate
    ftruncate($handleOut, 0);
    fwrite($handleOut, "### Proyecto Unificado\n");
    fwrite($handleOut, "### Fecha: " . date('Y-m-d H:i:s') . "\n\n");
    fwrite($handleOut, "### ÍNDICE DE ARCHIVOS PROCESADOS ({$total})\n");
    fwrite($handleOut, implode("\n", $index));
    fwrite($handleOut, "\n\n--------------------------------------\n\n");
    fflush($handleOut);

    // second pass: stream each file appended to outFile
    $processed = 0;
    foreach ($index as $relPath) {
        // check cancel flag
        if (file_exists($cancelFlag)) {
            sse_emit('cancelled', ['processed' => $processed, 'total' => $total]);
            fclose($handleOut);
            exit;
        }

        $abs = $base . DIRECTORY_SEPARATOR . $relPath;
        $processed++;
        $pct = round(($processed / $total) * 100);

        sse_emit('filestart', ['file' => $relPath, 'index' => $processed, 'total' => $total, 'pct' => $pct]);

        // robust read using SplFileObject (low mem)
        try {
            $spl = new SplFileObject($abs, 'r');
            // write file header
            fwrite($handleOut, "===== FILE: {$relPath} =====\n");
            // stream by line / block
            while (!$spl->eof()) {
                $chunk = $spl->fgets(); // line-based - safe
                if ($chunk === false) break;
                fwrite($handleOut, $chunk);
                // optionally flush occasionally to disk (reduce memory)
                if (ftell($handleOut) % (1024*1024) < 8192) fflush($handleOut);
            }
            fwrite($handleOut, "\n\n>> Procesado ($processed / $total) — {$pct}%\n\n");
            fflush($handleOut);
        } catch (Exception $e) {
            fwrite($handleOut, "\n\n>> ERROR leyendo {$relPath}: " . $e->getMessage() . "\n\n");
            fflush($handleOut);
            sse_emit('fileerror', ['file' => $relPath, 'msg' => $e->getMessage()]);
        }

        sse_emit('filedone', ['file' => $relPath, 'index' => $processed, 'total' => $total, 'pct' => $pct]);
        // small sleep to allow client to render UI on heavy IO (tweakable)
        usleep(20000);
    }

    fclose($handleOut);

    $downloadUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http')
        . '://' . $_SERVER['HTTP_HOST'] . $_SERVER['PHP_SELF']
        . '?action=download&token=' . $token;

    sse_emit('done', ['status' => 'ok', 'token' => $token, 'download' => $downloadUrl]);
    exit;
}

# -------------------------
# UI HTML (default)
# -------------------------
$baseDefault = __DIR__;
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8" />
<meta name="viewport" content="width=device-width,initial-scale=1" />
<title>Unificador — UX/UI Avanzado</title>
<style>
:root{
  --bg:#0f1723; --card:#0b1220; --accent:#ff6a00; --muted:#9aa4b2; --glass: rgba(255,255,255,0.04);
  --success:#16a34a; --danger:#ef4444;
}
*{box-sizing:border-box}
body{font-family:Inter, system-ui, Arial; margin:0; background:linear-gradient(180deg,#061024 0%, #07132a 100%); color:#e6eef6; padding:28px;}
.container{max-width:1100px;margin:0 auto;display:grid;grid-template-columns:380px 1fr;gap:20px;}
.card{background:var(--card);border-radius:12px;padding:18px;box-shadow:0 6px 18px rgba(2,6,23,0.6);border:1px solid rgba(255,255,255,0.03)}
.header{grid-column:1/-1; display:flex; align-items:center; justify-content:space-between; margin-bottom:6px;}
.h1{font-size:18px;color:#fff}
.small{font-size:13px;color:var(--muted)}
.left-panel{height:78vh; overflow:auto}
.right-panel{height:78vh; overflow:auto}
.checkbox-list{max-height:220px; overflow:auto; padding:8px; background:var(--glass); border-radius:8px; margin-bottom:10px}
.controls{display:flex;gap:8px;flex-wrap:wrap}
.input, .select, .btn{padding:8px 10px;border-radius:8px;border:1px solid rgba(255,255,255,0.04);background:transparent;color:inherit}
.btn{cursor:pointer}
.btn-primary{background:linear-gradient(90deg,var(--accent),#ff8a3d); color:#071426;border:none}
.btn-ghost{background:transparent;border:1px solid rgba(255,255,255,0.04)}
.exclusions{display:flex;gap:8px;margin-top:8px;flex-wrap:wrap}
.tag{background:rgba(255,255,255,0.05);padding:6px 8px;border-radius:6px;font-size:13px}
.area{width:100%;height:220px;background:transparent;border:1px dashed rgba(255,255,255,0.03);padding:10px;border-radius:8px;overflow:auto;font-family:monospace;color:var(--muted)}
.progress-wrap{margin-top:12px}
.progress{height:12px;background:rgba(255,255,255,0.04);border-radius:8px;overflow:hidden}
.progress > i{display:block;height:100%;background:linear-gradient(90deg,var(--accent),#ffc08a);width:0%}
.file-list{max-height:200px;overflow:auto;margin-top:10px;font-family:monospace;font-size:13px}
.status-line{display:flex;justify-content:space-between;align-items:center}
.badge{padding:4px 8px;border-radius:999px;background:rgba(255,255,255,0.03);font-size:12px}
.footer{grid-column:1/-1;margin-top:12px;color:var(--muted);font-size:13px}
a.link{color:var(--accent);text-decoration:none}
.folder-selector{margin-bottom:12px; padding:12px; background:var(--glass); border-radius:8px;}
.folder-selector input[type="text"]{width:100%; margin-top:6px;}
.btn-folder{margin-top:6px;}
</style>
</head>
<body>
<div class="container">
  <div class="header card">
    <div>
      <div class="h1">Unificador — UX/UI Avanzado</div>
      <div class="small">Selecciona carpetas, configura exclusiones y genera un único archivo listo para IA.</div>
    </div>
    <div>
      <div class="small">Carpeta base:</div>
      <div class="badge" id="basePath"><?=htmlspecialchars($baseDefault)?></div>
    </div>
  </div>

  <div class="card left-panel">
    <h3 style="margin-top:0">Carpeta personalizada</h3>
    <div class="folder-selector">
      <div class="small">Ingresa la ruta completa de cualquier carpeta en tu sistema:</div>
      <input type="text" class="input" id="customPath" placeholder="ej: /home/usuario/mi-proyecto" value="<?=htmlspecialchars($baseDefault)?>" />
      <button class="btn btn-primary btn-folder" id="loadCustomBtn">📁 Cargar carpeta</button>
      <div class="small" style="margin-top:6px; color:var(--accent)" id="pathStatus"></div>
    </div>

    <h3 style="margin-top:12px">Estructura & Selección</h3>
    <div class="small">Explora y selecciona carpetas raíz (nivel 1). Si no marcas ninguna, se incluirá todo (con exclusiones).</div>

    <div style="margin-top:10px">
      <button class="btn btn-ghost" id="refreshBtn">Refrescar estructura</button>
      <button class="btn btn-ghost" id="selectAllBtn">Seleccionar todo</button>
      <button class="btn btn-ghost" id="clearBtn">Limpiar selección</button>
    </div>

    <div style="margin-top:12px" class="checkbox-list" id="foldersBox">
      cargando...
    </div>

    <h4 style="margin-top:12px">Exclusiones</h4>
    <div class="small">Agregar extensiones, carpetas o nombres de archivo a excluir.</div>

    <div style="margin-top:8px">
      <div><strong>Extensiones</strong> (csv):</div>
      <input class="input" id="excludeExt" value="<?=htmlspecialchars(implode(',', $DEFAULT_EXCLUDE_EXT))?>" />
      <div style="margin-top:6px"><strong>Carpetas</strong> (csv):</div>
      <input class="input" id="excludeFolders" value="<?=htmlspecialchars(implode(',', $DEFAULT_EXCLUDE_FOLDERS))?>" />
      <div style="margin-top:6px"><strong>Archivos</strong> (csv):</div>
      <input class="input" id="excludeFiles" value="<?=htmlspecialchars(implode(',', $DEFAULT_EXCLUDE_FILES))?>" />
    </div>

    <h4 style="margin-top:12px">Formato de salida</h4>
    <select id="format" class="select">
      <option value="txt">TXT (contenido completo + índice)</option>
      <option value="json">JSON (índice only)</option>
    </select>

    <div style="margin-top:12px">
      <button class="btn btn-primary" id="startBtn">▶ Unificar y empezar</button>
      <button class="btn btn-ghost" id="cancelBtn" disabled>✖ Cancelar</button>
      <a id="downloadLink" class="btn btn-ghost" style="display:none" download>⬇ Descargar</a>
    </div>

    <div class="progress-wrap">
      <div class="small">Progreso total</div>
      <div class="progress" id="globalProgress"><i></i></div>
      <div class="status-line" style="margin-top:6px">
        <div class="small" id="progressText">0 / 0 — 0%</div>
        <div class="small" id="currentFile">—</div>
      </div>
    </div>
  </div>

  <div class="card right-panel">
    <h3 style="margin-top:0">Actividad & Archivos procesados</h3>
    <div class="small">Eventos en tiempo real. La interfaz mantiene un historial ligero.</div>

    <div class="file-list area" id="eventLog"></div>

    <h4 style="margin-top:12px">Últimos archivos procesados</h4>
    <div id="filesProcessed" class="file-list"></div>
  </div>

  <div class="footer card">
    Hecho con cariño — Opciones avanzadas: <a class="link" href="#" id="showTips">ver tips</a>
  </div>
</div>

<script>
let basePath = "<?= addslashes($baseDefault) ?>";
const foldersBox = document.getElementById('foldersBox');
const refreshBtn = document.getElementById('refreshBtn');
const selectAllBtn = document.getElementById('selectAllBtn');
const clearBtn = document.getElementById('clearBtn');
const startBtn = document.getElementById('startBtn');
const cancelBtn = document.getElementById('cancelBtn');
const downloadLink = document.getElementById('downloadLink');
const eventLog = document.getElementById('eventLog');
const filesProcessed = document.getElementById('filesProcessed');
const progressBar = document.querySelector('#globalProgress > i');
const progressText = document.getElementById('progressText');
const currentFileText = document.getElementById('currentFile');
const excludeExt = document.getElementById('excludeExt');
const excludeFolders = document.getElementById('excludeFolders');
const excludeFiles = document.getElementById('excludeFiles');
const formatSelect = document.getElementById('format');
const customPath = document.getElementById('customPath');
const loadCustomBtn = document.getElementById('loadCustomBtn');
const pathStatus = document.getElementById('pathStatus');
const basePathBadge = document.getElementById('basePath');

let sse = null;
let currentToken = null;

function logEvent(txt) {
  const line = document.createElement('div');
  line.textContent = txt;
  eventLog.prepend(line);
  // keep only 300 lines
  while (eventLog.childElementCount > 400) eventLog.removeChild(eventLog.lastChild);
}

function addProcessedFile(name) {
  const el = document.createElement('div');
  el.textContent = name;
  filesProcessed.prepend(el);
  while (filesProcessed.childElementCount > 200) filesProcessed.removeChild(filesProcessed.lastChild);
}

function fetchFolders() {
  foldersBox.innerHTML = 'Cargando estructura...';
  pathStatus.textContent = '';
  
  fetch('?action=list&base=' + encodeURIComponent(basePath))
    .then(r=>r.json())
    .then(data=>{
      if (data.error) {
        foldersBox.innerHTML = '<div class="small" style="color:var(--danger)">Error: ' + data.error + '</div>';
        pathStatus.textContent = '❌ Error al cargar carpeta';
        pathStatus.style.color = 'var(--danger)';
        return;
      }
      
      // build unique root folders (nivel 1) - solo nombres, no rutas completas
      const roots = new Set();
      data.items.forEach(p=>{
        // Normalizar separadores de ruta (\ o /)
        const normalized = p.replace(/\\/g, '/');
        const parts = normalized.split('/');
        // Tomar solo la primera parte (carpeta raíz nivel 1)
        if (parts[0] && parts[0] !== '') {
          roots.add(parts[0]);
        }
      });
      const arr = Array.from(roots).sort();
      if (!arr.length) {
        foldersBox.innerHTML = '<div class="small">No se detectaron carpetas en la raíz.</div>';
      } else {
        foldersBox.innerHTML = '';
        arr.forEach(name=>{
          const id = 'chk_' + name.replace(/[^a-z0-9]/gi,'_');
          const wrapper = document.createElement('div');
          wrapper.innerHTML = '<label><input type="checkbox" data-folder="'+name+'" id="'+id+'"> <strong>'+name+'</strong></label>';
          foldersBox.appendChild(wrapper);
        });
      }
      
      pathStatus.textContent = '✓ Carpeta cargada correctamente (' + arr.length + ' carpetas encontradas)';
      pathStatus.style.color = 'var(--success)';
      basePathBadge.textContent = basePath;
    })
    .catch(e=>{
      foldersBox.innerHTML = '<div class="small" style="color:var(--danger)">Error cargando estructura</div>';
      pathStatus.textContent = '❌ Error de conexión';
      pathStatus.style.color = 'var(--danger)';
    });
}

loadCustomBtn.addEventListener('click', ()=>{
  const newPath = customPath.value.trim();
  if (!newPath) {
    pathStatus.textContent = '⚠ Ingresa una ruta válida';
    pathStatus.style.color = 'var(--danger)';
    return;
  }
  
  basePath = newPath;
  pathStatus.textContent = '⏳ Cargando...';
  pathStatus.style.color = 'var(--muted)';
  fetchFolders();
});

refreshBtn.addEventListener('click', fetchFolders);
selectAllBtn.addEventListener('click', ()=>{
  Array.from(foldersBox.querySelectorAll('input[type=checkbox]')).forEach(c=>c.checked = true);
});
clearBtn.addEventListener('click', ()=>{
  Array.from(foldersBox.querySelectorAll('input[type=checkbox]')).forEach(c=>c.checked = false);
});

function startProcess() {
  // collect selected folders
  const selected = Array.from(foldersBox.querySelectorAll('input[type=checkbox]:checked')).map(c=>c.dataset.folder);
  // build query
  const params = new URLSearchParams();
  params.set('action','process');
  params.set('base', basePath);
  if (selected.length) selected.forEach(f=>params.append('folders[]', f));
  params.set('exclude_ext', excludeExt.value);
  params.set('exclude_folders', excludeFolders.value);
  params.set('exclude_files', excludeFiles.value);
  params.set('format', formatSelect.value);
  // generate token client-side
  const token = Math.random().toString(36).slice(2,12);
  params.set('token', token);

  startBtn.disabled = true;
  cancelBtn.disabled = false;
  downloadLink.style.display = 'none';
  filesProcessed.innerHTML = '';
  eventLog.innerHTML = '';
  progressBar.style.width = '0%';
  progressText.textContent = '0 / 0 — 0%';
  currentFileText.textContent = '—';
  currentToken = token;

  // connect SSE
  const url = '?' + params.toString();
  logEvent('Conectando y empezando proceso...');
  sse = new EventSource(url);

  sse.addEventListener('started', (ev)=>{
    const d = JSON.parse(ev.data);
    logEvent('Proceso iniciado. token=' + d.token);
  });

  sse.addEventListener('indexed', (ev)=>{
    const d = JSON.parse(ev.data);
    logEvent('Indexación completada: ' + d.count + ' archivos.');
  });

  sse.addEventListener('filestart', (ev)=>{
    const d = JSON.parse(ev.data);
    progressText.textContent = d.index + ' / ' + d.total + ' — ' + d.pct + '%';
    progressBar.style.width = d.pct + '%';
    currentFileText.textContent = d.file;
    logEvent('Iniciando: ' + d.file);
  });

  sse.addEventListener('filedone', (ev)=>{
    const d = JSON.parse(ev.data);
    addProcessedFile(d.file);
    logEvent('Procesado: ' + d.file + ' (' + d.pct + '%)');
  });

  sse.addEventListener('fileerror', (ev)=>{
    const d = JSON.parse(ev.data);
    logEvent('ERROR file: ' + d.file + ' — ' + d.msg);
  });

  sse.addEventListener('cancelled', (ev)=>{
    const d = JSON.parse(ev.data);
    logEvent('Proceso cancelado por usuario. Procesados: ' + d.processed + '/' + d.total);
    cleanupAfterFinish();
  });

  sse.addEventListener('done', (ev)=>{
    const d = JSON.parse(ev.data);
    if (d.status === 'ok') {
      logEvent('Proceso finalizado correctamente. Archivo listo.');
      downloadLink.href = d.download;
      downloadLink.style.display = 'inline-block';
      downloadLink.textContent = '⬇ Descargar archivo';
    } else if (d.status === 'empty') {
      logEvent('No hay archivos para procesar según tus filtros.');
    } else {
      logEvent('Proceso finalizó con estado: ' + d.status);
    }
    cleanupAfterFinish();
  });

  sse.addEventListener('error', (ev)=>{
    logEvent('Error (SSE) o conexión cerrada.');
    cleanupAfterFinish();
  });
}

function cleanupAfterFinish(){
  if (sse) { try { sse.close(); } catch(e){} sse = null; }
  startBtn.disabled = false;
  cancelBtn.disabled = true;
}

startBtn.addEventListener('click', startProcess);

cancelBtn.addEventListener('click', ()=>{
  if (!currentToken) return;
  fetch('?action=cancel&token=' + encodeURIComponent(currentToken))
    .then(r=>r.json()).then(j=>{
      logEvent('Solicitud de cancelación enviada.');
    });
});

document.getElementById('showTips').addEventListener('click', (e)=>{
  e.preventDefault();
  alert('TIPS:\n- Si el proyecto es gigante, espera que el proceso termine. \n- Ajusta exclusiones para evitar archivos binarios.\n- Puedes descargar el archivo final desde el botón "Descargar".\n- Ingresa rutas absolutas como: /home/usuario/proyecto o C:\\Users\\usuario\\proyecto');
});

// Cargar la estructura inicial
fetchFolders();
</script>
</body>
</html>