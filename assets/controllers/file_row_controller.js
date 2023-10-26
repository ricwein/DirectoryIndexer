import {Controller} from "@hotwired/stimulus"

export default class extends Controller {
    static targets = ["modal"]

    getTargetById(id) {
        for (let target of this.modalTargets) {
            if (target.dataset['id'] === id) {
                return target;
            }
        }

        return null;
    }

    openModal(event) {
        event.preventDefault();
        event.stopPropagation();

        const {url: url, id: id} = event.params;
        const target = this.getTargetById(id);
        if (target !== null) {
            target.showModal();

            target.addEventListener('click', (e) => this.backdropClick(e, target, id));

            fetch(url, {method: 'OPTIONS'})
                .then((response) => response.text())
                .then((data) => target.getElementsByClassName('content')[0].innerHTML = data)
        }
    }

    backdropClick(event, target, id) {
        event.stopPropagation();

        if (event.target !== target) {
            event.preventDefault();
            return;
        }

        this.closeModal(event, id)
    }


    closeModal(event, id = null) {
        event.preventDefault();
        event.stopPropagation();

        const target = this.getTargetById(id ?? event.params['id']);
        if (target !== null) {
            target.close();
        }
    }

    openLink(event) {
        console.log(event)
        event.preventDefault();
        event.stopPropagation();

        const {url: url} = event.params;
        window.open(url, '_self')

    }
}
