const defaultTheme = require("tailwindcss/defaultTheme");
module.exports = {
    content: [
        "./node_modules/flowbite/**/*.js",
        "./assets/**/*.js",
        "./templates/**/*.html.twig",
    ],
    theme: {
        extend: {
            // Set font family
            fontFamily: {
                sans: ["Inter", ...defaultTheme.fontFamily.sans],
            },
            scale: {
                '102': '1.02',
            },
            keyframes: {
                "fade-in": {
                    '0%': {opacity: '0%'},
                    '100%': {opacity: '100%'},
                }
            },
            animation: {
                "fade-in": 'fade-in 0.3s ease-in-out',
            }
        },
    },
    darkMode: 'media',
    plugins: [
        require('flowbite/plugin'),
        require("@tailwindcss/typography")
    ],
}
