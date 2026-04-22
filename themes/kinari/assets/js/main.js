(function () {
    "use strict";

    // Mobile navigation toggle — progressive enhancement on the hamburger
    // button emitted by partials/header.latte. No-ops gracefully if either
    // the toggle or the nav element is missing.
    const toggle = document.querySelector(".menu-toggle");
    const nav    = document.querySelector("#primary-navigation");
    if (toggle && nav) {
        toggle.addEventListener("click", function () {
            const expanded = toggle.getAttribute("aria-expanded") === "true";
            toggle.setAttribute("aria-expanded", expanded ? "false" : "true");
            nav.classList.toggle("is-open", !expanded);
        });
    }
})();
