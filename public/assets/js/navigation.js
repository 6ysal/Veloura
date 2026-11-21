document.addEventListener("DOMContentLoaded", function () {
    const header = document.querySelector(".header");
    const menuToggle = header ? header.querySelector("[data-menu-toggle]") : null;
    const navigation = header ? header.querySelector("#site-navigation") : null;

    if (!header || !menuToggle || !navigation) {
        return;
    }

    function setMenuOpen(isOpen) {
        header.classList.toggle("is-menu-open", isOpen);
        menuToggle.setAttribute("aria-expanded", isOpen ? "true" : "false");
        menuToggle.setAttribute("aria-label", isOpen ? "Close navigation" : "Open navigation");
    }

    menuToggle.addEventListener("click", function () {
        setMenuOpen(menuToggle.getAttribute("aria-expanded") !== "true");
    });

    navigation.addEventListener("click", function (event) {
        if (event.target.closest("a")) {
            setMenuOpen(false);
        }
    });

    document.addEventListener("click", function (event) {
        if (window.matchMedia("(max-width: 860px)").matches && !header.contains(event.target)) {
            setMenuOpen(false);
        }
    });

    document.addEventListener("keydown", function (event) {
        if (event.key === "Escape") {
            setMenuOpen(false);
            const accountMenu = header.querySelector(".account-menu[open]");
            if (accountMenu) {
                accountMenu.removeAttribute("open");
            }
        }
    });

    window.addEventListener("resize", function () {
        if (window.innerWidth > 860) {
            setMenuOpen(false);
        }
    });
});
