/**
 * Esquema do banco (PostgreSQL, Drizzle). É a fonte das migrações: depois de alterar este
 * arquivo, rode `npm run db:gerar` e versione o que aparecer em `drizzle/`.
 *
 * Duas partes:
 *
 * 1. Tabelas do Better Auth (`user`, `session`, `account`, `verification`). Seguem exatamente
 *    o que o gerador da versão instalada produz para o adaptador Drizzle com PostgreSQL, com
 *    os nomes padrão dele. Não renomeie nem mude tipos: quem configura o Better Auth é
 *    `src/acesso/`, em cima destas tabelas. Plugins novos do Better Auth podem exigir colunas.
 *
 * 2. Tabelas da genealogia. Regras que valem para todas:
 *    - Tudo pertence a uma árvore: `arvore_id` (apagar a árvore apaga os dados dela) e
 *      `criado_por` (quem cadastrou; decide quem pode remover).
 *    - Vínculos ficam em tabelas próprias (`filiacoes`, `unioes`); `pessoas` não tem coluna
 *      de pai nem de cônjuge.
 *    - Data genealógica ocupa quatro colunas (data, qualificador, precisão, fim da faixa),
 *      montadas por `colunasData()`. A coluna DATE é lida como texto AAAA-MM-DD, nunca como
 *      `Date`, para o fuso não deslocar o dia. Texto e leitura ficam em `src/dominio/datas.ts`.
 *    - O banco NÃO garante que um vínculo liga registros da mesma árvore: isso é conferido
 *      nos serviços (`src/servicos/`).
 */

import { sql } from "drizzle-orm";
import {
  boolean,
  check,
  date,
  index,
  integer,
  jsonb,
  pgEnum,
  pgTable,
  primaryKey,
  text,
  timestamp,
  uniqueIndex,
} from "drizzle-orm/pg-core";

import {
  PAPEIS,
  SEXOS,
  TIPOS_DOCUMENTO,
  TIPOS_EVENTO,
  TIPOS_UNIAO,
  TIPOS_VINCULO,
} from "../dominio/catalogos";
import { PRECISOES, QUALIFICADORES } from "../dominio/datas";

// ---------------------------------------------------------------------
// Better Auth
// ---------------------------------------------------------------------

export const user = pgTable("user", {
  id: text("id").primaryKey(),
  name: text("name").notNull(),
  email: text("email").notNull().unique(),
  emailVerified: boolean("email_verified").default(false).notNull(),
  image: text("image"),
  createdAt: timestamp("created_at").defaultNow().notNull(),
  updatedAt: timestamp("updated_at")
    .defaultNow()
    .$onUpdate(() => new Date())
    .notNull(),
});

export const session = pgTable(
  "session",
  {
    id: text("id").primaryKey(),
    expiresAt: timestamp("expires_at").notNull(),
    token: text("token").notNull().unique(),
    createdAt: timestamp("created_at").defaultNow().notNull(),
    updatedAt: timestamp("updated_at")
      .$onUpdate(() => new Date())
      .notNull(),
    ipAddress: text("ip_address"),
    userAgent: text("user_agent"),
    userId: text("user_id")
      .notNull()
      .references(() => user.id, { onDelete: "cascade" }),
  },
  (t) => [index("session_userId_idx").on(t.userId)],
);

export const account = pgTable(
  "account",
  {
    id: text("id").primaryKey(),
    accountId: text("account_id").notNull(),
    providerId: text("provider_id").notNull(),
    userId: text("user_id")
      .notNull()
      .references(() => user.id, { onDelete: "cascade" }),
    accessToken: text("access_token"),
    refreshToken: text("refresh_token"),
    idToken: text("id_token"),
    accessTokenExpiresAt: timestamp("access_token_expires_at"),
    refreshTokenExpiresAt: timestamp("refresh_token_expires_at"),
    scope: text("scope"),
    password: text("password"),
    createdAt: timestamp("created_at").defaultNow().notNull(),
    updatedAt: timestamp("updated_at")
      .$onUpdate(() => new Date())
      .notNull(),
  },
  (t) => [index("account_userId_idx").on(t.userId)],
);

export const verification = pgTable(
  "verification",
  {
    id: text("id").primaryKey(),
    identifier: text("identifier").notNull(),
    value: text("value").notNull(),
    expiresAt: timestamp("expires_at").notNull(),
    createdAt: timestamp("created_at").defaultNow().notNull(),
    updatedAt: timestamp("updated_at")
      .defaultNow()
      .$onUpdate(() => new Date())
      .notNull(),
  },
  (t) => [index("verification_identifier_idx").on(t.identifier)],
);

// ---------------------------------------------------------------------
// Catálogos (valores em src/dominio/)
// ---------------------------------------------------------------------

export const sexoEnum = pgEnum("sexo", SEXOS);
export const tipoVinculoEnum = pgEnum("tipo_vinculo", TIPOS_VINCULO);
export const tipoUniaoEnum = pgEnum("tipo_uniao", TIPOS_UNIAO);
export const tipoEventoEnum = pgEnum("tipo_evento", TIPOS_EVENTO);
export const tipoDocumentoEnum = pgEnum("tipo_documento", TIPOS_DOCUMENTO);
export const qualificadorDataEnum = pgEnum("qualificador_data", QUALIFICADORES);
export const precisaoDataEnum = pgEnum("precisao_data", PRECISOES);
export const papelEnum = pgEnum("papel", PAPEIS);

