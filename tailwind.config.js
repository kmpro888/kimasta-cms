/**
 * Tailwind configuration for the **admin** stylesheet only.
 *
 * The public theme keeps its own hand-written CSS: it is served to visitors and must not grow a
 * build step. This config exists so the admin can be styled with Tailwind utilities *without*
 * requiring Node in production — the generated `public/assets/admin.css` is committed, and the
 * runtime never sees `npx`.
 *
 * Build:  npx tailwindcss@3.4.19 -c tailwind.config.js -i resources/admin.tailwind.css -o public/assets/admin.css --minify
 */
module.exports = {
    content: [
        './app/Modules/Admin/Resources/views/**/*.php',
    ],
    theme: {
        extend: {
            fontFamily: {
                sans: [
                    'system-ui', '-apple-system', 'Segoe UI', 'Roboto', 'Helvetica Neue',
                    'Arial', 'Noto Sans', 'sans-serif',
                ],
            },
            boxShadow: {
                card: '0 1px 2px 0 rgb(15 23 42 / 0.04), 0 1px 3px 0 rgb(15 23 42 / 0.06)',
            },
        },
    },
    corePlugins: {
        // The admin never uses these; leaving them out keeps the generated file smaller.
        preflight: true,
    },
};
