# arena-backend-rate-limit-window · seed

Cliente buggy enviou 4000 req/min e derrubou worker. Arm implementa
sliding-window rate limiting (60 req/min por user_id) com headers
`X-RateLimit-*` e `Retry-After` em 429.
