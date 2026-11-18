/** @type {import('tailwindcss').Config} */
module.exports = {
  darkMode: 'class',
  content: [
    "./resources/**/*.blade.php",
    "./resources/**/*.js",
    "./resources/**/*.vue",
    "./app/Livewire/**/*.php",
  ],
  theme: {
    extend: {
      colors: {
        primary: {
          50: '#fdf3f6',
          100: '#fbe8ef',
          200: '#f5d3df',
          300: '#efb4c8',
          400: '#e58aa8',
          500: '#cb0c9f',
          600: '#b00a89',
          700: '#8f086f',
          800: '#700657',
          900: '#540542',
        },
        secondary: '#8392AB',
        dark: '#344767',
        soft: '#F8F9FA',
      },
      fontFamily: {
        sans: ['Open Sans', 'ui-sans-serif', 'system-ui', '-apple-system', 'BlinkMacSystemFont', 'Segoe UI', 'Roboto', 'Helvetica Neue', 'Arial', 'sans-serif'],
      },
      boxShadow: {
        'soft': '0 20px 27px 0 rgba(0, 0, 0, 0.05)',
        'soft-sm': '0 4px 6px -1px rgba(0, 0, 0, 0.08), 0 2px 4px -1px rgba(0, 0, 0, 0.04)',
        'soft-md': '0 8px 26px -4px rgba(20, 20, 20, 0.12), 0 8px 9px -5px rgba(20, 20, 20, 0.06)',
        'soft-xl': '0 23px 40px -10px rgba(0, 0, 0, 0.15)',
      },
      borderRadius: {
        '2xl': '1rem',
        '3xl': '1.5rem',
      },
    },
  },
  plugins: [
    require('@tailwindcss/forms'),
  ],
}
