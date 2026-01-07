const replaceImportMeta = ({ types: t }) => ({
  name: 'replace-import-meta',
  visitor: {
    MetaProperty(path) {
      if (path.node.meta.name === 'import' && path.node.property.name === 'meta') {
        path.replaceWith(t.identifier('__IMPORT_META__'));
      }
    },
  },
});

module.exports = {
  presets: [
    ['@babel/preset-env', { targets: { node: 'current' } }],
    ['@babel/preset-react', { runtime: 'automatic' }],
  ],
  plugins: ['@babel/plugin-syntax-import-meta', replaceImportMeta],
};
