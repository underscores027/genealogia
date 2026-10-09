# Genealogia — guia para agentes de IA

Contexto do projeto para quem vai ler ou alterar o código sem ter acompanhado o histórico.
Leia este arquivo inteiro antes de mexer em qualquer coisa; os cabeçalhos de comentário de cada
arquivo trazem o detalhe que não cabe aqui.

## 1. O que é

Aplicação web de árvore genealógica. O centro do projeto é uma **árvore navegável** a partir de
uma pessoa em foco: abre pais, irmãos, filhos e cônjuges sob demanda, e quem está logado
cadastra parentes direto nos cards.

Situação atual (outubro de 2026): o projeto nasceu como site de uma família específica e passou
por uma limpeza para virar uma **base genérica de busca de ascendência e descendência**. O banco
está vazio e a identidade visual ainda vai ser redefinida. Consequências práticas:

- Não reintroduza nomes de família, textos temáticos ou o tema antigo (verde/azul/dourado, fonte
  Playfair).
- Foram removidos de propósito: importação/exportação GEDCOM, detecção de duplicatas, relatório
  em PDF, dashboard, landing page, cadastro público de usuários, envio de e-mail e níveis de
  acesso. Não os recrie sem pedido explícito.
- O projeto está em **fase de protótipo**: proteção CSRF e endurecimento para produção ainda não
  são requisito (ver seção 10).

## 2. Stack e ambiente

| Item | Valor |
|---|---|
| Servidor | PHP 7.4 (o código precisa continuar compatível com 7.4) + Apache |
| Banco | MySQL 8 / InnoDB, `utf8mb4_unicode_ci`, acesso por PDO |
| Front | Bootstrap 5.3, Font Awesome 6.4 e d3 7.9, todos por CDN |
| JavaScript | ES5 em IIFEs, sem etapa de build, sem Node, sem npm |
| Dependências PHP | Nenhuma. Não há Composer nem framework |
| Testes | Não há suíte automatizada (ver seção 9) |

Idioma: código, comentários, nomes de tabelas e mensagens em **português**.

### Como rodar

1. Criar um banco vazio e importar `genealogia.sql` (só a estrutura; apaga e recria as tabelas).
2. Copiar `includes/config.exemplo.php` para `includes/config.php` e preencher os dados do banco.
3. Criar um login: `php criar_usuario.php <usuario> <senha>` (só funciona por linha de comando).
4. Servir a pasta pelo Apache e abrir `arvore.php`.

`includes/config.php` e o conteúdo de `uploads/` ficam fora do Git.

## 3. Mapa dos arquivos

```
index.php                  redireciona para arvore.php
arvore.php                 página da árvore (pública); monta o HTML e passa a config para o JS
login.php / logout.php     login simples por usuário e senha
criar_usuario.php          CLI: cria usuário ou troca senha

perfil.php                 perfil público de uma pessoa (dados, família, eventos, documentos)
adicionar_pessoa.php       formulário completo de nova pessoa        (login)
editar_pessoa.php          formulário completo de edição             (login)
gerenciar_pessoas.php      lista de pessoas + edição rápida de pais  (login)
deletar_pessoa.php         exclusão de pessoa, só POST               (login)
buscar.php                 busca por nome                            (login)
gerenciar_documentos.php   documentos por pessoa; exclusão só POST   (login)
salvar_documento.php       upload de documento                       (login)
timeline.php               linha do tempo de todos os eventos (pública)

api/_comum.php             infraestrutura dos endpoints JSON
api/raiz.php               pessoa inicial padrão
api/pessoa.php             pessoa + vizinhança imediata
api/ancestrais.php         N gerações acima
api/descendentes.php       N gerações abaixo
api/busca.php              busca por nome (autocomplete)
api/adicionar_parente.php  ÚNICO endpoint de gravação (POST, login)

includes/app.php           app_config() e app_nome(): leitura do config.php
includes/config.exemplo.php  modelo de configuração (versionado)
includes/db.php            fuso horário, conexão PDO ($pdo) e registrarLog()
includes/auth.php          exige login; redireciona para login.php?voltar=...
includes/header.php        <head>, barra de navegação, abre <main>
includes/footer.php        fecha <main>, carrega o JS do Bootstrap
includes/genealogia.php    CAMADA DE DOMÍNIO: todas as regras, consultas e gravações de vínculos
includes/pessoa_form.php   formulário de pessoa (estado, processamento e HTML), prefixo pf_

assets/js/arvore.js        utilitários, Grafo, painel, busca, diálogo "Adicionar parente" e a visão Paisagem
assets/js/visoes.js        coordenador das visões + visões Descendência e Leque
assets/css/arvore.css      todos os estilos da árvore (prefixo arv-, leque usa lq-)

genealogia.sql             estrutura do banco (linha de base)
uploads/                   fotos (avatar/) e documentos (documentos/); .htaccess bloqueia scripts
```

