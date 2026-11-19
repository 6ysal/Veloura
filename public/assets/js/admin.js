(() => {
    const sidebar = document.getElementById("adminSidebar");
    const scrim = document.querySelector(".sidebar-scrim");
    const openButton = document.querySelector("[data-sidebar-open]");
    const closeButton = document.querySelector("[data-sidebar-close]");

    const setSidebarOpen = (isOpen) => {
        if (!sidebar || !scrim) return;
        sidebar.classList.toggle("is-open", isOpen);
        scrim.classList.toggle("is-visible", isOpen);
        document.body.classList.toggle("admin-menu-open", isOpen);
    };

    openButton?.addEventListener("click", () => setSidebarOpen(true));
    closeButton?.addEventListener("click", () => setSidebarOpen(false));
    sidebar?.querySelectorAll("a").forEach((link) => {
        link.addEventListener("click", () => setSidebarOpen(false));
    });

    document.addEventListener("keydown", (event) => {
        if (event.key === "Escape") setSidebarOpen(false);
    });

    document.querySelectorAll("form[data-confirm]").forEach((form) => {
        form.addEventListener("submit", (event) => {
            const prompt = form.getAttribute("data-confirm");
            if (prompt && !window.confirm(prompt)) event.preventDefault();
        });
    });

    document.querySelectorAll("[data-dismiss]").forEach((button) => {
        button.addEventListener("click", () => {
            button.closest(".flash-message")?.remove();
        });
    });

    document.querySelectorAll("[data-image-input]").forEach((input) => {
        input.addEventListener("change", () => {
            const file = input.files?.[0];
            const preview = input.closest("label")?.querySelector("[data-image-preview]");
            if (!preview) return;
            if (!file) {
                preview.hidden = true;
                preview.removeAttribute("src");
                return;
            }
            if (!["image/jpeg", "image/png", "image/webp"].includes(file.type)) {
                input.value = "";
                preview.hidden = true;
                window.alert("Choose a JPEG, PNG, or WebP image.");
                return;
            }
            preview.src = URL.createObjectURL(file);
            preview.hidden = false;
        });
    });
})();
