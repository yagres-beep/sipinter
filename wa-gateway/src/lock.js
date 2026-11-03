import { EventEmitter } from 'node:events';

/**
 * Lock sesi WhatsApp. Karena gateway kini berjalan satu-ke-satu di dalam
 * container SIPINTER sendiri (127.0.0.1:3001), lock langsung diperoleh tanpa
 * menahan koneksi idle di Supabase Session Pooler.
 */
export class SessionLock extends EventEmitter {
  constructor(pool, logger) {
    super();
    this.pool = pool;
    this.logger = logger;
    this.held = true;
    this.stopped = false;
  }

  async acquire() {
    if (this.stopped) {
      return false;
    }
    this.held = true;
    this.logger.info('Lock sesi WhatsApp siap');
    return true;
  }

  async release() {
    this.stopped = true;
    this.held = false;
  }
}
