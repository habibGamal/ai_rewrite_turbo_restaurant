/** @type {import('tailwindcss').Config} */
export default {
  content: [
    './inertia/**/*.tsx',
    './inertia/**/*.ts',
  ],
  darkMode: 'class',
  corePlugins: {
    preflight: false, // <== disable this!
  },
  theme: {
    extend: {
      container: {
        center: true,
        padding: '2rem',
      },
      colors: {
        'ov-white': '#eef1f7',
        'main': '#f23276',
        'second': '#195cc5',
        'x-blue': '#273044',
        'indigo': {
          50: '#EDE7F6',
          100: '#D1C4E9',
          200: '#B39DDB',
          300: '#9575CD',
          400: '#7E57C2',
          500: '#673AB7',
          600: '#5E35B1',
          700: '#512DA8',
          800: '#4527A0',
          900: '#311B92',
        },
        'yellow': {
          100: '#FFF7D4',
          200: '#FFEDAA',
          300: '#FFE07F',
          400: '#FFD460',
          500: '#FFC02B',
          600: '#DB9D1F',
          700: '#B77D15',
          800: '#935F0D',
          900: '#7A4A08',
        },
        'dark': {
          50: '#373737',
          100: '#353535',
          200: '#323232',
          300: '#2d2d2d',
          400: '#2c2c2c',
          500: '#272727',
          600: '#252525',
          700: '#222',
          800: '#1e1e1e',
          900: '#121212',
        },
      },
    },
  },
  plugins: [],
}

