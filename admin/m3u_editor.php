<?php
// admin/m3u_editor.php
// M3U Real-time Editor (upload / URL fetch) with bulk replace + per-group popup editor

require_once __DIR__ . '/../api_common.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../helpers.php';

require_admin();

$topbar = file_get_contents(__DIR__ . '/topbar.html');
$topbar = str_replace('{{USERNAME}}', e($_SESSION['admin_username'] ?? 'Admin'), $topbar);

$error = '';
$content = '';
$finds = [];
$replaces = [];
$appendSuffix = '';
$forceExt = false;

function m3u_collect_groups(string $content): array {
  $groups = [];
  $lines = preg_split('/\r\n|\r|\n/', $content);
  foreach ($lines as $line) {
    if (strpos($line, '#EXTINF') !== false && preg_match('/group-title="([^"]+)"/', $line, $m)) {
      $groups[$m[1]] = true;
    }
  }
  $groupList = array_keys($groups);
  sort($groupList);
  return $groupList;
}

// Any POST => CSRF validate (also gives a clean 413 when POST body gets dropped due to limits).
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
  csrf_validate();
}

// Download path (client posts edited content)
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['content']) && isset($_POST['download'])) {
  $content = (string)($_POST['content'] ?? '');
  $confirm = (string)($_POST['confirm'] ?? '');

  // Preserve bulk replace inputs on error
  $finds = $_POST['find'] ?? $_POST['find[]'] ?? [];
  $replaces = $_POST['replace'] ?? $_POST['replace[]'] ?? [];
  if (!is_array($finds)) $finds = [];
  if (!is_array($replaces)) $replaces = [];


  $appendSuffix = (string)($_POST['appendSuffix'] ?? '');
  $forceExt = !empty($_POST['forceExt']);
  if ($confirm !== 'yes') {
    $error = 'You must confirm changes by checking the box before download.';
  } else {
    header('Content-Type: audio/x-mpegurl');
    header('Content-Disposition: attachment; filename="updated.m3u"');
    header('Content-Length: ' . strlen($content));
    echo $content;
    exit;
  }
}

// Preview path (upload / URL)
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && !$content) {
  // Preserve bulk replace inputs
  $finds = $_POST['find'] ?? $_POST['find[]'] ?? [];
  $replaces = $_POST['replace'] ?? $_POST['replace[]'] ?? [];
  if (!is_array($finds)) $finds = [];
  if (!is_array($replaces)) $replaces = [];

  $appendSuffix = (string)($_POST['appendSuffix'] ?? '');
  $forceExt = !empty($_POST['forceExt']);

  // Prefer upload if present
  $tmp = $_FILES['m3u']['tmp_name'] ?? '';
  if ($tmp && is_file($tmp)) {
    $raw = file_get_contents($tmp);
    if ($raw === false) {
      $error = 'Failed to read uploaded file.';
    } else {
      $content = $raw;
    }
  } else {
    // Or fetch by URL
    $url = trim((string)($_POST['m3u_url'] ?? ''));
    if ($url !== '') {
      $r = iptv_fetch_url_to_temp($url, 52428800, 25); // 50MB max, 25s timeout
      if (empty($r['ok'])) {
        $error = 'Failed to download M3U: ' . (string)($r['error'] ?? 'Unknown error');
      } else {
        $raw = file_get_contents((string)$r['tmp']);
        @unlink((string)$r['tmp']);
        if ($raw === false) {
          $error = 'Failed to read downloaded file.';
        } else {
          $content = $raw;
        }
      }
    }
  }

  if (!$content && !$error) {
    $error = 'No M3U file uploaded (or URL provided).';
  }
}

$groupList = $content ? m3u_collect_groups($content) : [];

