# Genealogia — guia para agentes de IA

Leia este arquivo inteiro antes de mexer em qualquer coisa. Ele vale para a sessão principal e
para todos os agentes especializados; o detalhe de cada área fica no arquivo do agente dono dela,
em `.claude/agents/`.

## 1. O que é

Aplicação web de árvores genealógicas com vários usuários. Cada usuário cria suas árvores, monta
cada uma num editor visual e convida outras pessoas com um papel (observador, editor ou
administrador).

**Estado atual (09/10/2026): reescrita do zero, no começo.** O projeto era um site em PHP sem
framework; ele foi movido para `legado/` e serve só de referência. O projeto Next.js está gerado e
passa em tipos, lint, testes e build. Já existem: as datas aproximadas e os catálogos em
`src/dominio/`, o esquema em `src/db/esquema.ts` e a primeira migração em `drizzle/`, que
**nunca foi aplicada** (não há banco configurado). Ainda não existem: conexão com o banco,
validações de vínculo, `src/servicos/`, `src/acesso/` e qualquer tela além de uma página
provisória. Os navegadores do Playwright não estão instalados. Das pastas da seção 3, as que não
existem são alvo, não fato. Atualize este parágrafo quando isso mudar.

- Dados fictícios por enquanto; o banco novo começa vazio.
- Não reintroduza nomes de família, textos temáticos nem o tema visual antigo.
- Identidade visual ainda indefinida: use um visual neutro e não invente tema sem alinhar antes.

## 2. Stack

| Camada | Escolha |
|---|---|
| Linguagem | TypeScript em modo estrito, de ponta a ponta |
| Aplicação | Next.js (App Router) com React |
| Editor da árvore | React Flow; d3 apenas para o leque de ancestrais |
| Banco | PostgreSQL com Drizzle (esquema em código, migrações versionadas) |
| Login e sessões | Better Auth |
| Testes | Vitest (regras e serviços) e Playwright (telas) |

Versões instaladas: Next.js 16.4, React 19.3, Tailwind 4, Zod 4, Vitest 5, Node 24. Várias são
mais novas do que o conhecimento de um modelo de IA: **antes de usar uma API de biblioteca, leia a
documentação ou os tipos em `node_modules/`** (para o Next.js, `node_modules/next/dist/docs/`).

Idioma: nomes de domínio, comentários, mensagens e tabelas em **português**. Termos próprios do
framework ficam como são (`page.tsx`, `layout.tsx`, `use client`).

## 3. Estrutura e donos

Cada pasta tem **um único agente dono**, o único que escreve nela.

| Pasta | Conteúdo | Dono |
|---|---|---|
| `src/dominio/` | Regras puras, sem banco e sem React: datas aproximadas, validação de vínculos, filiação principal | `dominio-dados` |
| `src/db/`, `drizzle/` | Esquema, conexão, consultas e migrações | `dominio-dados` |
| `src/servicos/` | Casos de uso: confere permissão, valida, grava em transação, registra auditoria | `dominio-dados` |
| `src/acesso/` | Login, sessão, papéis e a função `pode()` | `acesso` |
| `src/app/` | Rotas, páginas, layouts e ações de servidor (finas: só chamam serviços) | `interface` |
| `src/componentes/` | Componentes React reutilizáveis | `interface` |
| `src/arvore/` | Editor e visões da árvore | `interface` |
| `testes/e2e/` | Testes de tela | `interface` |
| `legado/` | Código PHP antigo, **somente leitura** | ninguém |

Testes de unidade ficam ao lado do arquivo testado (`datas.ts` e `datas.test.ts`) e pertencem ao
dono da pasta.

Sentido das dependências, que não pode ser invertido:

```
app / componentes / arvore  ->  servicos  ->  dominio
                                    |->  acesso
                                    |->  db
```

A interface nunca importa `src/db/` nem consulta o banco direto. `src/dominio/` não importa nada
do projeto; `src/db/` e `src/acesso/` podem importar dele (os catálogos, como papéis e tipos de
vínculo, têm fonte única em `src/dominio/catalogos.ts`).

## 4. Como a equipe trabalha

A sessão principal é a **coordenadora**: entende o pedido, pergunta ao `consultor` o que precisa
saber, divide o trabalho por dono, delega e junta os resultados. Ela não edita código de área.

| Agente | Faz | Escreve código? |
|---|---|---|
| `consultor` | Responde "onde fica" e "como funciona", inclusive sobre o legado | não |
| `dominio-dados` | Esquema, migrações, regras genealógicas e serviços | sim |
| `acesso` | Login, cadastro, papéis e autorização | sim |
| `interface` | Telas, editor e visões | sim |
| `verificador` | Roda as verificações e revisa a alteração | não |

Regras de coordenação:

- Mudança que cruza áreas é dividida: cada dono altera a sua parte, na ordem das dependências
  (domínio e acesso antes da interface).
