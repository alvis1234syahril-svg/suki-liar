const form = document.getElementById('analysis-upload-form');

if (form) {
    const fileInput = document.getElementById('log_file');
    const fileInfo = document.getElementById('selected-file-info');
    const submit = document.getElementById('upload-submit');
    const panel = document.getElementById('upload-progress-panel');
    const title = document.getElementById('upload-progress-title');
    const message = document.getElementById('upload-progress-message');
    const percent = document.getElementById('upload-progress-percent');
    const bar = document.getElementById('upload-progress-bar');
    const maximumBytes = 500 * 1024 * 1024;
    const chunkSize = 5 * 1024 * 1024;
    const formatter = new Intl.NumberFormat('id-ID', { maximumFractionDigits: 1 });

    const formatBytes = (bytes) => {
        if (bytes >= 1024 ** 3) return `${formatter.format(bytes / 1024 ** 3)} GB`;
        if (bytes >= 1024 ** 2) return `${formatter.format(bytes / 1024 ** 2)} MB`;
        return `${formatter.format(bytes / 1024)} KB`;
    };

    const csrfToken = () => form.querySelector('input[name="_token"]')?.value ?? '';

    const readResponse = async (response) => {
        let data = {};
        try { data = await response.json(); } catch { data = {}; }

        if (!response.ok) {
            const validationMessage = Object.values(data.errors ?? {})[0]?.[0];
            throw new Error(validationMessage || data.message || `Server menolak permintaan (${response.status}).`);
        }

        return data;
    };

    const showError = (text) => {
        panel.classList.remove('hidden', 'border-blue-100', 'bg-blue-50');
        panel.classList.add('border-red-100', 'bg-red-50');
        title.className = 'text-sm font-extrabold text-red-950';
        message.className = 'mt-1 text-xs text-red-700';
        percent.className = 'font-mono text-sm font-extrabold text-red-800';
        bar.className = 'h-full rounded-full bg-red-500 transition-all';
        title.textContent = 'File belum berhasil diunggah';
        message.textContent = text;
        percent.textContent = 'Gagal';
        bar.style.width = '100%';
        submit.disabled = false;
        submit.textContent = 'Coba unggah lagi';
    };

    const sendChunk = (url, chunk, index, file, uploadedBefore) => new Promise((resolve, reject) => {
        const request = new XMLHttpRequest();
        const data = new FormData();
        data.append('_token', csrfToken());
        data.append('index', String(index));
        data.append('chunk', chunk, `${file.name}.part-${index}`);

        request.open('POST', url);
        request.setRequestHeader('Accept', 'application/json');
        request.setRequestHeader('X-Requested-With', 'XMLHttpRequest');

        request.upload.addEventListener('progress', (event) => {
            if (!event.lengthComputable) return;
            const uploaded = Math.min(file.size, uploadedBefore + event.loaded);
            const value = Math.min(99, Math.round((uploaded / file.size) * 100));
            percent.textContent = `${value}%`;
            bar.style.width = `${value}%`;
            message.textContent = `${formatBytes(uploaded)} dari ${formatBytes(file.size)} sudah terkirim.`;
        });

        request.addEventListener('load', () => {
            let data = {};
            try { data = JSON.parse(request.responseText); } catch { data = {}; }

            if (request.status >= 200 && request.status < 300) {
                resolve(data);
                return;
            }

            const validationMessage = Object.values(data.errors ?? {})[0]?.[0];
            reject(new Error(validationMessage || data.message || `Potongan ke-${index + 1} ditolak server.`));
        });

        request.addEventListener('error', () => reject(new Error('Koneksi terputus saat mengirim potongan file.')));
        request.send(data);
    });

    fileInput?.addEventListener('change', () => {
        const file = fileInput.files?.[0];
        if (!file) {
            fileInfo.classList.add('hidden');
            return;
        }

        fileInfo.textContent = `${file.name} • ${formatBytes(file.size)}`;
        fileInfo.classList.remove('hidden');

        if (file.size > maximumBytes) {
            fileInput.value = '';
            showError('Ukuran file melebihi batas 500 MB. Pecah file menjadi beberapa bagian sebelum dianalisis.');
        }
    });

    form.addEventListener('submit', async (event) => {
        event.preventDefault();

        const file = fileInput.files?.[0];
        if (!file || file.size > maximumBytes) return;

        panel.classList.remove('hidden', 'border-red-100', 'bg-red-50');
        panel.classList.add('border-blue-100', 'bg-blue-50');
        title.className = 'text-sm font-extrabold text-blue-950';
        message.className = 'mt-1 text-xs text-blue-700';
        percent.className = 'font-mono text-sm font-extrabold text-blue-800';
        bar.className = 'h-full rounded-full bg-blue-600 transition-all';
        title.textContent = 'Sedang mengunggah file';
        message.textContent = `Ukuran file ${formatBytes(file.size)}. File akan dikirim sedikit demi sedikit.`;
        percent.textContent = '0%';
        bar.style.width = '0%';
        submit.disabled = true;
        submit.textContent = 'Sedang mengunggah…';

        try {
            const startData = new FormData();
            startData.append('_token', csrfToken());
            startData.append('file_name', file.name);
            startData.append('file_size', String(file.size));
            startData.append('organizational_unit_id', form.querySelector('[name="organizational_unit_id"]')?.value ?? '');

            const session = await readResponse(await fetch(form.dataset.chunkUploadUrl, {
                method: 'POST',
                body: startData,
                headers: {'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest'},
            }));

            const totalChunks = Math.ceil(file.size / chunkSize);

            for (let index = 0; index < totalChunks; index++) {
                const start = index * chunkSize;
                const end = Math.min(file.size, start + chunkSize);
                await sendChunk(session.chunk_url, file.slice(start, end), index, file, start);
            }

            title.textContent = 'File sudah terunggah';
            message.textContent = 'Analisis akan dimulai…';
            percent.textContent = '100%';
            bar.style.width = '100%';

            const completeData = new FormData();
            completeData.append('_token', csrfToken());
            const completed = await readResponse(await fetch(session.complete_url, {
                method: 'POST',
                body: completeData,
                headers: {'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest'},
            }));

            window.location.assign(completed.redirect_url);
        } catch (error) {
            showError(error.message || 'Upload tidak dapat diselesaikan.');
        }
    });
}