## 4. Modelo de dados

Sete tabelas. Não existe coluna "pai" ou "cônjuge" em `pessoas`: os vínculos ficam em tabelas
próprias.

- **`pessoas`** — `nome_completo`, `sexo` (`M`, `F` ou `D` = desconhecido), `foto` (caminho
  relativo em `uploads/avatar/`), nascimento e falecimento (data + local), `biografia`.
- **`filiacoes`** — uma linha por relação filho-e-pais: `filho_id`, `pai_id`, `mae_id` (qualquer
  um dos dois pode ser nulo) e `tipo_pai` / `tipo_mae` (`biologico`, `adotivo`, `padrasto`,
  `tutela`). Uma pessoa pode ter **mais de uma filiação** (ex.: pais biológicos e adotivos). A
  "principal" é a primeira na ordem biológica-primeiro, depois menor `id`; é a que a árvore
  desenha.
- **`unioes`** — par de cônjuges, sempre gravado **normalizado** (`pessoa1_id` < `pessoa2_id`,
  com índice único no par), `tipo` (`casamento`, `uniao_estavel`, `outro`), início, local e fim.
- **`eventos`** — fatos de uma pessoa além de nascimento e falecimento: `tipo` (`batismo`,
  `imigracao`, `naturalizacao`, `residencia`, `sepultamento`, `ocupacao`, `outro`), data, local,
  descrição e, opcionalmente, `uniao_id` e `documento_id`.
- **`documentos`** — arquivos anexados a uma pessoa (`caminho_arquivo` em `uploads/documentos/`).
- **`usuarios`** — `username`, `nome_completo`, `senha` (hash de `password_hash`). Todos os
  usuários têm o mesmo poder; não há papéis.
- **`logs_sistema`** — auditoria gravada por `registrarLog()`.

Chaves estrangeiras: apagar uma pessoa apaga em cascata suas uniões, eventos, documentos e as
filiações em que ela é o filho; nas filiações em que ela é pai ou mãe o campo vira `NULL`
(`gen_limpar_filiacoes_vazias()` remove as que ficarem sem nenhum genitor).

### Datas aproximadas

Toda data genealógica ocupa **quatro colunas**: `<campo>` (DATE, usado para ordenar),
`<campo>_qualificador` (`exata`, `cerca`, `antes`, `depois`, `entre`), `<campo>_precisao` (`dia`,
`mes`, `ano`) e `<campo>_ate` (fim da faixa quando o qualificador é `entre`). Precisão `mes` grava
dia 01; precisão `ano` grava 01/01.

Regras:

- Entrada de texto (`22/02/1882`, `02/1882`, `1882`) passa por `gen_ler_data_aprox()`.
- Saída em texto vem **só** de `gen_formatar_data()` e derivadas (`gen_data_texto`,
  `gen_data_ano`, `gen_data_json`). Nunca formate data em outro lugar, nem no JavaScript: a API já
  entrega `texto` e `ano` prontos.

## 5. Camada de domínio: `includes/genealogia.php`

Funções puras sobre PDO, sem HTML, todas com prefixo `gen_`. O arquivo é organizado em blocos:

