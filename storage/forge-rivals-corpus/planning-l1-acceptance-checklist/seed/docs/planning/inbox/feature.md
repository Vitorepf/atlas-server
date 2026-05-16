# Export CSV para tarefas atrasadas

Usuarios de operacoes precisam exportar uma lista de tarefas atrasadas para revisar cobrancas em reunioes semanais.

Regras de negocio:
- A exportacao deve incluir apenas tarefas abertas com prazo vencido.
- O operador pode filtrar por dono, mas o filtro nao e obrigatorio.
- O arquivo precisa mostrar o total exportado e o intervalo de datas usado.
- Emails completos nao podem aparecer no arquivo; somente o dominio pode ficar visivel.
- Quando nao houver tarefas, o operador precisa receber uma mensagem clara em vez de um arquivo vazio.
