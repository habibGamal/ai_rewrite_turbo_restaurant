import defaultTheme from "tailwindcss/defaultTheme";
import forms from "@tailwindcss/forms";

/** @type {import('tailwindcss').Config} */
export default {
    darkMode: "class",
    // corePlugins: {
    //     preflight: false,
    // },
    content: [
        "./vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php",
        "./storage/framework/views/*.php",
        "./resources/views/**/*.blade.php",
        './resources/views/filament/**/*.blade.php',
        "./resources/js/**/*.tsx",
    ],

    theme: {
        extend: {
            fontFamily: {
                sans: ["Figtree", ...defaultTheme.fontFamily.sans],
            },
            colors: {
                dark: {
                    50: "#373737",
                    100: "#353535",
                    200: "#323232",
                    300: "#2d2d2d",
                    400: "#2c2c2c",
                    500: "#272727",
                    600: "#252525",
                    700: "#222",
                    800: "#1e1e1e",
                    900: "#121212",
                },
            },
        },
    },

    // plugins: [forms],
};