?><!doctype html>
<html>
<head>
  <link rel="icon" href="/favicon.ico">
  <link rel="icon" type="image/png" sizes="32x32" href="/favicon-32x32.png">
  <link rel="icon" type="image/png" sizes="16x16" href="/favicon-16x16.png">
  <link rel="apple-touch-icon" sizes="180x180" href="/apple-touch-icon.png">

  <meta charset="utf-8">
  <title>M3U Editor</title>
  <link rel="stylesheet" href="panel.css">
  <style>
    /* Modal */
    .modal-backdrop{position:fixed;inset:0;background:rgba(0,0,0,.45);display:none;align-items:center;justify-content:center;padding:18px;z-index:9999;}
    .modal{background:#fff;width:min(980px,95vw);max-height:85vh;border-radius:16px;border:1px solid rgba(15,23,42,.18);box-shadow:0 14px 40px rgba(0,0,0,.25);display:flex;flex-direction:column;overflow:hidden;}
    .modal-header{padding:12px 14px;border-bottom:1px solid var(--line);display:flex;align-items:center;justify-content:space-between;gap:10px;}
    .modal-title{font-weight:900;}
    .modal-body{padding:14px;overflow:auto;}
    .modal-footer{padding:12px 14px;border-top:1px solid var(--line);display:flex;gap:10px;justify-content:flex-end;}
    .modal-body textarea{min-height:340px;}

    .toolbar{display:flex;gap:10px;flex-wrap:wrap;align-items:center;justify-content:space-between;margin-bottom:10px;}
    .toolbar .left{display:flex;gap:10px;flex-wrap:wrap;align-items:center;}
    .pill-mini{display:inline-flex;align-items:center;gap:8px;padding:7px 10px;border:1px solid var(--line);border-radius:12px;background:#fff;font-weight:800;}
    .btn-ghost{background:#fff;color:var(--text);border:1px solid var(--line);box-shadow:none;}
    .btn-ghost:hover{filter:brightness(0.98);}

    .editor-grid{display:grid;grid-template-columns:2fr 1fr;gap:14px;}
    @media (max-width: 1050px){.editor-grid{grid-template-columns:1fr;}}

    #preview{font-family:ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace;min-height:420px;}

    .pair-row{display:grid;grid-template-columns:1fr 1fr;gap:10px;}
    .pair-row input{margin-top:6px;}
    .hint{font-size:12px;color:var(--muted);margin-top:6px;}
  </style>
</head>
<body>
<?= $topbar ?>

<?php if (!$content): ?>
  <div class="card">
    <div class="card-title">Content • M3U Editor</div>
    <h2 style="margin:0 0 10px;">M3U Real-time Editor</h2>
    <?php if ($error): ?>
      <div class="notice notice-warn" style="margin-bottom:12px;">
        <div class="notice-icon"><?= gc_svg_icon('alert') ?></div>
        <div class="notice-body"><div class="notice-title">Error</div><div class="notice-text"><?= e($error) ?></div></div>
      </div>
    <?php endif; ?>

    <form method="post" enctype="multipart/form-data">
      <?= csrf_input() ?>
      <div class="form-row">
        <div>
          <label>Upload M3U File</label>
          <input type="file" name="m3u" accept=".m3u,.m3u8">
          <div class="hint">Best for large files. Your host upload limits apply.</div>
        </div>
        <div>
          <label>Or Fetch by URL</label>
          <input type="url" name="m3u_url" placeholder="https://example.com/playlist.m3u">
          <div class="hint">Server downloads up to 50MB.</div>
        </div>
      </div>
      <div style="margin-top:12px;display:flex;gap:10px;flex-wrap:wrap;">
        <button type="submit">Preview &amp; Edit</button>
        <a class="btn btn-ghost" href="upload_m3u.php">Go to Import M3U</a>
      </div>
    </form>
  </div>

<?php else: ?>
  <div class="card">
    <div class="card-title">Content • M3U Editor</div>

    <div class="toolbar">
      <div class="left">
        <div class="pill-mini"><?= gc_svg_icon('receipt', 'pill-ico') ?> Lines: <span id="lineCount">0</span></div>
        <div class="pill-mini"><?= gc_svg_icon('folder', 'pill-ico') ?> Groups: <span><?= (int)count($groupList) ?></span></div>
      </div>
      <div style="display:flex;gap:10px;flex-wrap:wrap;">
        <a class="btn btn-ghost" href="m3u_editor.php">Start Over</a>
      </div>
    </div>

    <?php if ($error): ?>
      <div class="notice notice-warn" style="margin-bottom:12px;">
        <div class="notice-icon"><?= gc_svg_icon('alert') ?></div>
        <div class="notice-body"><div class="notice-title">Action blocked</div><div class="notice-text"><?= e($error) ?></div></div>
      </div>
    <?php endif; ?>

    <form method="post" id="editorForm">
      <?= csrf_input() ?>
      <input type="hidden" name="download" value="1">

      <div class="editor-grid">
        <div>
          <label>Preview (live)</label>
          <textarea id="preview" name="content" spellcheck="false"><?php echo e($content); ?></textarea>
          <div class="hint">Edits happen in your browser in real time. Download sends the final text.</div>
        </div>

        <div>
          <div class="card" style="box-shadow:none;">
            <div class="card-title">Tools</div>

            <h3 style="margin:0 0 10px;">Bulk Find &amp; Replace</h3>
            <?php
              // Render exactly 3 rows (like your original), but preserve values on validation error.
              for ($i = 0; $i < 3; $i++) {
                $fv = (string)($finds[$i] ?? '');
                $rv = (string)($replaces[$i] ?? '');
                echo '<div style="margin-bottom:10px;">';
                echo '  <div class="pair-row">';
                echo '    <div><label>Find</label><input type="text" name="find[]" value="'.e($fv).'"/></div>';
                echo '    <div><label>Replace</label><input type="text" name="replace[]" value="'.e($rv).'"/></div>';
                echo '  </div>';
                echo '</div>';
              }
            ?>

            <h3 style="margin:0 0 10px;">Append suffix to stream links</h3>
<label>Suffix</label>
<input type="text" id="appendSuffix" name="appendSuffix" placeholder=".ts" list="suffixSuggestions" value="<?= e($appendSuffix) ?>">
<datalist id="suffixSuggestions">
  <option value=".ts"></option>
  <option value=".m3u"></option>
  <option value=".m3u8"></option>
</datalist>
<div class="hint">Leave blank for no change. If the link has <code>?token=</code> etc, the suffix is inserted before the <code>?</code>.</div>

<label class="check-row" style="display:flex;align-items:center;gap:10px;margin:10px 0 0;">
  <input type="checkbox" id="forceExt" name="forceExt" value="1" <?= $forceExt ? 'checked' : '' ?>>
  <span>Force/replace existing suffix</span>
</label>

<div id="suffixError" class="hint" style="color:#b00020;display:none;"></div>

<hr style="border:0;border-top:1px solid var(--line);margin:12px 0;">

            <h3 style="margin:0 0 10px;">Edit by Group-title</h3>
            <label>Group-title</label>
            <select id="category" name="category">
              <option value="">Select a group</option>
              <?php foreach ($groupList as $g): ?>
                <option value="<?= e($g) ?>"><?= e($g) ?></option>
              <?php endforeach; ?>
            </select>
            <div style="margin-top:10px;display:flex;gap:10px;flex-wrap:wrap;">
              <button type="button" id="editGroupBtn" class="btn btn-ghost">Edit selected group</button>
            </div>

            <hr style="border:0;border-top:1px solid var(--line);margin:12px 0;">

            <h3 style="margin:0 0 10px;">Filters</h3>

            <label>Keep only these groups (optional)</label>
            <select id="keepGroups" multiple size="8">
              <?php foreach ($groupList as $g): ?>
                <option value="<?= e($g) ?>"><?= e($g) ?></option>
              <?php endforeach; ?>
            </select>
            <div class="hint">If you select any, only those groups will remain.</div>

            <div style="height:10px;"></div>

            <label>Exclude groups (optional)</label>
            <select id="excludeGroups" multiple size="8">
              <?php foreach ($groupList as $g): ?>
                <option value="<?= e($g) ?>"><?= e($g) ?></option>
              <?php endforeach; ?>
            </select>
            <div class="hint">Entries in these groups will be removed.</div>

            <div style="height:10px;"></div>

            <label>Title contains (optional)</label>
            <input type="text" id="titleInclude" placeholder="sports, movies, uk">
            <div class="hint">Comma-separated keywords. Keeps entries whose channel name contains any keyword.</div>

            <div style="height:10px;"></div>

            <label>Title excludes (optional)</label>
            <input type="text" id="titleExclude" placeholder="xxx, adult">
            <div class="hint">Comma-separated keywords. Removes entries whose channel name contains any keyword.</div>

            <hr style="border:0;border-top:1px solid var(--line);margin:12px 0;">

            <label class="check-row" style="display:flex;align-items:center;gap:10px;margin:0 0 12px;">
              <input type="checkbox" name="confirm" value="yes">
              <span>Confirm changes (required to download)</span>
            </label>

            <button type="submit">Download updated.m3u</button>

            <div class="hint" style="margin-top:10px;">
              If download fails, increase <code>post_max_size</code> / <code>upload_max_filesize</code> on your server.
            </div>

          </div>
        </div>
      </div>
    </form>
  </div>

  <!-- Modal: separate editor for selected group -->
  <div id="modalBackdrop" class="modal-backdrop" aria-hidden="true">
    <div class="modal" role="dialog" aria-modal="true">
      <div class="modal-header">
        <div class="modal-title" id="modalTitle">Edit group</div>
        <button type="button" id="modalCancel" class="btn btn-ghost" style="padding:7px 10px;"><?= gc_svg_icon('x') ?></button>
      </div>
      <div class="modal-body">
        <label style="display:block;margin-bottom:8px;">Only this group (EXTINF + URL lines)</label>
        <textarea id="groupEditor" spellcheck="false"></textarea>
      </div>
      <div class="modal-footer">
        <button type="button" id="modalConfirm">Confirm changes</button>
        <button type="button" id="modalCancel2" class="btn btn-ghost">Cancel</button>
      </div>
    </div>
  </div>

  <script>
    // Main state
    const preview = document.getElementById('preview');
    const originalText = preview.value; // baseline (never mutated)
    const originalLines = originalText.split('\n');

    const editedGroups = {}; // editedGroups[groupName] = [{extIndex,urlIndex,extText,urlText}, ...]

    // Filters
    const keepGroups = document.getElementById('keepGroups');
    const excludeGroups = document.getElementById('excludeGroups');
    const titleInclude = document.getElementById('titleInclude');
    const titleExclude = document.getElementById('titleExclude');


    // Suffix appender
    const appendSuffixInput = document.getElementById('appendSuffix');
    const suffixError = document.getElementById('suffixError');
    const forceExtChk = document.getElementById('forceExt');
    function selectedValues(sel){
      if(!sel) return [];
      return Array.from(sel.selectedOptions || []).map(o => o.value);
    }
    function parseKeywords(v){
      return String(v || '')
        .split(',')
        .map(s => s.trim().toLowerCase())
        .filter(Boolean);
    }
    function extractGroup(extinf){
      const m = String(extinf || '').match(/group-title="([^"]*)"/i);
      const g = (m && m[1]) ? String(m[1]).trim() : '';
      return g || 'Uncategorized';
    }
    function extractTitle(extinf){
      const s = String(extinf || '');
      const idx = s.lastIndexOf(',');
      return (idx >= 0 ? s.slice(idx + 1) : '').trim();
    }
    function applyFilters(lines){
      const keep = new Set(selectedValues(keepGroups));
      const drop = new Set(selectedValues(excludeGroups));
      const inc = parseKeywords(titleInclude && titleInclude.value);
      const exc = parseKeywords(titleExclude && titleExclude.value);

      if(keep.size === 0 && drop.size === 0 && inc.length === 0 && exc.length === 0) return lines;

      const out = [];
      for(let i = 0; i < lines.length; i++){
        const line = lines[i] || '';
        if(line.startsWith('#EXTINF')){
          const ext = line;
          const url = (i + 1 < lines.length) ? (lines[i + 1] || '') : '';
          const grp = extractGroup(ext);
          const title = extractTitle(ext);
          const t = title.toLowerCase();

          let ok = true;

          if(keep.size > 0 && !keep.has(grp)) ok = false;
          if(ok && drop.size > 0 && drop.has(grp)) ok = false;

          if(ok && inc.length > 0){
            ok = inc.some(k => t.includes(k));
          }
          if(ok && exc.length > 0){
            if(exc.some(k => t.includes(k))) ok = false;
          }

          if(ok){
            out.push(ext);
            out.push(url);
          }
          i++; // skip URL line either way
        } else {
          out.push(line);
        }
      }
      return out;
    }


    function safeRegex(pattern){
      return pattern.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
    }

    function parseGroupEntries(lines, groupName){
      const entries = [];
      for(let i=0;i<lines.length;i++){
        const line = lines[i];
        if(line.startsWith('#EXTINF') && line.includes('group-title="' + groupName + '"')){
          const extIndex = i;
          const urlIndex = (i + 1 < lines.length) ? i + 1 : -1;
          entries.push({ extIndex, urlIndex, extText: lines[extIndex], urlText: urlIndex >= 0 ? lines[urlIndex] : '' });
        }
      }
      return entries;
    }



function splitUrlTail(urlLine){
  const q = urlLine.indexOf('?');
  const h = urlLine.indexOf('#');
  let cut = -1;
  if(q !== -1 && h !== -1) cut = Math.min(q, h);
  else cut = (q !== -1) ? q : h;
  if(cut === -1) return { base: urlLine, tail: '' };
  return { base: urlLine.slice(0, cut), tail: urlLine.slice(cut) };
}

function hasPathExtension(base){
  return /\.[a-z0-9]{1,10}$/i.test(base);
}

function normalizeSuffix(raw){
  let s = String(raw || '').trim();
  if(!s){
    if(suffixError){ suffixError.style.display='none'; suffixError.textContent=''; }
    return '';
  }
  if(!s.startsWith('.')) s = '.' + s;
  s = s.toLowerCase();

  if(!/^\.[a-z0-9]{1,10}$/.test(s)){
    if(suffixError){ suffixError.textContent='Invalid suffix (example: .ts)'; suffixError.style.display='block'; }
    return null;
  }
  if(suffixError){ suffixError.style.display='none'; suffixError.textContent=''; }
  return s;
}

function replaceOrAppendSuffix(urlLine, suffix, force){
  const trimmed = String(urlLine || '').trim();
  if(!trimmed) return urlLine;
  if(trimmed.startsWith('#')) return urlLine;

  const parts = splitUrlTail(trimmed);
  const base = parts.base || '';
  const tail = parts.tail || '';
  if(!base) return urlLine;

  const want = suffix; // includes dot
  const baseLower = base.toLowerCase();

  if(!force){
    if(baseLower.endsWith(want)) return trimmed;
    if(hasPathExtension(base)) return trimmed;
    return base + want + tail;
  }

  const newBase = base.replace(/\.[a-z0-9]{1,10}$/i, want);
  if(newBase === base && !baseLower.endsWith(want)){
    return base + want + tail;
  }
  return newBase + tail;
}
    function buildWorkingText(){
      let lines = originalLines.slice();

      // Apply group overlays
      for(const groupName in editedGroups){
        const entries = editedGroups[groupName];
        if(!entries) continue;
        for(const entry of entries){
          if(entry.extIndex >= 0) lines[entry.extIndex] = entry.extText;
          if(entry.urlIndex >= 0) lines[entry.urlIndex] = entry.urlText;
        }
      }

      // Apply filters (group + title)
      lines = applyFilters(lines);

      // Apply bulk replacements globally
      const finds = document.getElementsByName('find[]');
      const replaces = document.getElementsByName('replace[]');
      for(let j=0;j<finds.length;j++){
        const f = (finds[j].value || '').trim();
        const r = replaces[j].value || '';
        if(f){
          const regex = new RegExp(safeRegex(f), 'g');
          lines = lines.map(l => l.replace(regex, r));
        }
      }

      // Append suffix to URL lines
      const suffix = normalizeSuffix(appendSuffixInput ? appendSuffixInput.value : '');
      if(suffix){
        const force = !!(forceExtChk && forceExtChk.checked);
        lines = lines.map(l => replaceOrAppendSuffix(l, suffix, force));
      }

      return lines.join('\n');
}

    function updatePreview(){
      preview.value = buildWorkingText();
      const lc = document.getElementById('lineCount');
      if(lc) lc.textContent = String(preview.value.split('\n').length);
    }

    // Modal
    const modalBackdrop = document.getElementById('modalBackdrop');
    const groupEditor = document.getElementById('groupEditor');
    const modalTitle = document.getElementById('modalTitle');
    const editGroupBtn = document.getElementById('editGroupBtn');
    const categorySelect = document.getElementById('category');
    const modalConfirm = document.getElementById('modalConfirm');
    const modalCancel = document.getElementById('modalCancel');
    const modalCancel2 = document.getElementById('modalCancel2');

    function openModalForGroup(groupName){
      if(!groupName){ alert('Please select a group first.'); return; }

      // Use current composed preview as source text, but keep indices from original
      const currentLines = buildWorkingText().split('\n');
      const entries = parseGroupEntries(originalLines, groupName);

      let buf = [];
      for(const entry of entries){
        buf.push(currentLines[entry.extIndex] || '');
        if(entry.urlIndex >= 0) buf.push(currentLines[entry.urlIndex] || '');
      }

      modalTitle.textContent = 'Edit group: ' + groupName;
      groupEditor.value = buf.join('\n');
      modalBackdrop.dataset.groupName = groupName;
      modalBackdrop._entries = entries;
      modalBackdrop.style.display = 'flex';
      modalBackdrop.setAttribute('aria-hidden','false');

      setTimeout(() => groupEditor.focus(), 0);
    }

    function closeModal(){
      modalBackdrop.style.display = 'none';
      modalBackdrop.setAttribute('aria-hidden','true');
      modalBackdrop.dataset.groupName = '';
      modalBackdrop._entries = null;
    }

    editGroupBtn.addEventListener('click', function(){
      openModalForGroup(categorySelect.value);
    });

    function cancel(){ closeModal(); }
    modalCancel.addEventListener('click', cancel);
    modalCancel2.addEventListener('click', cancel);
    modalBackdrop.addEventListener('click', function(e){ if(e.target === modalBackdrop) cancel(); });
    document.addEventListener('keydown', function(e){ if(e.key === 'Escape') cancel(); });

    modalConfirm.addEventListener('click', function(){
      const groupName = modalBackdrop.dataset.groupName;
      const entries = modalBackdrop._entries || [];
      if(!groupName || entries.length === 0){ closeModal(); return; }

      const editedLines = groupEditor.value.split('\n');
      let k = 0;
      const newEntries = [];

      for(const entry of entries){
        const extText = (k < editedLines.length) ? editedLines[k++] : entry.extText;
        let urlText = entry.urlText;
        if(entry.urlIndex >= 0){
          urlText = (k < editedLines.length) ? editedLines[k++] : entry.urlText;
        }
        newEntries.push({ extIndex: entry.extIndex, urlIndex: entry.urlIndex, extText, urlText });
      }

      editedGroups[groupName] = newEntries;
      updatePreview();
      closeModal();
    });

    // Real-time bulk find/replace
    document.querySelectorAll('input[name="find[]"], input[name="replace[]"]').forEach(function(el){
      el.addEventListener('input', updatePreview);
    });


    // Suffix appender
    if(appendSuffixInput) appendSuffixInput.addEventListener('input', updatePreview);
    if(forceExtChk) forceExtChk.addEventListener('change', updatePreview);

    // Filters
    if(keepGroups) keepGroups.addEventListener('change', updatePreview);
    if(excludeGroups) excludeGroups.addEventListener('change', updatePreview);
    if(titleInclude) titleInclude.addEventListener('input', updatePreview);
    if(titleExclude) titleExclude.addEventListener('input', updatePreview);

    // Keep preview synced if user changes group selection
    categorySelect.addEventListener('change', function(){ updatePreview(); });

    // Initial compose
    updatePreview();
  </script>
<?php endif; ?>

</div><!-- .container from topbar -->
</main></div><!-- .app from topbar -->
</body>
</html>
