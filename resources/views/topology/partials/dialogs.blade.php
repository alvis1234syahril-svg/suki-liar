<dialog class="at-dialog" data-location-dialog aria-labelledby="at-location-dialog-title">
    <form method="dialog" data-location-form>
        <header class="at-dialog-header">
            <div>
                <p class="at-eyebrow">Lokasi baru</p>
                <h2 id="at-location-dialog-title">Tambahkan lokasi</h2>
                <p>Sistem akan mencari sheet lokasi pada workbook master aset yang tersimpan di server.</p>
            </div>
            <button class="at-icon-button" type="button" data-close-dialog aria-label="Tutup dialog">&times;</button>
        </header>
        <div class="at-dialog-body">
            <label class="at-form-field">
                <span>Nama lokasi</span>
                <input type="text" name="name" required maxlength="120" autocomplete="organization" placeholder="Contoh: UPT PALEMBANG">
                <small>Ketik nama persis atau bagian unik dari nama sheet, contoh: UP2B SBS.</small>
            </label>
            <p class="at-form-error" data-location-error role="alert" hidden></p>
        </div>
        <footer class="at-dialog-footer">
            <button class="at-button at-button-secondary" type="button" data-close-dialog>Batal</button>
            <button class="at-button at-button-primary" type="submit" data-location-submit>Simpan lokasi</button>
        </footer>
    </form>
</dialog>

<dialog class="at-dialog" data-import-dialog aria-labelledby="at-import-dialog-title">
    <form method="dialog" data-import-form enctype="multipart/form-data">
        <header class="at-dialog-header">
            <div>
                <p class="at-eyebrow">Sinkronisasi database</p>
                <h2 id="at-import-dialog-title">Import dan susun otomatis</h2>
                <p>Sistem mengenali perangkat, membuat ID bila perlu, dan menyusun parent topologi secara otomatis.</p>
            </div>
            <button class="at-icon-button" type="button" data-close-dialog aria-label="Tutup dialog">&times;</button>
        </header>
        <div class="at-dialog-body">
            <div class="at-import-location">
                <span>Lokasi yang diperbarui</span>
                <strong data-import-location>Belum ada lokasi aktif</strong>
            </div>
            <label class="at-form-field">
                <span>File inventaris perangkat Excel</span>
                <input type="file" name="workbook" required accept=".xlsx,.xls,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,application/vnd.ms-excel">
                <small>Maksimal 50 MB. Kolom Jenis Aset, ID Aset, dan Parent Asset tidak wajib. File tetap harus memiliki identitas perangkat seperti hostname/IP serta petunjuk model, merek, atau nama perangkat.</small>
            </label>
            <p class="at-form-error" data-import-error role="alert" hidden></p>
        </div>
        <footer class="at-dialog-footer">
            <button class="at-button at-button-secondary" type="button" data-close-dialog>Batal</button>
            <button class="at-button at-button-primary" type="submit" data-import-submit>Import dan susun topologi</button>
        </footer>
    </form>
</dialog>

<dialog class="at-dialog" data-port-dialog aria-labelledby="at-port-dialog-title">
    <form method="dialog" data-port-form>
        <header class="at-dialog-header">
            <div>
                <p class="at-eyebrow">Penempatan access point</p>
                <h2 id="at-port-dialog-title">Hubungkan AP melalui switch</h2>
                <p data-port-summary>Pilih AP yang sudah tercatat di database.</p>
            </div>
            <button class="at-icon-button" type="button" data-close-dialog aria-label="Tutup dialog">&times;</button>
        </header>
        <div class="at-dialog-body">
            <div class="at-port-target">
                <span>Port tujuan</span>
                <strong data-port-target>—</strong>
            </div>
            <label class="at-form-field">
                <span>Access point dari database</span>
                <select name="access_point_id" data-port-access-point required>
                    <option value="">Pilih access point</option>
                </select>
                <small>Nama, ID aset, model, IP, dan lokasi AP diambil otomatis dari hasil import Excel.</small>
            </label>
            <p class="at-form-error" data-port-error role="alert" hidden></p>
        </div>
        <footer class="at-dialog-footer">
            <button class="at-button at-button-secondary" type="button" data-close-dialog>Batal</button>
            <button class="at-button at-button-primary" type="submit" data-port-submit>Hubungkan AP</button>
        </footer>
    </form>
</dialog>

<dialog class="at-dialog at-asset-dialog" data-asset-dialog aria-labelledby="at-asset-dialog-title">
    <form method="dialog" data-asset-form>
        <header class="at-dialog-header">
            <div>
                <p class="at-eyebrow">Kelola aset</p>
                <h2 id="at-asset-dialog-title" data-asset-dialog-title>Perubahan aset</h2>
                <p data-asset-dialog-summary>Pilih tindakan yang ingin dilakukan.</p>
            </div>
            <button class="at-icon-button" type="button" data-close-dialog aria-label="Tutup dialog">×</button>
        </header>

        <div class="at-dialog-body">
            <fieldset class="at-action-switcher">
                <legend>Jenis perubahan</legend>
                <label><input type="radio" name="action" value="replace" checked><span>Ganti aset</span></label>
                <label><input type="radio" name="action" value="mutate"><span>Mutasi lokasi</span></label>
            </fieldset>

            <section class="at-action-section" data-action-section="replace">
                <div class="at-form-grid">
                    <label class="at-form-field at-span-2"><span>ID aset pengganti</span><input name="replacement_asset_id" maxlength="120" required></label>
                    <label class="at-form-field"><span>Merk</span><input name="brand" maxlength="120"></label>
                    <label class="at-form-field"><span>Model</span><input name="model" maxlength="160" required></label>
                    <label class="at-form-field"><span>Serial number</span><input name="serial_number" maxlength="160"></label>
                    <label class="at-form-field"><span>Firmware / OS</span><input name="firmware" maxlength="180"></label>
                    <label class="at-form-field"><span>Kondisi</span><input name="condition" maxlength="100" placeholder="Contoh: baik"></label>
                    <label class="at-form-field"><span>Status operasional</span><input name="operational" maxlength="100" placeholder="Contoh: aktif"></label>
                    <label class="at-form-field at-span-2"><span>Catatan aset baru</span><textarea name="notes" rows="2" maxlength="500"></textarea></label>
                </div>
            </section>

            <section class="at-action-section" data-action-section="mutate" hidden>
                <div class="at-form-grid">
                    <label class="at-form-field at-span-2"><span>Lokasi tujuan</span><select name="target_organizational_unit_id" data-mutation-location></select></label>
                    <label class="at-form-field at-span-2"><span>Detail penempatan</span><input name="location" maxlength="200" placeholder="Ruang, lantai, atau site" required></label>
                    <label class="at-form-field at-span-2"><span>Parent asset (opsional)</span><input name="parent_asset" maxlength="160" placeholder="ID parent pada lokasi tujuan"></label>
                </div>
            </section>

            <div class="at-form-grid at-common-fields">
                <label class="at-form-field"><span>Tanggal efektif</span><input type="date" name="effective_date" required></label>
                <label class="at-form-field at-span-2"><span>Alasan perubahan</span><textarea name="reason" rows="3" maxlength="500" required></textarea></label>
            </div>
            <p class="at-form-error" data-asset-error role="alert" hidden></p>
        </div>

        <footer class="at-dialog-footer">
            <button class="at-button at-button-secondary" type="button" data-close-dialog>Batal</button>
            <button class="at-button at-button-primary" type="submit" data-asset-submit>Simpan perubahan</button>
        </footer>
    </form>
</dialog>