| Bloco | Exemplos |
|---|---|
| Catálogos e utilitários | `gen_tipos_vinculo`, `gen_sexos`, `gen_id`, `gen_data` |
| Datas aproximadas | `gen_formatar_data`, `gen_ler_data_aprox`, `gen_comparar_datas` |
| Leitura (uma pessoa) | `gen_pessoa`, `gen_filiacoes`, `gen_pais`, `gen_unioes`, `gen_filhos`, `gen_familias`, `gen_irmaos` |
| Grafo completo em memória | `gen_carregar_grafo` (carrega tudo; só para páginas que listam todo mundo) |
| Validações | `gen_validar_dados_pessoa`, `gen_validar_filiacao`, `gen_validar_uniao`, `gen_descende_de`, `gen_verificar_nascimento_pais` |
| Gravação | `gen_salvar_filiacao`, `gen_salvar_uniao`, `gen_vincular_como_genitor`, `gen_remover_*` |
| Consultas por lote (API) | `gen_subgrafo`, `gen_ancestrais_por_nivel`, `gen_descendentes_por_nivel`, `gen_pessoa_card` |
| Eventos e documentos | `gen_eventos`, `gen_salvar_evento`, `gen_evento_json`, `gen_documentos_pessoa` |

Convenções desta camada (siga-as em qualquer função nova):

- IDs vindos de fora passam por `gen_id()` (inteiro positivo ou `null`).
- Funções de validação e gravação devolvem **um array de mensagens de erro em português**; array
  vazio significa sucesso. Não lançam exceção para erro de regra de negócio.
- Funções de gravação **validam antes de gravar** e **não abrem transação**: quem chama decide
  (`beginTransaction` / `commit` / `rollBack`).
- Regras já garantidas aqui: pai não pode ter sexo `F` nem mãe `M`; vínculo não pode criar ciclo
  (ninguém vira ancestral de si mesmo); filho não nasce antes dos pais; união não se repete.

## 6. API JSON (`api/`)

Todo endpoint começa com `require __DIR__ . '/_comum.php';`, que define `$pdo` e as funções
`api_*`, força resposta JSON e converte qualquer warning em erro 500 sem vazar detalhes.

- Leitura: só `GET`/`HEAD`, pública.
- Gravação: o arquivo faz `define('API_ESCRITA', true);` **antes** do `require`; o `_comum.php`
  então exige `POST` e sessão logada (401 sem login).
- Erro padrão: `{"erro": "...", "status": N}` via `api_erro()`. Erro de validação: HTTP 422 com
  `erro` (texto único) e `erros` (lista).

| Endpoint | Parâmetros | Devolve |
|---|---|---|
| `raiz.php` | — | `id` e card da pessoa inicial; 404 se o banco está vazio |
| `pessoa.php` | `id` | `pessoa`, `pais`, `familias`, `irmaos`, `eventos` + grafo |
| `ancestrais.php` | `id`, `geracoes` (1–6, padrão 2) | `niveis` + grafo |
| `descendentes.php` | `id`, `geracoes` (1–6, padrão 2) | `conjuges_foco`, `niveis` + grafo |
| `busca.php` | `q` (2–100 caracteres), `limite` (até 50) | `pessoas` |
| `adicionar_parente.php` | POST: `ancora_id`, `relacao`, e `pessoa_id` ou dados da pessoa nova | `ok`, `id`, `nome`, `criado`, `avisos` |

**Formato de grafo** (`api_grafo()` sobre `gen_subgrafo()`), comum aos endpoints de leitura:

- `pessoas`: cards de `gen_pessoa_card()` — `id`, `nome`, `sexo`, `foto`, `nascimento` e
  `falecimento` (`data`, `local`, `texto`, `ano`, ...), `anos`, e flags.
- `filiacoes`: `id`, `filho_id`, `pai_id`, `mae_id`, `tipo_pai`, `tipo_mae`, `principal`.
- `unioes`: `id`, `pessoa1_id`, `pessoa2_id`, `tipo`, datas.

Flags do card, que controlam as setas da árvore:

- `tem_pais`, `tem_filhos`, `tem_irmaos`: **absolutas** (existe no banco).
- `tem_mais_ancestrais`, `tem_mais_descendentes`, `tem_mais_conjuges`: **relativas à resposta**
  (existe algum fora do conjunto devolvido).

`adicionar_parente.php` aceita `relacao` = `pai`, `mae`, `conjuge`, `filho` ou `irmao` (o que o
parente é da pessoa-âncora). Cria a pessoa e o vínculo numa única transação; qualquer erro desfaz
tudo. `irmao` copia os pais da filiação principal da âncora e falha se ela não tiver pais.

