/**
 * Adaptador D1 sobre better-sqlite3.
 *
 * Expone la misma superficie que usa el Worker (`prepare`, `bind`,
 * `first`, `all`, `run`, `batch`) para no tocar SQL ni dominio.
 */

import Database from "better-sqlite3";

function asParams(params) {
  return params.map((value) => (value === undefined ? null : value));
}

class D1Statement {
  constructor(sqlite, sql, params = []) {
    this.sqlite = sqlite;
    this.sql = sql;
    this.params = params;
  }

  bind(...params) {
    return new D1Statement(this.sqlite, this.sql, params);
  }

  _stmt() {
    return this.sqlite.prepare(this.sql);
  }

  async first() {
    const row = this._stmt().get(...asParams(this.params));
    return row ?? null;
  }

  async all() {
    const rows = this._stmt().all(...asParams(this.params));
    return { success: true, results: rows };
  }

  async run() {
    const info = this._stmt().run(...asParams(this.params));
    return {
      success: true,
      meta: {
        changes: info.changes,
        last_row_id: Number(info.lastInsertRowid),
      },
    };
  }
}

export function openD1(filePath) {
  const sqlite = new Database(filePath);
  sqlite.pragma("journal_mode = WAL");
  sqlite.pragma("foreign_keys = ON");

  return {
    sqlite,
    prepare(sql) {
      return new D1Statement(sqlite, sql);
    },
    async batch(statements) {
      const run = sqlite.transaction((items) => {
        const out = [];
        for (const item of items) {
          const stmt = item._stmt();
          const params = asParams(item.params);
          // D1 batch sirve tanto SELECT (getData) como CREATE/INSERT/UPDATE.
          if (stmt.reader) {
            out.push({ success: true, results: stmt.all(...params) });
          } else {
            const info = stmt.run(...params);
            out.push({
              success: true,
              results: [],
              meta: {
                changes: info.changes,
                last_row_id: Number(info.lastInsertRowid),
              },
            });
          }
        }
        return out;
      });
      return run(statements);
    },
  };
}
