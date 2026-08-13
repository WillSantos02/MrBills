# Roadmap de Novas Funcionalidades

> Este arquivo é a fonte de verdade sobre o status do projeto e o que vem a seguir. O histórico das fases 1–7
> (CRUD de Contas, Categorias, Carteira e Dashboard, já entregues) fica em `projeto_mrbills.md`; a partir daqui,
> tanto débito técnico resolvido quanto novas features entram aqui, para não espalhar essa informação em vários
> arquivos.

---

## Concluído — Cobertura de Testes e Débito Técnico de Tipagem

* **Testes automatizados de regras de negócio**: `tests/Feature/{Bill,Income,Category,IncomeCategory}Test.php`
  e `tests/Feature/Livewire/ListBillsFilterTest.php` cobrem o rollover de vencimento em fim de semana, o
  status "Vencido" derivado (nunca persistido), a geração de recorrência/parcelamento (`createRecurrent`),
  `siblings()` e os totais de `scopeWithTotals()`. Antes só havia testes de autenticação/configurações do
  starter kit. Foram criadas factories para `Bill`, `Income`, `Category` e `IncomeCategory` (só `User` tinha).
* **`composer types:check` (PHPStan nível 7) zerado**: eram 19 erros pré-existentes, resolvidos com PHPDoc de
  generics nas relações (`BelongsTo`/`HasMany`) e nos `scopeWithTotals()`, mais dois bugs reais corrigidos em
  `Bill.php` — uma checagem sempre-verdadeira (`due_date`/`actual_due_date` são `NOT NULL`, então os guards
  eram código morto) e uma atribuição de `string` a um atributo tipado `CarbonImmutable` (o app usa
  `Date::use(CarbonImmutable::class)` globalmente).

Essa base deixa o projeto pronto para a Central de Notificações (próximo item da ordem recomendada abaixo),
que depende de `actual_due_date` estar correto.

---

## Concluído — Traefik (reverse proxy + HTTPS local + preparo para deploy)

* **Container da aplicação renomeado**: de `laravel.test` (padrão do Sail) para `core`, via `APP_SERVICE=core`
  no `.env` — `vendor/bin/sail` respeita essa variável, então os comandos `sail ...` continuam funcionando sem
  mudança de uso.
* **Sem porta HTTP exposta diretamente**: `core` não publica mais porta no host; o Traefik assume a borda
  (80/443) e roteia por domínio (`APP_DOMAIN`, label `traefik.http.routers.core.rule=Host(...)`) via Docker
  provider.
* **HTTPS local com domínio próprio**: `https://mrbills.localhost` (sufixo `.localhost` resolve para
  `127.0.0.1` sem precisar editar `/etc/hosts`), com certificado confiável gerado via mkcert
  (`docker/traefik/generate-dev-certs.sh`), carregado só em dev via `compose.override.yaml` (auto-carregado
  pelo `docker compose`/`sail`).
* **Preparado para deploy real em VPS**: `compose.prod.yaml` é um overlay explícito (`docker compose -f
  compose.yaml -f compose.prod.yaml up -d`) que troca o certificado para o resolver `letsencrypt` (desafio
  HTTP-01, config em `docker/traefik/traefik.yml`) e adiciona `restart: unless-stopped` aos serviços. Requer
  `APP_DOMAIN` com o domínio público real e `ACME_EMAIL` preenchido no `.env` do servidor.

Detalhes de uso em `CLAUDE.md` (seções "Local environment (Traefik + HTTPS)" e "Production deploy").

