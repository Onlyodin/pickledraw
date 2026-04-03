/* ============================================================
   DrawMaster - app.js
   ============================================================ */

(function () {
    'use strict';

    // ---- File Drop Zone ----
    const dropZone = document.getElementById('dropZone');
    const fileInput = document.getElementById('csvFile');

    if (dropZone) {
        ['dragenter', 'dragover'].forEach(evt => {
            dropZone.addEventListener(evt, e => {
                e.preventDefault();
                dropZone.classList.add('dragover');
            });
        });

        ['dragleave', 'drop'].forEach(evt => {
            dropZone.addEventListener(evt, () => dropZone.classList.remove('dragover'));
        });

        dropZone.addEventListener('drop', e => {
            e.preventDefault();
            const file = e.dataTransfer?.files[0];
            if (file && fileInput) {
                const dt = new DataTransfer();
                dt.items.add(file);
                fileInput.files = dt.files;
                updateFileLabel(file.name);
            }
        });

        if (fileInput) {
            fileInput.addEventListener('change', () => {
                const file = fileInput.files[0];
                if (file) updateFileLabel(file.name);
            });
        }
    }

    function updateFileLabel(name) {
        const content = dropZone?.querySelector('.file-drop-content');
        if (content) {
            content.querySelector('.file-text').innerHTML = `<strong>${escapeHtml(name)}</strong> selected`;
            content.querySelector('.file-icon').textContent = '✓';
        }
    }

    // ---- Sample Data ----
    window.loadSample = function () {
        const textarea = document.querySelector('textarea[name="csv_text"]');
        if (!textarea) return;

        const sample = `Player Name,Skill,Partner
Alice Thompson,8.5,Ben Clarke
Ben Clarke,8,Alice Thompson
Carol Wright,7.5,Dan Fisher
Dan Fisher,7,Carol Wright
Emma Davis,7,Frank Hall
Frank Hall,6.5,Emma Davis
Grace Allen,6,Henry Baker
Henry Baker,6,Grace Allen
Isla Morris,5.5,Jack Turner
Jack Turner,5,Isla Morris
Karen White,5,Leo King
Leo King,4.5,Karen White
Mia Scott,4,Noel Reed
Noel Reed,4,Mia Scott
Olivia Young,9,Peter Adams
Peter Adams,8.5,Olivia Young
Quinn Carter,8,Rose Evans
Rose Evans,7.5,Quinn Carter
Sam Mitchell,3.5,Tina Nelson
Tina Nelson,3,Sam Mitchell`;

        textarea.value = sample;
        textarea.style.borderColor = 'var(--accent)';
        setTimeout(() => { textarea.style.borderColor = ''; }, 800);
    };

    // ---- Score input: auto-format ----
    document.querySelectorAll('.score-input').forEach(input => {
        input.addEventListener('blur', () => {
            const val = input.value.trim();
            // Accept formats: "21-15" "21 15" "21:15"
            const match = val.match(/^(\d+)\s*[-:]\s*(\d+)$/);
            if (match) {
                input.value = `${match[1]} - ${match[2]}`;
                input.style.color = '#3ddc84';
            }
        });
    });

    // ---- Smooth scroll to draw section ----
    const drawSection = document.getElementById('step-draw');
    if (drawSection) {
        setTimeout(() => {
            drawSection.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }, 200);
    }

    // ---- Keyboard shortcut: Ctrl+Enter to submit form ----
    document.querySelectorAll('form').forEach(form => {
        form.addEventListener('keydown', e => {
            if ((e.ctrlKey || e.metaKey) && e.key === 'Enter') {
                form.querySelector('[type="submit"]')?.click();
            }
        });
    });

    // ---- Utility ----
    function escapeHtml(str) {
        return str.replace(/[&<>"']/g, m => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[m]));
    }
})();
