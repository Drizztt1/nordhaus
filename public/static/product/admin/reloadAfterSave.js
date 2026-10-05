/**
 * Po zapisie produktu przeładowuje formularz, żeby edytor od razu widział wartości wyliczone na backendzie
 * w pre-save (availability, wygenerowany urlKey, znormalizowane SKU). Bez tego admin pokazuje stan sprzed zapisu.
 * reload() zachowuje otwartą zakładkę i stan layoutu.
 */
document.addEventListener(pimcore.events.postSaveObject, (event) => {
    const object = event.detail.object;

    // willClose: "Zapisz (i opublikuj) i zamknij" — zakładka zaraz zniknie, nie ma czego przeładowywać.
    if (event.detail.task === 'autoSave' || object?.willClose || object?.data?.general?.className !== 'Product') {
        return;
    }

    // postSaveObject przychodzi jeszcze w trakcie obsługi zapisu (object.saving === true). Przeładowanie od razu
    // zamyka zakładkę w połowie zapisu i admin pokazuje "Another saving process is in progress".
    const reloadWhenSaved = () => {
        if (!object.tab || object.tab.destroyed) {
            return; // zakładkę zamknięto w międzyczasie
        }
        object.saving ? setTimeout(reloadWhenSaved, 50) : object.reload();
    };
    setTimeout(reloadWhenSaved, 0);
});
