document.addEventListener("click", async (event) => {
    const button = event.target.closest("[data-copy-coupon]");
    if (!button) {
        return;
    }

    const feedback = button.closest("[data-offer-card]")?.querySelector("[data-copy-feedback]");
    const couponCode = button.dataset.couponCode;
    if (!feedback || !couponCode) {
        return;
    }

    try {
        await navigator.clipboard.writeText(couponCode);
        feedback.textContent = "Coupon code copied.";
    } catch {
        feedback.textContent = "Copy unavailable. Select the code above to copy it.";
    }
});
