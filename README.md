# Atlas Server

Backend + infraestrutura do Atlas: API Fastify + PostgreSQL + Docker Compose.

## Setup Rapido

```bash
cd ~/Develop/atlas-server
cp .env.example .env
docker compose up -d
curl http://localhost:3737/health
```

## Servicos

| Servico | Container | Porta |
|---------|-----------|-------|
| PostgreSQL 16 + pgvector | atlas-db | 5432 |
| Backend API | atlas-backend | 3737 |

## Comandos uteis

```bash
docker compose down          # para tudo
docker compose logs -f       # logs
docker compose exec db psql -U atlas -d atlas  # banco
docker compose up -d --build backend  # rebuild
```