- Quem precisa de algo de outra área **pede ao dono** pela coordenadora, em vez de editar.
- Toda alteração termina com o `verificador`. Só depois dele a tarefa é dada por pronta.
- A coordenadora edita apenas `AGENTS.md`, `.claude/` e arquivos de configuração da raiz.

## 5. Modelo de dados (alvo)

- **`user`**, **`session`**, **`account`**, **`verification`** — tabelas do Better Auth, com os
  nomes padrão dele (singular, em inglês, id em texto). Não renomeie.
- **`arvores`** — nome, descrição, `criado_por`.
- **`membros_arvore`** — `arvore_id`, `usuario_id`, `papel` (`observador`, `editor`,
  `administrador`). Quem cria a árvore entra como administrador.
- **`pessoas`**, **`filiacoes`**, **`unioes`**, **`eventos`**, **`documentos`** — mesmo desenho do
  legado (seção 4 de `legado/AGENTS.md`), mais `arvore_id` e `criado_por` em todas.
- **`auditoria`** — quem fez o quê, em qual árvore e quando.

Regras herdadas do legado, que continuam valendo:

- Vínculos ficam em tabelas próprias; não existe coluna "pai" ou "cônjuge" em `pessoas`.
- Uma pessoa pode ter mais de uma filiação; a principal é a biológica, depois a de menor id.
- União é gravada normalizada (`pessoa1_id` < `pessoa2_id`) e não se repete.
- Pai não pode ter sexo `F` nem mãe `M`; vínculo não cria ciclo; filho não nasce antes dos pais.
- Data genealógica ocupa quatro colunas (data, qualificador, precisão, fim da faixa) e é
  formatada num único lugar.

Regra nova: **todo vínculo liga registros da mesma árvore**, e toda consulta filtra por
`arvore_id`. O banco não garante isso sozinho: é obrigação dos serviços.

Outras decisões do esquema: ids inteiros sequenciais nas tabelas de genealogia; `criado_por`
obrigatório e com exclusão restrita (usuário com registros não pode ser apagado); datas gravadas
e lidas como texto `AAAA-MM-DD`, nunca como `Date`.

## 6. Permissões

| Ação | Observador | Editor | Administrador |
|---|---|---|---|
| Ver a árvore | sim | sim | sim |
| Criar registros | não | sim | sim |
| Remover o que criou | não | sim | sim |
| Remover o que outros criaram | não | não | sim |
| Gerir participantes e a árvore | não | não | sim |

Toda leitura e toda gravação passa por `pode(usuario, acao, alvo)` em `src/acesso/`, chamada
dentro do serviço. Nenhuma página decide permissão por conta própria.

**Em aberto:** se o editor pode *alterar* registros criados por outros. Até o dono do projeto
decidir, trate como não permitido.

## 7. Padrões

- TypeScript estrito; sem `any` e sem `// @ts-ignore`.
- Erro de regra de negócio é **valor devolvido**, não exceção: os serviços devolvem
  `{ ok: true, ... }` ou `{ ok: false, erros: string[] }`, com mensagens em português.
- Serviço que grava: confere permissão, valida, grava em transação e registra auditoria.
- Entrada vinda de fora (formulário, URL) é validada com esquema antes de chegar ao serviço.
- Credenciais só em `.env`; o modelo versionado é `.env.exemplo`.
- Mudança de banco é sempre migração gerada pelo Drizzle, nunca alteração manual.
- Comente o porquê, não o óbvio.

## 8. Verificação

| Comando | Faz |
|---|---|
| `npm run dev` | Sobe a aplicação em `localhost:3000` |
| `npm run verificar` | Tipos, lint e testes de unidade, nessa ordem |
| `npm run e2e` | Testes de tela (exige `npx playwright install` uma vez) |
| `npm run db:gerar` | Gera a migração a partir de `src/db/esquema.ts` (não precisa de banco) |
| `npm run db:migrar` | Aplica as migrações (exige `DATABASE_URL` no `.env`) |

No PowerShell desta máquina o Node pode não estar no `PATH` da sessão; se `npm` não for
encontrado, use `C:\Program Files\nodejs\npm.cmd`.

Regra nova de domínio ou de permissão vem com teste. Diga com clareza o que foi verificado e o que
não foi.

## 9. Git

- Branch `main`, remoto em `github.com/underscores027/genealogia` (público). Fim de linha LF.
- Nunca versionar `.env`, arquivos enviados por usuários, despejos de banco com dados reais ou
  `legado/includes/config.php`.
- Commit e push só quando o dono do projeto pedir.

<!-- BEGIN:nextjs-agent-rules -->

## This is NOT the Next.js you know

This version has breaking changes — APIs, conventions, and file structure may all differ from your training data. Read the relevant guide in `node_modules/next/dist/docs/` (resolved from this file's directory; in monorepos the `next` package may not be visible from the repo root) before writing any code. Heed deprecation notices.

This block is written and re-added by `next dev` — verify at `node_modules/next/dist/server/lib/generate-agent-files.js`. Removing it from a diff only re-creates the uncommitted change; committing it with your work keeps the tree clean.

<!-- END:nextjs-agent-rules -->
