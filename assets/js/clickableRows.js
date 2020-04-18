document
    .querySelectorAll('.clickableRow')
    .forEach(row => row.addEventListener(
        'click',
        function() { window.open(row.dataset.uri, '_self'); }
    ));
