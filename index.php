<?php
// UNIFICADOR ULTRA PRO v5.0 - AI CONTEXT EDITION
// Features: UI Pro, Dark Mode, Árbol ASCII, Estimación Tokens, Limpieza Semántica, Prompt Injection.

// Configuración de Buffer para streaming fluido
@ini_set('zlib.output_compression', 0);
@ini_set('implicit_flush', 1);
@ob_end_clean(); 
set_time_limit(0);
ini_set('memory_limit', '4096M');
date_default_timezone_set('America/Bogota');

# -------------------------
# CONFIGURACIÓN
# -------------------------
$DEFAULT_EXCLUDE_EXT = ['jpg','jpeg','png','gif','webp','svg','ico','mp4','mp3','zip','tar','gz','rar','pdf','exe','dll','class','o','pyc','iso','dmg'];
$DEFAULT_EXCLUDE_FOLDERS = ['vendor','node_modules','.git','.idea','.vscode','dist','build','coverage','storage','bin','obj','__pycache__','env','venv'];
// Archivos que JAMÁS deben leerse por seguridad
$SECURITY_BLOCKLIST = ['.env', 'id_rsa', 'id_rsa.pub', '.pem', '.key', 'wp-config.php', 'config.php', '.secret'];

# -------------------------
# FUNCIONES AUXILIARES
# -------------------------
function generateAsciiTree($paths) {
    $tree = [];
    foreach ($paths as $path) {
        $parts = explode('/', $path);
        $curr = &$tree;
        foreach ($parts as $part) {
            if (!isset($curr[$part])) $curr[$part] = [];
            $curr = &$curr[$part];
        }
    }
    
    $render = function($items, $prefix = '') use (&$render) {
        $out = '';
        $keys = array_keys($items);
        usort($keys, function($a, $b) use ($items) {
            $aIsContent = !empty($items[$a]);
            $bIsContent = !empty($items[$b]);
            if ($aIsContent === $bIsContent) return strnatcasecmp($a, $b);
            return $aIsContent ? -1 : 1; 
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

// Limpieza básica de código para ahorrar tokens
function cleanCode($content, $ext) {
    // 1. PHP: Usar función nativa (elimina comentarios y espacios)
    if ($ext === 'php') {
        // Un hack sucio pero efectivo: php_strip_whitespace requiere archivo, simulamos con tmp o regex
        // Como ya leímos el contenido, usamos Regex robusto para PHP
        $content = preg_replace('!/\*.*?\*/!s', '', $content); // Block comments
        $content = preg_replace('/\n\s*\n/', "\n", $content);   // Empty lines
        return trim($content);
    }
    
    // 2. JS, CSS, JAVA, C, ETC: Eliminar bloques y reducir líneas vacías
    if (in_array($ext, ['js', 'css', 'ts', 'tsx', 'jsx', 'java', 'c', 'cpp', 'h', 'cs', 'go', 'rs'])) {
        $content = preg_replace('!/\*.*?\*/!s', '', $content); // /* ... */
        $content = preg_replace('/\n\s*\n/', "\n", $content);   // Líneas vacías múltiples
        // No eliminamos // para evitar romper URLs (http://) o regex literales
        return trim($content);
    }
    
    return $content;
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
    if (!$token || !file_exists($file)) die("Archivo expirado o no encontrado.");
    header('Content-Type: text/plain');
    header('Content-Disposition: attachment; filename="contexto_proyecto.txt"');
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
    $cleanMode = isset($_GET['clean_mode']) && $_GET['clean_mode'] === '1';
    $aiContext = isset($_GET['ai_context']) && $_GET['ai_context'] === '1';
    
    $token = preg_replace('/[^a-z0-9_-]/i', '', $_GET['token'] ?? bin2hex(random_bytes(6)));
    
    $tmp = sys_get_temp_dir();
    $outFile = $tmp . "/unified_{$token}.txt";
    $cancelFlag = $tmp . "/cancel_{$token}.flag";
    if (file_exists($cancelFlag)) @unlink($cancelFlag);

    function sse($evt, $data) { 
        echo "event: $evt\n";
        echo "data: " . json_encode($data) . "\n\n";
        echo str_repeat(' ', 4096); echo "\n"; 
        @ob_flush(); @flush(); 
    }

    sse('started', ['token' => $token]);

    // 1. Indexado
    $files = [];
    try {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS));
    } catch (Exception $e) {
        sse('done', ['error' => 'Ruta no legible.']); exit;
    }
    
    foreach ($iterator as $file) {
        if (file_exists($cancelFlag)) { sse('cancelled', []); exit; }
        $rel = str_replace('\\', '/', ltrim(substr($file->getPathname(), strlen($base)), '/\\'));
        $filename = $file->getFilename();
        
        // Security Blocklist Check
        if (in_array($filename, $SECURITY_BLOCKLIST)) continue;

        // Filtros Selección
        if (!empty($selPaths)) {
            $included = false;
            foreach ($selPaths as $sel) {
                $sel = str_replace('\\', '/', $sel);
                if ($rel === $sel || strpos($rel, $sel.'/') === 0) { $included = true; break; }
            }
            if (!$included) continue;
        }
        
        // Filtros Exclusión
        $parts = explode('/', $rel);
        foreach ($parts as $p) if (in_array($p, $ex_folders)) continue 2;
        if (in_array(strtolower($file->getExtension()), $ex_ext)) continue;

        $files[] = $rel;
    }

    $total = count($files);
    sse('indexed', ['count' => $total]);

    if ($total > 0) {
        $fh = fopen($outFile, 'w');
        
        // --- PROMPT DE CONTEXTO ---
        if ($aiContext) {
            $date = date('Y-m-d H:i:s');
            $header = <<<EOT
# CONTEXTO DEL SISTEMA PARA LA IA
# ===============================
# Este archivo contiene el código fuente completo de un proyecto.
# Fecha de generación: $date
# Total de archivos: $total
#
# INSTRUCCIONES:
# 1. Analiza la estructura de directorios a continuación para entender la arquitectura.
# 2. Usa este código como única fuente de verdad.
# 3. Al sugerir cambios, mantén el estilo de código existente.
#
# ESTRUCTURA DE DIRECTORIOS:
# ==========================
EOT;
            fwrite($fh, $header . "\n");
        } else {
            fwrite($fh, "# REPORTE DE UNIFICACIÓN\n# ARCHIVOS: $total\n\n");
        }
        
        // Árbol ASCII
        fwrite($fh, generateAsciiTree($files));
        fwrite($fh, "\n" . str_repeat("=", 50) . "\n\n");

        $i = 0;
        foreach ($files as $f) {
            if (file_exists($cancelFlag)) { fclose($fh); sse('cancelled', []); exit; }
            $i++;
            
            sse('progress', ['file' => $f, 'pct' => round(($i/$total)*100)]);
            
            fwrite($fh, "====== INICIO ARCHIVO: $f ======\n");
            try {
                $c = file_get_contents($base . DIRECTORY_SEPARATOR . $f);
                
                // --- LIMPIEZA DE CÓDIGO (TOKEN SAVER) ---
                if ($cleanMode) {
                    $ext = pathinfo($f, PATHINFO_EXTENSION);
                    $c = cleanCode($c, $ext);
                }
                
                fwrite($fh, $c . "\n");
            } catch(Exception $e) { fwrite($fh, "[ERROR DE LECTURA]\n"); }
            fwrite($fh, "====== FIN ARCHIVO: $f ======\n\n");
            
            if ($i % 10 === 0) usleep(1000); // Pequeña pausa cada 10 archivos para no ahogar la CPU
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
    <title>Unificador AI Context v5.0</title>
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
        .checkbox-wrapper input:checked { background-color: #2563eb; border-color: #2563eb; }
        @keyframes slideUp { from { transform: translateY(20px); opacity: 0; } to { transform: translateY(0); opacity: 1; } }
    </style>
</head>
<body class="bg-light-50 text-slate-700 dark:bg-dark-900 dark:text-slate-300 font-sans h-screen flex flex-col overflow-hidden">

    <!-- HEADER -->
    <header class="h-14 bg-white dark:bg-dark-800 border-b border-light-200 dark:border-dark-600 flex items-center justify-between px-6 shadow-sm z-20">
        <div class="flex items-center gap-3">
            <div class="w-8 h-8 rounded-lg bg-gradient-to-br from-indigo-500 to-purple-600 flex items-center justify-center shadow-md">
                <i class="ph ph-robot text-white text-lg"></i>
            </div>
            <div>
                <h1 class="font-bold text-slate-800 dark:text-white text-sm leading-tight">Unificador <span class="text-indigo-500">AI Context</span></h1>
                <p class="text-[9px] text-slate-500 uppercase tracking-widest font-semibold">v5.0 &bull; Prompt Engineering Tool</p>
            </div>
        </div>
        <button onclick="toggleTheme()" class="w-8 h-8 rounded-full hover:bg-light-200 dark:hover:bg-dark-600 flex items-center justify-center transition-all text-slate-600 dark:text-yellow-400">
            <i id="themeIcon" class="ph-fill ph-sun text-lg"></i>
        </button>
    </header>

    <main class="flex-1 flex overflow-hidden">
        
        <!-- SIDEBAR -->
        <aside class="w-80 bg-white dark:bg-dark-800 border-r border-light-200 dark:border-dark-600 flex flex-col shadow-xl z-10">
            <div class="p-4 border-b border-light-200 dark:border-dark-600">
                <label class="text-[10px] font-bold text-slate-400 uppercase tracking-wider mb-1 block">Ruta del Proyecto</label>
                <div class="relative group">
                    <input type="text" id="basePath" value="<?php echo htmlspecialchars(str_replace('\\', '/', __DIR__)); ?>" 
                           class="w-full pl-2 pr-8 bg-light-50 dark:bg-dark-900 border border-light-300 dark:border-dark-600 text-xs rounded py-1.5 focus:border-indigo-500 outline-none transition-all text-slate-700 dark:text-slate-200 font-mono">
                    <button onclick="initTree()" class="absolute right-1 top-1 p-1 text-slate-400 hover:text-indigo-500" title="Recargar"><i class="ph ph-arrows-clockwise text-base"></i></button>
                </div>
            </div>

            <div class="flex-1 overflow-y-auto p-2 bg-light-50/50 dark:bg-dark-800" id="treeContainer">
                <div id="treeRoot" class="text-sm"></div>
            </div>

            <div class="p-4 border-t border-light-200 dark:border-dark-600 bg-white dark:bg-dark-800 space-y-3">
                
                <!-- PRO OPTIONS -->
                <div class="space-y-2 pb-2 border-b border-light-200 dark:border-dark-600">
                    <label class="flex items-center gap-2 cursor-pointer group">
                        <input type="checkbox" id="chkAiContext" checked class="rounded border-slate-300 text-indigo-600 focus:ring-0">
                        <span class="text-xs text-slate-600 dark:text-slate-300 group-hover:text-indigo-400 transition-colors">Añadir Prompt de Contexto IA</span>
                    </label>
                    <label class="flex items-center gap-2 cursor-pointer group">
                        <input type="checkbox" id="chkClean" class="rounded border-slate-300 text-indigo-600 focus:ring-0">
                        <div class="flex flex-col">
                            <span class="text-xs text-slate-600 dark:text-slate-300 group-hover:text-indigo-400 transition-colors">Token Saver (Minificar)</span>
                            <span class="text-[9px] text-slate-400">Elimina comentarios y espacios extra</span>
                        </div>
                    </label>
                </div>

                <div class="grid grid-cols-2 gap-2">
                    <input type="text" id="exFolders" value="<?php echo implode(',', $DEFAULT_EXCLUDE_FOLDERS); ?>" class="bg-light-50 dark:bg-dark-900 border border-light-300 dark:border-dark-600 text-[10px] rounded px-2 py-1 outline-none text-slate-600 dark:text-slate-400 placeholder-slate-400" placeholder="Excluir carpetas">
                    <input type="text" id="exExt" value="<?php echo implode(',', $DEFAULT_EXCLUDE_EXT); ?>" class="bg-light-50 dark:bg-dark-900 border border-light-300 dark:border-dark-600 text-[10px] rounded px-2 py-1 outline-none text-slate-600 dark:text-slate-400 placeholder-slate-400" placeholder="Excluir ext">
                </div>
                
                <div class="flex justify-between text-[10px] font-medium text-slate-400">
                    <button onclick="checkAll(true)" class="hover:text-indigo-500">Todo</button>
                    <button onclick="checkAll(false)" class="hover:text-red-400">Nada</button>
                </div>

                <button id="btnGo" class="w-full bg-indigo-600 hover:bg-indigo-500 text-white font-semibold py-2.5 rounded-md shadow-lg shadow-indigo-500/20 transition-all active:scale-[0.98] flex items-center justify-center gap-2 group text-sm">
                    <i class="ph ph-lightning text-lg group-hover:text-yellow-300"></i><span>Procesar Proyecto</span>
                </button>
            </div>
        </aside>

        <!-- MAIN CONTENT -->
        <section class="flex-1 flex flex-col bg-light-100 dark:bg-dark-900 relative transition-colors">
            
            <!-- PROGRESS BAR -->
            <div id="progressContainer" class="hidden w-full bg-light-200 dark:bg-dark-700 h-1 relative z-30">
                <div id="progressBar" class="h-full bg-indigo-500 w-0 transition-all duration-150 ease-out shadow-[0_0_10px_#6366f1]"></div>
            </div>

            <!-- EMPTY STATE -->
            <div id="emptyState" class="absolute inset-0 flex flex-col items-center justify-center opacity-40 pointer-events-none transition-opacity">
                <i class="ph ph-code text-6xl mb-4 text-slate-400"></i>
                <h3 class="text-lg font-medium text-slate-600 dark:text-slate-400">Listo para ingerir código</h3>
            </div>

            <!-- CONSOLE -->
            <div id="consoleContainer" class="flex-1 p-6 overflow-y-auto hidden">
                <div class="max-w-3xl mx-auto w-full bg-white dark:bg-[#0f1115] rounded-xl border border-light-300 dark:border-dark-600 shadow-xl overflow-hidden flex flex-col h-full">
                    <div class="bg-light-50 dark:bg-dark-800 px-3 py-2 border-b border-light-200 dark:border-dark-600 flex justify-between items-center">
                        <span class="text-[10px] font-mono text-slate-400 uppercase tracking-widest"><i class="ph-fill ph-terminal-window mr-1"></i> System Log</span>
                        <button id="btnCancel" class="hidden text-[10px] uppercase font-bold text-red-500 hover:bg-red-50 dark:hover:bg-red-900/20 px-2 py-1 rounded">Cancelar</button>
                    </div>
                    <div id="logs" class="flex-1 p-4 font-mono text-[11px] space-y-1 overflow-y-auto bg-white dark:bg-[#0f1115] text-slate-600 dark:text-slate-300 leading-relaxed"></div>
                </div>
            </div>

            <!-- SUCCESS PANEL (PRO) -->
            <div id="successPanel" class="hidden absolute bottom-8 right-8 z-50 animate-[slideUp_0.4s_cubic-bezier(0.16,1,0.3,1)]">
                <div class="bg-white dark:bg-dark-700 border border-indigo-100 dark:border-dark-500 p-0 rounded-lg shadow-2xl w-80 overflow-hidden ring-1 ring-black/5">
                    <div class="bg-green-500 p-3 flex items-center justify-between">
                        <div class="flex items-center gap-2 text-white">
                            <i class="ph-bold ph-check-circle text-lg"></i>
                            <span class="font-bold text-sm">Completado</span>
                        </div>
                        <button onclick="el('successPanel').classList.add('hidden')" class="text-white/80 hover:text-white"><i class="ph-bold ph-x"></i></button>
                    </div>
                    
                    <div class="p-4 space-y-3">
                        <div class="flex justify-between items-end border-b border-light-200 dark:border-dark-600 pb-3">
                            <div>
                                <p class="text-[10px] text-slate-400 uppercase">Peso Aprox.</p>
                                <p id="statSize" class="text-sm font-bold text-slate-700 dark:text-white">0 MB</p>
                            </div>
                            <div class="text-right">
                                <p class="text-[10px] text-slate-400 uppercase">Tokens Estimados</p>
                                <p id="statTokens" class="text-sm font-bold text-indigo-500 font-mono">0k</p>
                            </div>
                        </div>

                        <button id="btnCopy" onclick="copyToClipboard()" class="w-full flex items-center justify-center gap-2 bg-slate-800 hover:bg-slate-700 text-white text-xs font-semibold py-2.5 rounded transition-all active:scale-95">
                            <i class="ph-bold ph-copy"></i> Copiar al Portapapeles
                        </button>
                        
                        <a id="btnDownload" href="#" class="block w-full text-center text-xs text-indigo-500 hover:underline">Descargar archivo .txt</a>
                    </div>
                </div>
            </div>
        </section>
    </main>

    <script>
        const el = id => document.getElementById(id);
        const state = { base: '', source: null };

        // THEME INIT
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
            el('themeIcon').className = isDark ? 'ph-fill ph-sun text-lg text-yellow-400' : 'ph-fill ph-moon text-lg text-slate-600';
        }
        initTheme();

        // ICONS
        function getIconClass(ext, name) {
            const map = {
                'php': 'devicon-php-plain colored', 'js': 'devicon-javascript-plain colored', 'html': 'devicon-html5-plain colored',
                'css': 'devicon-css3-plain colored', 'py': 'devicon-python-plain colored', 'java': 'devicon-java-plain colored',
                'json': 'devicon-json-plain colored', 'sql': 'devicon-mysql-plain colored', 'ts': 'devicon-typescript-plain colored',
                'md': 'devicon-markdown-original dark:text-white', 'gitignore': 'devicon-git-plain colored'
            };
            if(name === 'package.json') return 'devicon-npm-original-wordmark colored';
            return map[ext] || 'ph ph-file-text text-slate-400';
        }

        // TREE LOGIC
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
                if(!data.items || !data.items.length) { container.innerHTML = `<div class="pl-6 py-1 text-slate-400 text-[10px] italic">Vacío</div>`; return; }

                const ul = document.createElement('ul');
                ul.className = isRoot ? '' : 'pl-3 border-l border-light-200 dark:border-dark-600 ml-1.5 mt-0.5';
                
                data.items.forEach(item => {
                    const li = document.createElement('li');
                    const uid = btoa(item.path).replace(/=/g, '');
                    const isDir = item.type === 'dir';
                    // Seguridad visual: Bloqueo de archivos sensibles
                    const isBlocked = ['.env','id_rsa','.git'].includes(item.name); 
                    const icon = isDir ? '<i class="ph-fill ph-folder text-indigo-400 text-base"></i>' : `<i class="${getIconClass(item.ext, item.name)} text-base"></i>`;

                    li.innerHTML = `
                        <div class="group flex items-center gap-1.5 py-0.5 px-1 rounded hover:bg-light-200 dark:hover:bg-dark-700 cursor-pointer select-none" data-path="${item.path}">
                            <span class="w-3 h-3 flex items-center justify-center text-slate-400 toggle-btn text-[9px] hover:text-indigo-500">${isDir ? '<i class="ph-bold ph-caret-right"></i>' : ''}</span>
                            <div class="checkbox-wrapper flex">
                                <input type="checkbox" ${isBlocked ? 'disabled' : ''} class="appearance-none w-3.5 h-3.5 border border-slate-300 dark:border-slate-600 rounded bg-white dark:bg-dark-900 cursor-pointer disabled:opacity-30" value="${item.path}">
                            </div>
                            <span class="flex w-5 justify-center opacity-90">${icon}</span>
                            <span class="${isBlocked ? 'text-red-400 line-through decoration-2' : 'text-slate-600 dark:text-slate-300'} group-hover:text-slate-900 dark:group-hover:text-white truncate text-[11px] font-medium">${item.name}</span>
                        </div>
                        ${isDir ? `<div id="sub-${uid}" class="hidden"></div>` : ''}
                    `;
                    ul.appendChild(li);

                    if(isBlocked) return; 

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
                    chk.addEventListener('change', () => { if(isDir) li.querySelector(`#sub-${uid}`)?.querySelectorAll('input:not([disabled])').forEach(c => c.checked = chk.checked); });
                    row.addEventListener('click', e => { if(e.target!==toggle) { chk.checked=!chk.checked; chk.dispatchEvent(new Event('change')); }});
                });
                container.appendChild(ul);
            } catch(e) { container.innerHTML = `<div class="text-red-500 text-[10px] p-2">${e.message}</div>`; }
        }

        // COPY TO CLIPBOARD & STATS CALC
        async function copyToClipboard() {
            const btn = el('btnCopy');
            const url = el('btnDownload').href;
            const originalHtml = btn.innerHTML;
            
            try {
                btn.disabled = true;
                btn.innerHTML = '<i class="ph ph-spinner animate-spin"></i> Descargando...';
                
                const res = await fetch(url);
                const text = await res.text();
                
                // Calculo de Stats en tiempo real
                const sizeMB = (text.length / 1024 / 1024).toFixed(2);
                const tokens = Math.ceil(text.length / 4); // Aprox simple
                
                el('statSize').innerText = sizeMB + ' MB';
                el('statTokens').innerText = (tokens/1000).toFixed(1) + 'k';
                
                await navigator.clipboard.writeText(text);
                
                btn.classList.remove('bg-slate-800', 'hover:bg-slate-700');
                btn.classList.add('bg-green-600', 'hover:bg-green-500');
                btn.innerHTML = '<i class="ph-bold ph-check"></i> ¡Copiado!';
                
                setTimeout(() => {
                    btn.className = "w-full flex items-center justify-center gap-2 bg-slate-800 hover:bg-slate-700 text-white text-xs font-semibold py-2.5 rounded transition-all active:scale-95";
                    btn.innerHTML = originalHtml;
                    btn.disabled = false;
                }, 2000);
            } catch (err) {
                alert('Error al copiar. El archivo puede ser muy grande para el navegador.');
                btn.innerHTML = '<i class="ph-bold ph-warning"></i> Falló';
                btn.disabled = false;
            }
        }

        // LOGGING
        function log(msg, type='info') {
            const d = document.createElement('div');
            const clr = { info: 'text-slate-500 dark:text-slate-400', success: 'text-green-500 font-bold', error: 'text-red-500 font-bold' };
            d.className = `${clr[type] || clr.info} border-l-2 border-transparent pl-2 hover:bg-light-100 dark:hover:bg-white/5 py-0.5 rounded-r`;
            // Timestamp
            const time = new Date().toLocaleTimeString('es-CO', {hour12:false, hour:'2-digit', minute:'2-digit', second:'2-digit'});
            d.innerHTML = `<span class="opacity-50 text-[9px] mr-2">[${time}]</span> ${msg}`;
            el('logs').appendChild(d);
            el('logs').parentElement.scrollTop = el('logs').parentElement.scrollHeight;
        }

        // PROCESS
        el('btnGo').onclick = () => {
            const checks = document.querySelectorAll('input[type="checkbox"][value]:checked');
            const paths = Array.from(checks).map(c => c.value);

            el('emptyState').classList.add('hidden');
            el('consoleContainer').classList.remove('hidden');
            el('successPanel').classList.add('hidden');
            el('logs').innerHTML = '';
            el('progressContainer').classList.remove('hidden');
            el('progressBar').style.width = '0%';
            el('btnCancel').classList.remove('hidden');
            el('btnGo').disabled = true;
            el('btnGo').classList.add('opacity-50', 'cursor-not-allowed');

            const params = new URLSearchParams({ 
                action: 'process', 
                base: state.base, 
                exclude_folders: el('exFolders').value, 
                exclude_ext: el('exExt').value,
                clean_mode: el('chkClean').checked ? '1' : '0',
                ai_context: el('chkAiContext').checked ? '1' : '0'
            });
            paths.forEach(p => params.append('paths[]', p));

            if(state.source) state.source.close();
            state.source = new EventSource('?' + params.toString());
            
            state.source.addEventListener('started', e => log('Iniciando motor de unificación...', 'info'));
            state.source.addEventListener('indexed', e => log(`Índice generado: ${JSON.parse(e.data).count} archivos encontrados.`, 'info'));
            
            state.source.addEventListener('progress', e => {
                const d = JSON.parse(e.data);
                requestAnimationFrame(() => el('progressBar').style.width = d.pct + '%');
                if(Math.random() > 0.7) log(`Procesando: ${d.file}`); 
            });

            state.source.addEventListener('done', e => {
                const d = JSON.parse(e.data);
                finish();
                if(d.url) {
                    el('progressBar').style.width = '100%';
                    el('btnDownload').href = d.url;
                    el('successPanel').classList.remove('hidden');
                    // Simular click en copy para calcular stats automágicamente (opcional, mejor que el usuario haga click)
                    // copyToClipboard(); 
                    log('PROCESO FINALIZADO CON ÉXITO', 'success');
                } else log(d.error, 'error');
            });

            state.source.onerror = () => { log('Conexión perdida con el servidor.', 'error'); finish(); };
        };

        el('btnCancel').onclick = () => { fetch('?action=cancel&token=1'); if(state.source) state.source.close(); log('Cancelado por usuario.', 'error'); finish(); };

        function finish() {
            if(state.source) state.source.close();
            el('btnGo').disabled = false;
            el('btnGo').classList.remove('opacity-50', 'cursor-not-allowed');
            setTimeout(() => el('progressContainer').classList.add('hidden'), 1000);
        }

        function checkAll(v) { document.querySelectorAll('input[type="checkbox"]:not([disabled])').forEach(c => c.checked = v); }
        if(el('basePath').value) initTree();
    </script>
</body>
</html>