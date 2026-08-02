# 📑 Escopo do Projeto: Mr. Bills

O **Mr. Bills** é um sistema pessoal de gerenciamento financeiro focado no controle de contas a pagar, com inteligência para recalcular datas de vencimento que coincidem com fins de semana e gerenciamento de recorrências.

---

## 🛠️ 1. Ambiente e Stack Tecnológica

* **Sistema Operacional:** Linux (Ubuntu via WSL 2) integrado ao Docker Desktop.
* **Backend:** PHP 8.5 / Laravel 13.
* **Frontend:** Livewire v3 (Volt - API Funcional) + Tailwind CSS + Flux UI.
* **Banco de Dados:** PostgreSQL 18 (Rodando isolado em container Docker via Laravel Sail).
* **IDE:** PHPStorm.

---

## 📐 2. Metodologia de Desenvolvimento

O projeto adota uma metodologia **incremental e focada em features isoladas**, baseada em um ciclo rígido de 7 passos. Uma nova funcionalidade só é iniciada após a validação completa e homologação visual da funcionalidade anterior.

### O Ciclo de Desenvolvimento Oficial:

1. **Migration:** Geração do arquivo de banco de dados.
2. **Schema:** Configuração detalhada das colunas e execução da migração.
3. **Model:** Criação da classe de representação de dados do Eloquent.
4. **Business Logic:** Implementação de regras de negócio, travas e hooks (como o cálculo via *Carbon*) no Model.
5. **Livewire Component:** Desenvolvimento da interface visual e lógica de manipulação de dados em arquivo único (.blade.php).
6. **Routes & Menu:** Registro de rotas e acoplamento no menu lateral do painel.
7. **Test & Validate:** Teste de mesa e homologação em navegador.

---

## 🏁 3. Fases Realizadas (Status do Projeto)

### [x] Fase 1: Infraestrutura e Setup Inicial

* Criação do ecossistema Laravel 13 com o Starter Kit do Breeze + Livewire Funcional.
* Acoplamento do Laravel Sail para provisionamento do ambiente Docker com PostgreSQL.
* Ajuste de conflito de portas com o Apache nativo do Ubuntu.

### [x] Fase 2: Estrutura Base de Contas & Listagem

* Modelagem do banco de dados para a entidade de contas (`bills`).
* Implementação da lógica de tratamento automático de finais de semana via Carbon (avançando vencimentos de Sábado/Domingo para a Segunda-feira subsequente).
* Criação da interface de listagem integrada ao layout nativo do painel administrativo.
* Ajuste e limpeza de rotas e navegação reativa via Flux UI (`wire:navigate`).

### [x] Fase 3: CRUD Completo de Contas (Cadastro, Edição, Exclusão e Recorrência)

* Componente `⚡create-bill` para cadastro de contas avulsas ou parceladas/recorrentes.
* `Bill::createRecurrent()` no Model: gera de uma só vez todas as parcelas de uma recorrência (uma linha por mês, via `addMonthsNoOverflow`), vinculadas por um `recurrence_group_id` (UUID) comum.
* Edição e exclusão de contas diretamente na listagem (`⚡list-bills`), via modais.
* Exclusão inteligente de recorrências: exclusão de **apenas a parcela atual** ou de **esta e todas as futuras** (`deleteOnlyThis` / `deleteThisAndFuture`), usando `siblings()` (relação `hasMany` por `recurrence_group_id`).
* Enum `BillStatus` (`Pendente`, `Pago`, `Vencido`, `Renegociado`). O status "Vencido" **não é persistido** — é derivado (`getEffectiveStatusAttribute`) a partir de `status = Pendente` + `actual_due_date` no passado, para permitir marcar manualmente uma conta como "Pago" ou "Renegociado" sem perder o rastro do vencimento original.
* Filtros de listagem por período (geral / mês atual / mês específico / intervalo), status e categoria.

### [x] Fase 4: Categorias (Despesas e Entradas)

* Duas entidades de categoria separadas: `categories` (despesas/contas) e `income_categories` (entradas/carteira) — não compartilham tabela.
* Componente único `⚡create-category` com seletor de tipo (`despesa` | `entrada`) que decide em qual tabela gravar.
* Listagens (`⚡list-categories`, `⚡list-income-categories`) com edição, exclusão e nome único por usuário (`Rule::unique(...)->where('user_id', ...)`).
* Ao excluir uma categoria, as contas/entradas vinculadas ficam sem categoria (`nullOnDelete` na migration) — a UI avisa quantos registros serão afetados antes de confirmar.
* Scope `withTotals()` (em `Category` e `IncomeCategory`) que usa `withSum` para trazer `total_geral` e `total_mes_atual` sem N+1.
* Página `categorias.blade.php` unifica cadastro + as duas listagens.

