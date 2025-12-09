<?php
// UNIFICADOR ULTRA PRO v4.5 - PROJECT STRUCTURE EDITION
// Features: UI Pro, Dark Mode, Anti-Freeze Streaming, Árbol de Directorios ASCII.

// Configuración de Buffer para evitar bloqueos
@ini_set('zlib.output_compression', 0);
@ini_set('implicit_flush', 1);
@ob_end_clean(); 
set_time_limit(0);
ini_set('memory_limit', '4096M');
date_default_timezone_set('America/Bogota');

# -------------------------
# CONFIGURACIÓN
# -------------------------
$DEFAULT_EXCLUDE_EXT = ['jpg','jpeg','png','gif','webp','svg','ico','mp4','mp3','zip','tar','gz','rar','pdf','exe','dll','class','o','pyc'];
$DEFAULT_EXCLUDE_FOLDERS = ['vendor','node_modules','.git','.idea','.vscode','dist','build','coverage','storage','bin','obj','__pycache__'];

# -------------------------
# FUNCIONES AUXILIARES
# -------------------------
// Función para generar el árbol ASCII a partir de rutas planas
function generateAsciiTree($paths) {
    $tree = [];
    // 1. Construir jerarquía
    foreach ($paths as $path) {
        $parts = explode('/', $path);
        $curr = &$tree;
        foreach ($parts as $part) {
            if (!isset($curr[$part])) $curr[$part] = [];
            $curr = &$curr[$part];
        }
    }
    
    // 2. Renderizar recursivamente
    $render = function($items, $prefix = '') use (&$render) {
        $out = '';
        $keys = array_keys($items);
        
        // Ordenar: Carpetas primero, luego archivos
        usort($keys, function($a, $b) use ($items) {
            $aIsContent = !empty($items[$a]);
            $bIsContent = !empty($items[$b]);
            if ($aIsContent === $bIsContent) return strnatcasecmp($a, $b);
            return $aIsContent ? -1 : 1; // Carpetas arriba
        });

        $lastIdx = count($keys) - 1;
        foreach ($keys as $i => $name) {
            $isLast = ($i === $lastIdx);
            $connector = $isLast ? '└── ' : '├── ';
            $out .= $prefix . $connector . $name . "\n";
            
            $children = $items[$name];
            if (!empty($children)) {
                $childPrefix = $prefix . ($isLast ? '    ' : '│   ');
                $out .= $render($children, $childPrefix);
            }
        }
        return $out;
    };
    
    return ".\n" . $render($tree);
}

# -------------------------
# BACKEND
# -------------------------
$action = $_GET['action'] ?? '';

// LISTAR
if ($action === 'list') {
    $base = rtrim($_GET['base'] ?? __DIR__, '/\\');
    $relDir = isset($_GET['dir']) ? trim($_GET['dir'], '/\\') : '';
    $scanDir = $base . ($relDir !== '' ? DIRECTORY_SEPARATOR . $relDir : '');

    if (!is_dir($scanDir)) { echo json_encode(['error' => 'Ruta inválida']); exit; }
    
    $items = [];
    try {
        foreach (new DirectoryIterator($scanDir) as $fileinfo) {
            if ($fileinfo->isDot()) continue;
            $items[] = [
                'name' => $fileinfo->getFilename(),
                'path' => $relDir ? $relDir . '/' . $fileinfo->getFilename() : $fileinfo->getFilename(),
                'type' => $fileinfo->isDir() ? 'dir' : 'file',
                'ext'  => strtolower($fileinfo->getExtension())
            ];
        }
        usort($items, function($a, $b) {
            return ($a['type'] === $b['type']) ? strnatcasecmp($a['name'], $b['name']) : ($a['type'] === 'dir' ? -1 : 1);
        });
    } catch (Exception $e) { echo json_encode(['error' => $e->getMessage()]); exit; }
    echo json_encode(['items' => $items]); exit;
}

// DESCARGAR
if ($action === 'download') {
    $token = preg_replace('/[^a-z0-9_-]/i', '', $_GET['token'] ?? '');
    $file = sys_get_temp_dir() . DIRECTORY_SEPARATOR . "unified_{$token}.txt";
    if (!$token || !file_exists($file)) die("Archivo expirado.");
    header('Content-Type: text/plain');
    header('Content-Disposition: attachment; filename="proyecto_unificado.txt"');
    header('Content-Length: ' . filesize($file));
    readfile($file); exit;
}

