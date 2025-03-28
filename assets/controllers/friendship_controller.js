import { Controller } from "@hotwired/stimulus";

export default class extends Controller {
    static targets = ["friendList", "pendingList", "userIdInput", "statusMessage"];
    static values = {
        fetchUrl: String,       // /api/friends
        pendingUrl: String      // /api/friend-request/pending
    };

    connect() {
        this.loadFriends();
        this.loadPendingRequests();
    }

    // 🔹 Liste des amis
    loadFriends() {
        fetch('/api/friends')
            .then(response => response.json())
            .then(data => {
                this.friendListTarget.innerHTML = "";

                if (data.length === 0) {
                    this.friendListTarget.innerHTML = "<li>You dont have the friends for the moment</li>";
                    return;
                }

                data.forEach(friend => {
                    const li = document.createElement("li");
                    li.textContent = `#${friend.id} – ${friend.email}`;
                    this.friendListTarget.appendChild(li);
                });
            })
            .catch(err => {
                this.friendListTarget.innerHTML = "<li>error</li>";
                console.error(err);
            });
    }

    // 🔹 Demandes reçues
    loadPendingRequests() {
        fetch('/api/friend-request/pending')
            .then(response => response.json())
            .then(data => {
                this.pendingListTarget.innerHTML = "";

                if (data.length === 0) {
                    this.pendingListTarget.innerHTML = "<li>No request</li>";
                    return;
                }

                data.forEach(request => {
                    const li = document.createElement("li");
                    li.innerHTML = `
                    ${request.email}
                    <button class="pixel-button btn-pause" data-action="click->friendship#acceptRequest" data-id="${request.id}">Accept</button>
                    <button class="pixel-button btn-start" data-action="click->friendship#rejectRequest" data-id="${request.id}">Reject</button>
                `;
                
                    this.pendingListTarget.appendChild(li);
                });
            })
            .catch(err => {
                this.pendingListTarget.innerHTML = "<li>load error</li>";
                console.error(err);
            });
    }

    // 🔹 Envoi d'une demande
    sendRequest() {
        const userId = this.userIdInputTarget.value;

        fetch(`/api/friend-request/send/${userId}`, {
            method: "POST"
        })
        .then(response => response.json())
        .then(data => {
            if (data.error) {
                this.showFlash('error', data.error);
            } else {
                this.showFlash('success', data.message);
            }

            this.userIdInputTarget.value = "";
            this.loadFriends();
            this.loadPendingRequests();
        })
        .catch(err => {
            this.showFlash('error', "An error occurred");
            console.error(err);
        });
    }

    acceptRequest(event) {
        const friendshipId = event.target.dataset.id;

        fetch(`/api/friend-request/accept/${friendshipId}`, {
            method: "POST"
        })
        .then(response => response.json())
        .then(data => {
            this.showFlash('success', data.message);
            this.loadFriends();
            this.loadPendingRequests();
        })
        .catch(err => {
            this.showFlash('error', "Error accepting the request");
            console.error(err);
        });
    }

    // 🔹 Rejeter une demande
    rejectRequest(event) {
        const friendshipId = event.target.dataset.id;

        fetch(`/api/friend-request/reject/${friendshipId}`, {
            method: "POST"
        })
        .then(response => response.json())
        .then(data => {
            this.showFlash('error', data.message);
            this.loadPendingRequests();
        })
        .catch(err => {
            this.showFlash('error', "Error rejecting the request");
            console.error(err);
        });
    }

    showFlash(type, message) {
        const flash = document.createElement("div");
        flash.className = `flash-message flash-${type}`;
        flash.textContent = message;
    
        document.body.appendChild(flash);
        
        // Disparition après 3 secondes
        setTimeout(() => {
            flash.classList.add("hide");
            flash.addEventListener("transitionend", () => flash.remove());
        }, 3000);
    }
    
}
