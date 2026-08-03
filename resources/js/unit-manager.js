const unitManagerDialog = document.querySelector('[data-unit-manager-dialog]');

if (unitManagerDialog instanceof HTMLDialogElement) {
    document.querySelectorAll('[data-unit-manager-open]').forEach((button) => {
        button.addEventListener('click', () => {
            if (!unitManagerDialog.open) {
                unitManagerDialog.showModal();
            }
        });
    });

    unitManagerDialog.querySelectorAll('[data-unit-manager-close]').forEach((button) => {
        button.addEventListener('click', () => unitManagerDialog.close());
    });

    unitManagerDialog.addEventListener('click', (event) => {
        if (event.target === unitManagerDialog) {
            unitManagerDialog.close();
        }
    });

    if (unitManagerDialog.hasAttribute('data-open-on-load')) {
        unitManagerDialog.showModal();
    }
}
