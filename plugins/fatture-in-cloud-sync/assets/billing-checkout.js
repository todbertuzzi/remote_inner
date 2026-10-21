/* Only controls field visibility; all validation also happens on the server. */
function initializeFicBilling() {
    document.querySelectorAll('[data-fic-billing]').forEach(function (form) {
        const type = form.querySelector('[name="fic_billing[customer_type]"]');
        const taxCode = form.querySelector('[name="fic_billing[tax_code]"]');
        function update() {
            const business = type.value === 'business';
            ['private', 'business'].forEach(function (kind) {
                const visible = (kind === 'business') === business;
                form.querySelectorAll('[data-fic-' + kind + ']').forEach(function (section) {
                    section.hidden = !visible;
                    section.querySelectorAll('input').forEach(function (input) { input.disabled = !visible; });
                });
            });
            taxCode.required = !business;
            form.querySelector('label[for="' + taxCode.id + '"]').textContent = business ? 'Codice fiscale (facoltativo)' : 'Codice fiscale';
        }
        type.addEventListener('change', update);
        update();
    });
}
if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', initializeFicBilling);
else initializeFicBilling();