// ---------------------------------------------------------------------
// Peças comuns
// ---------------------------------------------------------------------

/**
 * As quatro colunas de uma data genealógica. `campo` é a chave em TypeScript e `coluna` o
 * nome no banco: `colunasData("dataNascimento", "data_nascimento")` cria `dataNascimento`,
 * `dataNascimentoQualificador`, `dataNascimentoPrecisao` e `dataNascimentoAte`, que é o
 * formato que `dataDoCampo()` de `src/dominio/datas.ts` lê.
 */
function colunasData<C extends string>(campo: C, coluna: string) {
  const colunas = {
    [campo]: colData(coluna),
    [`${campo}Qualificador`]: colQualificador(`${coluna}_qualificador`),
    [`${campo}Precisao`]: colPrecisao(`${coluna}_precisao`),
    [`${campo}Ate`]: colData(`${coluna}_ate`),
  };
  // Chave computada vira `string` na inferência; o cast devolve os nomes exatos das colunas.
  return colunas as { [K in C]: ReturnType<typeof colData> } & {
    [K in `${C}Qualificador`]: ReturnType<typeof colQualificador>;
  } & { [K in `${C}Precisao`]: ReturnType<typeof colPrecisao> } & {
    [K in `${C}Ate`]: ReturnType<typeof colData>;
  };
}
const colData = (coluna: string) => date(coluna, { mode: "string" });
const colQualificador = (coluna: string) =>
  qualificadorDataEnum(coluna).notNull().default("exata");
const colPrecisao = (coluna: string) => precisaoDataEnum(coluna).notNull().default("dia");

const id = () => integer("id").primaryKey().generatedAlwaysAsIdentity();

const criadoEm = () => timestamp("criado_em", { withTimezone: true }).notNull().defaultNow();

// ---------------------------------------------------------------------
// Árvores e participantes
// ---------------------------------------------------------------------

export const arvores = pgTable(
  "arvores",
  {
    id: id(),
    nome: text("nome").notNull(),
    descricao: text("descricao"),
    // "restrict": um usuário com árvores ou registros não some sem alguém decidir o destino deles.
    criadoPor: text("criado_por")
      .notNull()
      .references(() => user.id, { onDelete: "restrict" }),
    criadoEm: criadoEm(),
  },
  (t) => [index("idx_arvores_criado_por").on(t.criadoPor)],
);

export const membrosArvore = pgTable(
  "membros_arvore",
  {
    arvoreId: integer("arvore_id")
      .notNull()
      .references(() => arvores.id, { onDelete: "cascade" }),
    usuarioId: text("usuario_id")
      .notNull()
      .references(() => user.id, { onDelete: "cascade" }),
    papel: papelEnum("papel").notNull(),
    criadoEm: criadoEm(),
  },
  (t) => [
    // Um papel por usuário em cada árvore; a chave já serve de índice por arvore_id.
    primaryKey({ columns: [t.arvoreId, t.usuarioId] }),
    index("idx_membros_arvore_usuario").on(t.usuarioId),
  ],
);

// ---------------------------------------------------------------------
// Genealogia
// ---------------------------------------------------------------------

const arvoreId = () =>
  integer("arvore_id")
    .notNull()
    .references(() => arvores.id, { onDelete: "cascade" });

const criadoPor = () =>
  text("criado_por")
    .notNull()
    .references(() => user.id, { onDelete: "restrict" });

export const pessoas = pgTable(
  "pessoas",
  {
    id: id(),
    arvoreId: arvoreId(),
    nomeCompleto: text("nome_completo").notNull(),
    sexo: sexoEnum("sexo").notNull().default("D"),
    /** Caminho relativo do arquivo da foto. */
    foto: text("foto"),
    ...colunasData("dataNascimento", "data_nascimento"),
    localNascimento: text("local_nascimento"),
    ...colunasData("dataFalecimento", "data_falecimento"),
    localFalecimento: text("local_falecimento"),
    biografia: text("biografia"),
    criadoPor: criadoPor(),
    criadoEm: criadoEm(),
  },
  (t) => [index("idx_pessoas_arvore").on(t.arvoreId)],
);

/**
 * Uma linha por relação filho-e-pais. Uma pessoa pode ter mais de uma (biológica e adotiva);
 * a principal é a biológica e, no empate, a de menor id, por isso o id é sequencial.
 *
 * Apagar o filho apaga a filiação; apagar um genitor só esvazia a coluna dele. Isso pode
 * deixar uma filiação sem pai e sem mãe, que o serviço de exclusão de pessoa precisa limpar
 * (no legado, `gen_limpar_filiacoes_vazias`). Não há CHECK exigindo um genitor justamente
 * porque ele faria essa exclusão falhar.
 */
