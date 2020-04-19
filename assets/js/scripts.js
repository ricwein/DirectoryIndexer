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

