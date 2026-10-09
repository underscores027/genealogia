/**
 * Catálogos do domínio: os valores permitidos de cada campo de escolha fechada.
 *
 * Esta é a fonte única desses valores. O esquema do banco (`src/db/esquema.ts`) cria os enums
 * do PostgreSQL a partir destas listas, então acrescentar ou remover um valor aqui exige gerar
 * migração. Os catálogos de data (qualificador e precisão) ficam em `datas.ts`.
 */

/** M = masculino, F = feminino, D = desconhecido. */
export const SEXOS = ["M", "F", "D"] as const;
export type Sexo = (typeof SEXOS)[number];

/** Tipo do vínculo de cada genitor numa filiação. */
export const TIPOS_VINCULO = ["biologico", "adotivo", "padrasto", "tutela"] as const;
export type TipoVinculo = (typeof TIPOS_VINCULO)[number];

export const TIPOS_UNIAO = ["casamento", "uniao_estavel", "outro"] as const;
export type TipoUniao = (typeof TIPOS_UNIAO)[number];

/** Fatos de uma pessoa além de nascimento e falecimento (que ficam em `pessoas`). */
export const TIPOS_EVENTO = [
  "batismo",
  "imigracao",
  "naturalizacao",
  "residencia",
  "sepultamento",
  "ocupacao",
  "outro",
] as const;
export type TipoEvento = (typeof TIPOS_EVENTO)[number];

export const TIPOS_DOCUMENTO = [
  "certidao_nascimento",
  "certidao_casamento",
  "foto",
  "obito",
  "passaporte",
  "outro",
] as const;
export type TipoDocumento = (typeof TIPOS_DOCUMENTO)[number];

/** Papel de um usuário numa árvore, do menor poder para o maior. */
export const PAPEIS = ["observador", "editor", "administrador"] as const;
export type Papel = (typeof PAPEIS)[number];
