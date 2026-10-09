# Genealogia

Aplicação web para montar e compartilhar árvores genealógicas. Cada usuário cria suas árvores,
monta cada uma num editor visual e convida outras pessoas para ver ou colaborar.

> **Projeto em estágio inicial.** O código está sendo reescrito do zero e ainda não há uma
> aplicação utilizável: hoje existem a base do projeto, as regras de datas e o esquema do banco.
> Veja [Situação atual](#situação-atual).

## O que o projeto pretende ser

- **Várias árvores por usuário**, cada uma com seus participantes.
- **Editor visual** que funciona como um quadro em branco: adicionar pessoas e ligá-las como
  pais, filhos, irmãos e cônjuges.
- **Papéis por árvore:**
  - *observador* só vê;
  - *editor* adiciona registros e remove o que ele mesmo criou;
  - *administrador* faz tudo, inclusive gerir participantes.
- **Visões da árvore:** navegação a partir de uma pessoa em foco, lista de descendência, leque de
  ancestrais e linha do tempo.
- **Dados genealógicos de verdade:** datas aproximadas ("cerca de 1882", "antes de 03/1890",
  "entre 1850 e 1855"), mais de uma filiação por pessoa (biológica, adotiva, padrasto, tutela),
  eventos de vida e documentos anexados.

## Situação atual

| Parte | Estado |
|---|---|
| Base do projeto (Next.js, lint, testes, build) | pronta |
| Datas aproximadas (leitura, formatação, comparação) | pronta, com testes |
| Esquema do banco e primeira migração | escritos, ainda não aplicados num banco |
| Login, cadastro e permissões | a fazer |
| Validações de vínculo, consultas e serviços | a fazer |
| Telas e editor da árvore | a fazer |

O projeto nasceu como um site em PHP sem framework. Esse código está em [`legado/`](legado/) só
como referência de comportamento durante a reescrita; ele não é mantido.

## Tecnologias

| Camada | Escolha |
|---|---|
| Linguagem | TypeScript |
| Aplicação | Next.js (App Router) e React |
| Estilos | Tailwind CSS |
| Editor da árvore | React Flow |
| Banco | PostgreSQL com Drizzle |
| Login e sessões | Better Auth |
| Testes | Vitest e Playwright |

## Como rodar

Requisitos: Node.js 24 e, para tudo que envolve dados, um PostgreSQL.

```bash
git clone https://github.com/underscores027/genealogia.git
cd genealogia
npm install
npm run dev
```

A aplicação sobe em `http://localhost:3000`. Por enquanto ela mostra apenas uma página
provisória.

Para preparar o banco, copie `.env.exemplo` para `.env`, preencha `DATABASE_URL` e rode:

```bash
npm run db:migrar
```

### Comandos

| Comando | Faz |
|---|---|
| `npm run dev` | Sobe a aplicação em modo de desenvolvimento |
| `npm run verificar` | Checa tipos, lint e testes de unidade |
| `npm run e2e` | Testes de tela (exige `npx playwright install` uma vez) |
| `npm run db:gerar` | Gera uma migração a partir do esquema |
| `npm run db:migrar` | Aplica as migrações no banco |

## Organização do código

```
src/dominio/      regras genealógicas puras (datas, catálogos), sem banco e sem React
src/db/           esquema do banco
src/app/          páginas e layouts
drizzle/          migrações
legado/           versão antiga em PHP, só referência
.claude/agents/   agentes de IA especializados por área
```

O desenvolvimento é feito com apoio de agentes de IA, cada um responsável por uma área do
código. As regras do projeto, o modelo de dados e a divisão de responsabilidades estão em
[`AGENTS.md`](AGENTS.md), que também serve de guia para quem quiser entender a arquitetura.

## Contribuições e licença

O projeto ainda está tomando forma e não tem um processo de contribuição definido; sugestões e
relatos de problema são bem-vindos pelas *issues*. Ainda não há licença definida.
