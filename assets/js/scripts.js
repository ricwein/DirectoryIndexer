function updateSearchButton() {
    let btn = document.getElementById('search_button');
    btn.innerHTML = '<i class="fas fa-spinner fa-pulse"></i>';
}

// handle smooth scrolling to anchor links
let anchorLink = document.querySelector('.scroll-smooth');
if (anchorLink !== null) {
    anchorLink.addEventListener('click', function (event) {
        event.preventDefault();
        document.querySelector(anchorLink.dataset.anchor).scrollIntoView({
            behavior: 'smooth'
        });
    });
}

// handle modal-dialogs
document
    .querySelectorAll('.view-modal-info')
    .forEach(button => button.addEventListener('click', function (event) {
        event.preventDefault();
        event.stopPropagation();
        openModalDetails(button.href, button);
    }));

function openModalDetails(url, btn) {
    let originalBtnContent = btn.innerHTML;
    btn.innerHTML = '<i class="fas fa-spinner fa-pulse"></i>';

    var modal = new tingle.modal({
        footer: true,
        cssClass: ['container', 'is-fluid'],
        closeLabel: "Close",
    });
    modal.addFooterBtn('Close', 'button is-danger is-pulled-right', function () {
        modal.close();
    });

    let request = new XMLHttpRequest();
    request.open('POST', url, true);
    request.onerror = function () {
        fetchingInfoHasFailed(btn);
    }
    request.onload = function () {
        if (request.status < 200 || request.status >= 400) {
            fetchingInfoHasFailed(btn);
            return;
        }
        modal.setContent(request.responseText);

        btn.innerHTML = originalBtnContent;
        btn.classList.remove("is-black");
        btn.classList.remove("is-danger");
        btn.classList.add("is-black");
        modal.open();
    };

    request.send();
}

function fetchingInfoHasFailed(btn) {
    btn.innerHTML = '<i class="fas fa-times"></i>';
    btn.classList.remove("is-black");
    btn.classList.remove("is-danger");
    btn.classList.add("is-danger");
}