## 7. Front-end da árvore

`arvore.php` não consulta o banco: monta o HTML, carrega d3, `arvore.js` e `visoes.js`, e chama
`ArvoreVisoes.iniciar({...})`. A URL guarda o estado: `?id=N&visao=paisagem|descendencia|leque`.

### `assets/js/arvore.js` (expõe `window.ArvoreGenealogica`)

| Parte | Papel |
|---|---|
| utilidades | `esc`, `el` (cria DOM), `chamarApi` (GET), `enviarApi` (POST) |
| `Grafo` | junta as respostas da API por id; `paisDe(id)`, `familias(id)` |
| `criarPainel` | painel lateral de resumo (vira *bottom sheet* no celular) |
| `configurarBusca` | autocomplete "Ir para pessoa" |
| `criarDialogoAdicionar` | `<dialog>` do botão "+": parentesco, pessoa nova ou já cadastrada |
| `criar` | visão **Paisagem**: layout, desenho, zoom, setas, recolher, adicionar |

Dentro de `criar()`:

- `abertos` = o que está visível (`pais`, `irmaos`, `filhos`: conjuntos de ids); zera a cada novo
  foco. `carregado` = o que já veio da API na sessão.
- `calcular()` posiciona tudo com `d3.tree`: ancestrais acima do foco, linha do foco (foco,
  cônjuges e irmãos) e blocos de descendentes abaixo. Cada pessoa aparece **uma vez só**.
- `htmlCard()` gera o card; `desenhar()` aplica no DOM; `renderizar()` orquestra.
- `expandir()` / `recolher()` tratam as setas; `adicionar()` e `aposAdicionar()` tratam o "+".
- O tamanho do card está em `DIM` (150×210 normal, 110×154 compacto, proporção 5:7). Mudar essas
  medidas exige revisar os espaçamentos no mesmo objeto e o CSS correspondente.

### `assets/js/visoes.js` (expõe `window.ArvoreVisoes`)

- `iniciar()` é o **coordenador**: guarda foco e visão, mantém a URL (`pushState`), e compartilha
  painel, busca e mensagem de status entre as visões.
- `criarPaisagem` embrulha `ArvoreGenealogica.criar`; `criarDescendencia` é a lista hierárquica;
  `criarLeque` é o *fan chart* de ancestrais em SVG.
- Banco vazio (`raiz.php` responde 404) mostra o convite para cadastrar a primeira pessoa.

### Segurança no front

- Em `arvore.js`, o card é montado com `innerHTML`: **todo** texto vindo do banco passa por
  `esc()`. O restante usa `el()` com `textContent`.
- Em `visoes.js`, nada da API vai para `innerHTML`.

## 8. Padrões de código

**PHP**

- Compatível com PHP 7.4 (sem `match`, sem *named arguments*, sem `readonly`).
- Funções em `snake_case` com prefixo do módulo: `gen_` (domínio), `pf_` (formulário de pessoa),
  `api_` (API), `app_` (configuração). `registrarLog` é exceção histórica.
- SQL sempre com *prepared statements*. Listas em `IN (...)` usam `gen_placeholders()`.
- SQL e regra de negócio pertencem a `includes/genealogia.php`. Algumas páginas antigas ainda têm
  SQL próprio (`buscar.php`, `timeline.php`, `gerenciar_documentos.php`); não copie esse padrão.
- Saída HTML sempre escapada (`pf_e()` ou `htmlspecialchars(..., ENT_QUOTES, 'UTF-8')`).
- **Toda alteração de estado é por POST.** Páginas que gravam incluem `includes/auth.php`.
- Gravações relevantes chamam `registrarLog($pdo, $acao, $detalhes)`.
- Configuração só por `app_config()`; nunca escreva credenciais no código.

**JavaScript**

- ES5: `var`, `function`, sem *arrow functions*, sem módulos, sem dependência além do d3.
- `camelCase`, nomes de domínio em português.
- Leitura por `chamarApi`, gravação por `enviarApi`.

**CSS**

- Tudo em `assets/css/arvore.css`, com prefixo `arv-` (o leque usa `lq-`).
- As páginas fora da árvore usam Bootstrap puro; não crie tema global no `header.php`.