// CANCELAR
if ($action === 'cancel') {
    $token = preg_replace('/[^a-z0-9_-]/i', '', $_GET['token'] ?? '');
    if ($token) file_put_contents(sys_get_temp_dir() . "/cancel_{$token}.flag", "1");
    exit;
}

// PROCESAR (SSE)
if ($action === 'process') {
    header('Content-Type: text/event-stream');
    header('Cache-Control: no-cache');
    header('Connection: keep-alive');
    header('X-Accel-Buffering: no');
    
    $base = rtrim($_GET['base'] ?? __DIR__, '/\\');
    $selPaths = $_GET['paths'] ?? [];
    $ex_ext = explode(',', $_GET['exclude_ext'] ?? '');
    $ex_folders = explode(',', $_GET['exclude_folders'] ?? '');
    $token = preg_replace('/[^a-z0-9_-]/i', '', $_GET['token'] ?? bin2hex(random_bytes(6)));
    
    $tmp = sys_get_temp_dir();
    $outFile = $tmp . "/unified_{$token}.txt";
    $cancelFlag = $tmp . "/cancel_{$token}.flag";
    if (file_exists($cancelFlag)) @unlink($cancelFlag);

    function sse($evt, $data) { 
        echo "event: $evt\n";
        echo "data: " . json_encode($data) . "\n\n";
        echo str_repeat(' ', 4096); echo "\n"; // Padding Anti-Freeze
        @ob_flush(); @flush(); 
    }

    sse('started', ['token' => $token]);

    // 1. Indexado
    $files = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS));
    
    foreach ($iterator as $file) {
        if (file_exists($cancelFlag)) { sse('cancelled', []); exit; }
        $rel = str_replace('\\', '/', ltrim(substr($file->getPathname(), strlen($base)), '/\\'));
        
        // Filtros
        if (!empty($selPaths)) {
            $included = false;
            foreach ($selPaths as $sel) {
                $sel = str_replace('\\', '/', $sel);
                if ($rel === $sel || strpos($rel, $sel.'/') === 0) { $included = true; break; }
            }
            if (!$included) continue;
        }
        $parts = explode('/', $rel);
        foreach ($parts as $p) if (in_array($p, $ex_folders)) continue 2;
        if (in_array(strtolower($file->getExtension()), $ex_ext)) continue;

        $files[] = $rel;
    }

    $total = count($files);
    sse('indexed', ['count' => $total]);

    if ($total > 0) {
        $fh = fopen($outFile, 'w');
        
        // --- NUEVO: Escribir Estructura del Proyecto (Árbol) ---
        fwrite($fh, "# REPORTE DE UNIFICACIÓN\n");
        fwrite($fh, "# FECHA: " . date('Y-m-d H:i:s') . "\n");
        fwrite($fh, "# ARCHIVOS TOTALES: $total\n\n");
        
        fwrite($fh, str_repeat("=", 50) . "\n");
        fwrite($fh, " ESTRUCTURA DEL PROYECTO \n");
        fwrite($fh, str_repeat("=", 50) . "\n");
        
        // Generar árbol y escribirlo
        $asciiTree = generateAsciiTree($files);
        fwrite($fh, $asciiTree);
        fwrite($fh, "\n" . str_repeat("=", 50) . "\n\n");
        // -----------------------------------------------------

        $i = 0;
        foreach ($files as $f) {
            if (file_exists($cancelFlag)) { fclose($fh); sse('cancelled', []); exit; }
            $i++;
            
            sse('progress', ['file' => $f, 'pct' => round(($i/$total)*100)]);
            
            fwrite($fh, "====== INICIO ARCHIVO: $f ======\n");
            try {
                $c = file_get_contents($base . DIRECTORY_SEPARATOR . $f);
                fwrite($fh, $c . "\n");
            } catch(Exception $e) { fwrite($fh, "[ERROR LECTURA]\n"); }
            fwrite($fh, "====== FIN ARCHIVO: $f ======\n\n");
            
            usleep(5000); // Pausa minúscula para UI fluida
        }
        fclose($fh);
        sse('done', ['url' => "?action=download&token=$token"]);
    } else {
        sse('done', ['error' => 'Sin archivos para procesar.']);
    }
    exit;
}
?>
<!DOCTYPE html>
<html lang="es" class="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Unificador Ultra PRO v4.5</title>
    <!-- Tailwind CSS -->
    <script src="https://cdn.tailwindcss.com"></script>
    <!-- Fonts & Icons -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
    <script src="https://unpkg.com/@phosphor-icons/web"></script>
    <link rel="stylesheet" type='text/css' href="https://cdn.jsdelivr.net/gh/devicons/devicon@latest/devicon.min.css" />

    <script>
        tailwind.config = {
            darkMode: 'class',
            theme: {
                extend: {
                    fontFamily: { sans: ['Inter', 'sans-serif'], mono: ['JetBrains Mono', 'monospace'] },
                    colors: {
                        dark: { 900: '#0d1117', 800: '#161b22', 700: '#21262d', 600: '#30363d' }, 
                        light: { 50: '#f9fafb', 100: '#f3f4f6', 200: '#e5e7eb', 300: '#d1d5db' },
                        accent: { 500: '#2563eb', 600: '#1d4ed8' }
                    }
                }
            }
        }
    </script>
    <style>
        body { transition: background-color 0.3s ease, color 0.3s ease; }
        ::-webkit-scrollbar { width: 8px; height: 8px; }
        .dark ::-webkit-scrollbar-track { background: #0d1117; }
        .dark ::-webkit-scrollbar-thumb { background: #30363d; border-radius: 4px; }
        ::-webkit-scrollbar-track { background: #f3f4f6; }
        ::-webkit-scrollbar-thumb { background: #d1d5db; border-radius: 4px; }
        .tree-enter { animation: slideIn 0.2s ease-out; }
        @keyframes slideIn { from { opacity: 0; transform: translateX(-5px); } to { opacity: 1; transform: translateX(0); } }
        .checkbox-wrapper input:checked { background-color: #2563eb; border-color: #2563eb; background-image: url("data:image/svg+xml,%3csvg viewBox='0 0 16 16' fill='white' xmlns='http://www.w3.org/2000/svg'%3e%3cpath d='M12.207 4.793a1 1 0 010 1.414l-5 5a1 1 0 01-1.414 0l-2-2a1 1 0 011.414-1.414L6.5 9.086l4.293-4.293a1 1 0 011.414 0z'/%3e%3c/svg%3e"); }
    </style>
</head>
<body class="bg-light-50 text-slate-700 dark:bg-dark-900 dark:text-slate-300 font-sans h-screen flex flex-col overflow-hidden">

    <!-- HEADER -->
    <header class="h-16 bg-white dark:bg-dark-800 border-b border-light-200 dark:border-dark-600 flex items-center justify-between px-6 shadow-sm z-20 transition-colors">
        <div class="flex items-center gap-3">
            <div class="w-9 h-9 rounded-lg bg-gradient-to-br from-blue-600 to-indigo-700 flex items-center justify-center shadow-md">
                <i class="ph ph-tree-structure text-white text-xl"></i>
            </div>
            <div>
                <h1 class="font-bold text-slate-800 dark:text-white leading-tight">Unificador <span class="text-accent-500">v4.5</span></h1>
                <p class="text-[10px] text-slate-500 uppercase tracking-widest font-semibold">Structure Aware</p>
            </div>
        </div>
        <button onclick="toggleTheme()" class="w-10 h-10 rounded-full bg-light-100 dark:bg-dark-700 hover:bg-light-200 dark:hover:bg-dark-600 flex items-center justify-center transition-all text-slate-600 dark:text-yellow-400">
            <i id="themeIcon" class="ph-fill ph-sun text-xl"></i>
        </button>
    </header>

    <main class="flex-1 flex overflow-hidden">
        
        <!-- SIDEBAR -->
        <aside class="w-96 bg-white dark:bg-dark-800 border-r border-light-200 dark:border-dark-600 flex flex-col shadow-xl z-10 transition-colors">
            <div class="p-5 border-b border-light-200 dark:border-dark-600">
                <label class="text-[11px] font-bold text-slate-400 uppercase tracking-wider mb-2 block">Directorio Raíz</label>
                <div class="relative group">
                    <input type="text" id="basePath" value="<?php echo htmlspecialchars(str_replace('\\', '/', __DIR__)); ?>" 
                           class="w-full pl-3 pr-10 bg-light-50 dark:bg-dark-900 border border-light-300 dark:border-dark-600 text-sm rounded-md py-2 focus:ring-2 focus:ring-accent-500 outline-none transition-all text-slate-700 dark:text-slate-200">
                    <button onclick="initTree()" class="absolute right-2 top-1.5 p-1 text-slate-400 hover:text-accent-500" title="Recargar"><i class="ph ph-arrows-clockwise text-lg"></i></button>
                </div>
            </div>

            <div class="flex-1 overflow-y-auto p-2 bg-light-50/50 dark:bg-dark-800" id="treeContainer">
                <div id="treeRoot" class="text-sm"></div>
            </div>

            <div class="p-4 border-t border-light-200 dark:border-dark-600 bg-white dark:bg-dark-800 space-y-3">
                <div class="flex justify-between text-xs font-medium text-slate-500 dark:text-slate-400 mb-1">
                    <button onclick="checkAll(true)" class="hover:text-accent-500">Marcar todo</button>
                    <button onclick="checkAll(false)" class="hover:text-red-400">Limpiar</button>
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <input type="text" id="exFolders" value="<?php echo implode(',', $DEFAULT_EXCLUDE_FOLDERS); ?>" class="bg-light-50 dark:bg-dark-900 border border-light-300 dark:border-dark-600 text-xs rounded px-2 py-1.5 outline-none text-slate-600 dark:text-slate-400 placeholder-slate-400" placeholder="Excluir carpetas">
                    <input type="text" id="exExt" value="<?php echo implode(',', $DEFAULT_EXCLUDE_EXT); ?>" class="bg-light-50 dark:bg-dark-900 border border-light-300 dark:border-dark-600 text-xs rounded px-2 py-1.5 outline-none text-slate-600 dark:text-slate-400 placeholder-slate-400" placeholder="Excluir ext">
                </div>
                <button id="btnGo" class="w-full bg-accent-600 hover:bg-accent-500 text-white font-semibold py-3 rounded-md shadow-lg shadow-accent-500/30 transition-all active:scale-[0.98] flex items-center justify-center gap-2 group">
                    <i class="ph ph-lightning text-xl group-hover:text-yellow-300"></i><span>Unificar Archivos</span>
                </button>
            </div>
        </aside>

        <!-- MAIN CONTENT -->
        <section class="flex-1 flex flex-col bg-light-100 dark:bg-dark-900 relative transition-colors">
            
            <!-- PROGRESS BAR -->
            <div id="progressContainer" class="hidden w-full bg-light-200 dark:bg-dark-700 h-1.5 relative z-30">
                <div id="progressBar" class="h-full bg-accent-500 w-0 transition-all duration-150 ease-out shadow-[0_0_15px_#2563eb]"></div>
            </div>

            <!-- EMPTY STATE -->
            <div id="emptyState" class="absolute inset-0 flex flex-col items-center justify-center opacity-40 pointer-events-none transition-opacity">
                <i class="ph ph-files text-6xl mb-4 text-slate-400"></i>
                <h3 class="text-xl font-medium text-slate-600 dark:text-slate-400">Selecciona archivos</h3>
            </div>

            <!-- CONSOLE -->
            <div id="consoleContainer" class="flex-1 p-8 overflow-y-auto hidden">
                <div class="max-w-4xl mx-auto w-full bg-white dark:bg-[#0f1115] rounded-xl border border-light-300 dark:border-dark-600 shadow-xl overflow-hidden flex flex-col h-full">
                    <div class="bg-light-50 dark:bg-dark-800 px-4 py-2 border-b border-light-200 dark:border-dark-600 flex justify-between items-center">
                        <span class="text-xs font-mono text-slate-400">System Log</span>
                        <button id="btnCancel" class="hidden text-[10px] uppercase font-bold text-red-500 hover:bg-red-50 dark:hover:bg-red-900/20 px-2 py-1 rounded">Cancelar</button>
                    </div>
                    <div id="logs" class="flex-1 p-4 font-mono text-xs space-y-1 overflow-y-auto bg-white dark:bg-[#0f1115] text-slate-600 dark:text-slate-300"></div>
                </div>
            </div>

            <!-- SUCCESS PANEL -->
            <div id="successPanel" class="hidden absolute bottom-10 right-10 z-40 animate-[slideIn_0.4s_ease-out]">
                <div class="bg-white dark:bg-dark-700 border border-green-200 dark:border-green-900 p-5 rounded-lg shadow-2xl w-80 flex flex-col gap-3">
                    <div class="flex items-center gap-3">
                        <i class="ph-fill ph-check-circle text-2xl text-green-500"></i>
                        <div>
                            <h4 class="font-bold text-slate-800 dark:text-white text-sm">¡Completado!</h4>
                            <p class="text-xs text-slate-500">Archivo generado con estructura.</p>
                        </div>
                    </div>
                    <a id="btnDownload" href="#" class="w-full text-center bg-green-600 hover:bg-green-500 text-white text-sm font-medium py-2 rounded-md transition-colors shadow-md">Descargar .txt</a>
                </div>
            </div>
        </section>
    </main>

    <script>
        const el = id => document.getElementById(id);
        const state = { base: '', source: null };

        // THEME
        function initTheme() {
            if (localStorage.theme === 'dark' || (!('theme' in localStorage) && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
                document.documentElement.classList.add('dark'); updateThemeIcon(true);
            } else { document.documentElement.classList.remove('dark'); updateThemeIcon(false); }
        }
        function toggleTheme() {
            const isDark = document.documentElement.classList.toggle('dark');
            localStorage.theme = isDark ? 'dark' : 'light';
            updateThemeIcon(isDark);
        }
        function updateThemeIcon(isDark) {
            const icon = el('themeIcon');
            icon.className = isDark ? 'ph-fill ph-sun text-xl text-yellow-400' : 'ph-fill ph-moon text-xl text-slate-600';
        }
        initTheme();

        // ICONS
        function getIconClass(ext, name) {
            const map = {
                'php': 'devicon-php-plain colored', 'js': 'devicon-javascript-plain colored', 'html': 'devicon-html5-plain colored',
                'css': 'devicon-css3-plain colored', 'py': 'devicon-python-plain colored', 'java': 'devicon-java-plain colored',
                'json': 'devicon-json-plain colored', 'sql': 'devicon-mysql-plain colored', 'ts': 'devicon-typescript-plain colored'
            };
            if(name === 'package.json') return 'devicon-npm-original-wordmark colored';
            return map[ext] || 'ph ph-file-text text-slate-400';
        }

        // TREE VIEW LOGIC
        function initTree() {
            state.base = el('basePath').value.trim();
            if(!state.base) return alert('Define una ruta');
            const root = el('treeRoot');
            root.innerHTML = `<div class="p-4 text-center text-slate-400"><i class="ph ph-spinner animate-spin text-2xl"></i></div>`;
            loadDir(state.base, '', root, true);
        }

        async function loadDir(base, rel, container, isRoot) {
            try {
                const res = await fetch(`?action=list&base=${encodeURIComponent(base)}&dir=${encodeURIComponent(rel)}`);
                const data = await res.json();
                container.innerHTML = '';
                if(data.error) throw new Error(data.error);
                if(!data.items || !data.items.length) { container.innerHTML = `<div class="pl-6 py-1 text-slate-400 text-[10px]">Vacío</div>`; return; }

                const ul = document.createElement('ul');
                ul.className = isRoot ? '' : 'pl-4 border-l border-light-200 dark:border-dark-600 ml-2 mt-1';
                
                data.items.forEach(item => {
                    const li = document.createElement('li');
                    const uid = btoa(item.path).replace(/=/g, '');
                    const isDir = item.type === 'dir';
                    const icon = isDir ? '<i class="ph-fill ph-folder text-blue-400 text-lg"></i>' : `<i class="${getIconClass(item.ext, item.name)} text-lg"></i>`;

                    li.innerHTML = `
                        <div class="group flex items-center gap-2 py-1 px-2 rounded hover:bg-light-200 dark:hover:bg-dark-700 cursor-pointer select-none" data-path="${item.path}">
                            <span class="w-4 h-4 flex items-center justify-center text-slate-400 toggle-btn text-[10px]">${isDir ? '<i class="ph-bold ph-caret-right"></i>' : ''}</span>
                            <div class="checkbox-wrapper flex"><input type="checkbox" class="appearance-none w-4 h-4 border border-slate-300 dark:border-slate-600 rounded bg-white dark:bg-dark-900 cursor-pointer" value="${item.path}"></div>
                            <span class="flex w-5 justify-center">${icon}</span>
                            <span class="text-slate-600 dark:text-slate-300 group-hover:text-slate-900 dark:group-hover:text-white truncate text-[13px]">${item.name}</span>
                        </div>
                        ${isDir ? `<div id="sub-${uid}" class="hidden"></div>` : ''}
                    `;
                    ul.appendChild(li);

                    const row = li.querySelector('div'), chk = li.querySelector('input'), toggle = li.querySelector('.toggle-btn');
                    if(isDir) {
                        const sub = li.querySelector(`#sub-${uid}`);
                        const doToggle = (e) => {
                            e.stopPropagation();
                            if(sub.classList.toggle('hidden')) toggle.innerHTML = '<i class="ph-bold ph-caret-right"></i>';
                            else { toggle.innerHTML = '<i class="ph-bold ph-caret-down"></i>'; if(!sub.hasChildNodes()) loadDir(base, item.path, sub); }
                        };
                        toggle.addEventListener('click', doToggle);
                        row.addEventListener('dblclick', doToggle);
                    }
                    chk.addEventListener('click', e => e.stopPropagation());
                    chk.addEventListener('change', () => { if(isDir) li.querySelector(`#sub-${uid}`)?.querySelectorAll('input').forEach(c => c.checked = chk.checked); });
                    row.addEventListener('click', e => { if(e.target!==toggle) { chk.checked=!chk.checked; chk.dispatchEvent(new Event('change')); }});
                });
                container.appendChild(ul);
            } catch(e) { container.innerHTML = `<div class="text-red-500 text-xs p-2">${e.message}</div>`; }
        }

        // APP LOGIC
        function log(msg, type='info') {
            const d = document.createElement('div');
            const clr = { info: 'text-slate-500 dark:text-slate-400', success: 'text-green-500 font-bold', error: 'text-red-500 font-bold' };
            d.className = `${clr[type] || clr.info} text-xs py-0.5 border-l-2 border-transparent pl-2`;
            d.innerText = `> ${msg}`;
            el('logs').appendChild(d);
            el('logs').parentElement.scrollTop = el('logs').parentElement.scrollHeight;
        }

        el('btnGo').onclick = () => {
            const checks = document.querySelectorAll('input[type="checkbox"]:checked');
            const paths = Array.from(checks).map(c => c.value);

            el('emptyState').classList.add('hidden');
            el('consoleContainer').classList.remove('hidden');
            el('successPanel').classList.add('hidden');
            el('logs').innerHTML = '';
            el('progressContainer').classList.remove('hidden');
            el('progressBar').style.width = '0%';
            el('btnCancel').classList.remove('hidden');
            el('btnGo').disabled = true;
            el('btnGo').classList.add('opacity-50');

            const params = new URLSearchParams({ 
                action: 'process', base: state.base, 
                exclude_folders: el('exFolders').value, exclude_ext: el('exExt').value 
            });
            paths.forEach(p => params.append('paths[]', p));

            if(state.source) state.source.close();
            state.source = new EventSource('?' + params.toString());
            
            state.source.addEventListener('started', e => log('Iniciando indexado...', 'info'));
            state.source.addEventListener('indexed', e => log(`Se encontraron ${JSON.parse(e.data).count} archivos.`, 'info'));
            
            state.source.addEventListener('progress', e => {
                const d = JSON.parse(e.data);
                requestAnimationFrame(() => el('progressBar').style.width = d.pct + '%');
                if(Math.random() > 0.8) log(d.file); 
            });

            state.source.addEventListener('done', e => {
                const d = JSON.parse(e.data);
                finish();
                if(d.url) {
                    el('progressBar').style.width = '100%';
                    el('btnDownload').href = d.url;
                    el('successPanel').classList.remove('hidden');
                    log('GENERACIÓN EXITOSA (Incluye Estructura de Proyecto)', 'success');
                } else log(d.error, 'error');
            });

            state.source.onerror = () => { log('Error de conexión', 'error'); finish(); };
        };

        el('btnCancel').onclick = () => { fetch('?action=cancel&token=1'); if(state.source) state.source.close(); log('Cancelado'); };

        function finish() {
            if(state.source) state.source.close();
            el('btnGo').disabled = false;
            el('btnGo').classList.remove('opacity-50');
            setTimeout(() => el('progressContainer').classList.add('hidden'), 1000);
        }

        function checkAll(v) { document.querySelectorAll('input[type="checkbox"]').forEach(c => c.checked = v); }
        if(el('basePath').value) initTree();
    </script>
</body>
</html>