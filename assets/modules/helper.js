export function openUrl(url, event) {
    window.open(
        url,
        (event.metaKey || event.which === 2)
            ? '_target'
            : '_self'
    );
}
