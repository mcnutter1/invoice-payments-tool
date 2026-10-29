/** Built with the Tailwind standalone CLI (no Node runtime required): scripts/build-assets.sh */
module.exports = {
  content: ['./resources/views/**/*.blade.php', './public/js/**/*.js', './app/**/*.php', './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/tailwind.blade.php'],
  theme: {
    extend: {
      colors: {
        brand: {
          50: '#eef6fb', 100: '#d6e9f4', 200: '#aed3e9', 300: '#7db6d8', 400: '#4f95c2',
          500: '#2f78a8', 600: '#23659a', 700: '#1f5f8b', 800: '#1a4c70', 900: '#173f5c',
        },
      },
    },
  },
  plugins: [],
};
