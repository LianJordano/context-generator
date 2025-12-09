<?php
// UNIFICADOR ULTRA PRO v3.5 - DUAL THEME & DEVICONS
// PHP Logic permanece igual de robusta. UI totalmente renovada.

ini_set('memory_limit', '4096M');
ini_set('max_execution_time', '0');
set_time_limit(0);
date_default_timezone_set('America/Bogota');

# -------------------------
# CONFIGURACIÓN DEFAULT
# -------------------------
$DEFAULT_EXCLUDE_EXT = ['jpg','jpeg','png','gif','webp','svg','ico','mp4','mp3','zip','tar','gz','rar','pdf','exe','dll','class','o','pyc'];
$DEFAULT_EXCLUDE_FOLDERS = ['vendor','node_modules','.git','.idea','.vscode','dist','build','coverage','storage','bin','obj','__pycache__'];
$DEFAULT_EXCLUDE_FILES = ['.env', '.DS_Store', 'Thumbs.db', 'composer.lock', 'package-lock.json', 'yarn.lock'];

# -------------------------
# BACKEND (API)
# -------------------------
$action = $_GET['action'] ?? '';

if ($action === 'list') {
    $base = rtrim($_GET['base'] ?? __DIR__, '/\\');
    $relDir = isset($_GET['dir']) ? trim($_GET['dir'], '/\\') : '';
    $scanDir = $base . ($relDir !== '' ? DIRECTORY_SEPARATOR . $relDir : '');

    if (strpos(realpath($scanDir), realpath($base)) !== 0 || !is_dir($scanDir)) {
        echo json_encode(['error' => 'Ruta inválida']); exit;
    }
    
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

if ($action === 'download') {
    $token = preg_replace('/[^a-z0-9_-]/i', '', $_GET['token'] ?? '');
    $file = sys_get_temp_dir() . DIRECTORY_SEPARATOR . "unified_{$token}.txt";
    if (!$token || !file_exists($file)) die("Archivo expirado.");
    header('Content-Type: text/plain');
    header('Content-Disposition: attachment; filename="proyecto_unificado.txt"');
    header('Content-Length: ' . filesize($file));
    readfile($file); exit;
}

if ($action === 'cancel') {
    $token = preg_replace('/[^a-z0-9_-]/i', '', $_GET['token'] ?? '');
    if ($token) file_put_contents(sys_get_temp_dir() . "/cancel_{$token}.flag", "1");
    exit;
}

if ($action === 'process') {
    header('Content-Type: text/event-stream');
    header('Cache-Control: no-cache');
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

    function sse($evt, $data) { echo "event: $evt\ndata: ".json_encode($data)."\n\n"; ob_flush(); flush(); }

    sse('started', ['token' => $token]);

    $files = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS));
    
    foreach ($iterator as $file) {
        if (file_exists($cancelFlag)) { sse('cancelled', []); exit; }
        $rel = str_replace('\\', '/', ltrim(substr($file->getPathname(), strlen($base)), '/\\'));
        
        // Filtro Selección
        if (!empty($selPaths)) {
            $included = false;
            foreach ($selPaths as $sel) {
                $sel = str_replace('\\', '/', $sel);
                if ($rel === $sel || strpos($rel, $sel.'/') === 0) { $included = true; break; }
            }
            if (!$included) continue;
        }

        // Exclusiones
        $parts = explode('/', $rel);
        foreach ($parts as $p) if (in_array($p, $ex_folders)) continue 2;
        if (in_array(strtolower($file->getExtension()), $ex_ext)) continue;

        $files[] = $rel;
    }

    $total = count($files);
    sse('indexed', ['count' => $total]);

    if ($total > 0) {
        $fh = fopen($outFile, 'w');
        fwrite($fh, "# GENERADO: " . date('Y-m-d H:i:s') . "\n# TOTAL: $total archivos\n\n");
        $i = 0;
        foreach ($files as $f) {
            if (file_exists($cancelFlag)) { fclose($fh); sse('cancelled', []); exit; }
            $i++;
            sse('progress', ['file' => $f, 'pct' => round(($i/$total)*100)]);
            fwrite($fh, str_repeat("=", 50)."\nFILE: $f\n".str_repeat("=", 50)."\n");
            try {
                $c = file_get_contents($base . DIRECTORY_SEPARATOR . $f);
                fwrite($fh, $c . "\n\n");
            } catch(Exception $e) { fwrite($fh, "[ERROR DE LECTURA]\n\n"); }
            if ($total < 300) usleep(2000); 
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
<html lang="es" class="dark"> <!-- Default Dark -->
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Unificador Ultra PRO v3.5</title>
    <!-- Tailwind CSS -->
    <script src="https://cdn.tailwindcss.com"></script>
    <!-- Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
    <!-- Phosphor Icons (UI) -->
    <script src="https://unpkg.com/@phosphor-icons/web"></script>
    <!-- Devicon (Lenguajes) -->
    <link rel="stylesheet" type='text/css' href="https://cdn.jsdelivr.net/gh/devicons/devicon@latest/devicon.min.css" />

    <script>
        tailwind.config = {
            darkMode: 'class',
            theme: {
                extend: {
                    fontFamily: { sans: ['Inter', 'sans-serif'], mono: ['JetBrains Mono', 'monospace'] },
                    colors: {
                        dark: { 900: '#0d1117', 800: '#161b22', 700: '#21262d', 600: '#30363d' }, // Github Dark Dimmed Style
                        light: { 50: '#f9fafb', 100: '#f3f4f6', 200: '#e5e7eb', 300: '#d1d5db' },
                        accent: { 500: '#2563eb', 600: '#1d4ed8' }
                    }
                }
            }
        }
    </script>
    <style>
        /* UI Tweaks */
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
    <header class="h-16 bg-white dark:bg-dark-800 border-b border-light-200 dark:border-dark-600 flex items-center justify-between px-6 shadow-sm z-20 transition-colors duration-300">
        <div class="flex items-center gap-3">
            <div class="w-9 h-9 rounded-lg bg-gradient-to-br from-blue-600 to-indigo-700 flex items-center justify-center shadow-md shadow-blue-500/20">
                <i class="ph ph-stack text-white text-xl"></i>
            </div>
            <div>
                <h1 class="font-bold text-slate-800 dark:text-white leading-tight">Unificador <span class="text-accent-500">Ultra Pro</span></h1>
                <p class="text-[10px] text-slate-500 uppercase tracking-widest font-semibold">File Merger v3.5</p>
            </div>
        </div>
        
        <div class="flex items-center gap-4">
            <!-- Theme Toggle -->
            <button onclick="toggleTheme()" class="w-10 h-10 rounded-full bg-light-100 dark:bg-dark-700 hover:bg-light-200 dark:hover:bg-dark-600 flex items-center justify-center transition-all text-slate-600 dark:text-yellow-400 focus:outline-none focus:ring-2 focus:ring-accent-500" title="Cambiar Tema">
                <i id="themeIcon" class="ph-fill ph-sun text-xl"></i>
            </button>
        </div>
    </header>

    <!-- MAIN -->
    <main class="flex-1 flex overflow-hidden">
        
        <!-- SIDEBAR (LEFT) -->
        <aside class="w-96 bg-white dark:bg-dark-800 border-r border-light-200 dark:border-dark-600 flex flex-col shadow-xl z-10 transition-colors duration-300">
            <!-- Input Path -->
            <div class="p-5 border-b border-light-200 dark:border-dark-600">
                <label class="text-[11px] font-bold text-slate-400 uppercase tracking-wider mb-2 block">Directorio Raíz</label>
                <div class="relative group">
                    <i class="ph ph-folder-open absolute left-3 top-2.5 text-slate-400 group-focus-within:text-accent-500 transition-colors"></i>
                    <input type="text" id="basePath" value="<?php echo htmlspecialchars(str_replace('\\', '/', __DIR__)); ?>" 
                           class="w-full pl-9 pr-10 bg-light-50 dark:bg-dark-900 border border-light-300 dark:border-dark-600 text-sm rounded-md py-2 focus:ring-2 focus:ring-accent-500 focus:border-transparent outline-none transition-all text-slate-700 dark:text-slate-200 placeholder-slate-400 shadow-sm">
                    <button onclick="initTree()" class="absolute right-2 top-1.5 p-1 text-slate-400 hover:text-accent-500 rounded transition-colors" title="Recargar">
                        <i class="ph ph-arrows-clockwise text-lg"></i>
                    </button>
                </div>
            </div>

            <!-- Tree View -->
            <div class="flex-1 overflow-y-auto p-2 bg-light-50/50 dark:bg-dark-800" id="treeContainer">
                <div id="treeRoot" class="text-sm"></div>
            </div>

            <!-- Actions Footer -->
            <div class="p-4 border-t border-light-200 dark:border-dark-600 bg-white dark:bg-dark-800 space-y-3 shadow-[0_-5px_15px_rgba(0,0,0,0.02)]">
                <div class="flex justify-between text-xs font-medium text-slate-500 dark:text-slate-400 mb-1">
                    <button onclick="checkAll(true)" class="hover:text-accent-500 transition-colors">Marcar todo</button>
                    <button onclick="checkAll(false)" class="hover:text-red-400 transition-colors">Limpiar</button>
                </div>
                
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="text-[10px] font-bold text-slate-400 uppercase mb-1 block">Excluir Carpetas</label>
                        <input type="text" id="exFolders" value="<?php echo implode(',', $DEFAULT_EXCLUDE_FOLDERS); ?>" class="w-full bg-light-50 dark:bg-dark-900 border border-light-300 dark:border-dark-600 text-xs rounded px-2 py-1.5 focus:border-accent-500 outline-none text-slate-600 dark:text-slate-400">
                    </div>
                    <div>
                        <label class="text-[10px] font-bold text-slate-400 uppercase mb-1 block">Excluir Ext.</label>
                        <input type="text" id="exExt" value="<?php echo implode(',', $DEFAULT_EXCLUDE_EXT); ?>" class="w-full bg-light-50 dark:bg-dark-900 border border-light-300 dark:border-dark-600 text-xs rounded px-2 py-1.5 focus:border-accent-500 outline-none text-slate-600 dark:text-slate-400">
                    </div>
                </div>

                <button id="btnGo" class="w-full bg-accent-600 hover:bg-accent-500 text-white font-semibold py-3 rounded-md shadow-lg shadow-accent-500/30 transition-all active:scale-[0.98] flex items-center justify-center gap-2 group">
                    <i class="ph ph-lightning text-xl group-hover:text-yellow-300 transition-colors"></i>
                    <span>Unificar Archivos</span>
                </button>
            </div>
        </aside>

        <!-- CONTENT (RIGHT) -->
        <section class="flex-1 flex flex-col bg-light-100 dark:bg-dark-900 relative transition-colors duration-300">
            
            <!-- Progress Overlay -->
            <div id="progressContainer" class="hidden absolute top-0 left-0 w-full z-30">
                <div class="h-1 w-full bg-light-200 dark:bg-dark-700 overflow-hidden">
                    <div id="progressBar" class="h-full bg-accent-500 w-0 transition-all duration-300 shadow-[0_0_10px_#2563eb]"></div>
                </div>
            </div>

            <!-- Empty State -->
            <div id="emptyState" class="absolute inset-0 flex flex-col items-center justify-center opacity-40 pointer-events-none transition-opacity duration-300">
                <div class="w-24 h-24 rounded-full bg-light-200 dark:bg-dark-800 flex items-center justify-center mb-4 text-slate-400 dark:text-slate-600">
                    <i class="ph ph-code text-5xl"></i>
                </div>
                <h3 class="text-xl font-medium text-slate-600 dark:text-slate-400">Esperando instrucciones</h3>
                <p class="text-sm text-slate-500 mt-2">Selecciona archivos del árbol para comenzar</p>
            </div>

            <!-- Terminal -->
            <div id="consoleContainer" class="flex-1 p-8 overflow-y-auto hidden">
                <div class="max-w-4xl mx-auto w-full bg-white dark:bg-[#0f1115] rounded-xl border border-light-300 dark:border-dark-600 shadow-xl overflow-hidden flex flex-col h-full min-h-[400px]">
                    <div class="bg-light-50 dark:bg-dark-800 px-4 py-2 border-b border-light-200 dark:border-dark-600 flex justify-between items-center">
                        <div class="flex gap-2">
                            <div class="w-3 h-3 rounded-full bg-red-400"></div>
                            <div class="w-3 h-3 rounded-full bg-yellow-400"></div>
                            <div class="w-3 h-3 rounded-full bg-green-400"></div>
                        </div>
                        <span class="text-xs font-mono text-slate-400">Output Terminal</span>
                        <button id="btnCancel" class="hidden text-[10px] uppercase font-bold text-red-500 hover:bg-red-50 dark:hover:bg-red-900/20 px-2 py-1 rounded transition-colors">Cancelar</button>
                    </div>
                    <div id="logs" class="flex-1 p-4 font-mono text-xs space-y-1.5 overflow-y-auto bg-white dark:bg-[#0f1115] text-slate-600 dark:text-slate-300"></div>
                </div>
            </div>

            <!-- Success Modal -->
            <div id="successPanel" class="hidden absolute bottom-10 right-10 z-40 animate-[slideIn_0.4s_ease-out]">
                <div class="bg-white dark:bg-dark-700 border border-green-200 dark:border-green-900 p-5 rounded-lg shadow-2xl shadow-black/20 w-80 flex flex-col gap-3">
                    <div class="flex items-center gap-3">
                        <div class="bg-green-100 dark:bg-green-900/30 p-2 rounded-full text-green-600 dark:text-green-400">
                            <i class="ph-fill ph-check-circle text-2xl"></i>
                        </div>
                        <div>
                            <h4 class="font-bold text-slate-800 dark:text-white text-sm">¡Completado!</h4>
                            <p class="text-xs text-slate-500 dark:text-slate-400">Archivo unificado listo.</p>
                        </div>
                    </div>
                    <a id="btnDownload" href="#" class="w-full text-center bg-green-600 hover:bg-green-500 text-white text-sm font-medium py-2 rounded-md transition-colors shadow-md">
                        <i class="ph ph-download-simple mr-1"></i> Descargar
                    </a>
                </div>
            </div>
        </section>
    </main>

    <script>
        const el = id => document.getElementById(id);
        const state = { base: '', processing: false, source: null };

        // --- THEME LOGIC ---
        function initTheme() {
            if (localStorage.theme === 'dark' || (!('theme' in localStorage) && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
                document.documentElement.classList.add('dark');
                updateThemeIcon(true);
            } else {
                document.documentElement.classList.remove('dark');
                updateThemeIcon(false);
            }
        }
        function toggleTheme() {
            const isDark = document.documentElement.classList.toggle('dark');
            localStorage.theme = isDark ? 'dark' : 'light';
            updateThemeIcon(isDark);
        }
        function updateThemeIcon(isDark) {
            const icon = el('themeIcon');
            if(isDark) {
                icon.classList.remove('ph-moon');
                icon.classList.add('ph-sun', 'text-yellow-400');
            } else {
                icon.classList.remove('ph-sun', 'text-yellow-400');
                icon.classList.add('ph-moon', 'text-slate-600');
            }
        }
        initTheme();

        // --- ICON MAPPING (DEVICON) ---
        function getIconClass(ext, name) {
            // Mapeo específico de Devicon
            const map = {
                'php': 'devicon-php-plain colored',
                'js': 'devicon-javascript-plain colored',
                'ts': 'devicon-typescript-plain colored',
                'html': 'devicon-html5-plain colored',
                'css': 'devicon-css3-plain colored',
                'scss': 'devicon-sass-original colored',
                'json': 'devicon-json-plain colored',
                'py': 'devicon-python-plain colored',
                'java': 'devicon-java-plain colored',
                'c': 'devicon-c-plain colored',
                'cpp': 'devicon-cplusplus-plain colored',
                'cs': 'devicon-csharp-plain colored',
                'go': 'devicon-go-original-wordmark colored',
                'rs': 'devicon-rust-plain colored',
                'sql': 'devicon-mysql-plain colored',
                'md': 'devicon-markdown-original dark:text-white', // MD needs help in dark mode if generic
                'yml': 'devicon-yaml-plain colored',
                'xml': 'devicon-xml-plain colored',
                'vue': 'devicon-vuejs-plain colored',
                'jsx': 'devicon-react-original colored',
                'tsx': 'devicon-react-original colored',
                'rb': 'devicon-ruby-plain colored',
                'swift': 'devicon-swift-plain colored'
            };

            // Archivos especiales por nombre
            if(name === 'composer.json') return 'devicon-composer-line colored';
            if(name === 'package.json') return 'devicon-npm-original-wordmark colored';
            if(name === 'Dockerfile') return 'devicon-docker-plain colored';
            if(name === '.gitignore') return 'devicon-git-plain colored';

            if (map[ext]) return map[ext];
            
            // Fallback a iconos genéricos de Phosphor si no hay Devicon
            return 'ph ph-file-text text-slate-400';
        }

        // --- TREE LOGIC ---
        function initTree() {
            state.base = el('basePath').value.trim();
            if(!state.base) return alert('Define una ruta');
            const root = el('treeRoot');
            root.innerHTML = `<div class="p-4 text-center text-slate-400 flex flex-col items-center gap-2"><i class="ph ph-spinner animate-spin text-2xl"></i><span class="text-xs">Escaneando...</span></div>`;
            loadDir(state.base, '', root, true);
        }

        async function loadDir(base, rel, container, isRoot = false) {
            try {
                const res = await fetch(`?action=list&base=${encodeURIComponent(base)}&dir=${encodeURIComponent(rel)}`);
                const data = await res.json();
                container.innerHTML = '';
                
                if(data.error) throw new Error(data.error);
                if(!data.items || data.items.length === 0) {
                    container.innerHTML = `<div class="pl-6 py-1 text-slate-400 italic text-[10px]">Vacío</div>`;
                    return;
                }

                const ul = document.createElement('ul');
                ul.className = isRoot ? '' : 'pl-4 border-l border-light-200 dark:border-dark-600 ml-2 mt-1';
                
                data.items.forEach(item => {
                    const li = document.createElement('li');
                    li.className = 'tree-enter my-0.5';
                    const uid = btoa(item.path).replace(/=/g, ''); 
                    const isDir = item.type === 'dir';
                    
                    // Icon Selection
                    let iconHtml = '';
                    if (isDir) {
                        iconHtml = `<i class="ph-fill ph-folder text-blue-400 dark:text-blue-500 text-lg"></i>`;
                    } else {
                        const iconClass = getIconClass(item.ext, item.name);
                        // Si es Devicon (contiene 'devicon-') es clase i, si es ph es clase i tambien
                        iconHtml = `<i class="${iconClass} text-lg"></i>`;
                    }

                    li.innerHTML = `
                        <div class="group flex items-center gap-2 py-1.5 px-2 rounded-md hover:bg-light-200 dark:hover:bg-dark-700 cursor-pointer select-none transition-colors" data-path="${item.path}">
                            <span class="w-4 h-4 flex items-center justify-center text-slate-400 hover:text-slate-600 dark:hover:text-white toggle-btn text-[10px] transition-transform">
                                ${isDir ? '<i class="ph-bold ph-caret-right"></i>' : ''}
                            </span>
                            <div class="checkbox-wrapper flex items-center">
                                <input type="checkbox" class="appearance-none w-4 h-4 border border-light-400 dark:border-dark-500 rounded bg-white dark:bg-dark-900 cursor-pointer transition-all" value="${item.path}">
                            </div>
                            <span class="flex items-center justify-center w-5">${iconHtml}</span>
                            <span class="text-slate-600 dark:text-slate-300 group-hover:text-slate-900 dark:group-hover:text-white truncate text-[13px] font-medium">${item.name}</span>
                        </div>
                        ${isDir ? `<div id="sub-${uid}" class="hidden"></div>` : ''}
                    `;
                    ul.appendChild(li);

                    // Event Listeners
                    const row = li.querySelector('div');
                    const chk = li.querySelector('input');
                    const toggle = li.querySelector('.toggle-btn');
                    
                    if(isDir) {
                        const sub = li.querySelector(`#sub-${uid}`);
                        const doToggle = (e) => {
                            e.stopPropagation();
                            const isOpen = !sub.classList.contains('hidden');
                            if(isOpen) {
                                sub.classList.add('hidden');
                                toggle.innerHTML = '<i class="ph-bold ph-caret-right"></i>';
                            } else {
                                sub.classList.remove('hidden');
                                toggle.innerHTML = '<i class="ph-bold ph-caret-down"></i>';
                                if(!sub.hasChildNodes()) {
                                    sub.innerHTML = `<div class="pl-6 text-[10px] text-slate-400">Cargando...</div>`;
                                    loadDir(base, item.path, sub);
                                }
                            }
                        };
                        toggle.addEventListener('click', doToggle);
                        row.addEventListener('dblclick', doToggle);
                    }

                    chk.addEventListener('click', e => e.stopPropagation());
                    chk.addEventListener('change', () => {
                        if(isDir) {
                            const sub = li.querySelector(`#sub-${uid}`);
                            if(sub) sub.querySelectorAll('input').forEach(c => c.checked = chk.checked);
                        }
                    });
                    
                    row.addEventListener('click', (e) => {
                        if(e.target !== toggle && !e.target.closest('.toggle-btn')) {
                            chk.checked = !chk.checked;
                            chk.dispatchEvent(new Event('change'));
                        }
                    });
                });
                container.appendChild(ul);
            } catch (err) { container.innerHTML = `<div class="text-red-500 text-xs p-2">Error: ${err.message}</div>`; }
        }

        // --- CONSOLE & PROCESS ---
        function log(msg, type = 'info') {
            const colors = { 
                info: 'text-slate-500 dark:text-slate-400', 
                success: 'text-green-600 dark:text-green-400 font-bold', 
                error: 'text-red-500 dark:text-red-400 font-bold', 
                warn: 'text-yellow-600 dark:text-yellow-400' 
            };
            const div = document.createElement('div');
            div.className = `${colors[type]} flex gap-3 border-l-2 border-transparent pl-2 hover:bg-light-100 dark:hover:bg-white/5 py-0.5`;
            if(type === 'error') div.classList.add('border-red-500');
            div.innerHTML = `<span class="opacity-40 select-none w-16 text-right">${new Date().toLocaleTimeString('es-ES',{hour12:false})}</span> <span class="break-all">${msg}</span>`;
            el('logs').appendChild(div);
            el('logs').parentElement.scrollTop = el('logs').parentElement.scrollHeight;
        }

        el('btnGo').onclick = () => {
            const checks = document.querySelectorAll('input[type="checkbox"]:checked');
            const paths = Array.from(checks).map(c => c.value);

            el('emptyState').classList.add('opacity-0');
            setTimeout(() => el('emptyState').classList.add('hidden'), 300);
            
            el('consoleContainer').classList.remove('hidden');
            el('successPanel').classList.add('hidden');
            el('logs').innerHTML = '';
            el('progressContainer').classList.remove('hidden');
            el('progressBar').style.width = '0%';
            el('btnCancel').classList.remove('hidden');
            el('btnGo').disabled = true;
            el('btnGo').classList.add('opacity-50', 'cursor-not-allowed');

            log('Inicializando motor de unificación...', 'warn');
            log(`Rutas objetivo: ${paths.length ? paths.length : 'Modo Raíz (Todo)'}`, 'info');

            const params = new URLSearchParams();
            params.append('action', 'process');
            params.append('base', state.base);
            params.append('exclude_folders', el('exFolders').value);
            params.append('exclude_ext', el('exExt').value);
            paths.forEach(p => params.append('paths[]', p));

            if(state.source) state.source.close();
            state.source = new EventSource('?' + params.toString());
            
            state.source.addEventListener('started', e => log(`Token de sesión: ${JSON.parse(e.data).token}`, 'info'));
            state.source.addEventListener('indexed', e => log(`Indexado completado: ${JSON.parse(e.data).count} archivos encontrados.`, 'info'));
            state.source.addEventListener('progress', e => {
                const d = JSON.parse(e.data);
                el('progressBar').style.width = d.pct + '%';
                if(Math.random() > 0.7) log(`Leyendo: ${d.file}`, 'info');
            });
            state.source.addEventListener('done', e => {
                const d = JSON.parse(e.data);
                finish();
                if(d.url) {
                    el('progressBar').style.width = '100%';
                    log('Generación exitosa.', 'success');
                    el('btnDownload').href = d.url;
                    el('successPanel').classList.remove('hidden');
                } else {
                    log(d.error || 'Proceso finalizado sin datos.', 'error');
                }
            });
            state.source.addEventListener('cancelled', () => { log('Operación abortada por el usuario.', 'error'); finish(); });
            state.source.onerror = () => { log('Conexión perdida con el servidor.', 'error'); finish(); };
        };

        el('btnCancel').onclick = () => {
            fetch('?action=cancel&token=1');
            if(state.source) state.source.close();
            log('Enviando señal de parada...', 'warn');
        };

        function finish() {
            if(state.source) state.source.close();
            el('btnGo').disabled = false;
            el('btnGo').classList.remove('opacity-50', 'cursor-not-allowed');
            el('btnCancel').classList.add('hidden');
            setTimeout(() => el('progressContainer').classList.add('hidden'), 1000);
        }

        function checkAll(val) { document.querySelectorAll('input[type="checkbox"]').forEach(c => c.checked = val); }
        if(el('basePath').value) initTree();

    </script>
</body>
</html>