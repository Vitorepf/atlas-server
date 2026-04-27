import Fastify from 'fastify';
import cors from '@fastify/cors';
import multipart from '@fastify/multipart';
import pg from 'pg';
import 'dotenv/config';

const { Client } = pg;

const fastify = Fastify({
  logger: {
    transport: {
      target: 'pino-pretty',
      options: { colorize: true },
    },
  },
});

await fastify.register(cors, { origin: true });
await fastify.register(multipart, { limits: { fileSize: 50 * 1024 * 1024 } });

fastify.get('/health', async () => {
  return { status: 'ok', ts: new Date().toISOString(), service: 'atlas-server' };
});

fastify.get('/db-ping', async () => {
  const client = new Client({ connectionString: process.env.DATABASE_URL });
  await client.connect();
  const result = await client.query('SELECT now() as ts, version() as version');
  await client.end();
  return { status: 'ok', ...result.rows[0] };
});

const port = parseInt(process.env.PORT || '3737', 10);
try {
  await fastify.listen({ port, host: '0.0.0.0' });
  fastify.log.info('Atlas server running on port ' + port);
} catch (err) {
  fastify.log.error(err);
  process.exit(1);
}
