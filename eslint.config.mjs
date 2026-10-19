import wordpress from '@wordpress/eslint-plugin';
import globals from 'globals';

export default [
  ...wordpress.configs.esnext,
  {
    files: [ 'plugin/assets/*.js' ],
    languageOptions: {
      globals: {
        ...globals.browser,
        wp: 'readonly',
      },
    },
  },
  {
    files: [ 'worker/**/*.js' ],
    languageOptions: {
      globals: {
        ...globals.serviceworker,
        ...globals.node,
      },
    },
  },
];
