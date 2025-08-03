import env from '#start/env'
import app from '@adonisjs/core/services/app'
import { defineConfig, targets } from '@adonisjs/core/logger'

const loggerConfig = defineConfig({
  default: 'app',

  /**
   * The loggers object can be used to define multiple loggers.
   * By default, we configure only one logger (named "app").
   */
  loggers: {
    error: {
      enabled: true,
      name: 'error',
      level: 'error',
      transport: {
        targets: targets()
          .push({
            target: 'pino-roll',
            level: 'error',
            options: {
              file: './logs/error.log',
              // frequency: 'daily',
              size: '1m',
              mkdir: true,
            },
          })
          .toArray(),
      },
      redact: {
        paths: ['password', '*.password', 'images.*', '*.img', '*.images'],
      },
    },
    app: {
      enabled: true,
      name: env.get('APP_NAME'),
      level: env.get('LOG_LEVEL'),
      transport: {
        targets: targets()
          // .pushIf(!app.inProduction, targets.pretty())
          .push({
            target: 'pino-roll',
            level: 'info',
            options: {
              file: './logs/app.log',
              // frequency: 'daily',
              size: '1m',
              mkdir: true,
            },
          })
          .toArray(),
      },
      redact: {
        paths: ['password', '*.password', 'images.*', '*.img', '*.images'],
      },
    },
  },
})

export default loggerConfig

/**
 * Inferring types for the list of loggers you have configured
 * in your application.
 */
declare module '@adonisjs/core/types' {
  export interface LoggersList extends InferLoggers<typeof loggerConfig> {}
}
