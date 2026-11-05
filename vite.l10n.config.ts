/**
 * Vite configuration used by "make l10n-ignore" for the non-writing analysis
 * builds. Same as the regular configuration, but with an output directory
 * which is not the app root.
 */
import { defineConfig, mergeConfig } from 'vite';
import appConfig from './vite.config.ts';

export default defineConfig(async (env) => {
  const base = typeof appConfig === 'function' ? await appConfig(env) : await appConfig;
  return mergeConfig(base, { build: { outDir: 'build/l10n-analysis' } });
});
