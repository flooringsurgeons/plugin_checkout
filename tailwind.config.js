module.exports = {
  prefix: 'fls-',
  content: [
    './templates/**/*.php',
    './app/**/*.php',
    './resources/js/**/*.js',
    './vendor/preline/**/*.js'
  ],
  theme: {
    extend: {}
  },
  plugins: [
    require('@tailwindcss/forms'),
    require('preline/plugin')
  ]
};
