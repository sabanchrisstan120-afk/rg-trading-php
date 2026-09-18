const mysql = require('mysql2/promise');
require('dotenv').config();

const isProduction = process.env.NODE_ENV === 'production';

const dbConfig = {
  host: process.env.DB_HOST || (isProduction ? undefined : 'localhost'),
  port: parseInt(process.env.DB_PORT || '3306', 10),
  database: process.env.DB_NAME || (isProduction ? undefined : 'rg_trading'),
  user: process.env.DB_USER || (isProduction ? undefined : 'root'),
  password: process.env.DB_PASSWORD ?? '',
};

if (isProduction && (!dbConfig.host || !dbConfig.database || !dbConfig.user)) {
  throw new Error('Missing required production database configuration: set DB_HOST, DB_NAME, and DB_USER before starting in production.');
}

const pool = mysql.createPool({
  host: dbConfig.host,
  port: dbConfig.port,
  database: dbConfig.database,
  user: dbConfig.user,
  password: dbConfig.password,
  waitForConnections: true,
  connectionLimit:    50,
  queueLimit:         100,
  timezone:           '+00:00',
  decimalNumbers:     true,
  namedPlaceholders:  true,
});

(async () => {
  try {
    const conn = await pool.getConnection();
    console.log('✅ MySQL connected');
    conn.release();
  } catch (err) {
    console.error('❌ MySQL connection failed:', err.message);
    process.exit(1);
  }
})();

const query = async (text, params = []) => {
  let conn;
  try {
    const start = Date.now();
    const sql = text.replace(/\$\d+/g, '?');
    const [rows] = await pool.execute(sql, params);
    if (process.env.NODE_ENV === 'development') {
      console.log('📦 Query', { sql: sql.substring(0, 80), ms: Date.now() - start });
    }
    return { rows: Array.isArray(rows) ? rows : [rows], rowCount: rows.affectedRows ?? rows.length };
  } catch (err) {
    console.error('Query error:', err.message, { params });
    throw err;
  }
};

const getClient = async () => {
  let conn;
  try {
    conn = await pool.getConnection();
    const originalQuery = conn.query.bind(conn);
    conn.query = async (text, params = []) => {
      try {
        const sql = text.replace(/\$\d+/g, '?');
        const [rows] = await originalQuery(sql, params);
        return { rows: Array.isArray(rows) ? rows : [rows], rowCount: rows.affectedRows ?? rows.length };
      } catch (err) {
        console.error('Client query error:', err.message);
        throw err;
      }
    };
    return conn;
  } catch (err) {
    console.error('Failed to acquire connection:', err.message);
    throw err;
  }
};

module.exports = { query, getClient, pool };
