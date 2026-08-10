import { defineConfig } from 'vitest/config'
import { fileURLToPath } from 'node:url'

/*
 * The extension's runtime JavaScript is plain ES modules served through TYPO3's
 * importmap - there is no bundler and this config must not become one. It only
 * resolves the bare specifiers the importmap would have resolved in the browser,
 * pointing them at test doubles, so a module can be imported outside a backend.
 */
const doubles = fileURLToPath(new URL('./Tests/JavaScript/doubles', import.meta.url))

export default defineConfig({
  resolve: {
    alias: {
      '@typo3/core/ajax/ajax-request.js': `${doubles}/ajax-request.js`,
    },
  },
  test: {
    environment: 'jsdom',
    include: ['Tests/JavaScript/**/*.test.js'],
    restoreMocks: true,
  },
})
