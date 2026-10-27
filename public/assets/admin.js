/*
 * Admin behaviour that used to live in inline attributes.
 *
 * Inline event handlers (`onsubmit="return confirm(...)"`) require `'unsafe-inline'` in the
 * Content-Security-Policy, which is the one directive that makes a policy close to worthless. This
 * file exists so the policy can stay at `script-src 'self'`.
 *
 * Forms opt in with `data-confirm="message"`; nothing else changes.
 */
document.addEventListener(
    'submit',
    function (event) {
        var form = event.target;

        if (!(form instanceof HTMLFormElement)) {
            return;
        }

        var message = form.getAttribute('data-confirm');

        if (message && !window.confirm(message)) {
            event.preventDefault();
        }
    },
    // Capture, so the confirmation runs before any other submit handler can claim the event.
    true
);
