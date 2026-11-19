// Traitement en masse : une requête par pièce jointe, dans l'ordre, jusqu'à la fin ou l'arrêt.
(() => {
    const root = document.getElementById("cereal-images-bulk");

    if (!root || !window.wp?.apiFetch || !window.cerealImages) {
        return;
    }

    const apiFetch = window.wp.apiFetch;
    const base = `/${window.cerealImages.namespace}`;
    const buttons = root.querySelectorAll("[data-mode]");
    const stopButton = root.querySelector("[data-stop]");
    const status = root.querySelector("[data-status]");
    const progress = root.querySelector("[data-progress]");
    const errors = root.querySelector("[data-errors]");
    let stopped = false;

    const addError = (message) => {
        const item = document.createElement("li");
        item.textContent = message;
        errors.append(item);
    };

    const setRunning = (running) => {
        buttons.forEach((button) => {
            button.disabled = running;
        });
        stopButton.hidden = !running;
        progress.hidden = !running && progress.value === 0;
    };

    const run = async (mode, action) => {
        stopped = false;
        errors.replaceChildren();
        progress.value = 0;
        setRunning(true);
        status.textContent = "Préparation de la liste…";

        let ids;

        try {
            ({ ids } = await apiFetch({ path: `${base}/queue?mode=${mode}` }));
        } catch (error) {
            status.textContent = `Liste impossible à obtenir : ${error.message ?? error}`;
            setRunning(false);
            return;
        }

        progress.max = Math.max(ids.length, 1);
        let failed = 0;

        for (const [index, id] of ids.entries()) {
            if (stopped) {
                break;
            }

            status.textContent = `${index + 1} / ${ids.length} — pièce jointe #${id}`;

            try {
                const result = await apiFetch({ path: `${base}/${action}/${id}`, method: "POST" });
                result.errors.forEach(addError);
                failed += result.errors.length > 0 ? 1 : 0;
            } catch (error) {
                // Délai dépassé, erreur PHP… : on note et on continue avec la suivante.
                addError(`#${id} : ${error.message ?? error}`);
                failed += 1;
            }

            progress.value = index + 1;
        }

        const done = progress.value;
        status.textContent = `${stopped ? "Arrêté" : "Terminé"} : ${done} / ${ids.length} pièces jointes, ${failed} avec des erreurs. Recharger la page pour les statistiques.`;
        setRunning(false);
    };

    buttons.forEach((button) => {
        button.addEventListener("click", () => {
            if (button.dataset.confirm && !window.confirm(button.dataset.confirm)) {
                return;
            }

            run(button.dataset.mode, button.dataset.action ?? "process");
        });
    });

    stopButton.addEventListener("click", () => {
        stopped = true;
        status.textContent = "Arrêt après la pièce jointe en cours…";
    });
})();
