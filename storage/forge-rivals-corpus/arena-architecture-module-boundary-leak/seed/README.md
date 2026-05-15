# arena-architecture-module-boundary-leak · seed

Model Eloquent vaza do módulo Captures para Inbox. Coluna nova no DB aparece
automaticamente no contrato externo. Arm introduz CaptureDTO, Inbox usa só
o DTO, test arquitetural valida boundary.