### [x] Fase 5: Carteira (Entradas/Receitas)

* Nova entidade `incomes`, espelhando o padrão de `bills`: mesmo esquema de recorrência (`is_recurrent`, `total_installments`, `current_installments`, `recurrence_group_id`) e mesma lógica de `createRecurrent()` / `siblings()` / exclusão parcial vs. em cadeia.
* Diferença em relação a `Bill`: `Income` não tem conceito de "vencimento útil" (sem `due_date`/`actual_due_date` nem status) — apenas uma `date` de lançamento.
* Componentes `⚡create-income` e `⚡list-income`, página `carteira.blade.php`.

### [x] Fase 6: Dashboard Consolidado

* Componente `⚡dashboard-summary` com KPIs do mês atual: Total a Pagar (contas pendentes), Carteira (entradas) e Saldo do Mês (entradas − a pagar).
* Ranking de maiores despesas do mês por categoria (reaproveitando `withSum` sobre `bills`).
* Lista de "Contas Próximas" (pendentes vencendo nos próximos 3 dias).
* Gráfico trimestral (Entradas vs. Saídas dos últimos 3 meses) via Chart.js, carregado dinamicamente por CDN e renderizado com Alpine (`x-data`/`x-init`), plugado ao componente Livewire.

### [x] Fase 7: Navegação Completa do Painel

* Menu lateral (`layouts/app/sidebar.blade.php`) com as 4 seções ativas: Dashboard, Contas, Categorias e Carteira, todas com navegação reativa (`wire:navigate`) e destaque do item atual (`request()->routeIs(...)`).

---

## 📂 4. Arquivos Criados e Configurados

Abaixo estão listados os arquivos centrais do projeto até o momento:

| Caminho do Arquivo | Descrição |
| --- | --- |
| `.env` / `compose.yaml` | Ambiente Docker (Laravel Sail) e conexão com o container PostgreSQL 18 (`pgsql`, banco `mrbills`). |
| `database/migrations/*_create_categories_table.php` | Tabela de categorias de despesa. |
| `database/migrations/*_create_bills_table.php` | Contas a pagar: valor, vencimento original/real, status, recorrência, `category_id`. |
| `database/migrations/*_create_income_categories_table.php` | Tabela de categorias de entrada. |
| `database/migrations/*_create_incomes_table.php` | Entradas/receitas: valor, data, recorrência, `income_category_id`. |
| `app/Enums/BillStatus.php` | Enum de status de conta, com labels e classes de badge (Tailwind). |
| `app/Models/Bill.php` | Regra de vencimento útil (`static::saving`), recorrência (`createRecurrent`, `siblings`), status efetivo. |
| `app/Models/Category.php` | Relação com `bills` e scope `withTotals()`. |
| `app/Models/Income.php` | Espelha `Bill` para entradas (sem lógica de dia útil/status). |
| `app/Models/IncomeCategory.php` | Relação com `incomes` e scope `withTotals()`. |
| `resources/views/{contas,categorias,carteira}.blade.php` | Views estruturais que unificam cadastro + listagem(ns) por seção, dentro do layout `x-layouts::app`. |
| `resources/views/dashboard.blade.php` | Página do dashboard, hospeda o `⚡dashboard-summary`. |
| `resources/views/components/⚡create-bill.blade.php`, `⚡list-bills.blade.php` | Cadastro e listagem/gestão de contas. |
| `resources/views/components/⚡create-category.blade.php`, `⚡list-categories.blade.php`, `⚡list-income-categories.blade.php` | Cadastro (tipo despesa/entrada) e listagens de categorias. |
| `resources/views/components/⚡create-income.blade.php`, `⚡list-income.blade.php` | Cadastro e listagem/gestão de entradas. |
| `resources/views/components/⚡dashboard-summary.blade.php` | KPIs, gráfico trimestral e contas próximas do vencimento. |
| `resources/views/layouts/app/sidebar.blade.php` | Menu lateral com as 4 seções do painel. |
| `routes/web.php` | Rotas `dashboard`, `bills.index`, `categories.index`, `wallet.index`, todas sob middleware `auth`+`verified`. |

---

## 🎯 Próximos Passos (Próxima Feature)

* Todas as fases de CRUD básico (Contas, Categorias, Carteira) e o Dashboard consolidado estão implementadas e navegáveis.
* **Ainda não iniciado / pendente de definição:** próxima feature a ser escolhida — candidatos naturais incluem relatórios/exportação, edição em massa de recorrências (hoje só "esta" ou "esta e futuras"), e testes automatizados dedicados às regras de negócio de `Bill`/`Income` (o `phpunit.xml`/`tests/` atual cobre principalmente autenticação e configurações, sem testes de Feature para contas, categorias ou carteira).
