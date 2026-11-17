(function () {
    const profileForm = document.getElementById("profileForm");
    if (!profileForm) return;

    const toggle = profileForm.querySelector(".profile-password-toggle");
    const fields = profileForm.querySelector(".profile-password-fields");
    const password = profileForm.elements.password;
    if (!toggle || !fields || !password) return;

    toggle.addEventListener("click", () => {
        const isExpanded = toggle.getAttribute("aria-expanded") === "true";
        toggle.setAttribute("aria-expanded", String(!isExpanded));
        fields.hidden = isExpanded;
        if (!isExpanded) password.focus();
    });
})();
