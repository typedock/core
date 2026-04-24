import * as esbuild from 'esbuild'

const watch = process.argv.includes('--watch')
const dev = process.env.NODE_ENV !== 'production'

const opts = {
  entryPoints: {
    'editor.bundle': 'admin/src/editor/index.js',
  },
  outdir: 'public/admin/dist',
  bundle: true,
  format: 'iife',
  globalName: 'TypeDockEditor',
  target: 'es2020',
  minify: !dev,
  sourcemap: dev,
  loader: {
    '.css': 'css',
  },
}

if (watch) {
  const ctx = await esbuild.context(opts)
  await ctx.watch()
  console.log('esbuild: watching admin/src/editor/...')
} else {
  await esbuild.build(opts)
  console.log('esbuild: built editor.bundle.js')
}