**Comentários**

- Cada arquivo abre com um bloco que explica seu papel; mantenha-o verdadeiro ao alterar o
  arquivo. Comente o porquê, não o óbvio.

## 9. Como fazer alterações

### Receitas

- **Novo dado no card da árvore:** incluir a coluna em `gen_pessoas_por_ids()`, expor em
  `gen_pessoa_card()`, usar em `htmlCard()` (com `esc()`) e estilizar em `arvore.css`.
- **Novo endpoint de leitura:** arquivo em `api/` com `require __DIR__ . '/_comum.php';`, validar
  parâmetros com `api_param_int()`, montar o conjunto de ids, chamar `gen_subgrafo()` e responder
  com `api_responder(array_merge([...], api_grafo($sub)))`.
- **Novo endpoint de gravação:** copiar a estrutura de `api/adicionar_parente.php` —
  `define('API_ESCRITA', true)`, validação, transação, `rollBack` com resposta 422, `commit`,
  `registrarLog`. No JS, chamar com `enviarApi()` e recarregar o entorno como em `aposAdicionar()`.
- **Nova regra de validação:** função `gen_validar_*` que devolve array de erros, chamada dentro
  da função de gravação correspondente, para valer tanto no formulário quanto na API.
- **Novo campo de pessoa:** coluna em `pessoas` (com migração, ver abaixo), `pf_estado_vazio()`,
  a lista `$cols` em `pf_processar()`, o HTML em `pf_render_form()` e, se aparecer na árvore,
  `gen_pessoa_card()`.
- **Nova visão da árvore:** função `criarX(ctx, e)` em `visoes.js` que devolve `{ mostrar(id) }`,
  registrada em `VISOES` e em `visoes`, mais a entrada em `$visoes` e o contêiner em `arvore.php`.
- **Mudança de estrutura do banco:** `genealogia.sql` é a linha de base. Registre a alteração como
  script SQL numerado (ex.: `migracoes/001_descricao.sql`) e atualize a seção 4 deste arquivo.

### Verificação

Não há testes automatizados nem Node. O mínimo antes de dar uma alteração por pronta:

1. `php -l` em todo arquivo PHP alterado.
2. Abrir as páginas afetadas e conferir que não há *warning* no corpo da resposta.
3. Para a API, chamar o endpoint e conferir o JSON; para gravação, testar também um caso de erro
   e confirmar que nada foi gravado.
4. Para a árvore, conferir em largura de desktop e de celular (abaixo de 640 px entra o modo
   compacto) e nas duas orientações (botão de girar).

Diga com clareza o que foi verificado e o que não foi.

### Git

- Branch `main`, remoto em `github.com/underscores027/genealogia` (público).
- Fim de linha LF (`.gitattributes`).
- Nunca versionar `includes/config.php`, arquivos de `uploads/`, despejos de banco com dados
  reais ou hashes de senha.
- Commit e push só quando o dono do projeto pedir.

## 10. Pendências e limites conhecidos

- **Identidade visual indefinida.** As cores dos cards e das visões em `arvore.css` e a constante
  `COR` em `visoes.js` ainda são as do tema antigo (azul `#002776`, vermelho `#B22222`, dourado
  `#FFD700`, verde `#117A2B`). Uma tentativa de redesenho já foi rejeitada: não mude o visual sem
  alinhar antes.
- **Modo "mini-árvore" inativo.** `criar()` ainda aceita opções que só o antigo dashboard usava
  (`forcarCompacto`, `cliqueCard: 'focar'`, `atualizarUrl`, `busca`). Nada as usa hoje.
- **Arquivos grandes.** `genealogia.php` (cerca de 1.400 linhas) e `arvore.js` (cerca de 1.400)
  são candidatos a divisão por assunto.
- **Sem CSRF** nos formulários e na API de gravação (decisão da fase de protótipo).
- **`.git` e `genealogia.sql` acessíveis pelo Apache** se a pasta inteira for publicada.
- **Sem cadastro de usuários pela interface:** contas só por `criar_usuario.php`.
- **Retratos em pé são recortados** na janela da foto do card, que é mais larga que alta.
