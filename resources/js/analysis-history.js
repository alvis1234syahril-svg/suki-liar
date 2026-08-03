document.querySelectorAll('[data-analysis-edit-open]').forEach((button) => {
    button.addEventListener('click', () => {
        const dialogId = button.dataset.dialogId;
        const dialog = dialogId ? document.getElementById(dialogId) : null;

        if (!(dialog instanceof HTMLDialogElement)) {
            return;
        }

        if (!dialog.open) {
            dialog.showModal();
        }
    });
});

document.querySelectorAll('[data-analysis-edit-dialog]').forEach((dialog) => {
    dialog.querySelectorAll('[data-analysis-edit-close]').forEach((button) => {
        button.addEventListener('click', () => dialog.close());
    });

    dialog.addEventListener('click', (event) => {
        if (event.target === dialog) {
            dialog.close();
        }
    });
});
