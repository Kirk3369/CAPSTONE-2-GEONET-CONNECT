document.addEventListener("DOMContentLoaded", () => {
    const togglePassword = document.getElementById("togglePassword");
    const password = document.getElementById("password");

    if (togglePassword && password) {
        togglePassword.addEventListener("click", function () {
            const type = password.getAttribute("type");
            if (type === "password") {
                password.setAttribute("type", "text");
                this.setAttribute("aria-label", "Hide password");
                this.setAttribute("aria-pressed", "true");
                this.innerHTML = '<i class="fa-regular fa-eye-slash"></i>';
            } else {
                password.setAttribute("type", "password");
                this.setAttribute("aria-label", "Show password");
                this.setAttribute("aria-pressed", "false");
                this.innerHTML = '<i class="fa-regular fa-eye"></i>';
            }
        });
    }

    const helpButton = document.querySelector(".help-button");
    const helpModal = document.getElementById("helpModal");

    if (helpButton && helpModal) {
        const dialog = helpModal.querySelector('[role="dialog"]');
        const background = document.querySelector(".login-container");
        let previousOverflow = "";
        helpButton.setAttribute("aria-controls", helpModal.id);
        helpButton.setAttribute("aria-expanded", "false");
        helpModal.inert = true;
        const closeHelpModal = () => {
            helpModal.classList.remove("open");
            if (background) background.inert = false;
            helpButton.setAttribute("aria-expanded", "false");
            document.body.style.overflow = previousOverflow;
            helpButton.focus();
            helpModal.setAttribute("aria-hidden", "true");
            helpModal.inert = true;
        };

        const openHelpModal = () => {
            previousOverflow = document.body.style.overflow;
            helpModal.inert = false;
            helpModal.classList.add("open");
            helpModal.setAttribute("aria-hidden", "false");
            helpButton.setAttribute("aria-expanded", "true");
            document.body.style.overflow = "hidden";
            helpModal.querySelector(".help-modal-close")?.focus();
            if (background) background.inert = true;
        };

        helpButton.addEventListener("click", () => {
            if (helpModal.classList.contains("open")) {
                closeHelpModal();
                return;
            }

            openHelpModal();
        });

        helpModal.addEventListener("click", (event) => {
            if (event.target === helpModal) {
                closeHelpModal();
            }
        });

        const helpCloseButton = helpModal.querySelector(".help-modal-close");
        helpCloseButton?.addEventListener("click", closeHelpModal);

        document.addEventListener("keydown", (event) => {
            if (!helpModal.classList.contains("open")) return;
            if (event.key === "Escape") {
                event.preventDefault();
                closeHelpModal();
            }
            if (event.key === "Tab") {
                const focusable = Array.from(helpModal.querySelectorAll(
                    'button:not([disabled]), a[href], input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex="0"]'
                )).filter((element) => element.getClientRects().length > 0);
                const first = focusable[0];
                const last = focusable[focusable.length - 1];
                if (!first) {
                    event.preventDefault();
                    dialog?.focus();
                } else if (event.shiftKey && (document.activeElement === first || !helpModal.contains(document.activeElement))) {
                    event.preventDefault();
                    last.focus();
                } else if (!event.shiftKey && (document.activeElement === last || !helpModal.contains(document.activeElement))) {
                    event.preventDefault();
                    first.focus();
                }
            }
        });
    }
});
