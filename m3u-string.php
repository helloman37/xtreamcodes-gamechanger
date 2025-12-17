<?php
/**
 * M3U Editor: main preview + bulk replace + separate group popup editor
 * Single-file PHP with embedded HTML + JS
 */

declare(strict_types=1);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['content'])) {
    $content = $_POST['content'] ?? '';
    $confirm = $_POST['confirm'] ?? '';
    if ($confirm !== 'yes') {
        echo 'Error: You must confirm changes by checking the box before download.';
        exit;
    }
    header('Content-Type: audio/x-mpegurl');
    header('Content-Disposition: attachment; filename="updated.m3u"');
    header('Content-Length: ' . strlen($content));
    echo $content;
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['m3u'])) {
    $inputFile = $_FILES['m3u']['tmp_name'] ?? null;
    if (!$inputFile || !file_exists($inputFile)) {
        echo 'Error: No M3U file uploaded.';
        exit;
    }

    $content = file_get_contents($inputFile);
    if ($content === false) {
        echo 'Error: Failed to read uploaded file.';
        exit;
    }

    // Parse unique group-title categories
    $groups = [];
    $lines = preg_split('/\r\n|\r|\n/', $content);
    foreach ($lines as $line) {
        if (strpos($line, '#EXTINF') !== false && preg_match('/group-title="([^"]+)"/', $line, $m)) {
            $groups[$m[1]] = true;
        }
    }
    $groupList = array_keys($groups);
    sort($groupList);
    ?>
<!DOCTYPE html>
<html>
<head>
  <link rel="icon" href="/favicon.ico">
  <link rel="icon" type="image/png" sizes="32x32" href="/favicon-32x32.png">
  <link rel="icon" type="image/png" sizes="16x16" href="/favicon-16x16.png">
  <link rel="apple-touch-icon" sizes="180x180" href="/apple-touch-icon.png">

    <meta charset="utf-8">
    <title>M3U Bulk Editor</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; }
        .box { background:#f4f4f4; padding:10px; border:1px solid #ccc; }
        .row { margin-bottom:8px; }
        label { display:inline-block; min-width:120px; }
        .modal-backdrop {
            position: fixed; inset: 0; background: rgba(0,0,0,0.4);
            display: none; align-items: center; justify-content: center;
        }
        .modal {
            background: #fff; width: 80%; max-width: 900px; max-height: 80vh;
            border: 1px solid #888; box-shadow: 0 4px 20px rgba(0,0,0,0.25);
            display: flex; flex-direction: column;
        }
        .modal-header, .modal-footer { padding: 10px; border-bottom: 1px solid #eee; }
        .modal-footer { border-top: 1px solid #eee; border-bottom: none; }
        .modal-body { padding: 10px; overflow: auto; }
        .modal-body textarea { width: 100%; height: 300px; }
        .actions { display: inline-flex; gap: 10px; }
    </style>
</head>
<body>
    <h2>Preview & Bulk Edit Playlist</h2>
    <form method="post" id="editorForm">
        <textarea id="preview" name="content" rows="20" cols="100" class="box" style="width:100%;"><?php
            echo htmlspecialchars($content);
        ?></textarea><br><br>

        <h3>Bulk find & replace</h3>
        <div class="row">
            <label>Find:</label> <input type="text" name="find[]" />
            <label>Replace:</label> <input type="text" name="replace[]" />
        </div>
        <div class="row">
            <label>Find:</label> <input type="text" name="find[]" />
            <label>Replace:</label> <input type="text" name="replace[]" />
        </div>
        <div class="row">
            <label>Find:</label> <input type="text" name="find[]" />
            <label>Replace:</label> <input type="text" name="replace[]" />
        </div>

        <h3>Category filter</h3>
        <div class="row">
            <label>Group-title:</label>
            <select id="category" name="category">
                <option value="">Select a group</option>
                <?php foreach ($groupList as $g): ?>
                    <option value="<?php echo htmlspecialchars($g); ?>"><?php echo htmlspecialchars($g); ?></option>
                <?php endforeach; ?>
            </select>
            <span class="actions">
                <button type="button" id="editGroupBtn">Edit selected group</button>
            </span>
        </div>

        <br>
        <label><input type="checkbox" name="confirm" value="yes"> Confirm changes</label><br><br>
        <button type="submit">Apply & Download</button>
    </form>

    <!-- Modal: separate second editor for selected group -->
    <div id="modalBackdrop" class="modal-backdrop">
      <div class="modal">
        <div class="modal-header">
          <strong id="modalTitle">Edit group</strong>
        </div>
        <div class="modal-body">
          <textarea id="groupEditor"></textarea>
        </div>
        <div class="modal-footer">
          <button type="button" id="modalConfirm">Confirm changes</button>
          <button type="button" id="modalCancel">Cancel</button>
        </div>
      </div>
    </div>

    <script>
    // Main state
    const preview = document.getElementById('preview');
    const originalText = preview.value;           // baseline (never mutated)
    const originalLines = originalText.split('\n');

    // Overlay: per-group edits applied on top of original
    // editedGroups[groupName] = [{extIndex,urlIndex,extText,urlText}, ...]
    const editedGroups = {};

    function safeRegex(pattern) {
        return pattern.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
    }

    function parseGroupEntries(lines, groupName) {
        const entries = [];
        for (let i = 0; i < lines.length; i++) {
            const line = lines[i];
            if (line.startsWith('#EXTINF') && line.includes('group-title="' + groupName + '"')) {
                const extIndex = i;
                const urlIndex = (i + 1 < lines.length) ? i + 1 : -1;
                entries.push({
                    extIndex: extIndex,
                    urlIndex: urlIndex,
                    extText: lines[extIndex],
                    urlText: urlIndex >= 0 ? lines[urlIndex] : ''
                });
            }
        }
        return entries;
    }

    // Compose working text: original + group overlays, then bulk replacements
    function buildWorkingText() {
        let lines = originalLines.slice();

        // Apply group overlays
        for (const groupName in editedGroups) {
            const entries = editedGroups[groupName];
            if (!entries) continue;
            for (const entry of entries) {
                if (entry.extIndex >= 0) lines[entry.extIndex] = entry.extText;
                if (entry.urlIndex >= 0) lines[entry.urlIndex] = entry.urlText;
            }
        }

        // Apply bulk replacements globally
        const finds = document.getElementsByName('find[]');
        const replaces = document.getElementsByName('replace[]');
        for (let j = 0; j < finds.length; j++) {
            const f = finds[j].value;
            const r = replaces[j].value;
            if (f) {
                const regex = new RegExp(safeRegex(f), 'g');
                lines = lines.map(l => l.replace(regex, r));
            }
        }

        return lines.join('\n');
    }

    function updatePreview() {
        preview.value = buildWorkingText();
    }

    // Modal: second editor that does not touch main preview until Confirm
    const modalBackdrop = document.getElementById('modalBackdrop');
    const groupEditor = document.getElementById('groupEditor');
    const modalTitle = document.getElementById('modalTitle');
    const editGroupBtn = document.getElementById('editGroupBtn');
    const categorySelect = document.getElementById('category');
    const modalConfirm = document.getElementById('modalConfirm');
    const modalCancel = document.getElementById('modalCancel');

    function openModalForGroup(groupName) {
        if (!groupName) {
            alert('Please select a group first.');
            return;
        }

        // Use current composed preview as source text, but keep indices from original
        const currentLines = buildWorkingText().split('\n');
        const entries = parseGroupEntries(originalLines, groupName);

        // Build modal textarea content (EXTINF + URL per entry)
        let buf = [];
        for (const entry of entries) {
            buf.push(currentLines[entry.extIndex]);
            if (entry.urlIndex >= 0) buf.push(currentLines[entry.urlIndex]);
        }

        modalTitle.textContent = 'Edit group: ' + groupName;
        groupEditor.value = buf.join('\n');
        // Store mapping to original indices so we can write back overlay
        modalBackdrop.dataset.groupName = groupName;
        modalBackdrop._entries = entries;
        modalBackdrop.style.display = 'flex';
    }

    function closeModal() {
        modalBackdrop.style.display = 'none';
        modalBackdrop.dataset.groupName = '';
        modalBackdrop._entries = null;
        // DO NOT touch main preview here
    }

    editGroupBtn.addEventListener('click', function() {
        openModalForGroup(categorySelect.value);
    });

    modalCancel.addEventListener('click', function() { closeModal(); });

    modalConfirm.addEventListener('click', function() {
        const groupName = modalBackdrop.dataset.groupName;
        const entries = modalBackdrop._entries || [];
        if (!groupName || entries.length === 0) { closeModal(); return; }

        const editedLines = groupEditor.value.split('\n');
        let k = 0;
        const newEntries = [];
        for (const entry of entries) {
            const extText = (k < editedLines.length) ? editedLines[k++] : entry.extText;
            let urlText = entry.urlText;
            if (entry.urlIndex >= 0) {
                urlText = (k < editedLines.length) ? editedLines[k++] : entry.urlText;
            }
            newEntries.push({
                extIndex: entry.extIndex,
                urlIndex: entry.urlIndex,
                extText: extText,
                urlText: urlText
            });
        }

        // Save overlay (does not mutate original; composes into preview)
        editedGroups[groupName] = newEntries;

        // Now reflect changes in the main preview
        updatePreview();
        closeModal();
    });

    // Real-time bulk find/replace (only main inputs affect preview)
    document.querySelectorAll('input[name="find[]"], input[name="replace[]"]').forEach(function(el) {
        el.addEventListener('input', updatePreview);
    });

    // Category selection does not change preview by itself; it scopes the popup
    categorySelect.addEventListener('change', function() {
        // Rebuild in case stored overlays for selected group exist
        updatePreview();
    });

    // Initial compose
    updatePreview();
    </script>
</body>
</html>
<?php
    exit;
}
?>
<!DOCTYPE html>
<html>
<head><meta charset="utf-8"><title>M3U Bulk Editor</title></head>
<body>
    <h2>Upload M3U Playlist</h2>
    <form method="post" enctype="multipart/form-data">
        <label>Upload M3U file: <input type="file" name="m3u" required></label><br><br>
        <button type="submit">Preview</button>
    </form>
</body>
</html>
