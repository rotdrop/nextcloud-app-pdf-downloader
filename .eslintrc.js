module.exports = {
  extends: [
    '@nextcloud',
  ],
  rules: {
    'no-tabs': ['error', { allowIndentationTabs: false }],
    indent: ['error', 2],
    'no-mixed-spaces-and-tabs': 'error',
    'vue/html-indent': ['error', 2],
    semi: ['error', 'always'],
    'node/no-unpublished-import': 'off',
    'node/no-unpublished-require': 'off',
    'no-console': 'off',
    'node/no-missing-require': [
      'error', {
        // 'allowModules': [],
        resolvePaths: [
          './src',
          './style',
          './',
        ],
        tryExtensions: ['.js', '.json', '.node', '.css', '.scss', '.xml', '.vue'],
      },
    ],
  },
  overrides: [
    {
      files: ['*.vue'],
      rules: {
        semi: ['error', 'never'],
      },
    },
  ],
};