export const filiacoes = pgTable(
  "filiacoes",
  {
    id: id(),
    arvoreId: arvoreId(),
    filhoId: integer("filho_id")
      .notNull()
      .references(() => pessoas.id, { onDelete: "cascade" }),
    paiId: integer("pai_id").references(() => pessoas.id, { onDelete: "set null" }),
    maeId: integer("mae_id").references(() => pessoas.id, { onDelete: "set null" }),
    tipoPai: tipoVinculoEnum("tipo_pai").notNull().default("biologico"),
    tipoMae: tipoVinculoEnum("tipo_mae").notNull().default("biologico"),
    criadoPor: criadoPor(),
    criadoEm: criadoEm(),
  },
  (t) => [
    index("idx_filiacoes_arvore").on(t.arvoreId),
    index("idx_filiacoes_filho").on(t.filhoId),
    index("idx_filiacoes_pai").on(t.paiId),
    index("idx_filiacoes_mae").on(t.maeId),
  ],
);

/** Par de cônjuges, sempre gravado normalizado (pessoa1_id < pessoa2_id) e único por par. */
export const unioes = pgTable(
  "unioes",
  {
    id: id(),
    arvoreId: arvoreId(),
    pessoa1Id: integer("pessoa1_id")
      .notNull()
      .references(() => pessoas.id, { onDelete: "cascade" }),
    pessoa2Id: integer("pessoa2_id")
      .notNull()
      .references(() => pessoas.id, { onDelete: "cascade" }),
    tipo: tipoUniaoEnum("tipo").notNull().default("casamento"),
    ...colunasData("dataInicio", "data_inicio"),
    localInicio: text("local_inicio"),
    ...colunasData("dataFim", "data_fim"),
    criadoPor: criadoPor(),
    criadoEm: criadoEm(),
  },
  (t) => [
    uniqueIndex("uq_unioes_par").on(t.pessoa1Id, t.pessoa2Id),
    // O índice único só impede o par repetido se a ordem for sempre a mesma.
    check("ck_unioes_par_normalizado", sql`${t.pessoa1Id} < ${t.pessoa2Id}`),
    index("idx_unioes_arvore").on(t.arvoreId),
    index("idx_unioes_pessoa2").on(t.pessoa2Id),
  ],
);

export const documentos = pgTable(
  "documentos",
  {
    id: id(),
    arvoreId: arvoreId(),
    pessoaId: integer("pessoa_id")
      .notNull()
      .references(() => pessoas.id, { onDelete: "cascade" }),
    tipo: tipoDocumentoEnum("tipo").notNull(),
    caminhoArquivo: text("caminho_arquivo").notNull(),
    descricao: text("descricao"),
    criadoPor: criadoPor(),
    criadoEm: criadoEm(),
  },
  (t) => [
    index("idx_documentos_arvore").on(t.arvoreId),
    index("idx_documentos_pessoa").on(t.pessoaId),
  ],
);

/** Fatos de uma pessoa além de nascimento e falecimento, com união e documento-fonte opcionais. */
export const eventos = pgTable(
  "eventos",
  {
    id: id(),
    arvoreId: arvoreId(),
    pessoaId: integer("pessoa_id")
      .notNull()
      .references(() => pessoas.id, { onDelete: "cascade" }),
    uniaoId: integer("uniao_id").references(() => unioes.id, { onDelete: "cascade" }),
    tipo: tipoEventoEnum("tipo").notNull().default("outro"),
    ...colunasData("data", "data"),
    local: text("local"),
    descricao: text("descricao"),
    documentoId: integer("documento_id").references(() => documentos.id, { onDelete: "set null" }),
    criadoPor: criadoPor(),
    criadoEm: criadoEm(),
  },
  (t) => [
    index("idx_eventos_arvore").on(t.arvoreId),
    index("idx_eventos_pessoa").on(t.pessoaId, t.data),
    index("idx_eventos_uniao").on(t.uniaoId),
    index("idx_eventos_documento").on(t.documentoId),
  ],
);

// ---------------------------------------------------------------------
// Auditoria
// ---------------------------------------------------------------------

/**
 * Quem fez o quê, em qual árvore e quando. O registro sobrevive à exclusão da árvore e do
 * usuário (as colunas ficam vazias), senão a própria exclusão não deixaria rastro.
 */
export const auditoria = pgTable(
  "auditoria",
  {
    id: id(),
    arvoreId: integer("arvore_id").references(() => arvores.id, { onDelete: "set null" }),
    usuarioId: text("usuario_id").references(() => user.id, { onDelete: "set null" }),
    /** Verbo curto e estável, ex.: "pessoa.criar", "uniao.remover". */
    acao: text("acao").notNull(),
    /** Tabela e id do registro afetado, sem chave estrangeira: o registro pode já não existir. */
    entidade: text("entidade"),
    entidadeId: integer("entidade_id"),
    detalhes: jsonb("detalhes"),
    criadoEm: criadoEm(),
  },
  (t) => [
    index("idx_auditoria_arvore").on(t.arvoreId, t.criadoEm),
    index("idx_auditoria_usuario").on(t.usuarioId),
  ],
);