**Correções pós-implantação (404 no primeiro boot)**:
* `traefik:v3.3`/`v3.5` fixam o cliente Docker na API v1.24; Docker Engine 29+ passou a exigir mínimo v1.40 e
  rejeita essas chamadas com 400, então o provider Docker do Traefik nunca descobria o container `core` e todo
  request caía no 404 padrão. Corrigido subindo para `traefik:v3.6` (primeira versão com auto-negociação real
  da API do Docker — [traefik/traefik#12253](https://github.com/traefik/traefik/issues/12253)).
* `bootstrap/app.php` não confiava em proxies reversos, então o Laravel não via o `X-Forwarded-Proto: https`
  enviado pelo Traefik e gerava URLs de asset como `http://` mesmo servindo por HTTPS. Corrigido com
  `$middleware->trustProxies(at: '*', ...)` — seguro aqui porque o Traefik é o único ponto de entrada da rede
  `sail`, os demais serviços não são expostos publicamente.
* No Docker Desktop com WSL2, montar um diretório inteiro (`dynamic/`) e depois um arquivo dentro dele em
  outro compose file (mount aninhado) fez o conteúdo do arquivo vazar de volta pro host, no diretório
  compartilhado com produção. Corrigido montando `docker/traefik/dynamic.dev/` como diretório único (sem
  aninhamento) só em `compose.override.yaml`; não há mais diretório `dynamic/` compartilhado entre dev/prod.

---

## Concluído — Central de Notificações (contas a vencer) + Processamento Assíncrono (RabbitMQ)

* **RabbitMQ como fila real da aplicação**: novo serviço `rabbitmq` (`compose.yaml`, sem porta pública —
  só acessível pela rede `sail`; AMQP + UI de management em `localhost:5672`/`15672` só em dev, via
  `compose.override.yaml`), driver `vladimir-yuldashev/laravel-queue-rabbitmq` (`config/queue.php`),
  `QUEUE_CONNECTION=rabbitmq` no `.env`. Em produção, `worker` (`php artisan queue:work rabbitmq`) e
  `scheduler` (`php artisan schedule:work`) são serviços dedicados com `restart: unless-stopped`
  (`compose.prod.yaml`); em dev, os dois já vêm de graça no `composer dev` (o scheduler foi adicionado à
  lista via `DevCommands::artisan('schedule:work', ...)` em `AppServiceProvider::boot()`).
* **Notificações de "conta a vencer"** (primeiro caso de uso real da fila): usa o sistema nativo de
  notificações do Laravel (`User` já tinha `Notifiable`), não um model customizado. Sino + badge em
  `⚡notification-center.blade.php`, incluído duas vezes em `layouts/app/sidebar.blade.php`
  (desktop/mobile). `App\Notifications\BillDueSoonNotification` é `ShouldQueue` — passa pelo RabbitMQ de
  verdade. Comando diário `notifications:send-bill-due-soon` (agendado 08:00 em `bootstrap/app.php`)
  notifica contas `Pendente` com vencimento em 0–3 dias; dedupe é feito por uma coluna
  (`bills.last_due_soon_notified_at`), não consultando a tabela `notifications`, já que a notificação é
  assíncrona e essa linha pode não existir ainda no momento em que o comando termina. "Marcar como pago"
  atualiza a conta e marca a notificação como lida; "Lembrar depois" só marca como lida — no dia seguinte
  o comando roda de novo e, como a coluna de dedupe ficou desatualizada, notifica de novo.
* **Escopo**: só a parte de "contas a vencer" da Feature 3 abaixo. "Notificações de Convite" continuam
  pendentes — dependem da Feature 2 (Família) ainda não implementada; a estrutura do sino/badge já está
  pronta para reutilização quando esse convite existir.

---

## Concluído — Convites para Família (item 1 da Feature 2) + Notificações de Convite

* **Vínculo de família**: coluna `family_owner_id` em `users` (FK auto-referenciada, `nullable`,
  `nullOnDelete`) — família é o dono mais todos os `users` com `family_owner_id` apontando para ele;
  `NULL` significa "não é membro de ninguém" (dono ou usuário solo), sem flag booleana separada de "é
  dono". Tabela `family_invites` (`owner_id`, `invited_user_id`, `unique(invited_user_id)`) guarda **só**
  convites pendentes — aceitar ou recusar sempre apagam a linha, não há coluna de status.
* **Model `FamilyInvite`** e relations novas em `User` (`familyOwner()`, `familyMembers()`,
  `sentFamilyInvites()`, `receivedFamilyInvite()`, `remainingFamilySlots()`) — as primeiras relations do
  model `User`, que antes não tinha nenhuma.
* **Fluxo de convite** (`⚡send-family-invite.blade.php`, tela `/familia`): e-mail precisa pertencer a um
  usuário já cadastrado (sem envio de e-mail real — usa só o sino de notificações existente, que exige
  conta autenticada); valida autoconvite, convidado já pertencer a outra família (como dono ou membro) e
  convite duplicado. Envio roda em `DB::transaction()` com `lockForUpdate()` no dono para serializar
  concorrência, e uma violação da constraint `unique(invited_user_id)` é convertida em erro de validação
  amigável.
* **Aceite/recusa** (`⚡notification-center.blade.php`, ações `acceptInvite`/`rejectInvite`/`dismiss`):
  diferente de `markBillAsPaid`/`remindLater` (que só marcam como lida), essas ações apagam a notificação
  de verdade, seguindo o texto do roadmap ("a notificação é removida"). Ao aceitar, os convites que o
  próprio usuário havia enviado como dono-em-espera são cancelados automaticamente (só pode pertencer a
  uma família por vez). Notificações `FamilyInviteNotification` e `FamilyInviteAcceptedNotification` não
  são `ShouldQueue` (diferente de `BillDueSoonNotification`) — disparadas direto de ação síncrona do
  usuário, sem o cenário de dedupe de job agendado.
* **Tela "Família"** (`⚡family-members.blade.php`): dono vê membros aceitos e convites pendentes com
  botão de cancelar (adição além do texto literal do roadmap, para não deixar convite errado preso); membro
  vê o dono e os demais integrantes, somente leitura. Item novo no menu lateral, grupo "Conta".
* **Bug real corrigido no processo**: `family_owner_id` não estava no `#[Fillable(...)]` de `User`, então
  tanto o `update()` no aceite quanto os `factory()->create([...])` dos testes descartavam o campo
  silenciosamente por proteção de mass assignment.
* **Fora de escopo**: compartilhamento de Contas/Receitas/Categorias entre membros (próximo item da ordem
  recomendada) e "sair da família" para um membro aceito (não previsto no roadmap).

---

## Feature 1 — Cartões de Crédito

### Objetivo

Implementar um módulo de gerenciamento de cartões de crédito, permitindo ao usuário cadastrar cartões, registrar compras e gerar automaticamente as faturas que serão pagas através da área de **Despesas**.

---

## Menu Lateral

Adicionar um novo item:

* **Cartões**

---

## Tela de Cartões

### Cadastro de Cartão

O usuário poderá cadastrar um ou mais cartões de crédito.

Campos obrigatórios:

* Nome do cartão
* Dia de fechamento da fatura
* Dia de vencimento da fatura

---

### Registro de Compras

Cada cartão permitirá registrar compras.

Campos sugeridos:

* Descrição
* Valor
* Data da compra
* Categoria (opcional)
* Observação (opcional)

---

## Regra de Geração da Fatura

O sistema deverá identificar automaticamente a qual fatura a compra pertence.

### Regras

**Compra realizada antes (ou no dia) do fechamento**

A compra será adicionada à fatura atual.

Exemplo:

* Fechamento: dia 10
* Compra: dia 08

Resultado:

A compra pertence à fatura com vencimento do mês corrente.

---

**Compra realizada após o fechamento**

A compra será adicionada à próxima fatura.

Exemplo:

* Fechamento: dia 10
* Compra: dia 15

Resultado:

A compra pertence à fatura do mês seguinte.

---

## Geração Automática da Fatura

Sempre que existir pelo menos uma compra para determinado período, o sistema deverá criar automaticamente uma fatura.

Essa fatura deverá aparecer na tela de **Despesas**, comportando-se exatamente como qualquer outra despesa do sistema.

A cada nova compra adicionada ao cartão, o valor total da respectiva fatura deverá ser atualizado automaticamente.

---

## Regras Gerais

* Um usuário pode possuir vários cartões.
* Cada compra pertence obrigatoriamente a uma única fatura.
* O valor da fatura corresponde à soma de todas as compras pertencentes àquele período.
* Ao marcar a fatura como paga, apenas o status da despesa é alterado. As compras continuam vinculadas ao cartão para fins de histórico.

---

# Feature 2 — Conta Conjunta (Família)

> **Status**: fluxo de convite/aceite/recusa e vínculo de família implementados — ver seção "Concluído —
> Convites para Família" acima. Falta o compartilhamento de dados (Despesas/Receitas/Cartões/Categorias)
> entre os membros, descrito abaixo.

### Objetivo

Permitir que um usuário compartilhe sua conta com até **dois usuários adicionais**, formando uma "Família".

Todos os integrantes terão acesso às mesmas informações e poderão colaborar na gestão financeira.

---

## Regras

O proprietário da conta poderá convidar usuários através do endereço de e-mail.

Limites:

* Até 2 membros convidados.
* O proprietário também faz parte da família.

Após o aceite do convite, todos os membros poderão visualizar e editar:

* Despesas
* Receitas
* Cartões
* Categorias

Todos os dados serão compartilhados entre os integrantes da família.

---

## Convite

Fluxo:

1. Proprietário informa o e-mail do usuário.
2. O sistema cria um convite pendente.
3. O usuário convidado recebe uma notificação.
4. O usuário poderá aceitar ou recusar.

### Aceite

Após aceitar:

* o usuário passa a fazer parte da família;
* imediatamente passa a visualizar todos os dados compartilhados;
* o proprietário recebe uma notificação informando que o convite foi aceito.

### Recusa

Caso o usuário recuse:

* o convite é removido;
* nenhuma notificação é enviada ao proprietário.

---

# Feature 3 — Central de Notificações

> **Status**: sino/badge, "Notificações de Contas a Vencer" e "Notificações de Convite" implementados —
> ver seções "Concluído" acima. Feature 3 está completa.

## Objetivo

Implementar uma central de notificações acessível através de um ícone de sino na barra superior da aplicação.

O sino deverá exibir um badge indicando a quantidade de notificações pendentes.

Essa funcionalidade será utilizada tanto para notificações do sistema quanto para convites da funcionalidade de Família.

---

## Notificações de Contas a Vencer

O sistema deverá gerar notificações automaticamente para despesas que estejam próximas do vencimento.

### Regra

As notificações deverão ser enviadas **3 dias antes** da data de vencimento.

Mensagem:

> A conta **{Nome da Conta}** vencerá em **{X} dias**.

A notificação possuirá dois botões:

### Marcar como pago

Ao selecionar esta opção:

* a despesa será marcada como paga;
* a notificação será removida.

### Lembrar depois

Ao selecionar esta opção:

* a notificação será ocultada temporariamente;
* uma nova notificação será enviada no dia seguinte, caso a despesa continue pendente.

---

## Notificações de Convite

Mensagem:

> Você foi convidado(a) para fazer parte da família de **{Nome do Usuário}**.

A notificação deverá conter dois botões:

* Aceitar
* Recusar

### Aceitar

* adiciona o usuário à família;
* remove a notificação;
* envia uma notificação ao proprietário informando que o convite foi aceito.

### Recusar

* remove a notificação;
* encerra o convite;
* nenhuma notificação é enviada ao proprietário.

---

# Ordem Recomendada de Desenvolvimento

0. ~~Cobertura de testes automatizados e débito de tipagem (PHPStan)~~ — concluído, ver seção acima.
0. ~~Traefik (reverse proxy, HTTPS local, preparo para deploy)~~ — concluído, ver seção acima.
0. ~~RabbitMQ (fila real) + Central de Notificações — parte "contas a vencer"~~ — concluído, ver seção
   acima. Falta só "Notificações de Convite", que depende da Feature 2.
0. ~~Convites para Família (inclui "Notificações de Convite", completando a Feature 3)~~ — concluído, ver
   seção acima.
1. Compartilhamento de dados da Família
2. Módulo de Cartões de Crédito
3. Geração automática de Faturas
4. Integração das Faturas com a tela de Despesas
