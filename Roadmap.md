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
1. Central de Notificações
2. Convites para Família
3. Compartilhamento de dados da Família
4. Módulo de Cartões de Crédito
5. Geração automática de Faturas
6. Integração das Faturas com a tela de Despesas
