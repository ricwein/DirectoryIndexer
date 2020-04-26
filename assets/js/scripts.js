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
    request.open('GET', url, true);
    request.onerror = function () {
        fetchingInfoHasFailed(btn);
    }
    request.onload = function () {
        if (request.status < 200 || request.status >= 400) {
            fetchingInfoHasFailed(btn);
            return;
        }
        let info = JSON.parse(request.responseText);
        modal.setContent(buildPopupContent(info));

        btn.innerHTML = originalBtnContent;
        btn.classList.remove("is-link");
        btn.classList.remove("is-danger");
        btn.classList.add("is-link");
        modal.open();
    };

    request.send();
}

function buildPopupContent(info) {
    if (info.isDir) {
        return "<h1><i class='" + info.type.faIcon + "'></i>&nbsp;" + info.filename + "</h1>" +
            "<div class='content'><ul>" +
            "<li><strong>Size</strong>: " + info.size.hr + "</li>" +
            "</ul></div>";
    }

    if (!info.isDir) {
        return "<h1><i class='" + info.type.faIcon + "'></i>&nbsp;" + info.filename + "</h1>" +
            "<div class='content'><ul>" +
            "<li><strong>Size</strong>: " + info.size.hr + "</li>" +
            "<li><strong>MimeType</strong>: " + (info.type.mime.length > 0 ? info.type.mime : '-') + "</li>" +
            "<li><strong>MD5</strong>: " + info.hash.md5 + "</li>" +
            "<li><strong>SHA1</strong>: " + info.hash.sha1 + "</li>" +
            "<li><strong>SHA256</strong>: " + info.hash.sha256 + "</li>" +
            "</ul></div>";
    }

    return "ERROR";
}

function fetchingInfoHasFailed(btn) {
    btn.innerHTML = '<i class="fas fa-times"></i>';
    btn.classList.remove("is-link");
    btn.classList.remove("is-danger");
    btn.classList.add("is-danger");
}
