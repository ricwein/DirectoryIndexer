document
    .querySelectorAll('.clickableRow')
    .forEach(row => row.addEventListener('click', function (event) {
        if (row.dataset.uri) {
            window.open(row.dataset.uri, '_self');
        }
    }));
