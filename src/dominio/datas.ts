/**
 * Datas aproximadas da genealogia.
 *
 * Uma data ocupa quatro campos: `data` (AAAA-MM-DD, o valor usado para ordenar),
 * `qualificador` (exata, cerca, antes, depois, entre), `precisao` (dia, mes, ano) e `ate`
 * (fim da faixa quando o qualificador é "entre"). Precisão "mes" grava o dia 01; precisão
 * "ano" grava 01/01.
 *
 * Este é o ÚNICO módulo que lê texto digitado e que transforma data em texto. Ninguém mais
 * formata data: serviços entregam `texto` e `ano` prontos (ver `dataParaExibicao`).
 *
 * Uma data genealógica é uma data de calendário, não um instante. Por isso tudo aqui trabalha
 * com a string AAAA-MM-DD e aritmética de inteiros; `Date` não é usado em lugar nenhum, porque
 * ele interpretaria a data num fuso e poderia deslocar o dia.
 *
 * Portado do bloco "Datas aproximadas" de `legado/includes/genealogia.php`.
 */

export const QUALIFICADORES = ["exata", "cerca", "antes", "depois", "entre"] as const;
export type Qualificador = (typeof QUALIFICADORES)[number];

export const PRECISOES = ["dia", "mes", "ano"] as const;
export type Precisao = (typeof PRECISOES)[number];

export const ROTULOS_QUALIFICADOR: Readonly<Record<Qualificador, string>> = {
  exata: "Exata",
  cerca: "Cerca de",
  antes: "Antes de",
  depois: "Depois de",
  entre: "Entre",
};

export const ROTULOS_PRECISAO: Readonly<Record<Precisao, string>> = {
  dia: "Dia",
  mes: "Mês",
  ano: "Ano",
};

/** As quatro colunas de uma data, já conferidas. */
export interface DataAprox {
  /** AAAA-MM-DD ou null quando não há data. */
  data: string | null;
  qualificador: Qualificador;
  precisao: Precisao;
  /** AAAA-MM-DD; só existe quando o qualificador é "entre". */
  ate: string | null;
}

/** As quatro colunas como chegam de fora (banco, formulário), ainda sem conferir. */
export interface DataAproxBruta {
  data?: unknown;
  qualificador?: unknown;
  precisao?: unknown;
  ate?: unknown;
}

/** Data pronta para a interface: as quatro colunas, o local e os textos já formatados. */
export interface DataExibicao extends DataAprox {
  local: string | null;
  /** "c. 1824", "22/02/1882"...; null quando não há data. */
  texto: string | null;
  /** Forma curta para cards e listas; null quando não há data. */
  ano: string | null;
}

export const DATA_VAZIA: Readonly<DataAprox> = Object.freeze({
  data: null,
  qualificador: "exata",
  precisao: "dia",
  ate: null,
});

const MESES_ABREV = [
  "jan.", "fev.", "mar.", "abr.", "maio", "jun.",
  "jul.", "ago.", "set.", "out.", "nov.", "dez.",
] as const;

// Do mais fino para o mais grosseiro.
const ORDEM_PRECISAO: Readonly<Record<Precisao, number>> = { dia: 0, mes: 1, ano: 2 };

function ehQualificador(v: unknown): v is Qualificador {
  return typeof v === "string" && (QUALIFICADORES as readonly string[]).includes(v);
}

function ehPrecisao(v: unknown): v is Precisao {
  return typeof v === "string" && (PRECISOES as readonly string[]).includes(v);
}

function diasNoMes(ano: number, mes: number): number {
  if (mes === 2) {
    const bissexto = (ano % 4 === 0 && ano % 100 !== 0) || ano % 400 === 0;
    return bissexto ? 29 : 28;
  }
  return [4, 6, 9, 11].includes(mes) ? 30 : 31;
}

function existeNoCalendario(ano: number, mes: number, dia: number): boolean {
  return mes >= 1 && mes <= 12 && dia >= 1 && dia <= diasNoMes(ano, mes);
}

function doisDigitos(n: number): string {
  return String(n).padStart(2, "0");
}

function partes(data: string): { ano: string; mes: string; dia: string } {
  return { ano: data.slice(0, 4), mes: data.slice(5, 7), dia: data.slice(8, 10) };
}

/**
 * Data AAAA-MM-DD válida no calendário, ou null. Só aceita texto exatamente nesse formato
 * (com espaços em volta tolerados); o ano 0000 é recusado porque o PostgreSQL não o guarda.
 */
export function dataValida(v: unknown): string | null {
  if (typeof v !== "string") return null;
  const t = v.trim();
  const m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(t);
  if (!m) return null;
  const ano = Number(m[1]);
  if (ano < 1 || !existeNoCalendario(ano, Number(m[2]), Number(m[3]))) return null;
  return t;
}

/**
 * Confere as quatro colunas vindas de fora. Qualificador ou precisão desconhecidos viram
 * "exata" e "dia"; data inválida vira null; o fim da faixa só sobrevive com "entre".
 */
export function normalizarData(bruta: DataAproxBruta): DataAprox {
  const qualificador = ehQualificador(bruta.qualificador) ? bruta.qualificador : "exata";
  const precisao = ehPrecisao(bruta.precisao) ? bruta.precisao : "dia";
  return {
    data: dataValida(bruta.data),
    qualificador,
    precisao,
    ate: qualificador === "entre" ? dataValida(bruta.ate) : null,
  };
}

/**
 * Junta as quatro colunas de um campo de data numa linha do banco:
 * `dataDoCampo(pessoa, "dataNascimento")` lê `dataNascimento`, `dataNascimentoQualificador`,
 * `dataNascimentoPrecisao` e `dataNascimentoAte`.
 */
export function dataDoCampo(linha: Readonly<Record<string, unknown>>, campo: string): DataAprox {
  return normalizarData({
    data: linha[campo],
    qualificador: linha[`${campo}Qualificador`],
    precisao: linha[`${campo}Precisao`],
    ate: linha[`${campo}Ate`],
  });
}

/** Só a data conforme a precisão: "22/02/1882", "fev. 1882" ou "1882". */
function formatarBase(data: string | null, precisao: Precisao): string {
  if (!data) return "";
  const p = partes(data);
  const ano = String(Number(p.ano));
  if (precisao === "ano") return ano;
  if (precisao === "mes") return `${MESES_ABREV[Number(p.mes) - 1]} ${ano}`;
  return `${p.dia}/${p.mes}/${p.ano}`;
}

/**
 * Formatação única de datas: "22/02/1882", "fev. 1882", "1882", "c. 1824", "antes de 1890",
 * "depois de 1890", "entre 1880 e 1885". Sem data devolve "".
 */
export function formatarData(d: DataAproxBruta): string {
  const v = normalizarData(d);
  const base = formatarBase(v.data, v.precisao);
  if (base === "") return "";
  switch (v.qualificador) {
    case "cerca":
      return `c. ${base}`;
    case "antes":
      return `antes de ${base}`;
    case "depois":
      return `depois de ${base}`;
    case "entre": {
      const fim = formatarBase(v.ate, v.precisao);
      return fim !== "" ? `entre ${base} e ${fim}` : base;
    }
    default:
      return base;
  }
}

/**
 * Forma curta, só com o ano, para cards e listas: "1824", "c. 1824", "ant. 1890", "dep. 1890",
 * "1880/1885". Sem data devolve "".
 */
export function formatarAno(d: DataAproxBruta): string {
  const v = normalizarData(d);
  if (!v.data) return "";
  const ano = Number(partes(v.data).ano);
  switch (v.qualificador) {
    case "cerca":
      return `c. ${ano}`;
    case "antes":
      return `ant. ${ano}`;
    case "depois":
      return `dep. ${ano}`;
    case "entre": {
      const anoFim = v.ate ? Number(partes(v.ate).ano) : ano;
      return anoFim !== ano ? `${ano}/${anoFim}` : String(ano);
    }
    default:
      return String(ano);
  }
}

/** Data estruturada para a interface, com `texto` e `ano` prontos (null quando não há data). */
export function dataParaExibicao(d: DataAproxBruta, local: string | null = null): DataExibicao {
  const v = normalizarData(d);
  const texto = formatarData(v);
  return {
    ...v,
    local,
    texto: texto !== "" ? texto : null,
    ano: texto !== "" ? formatarAno(v) : null,
  };
}

/** "c. 1824 – 1882", "1871 –", "– dep. 1890" ou "" quando não há nenhuma das duas datas. */
export function anosDeVida(nascimento: DataAproxBruta, falecimento: DataAproxBruta): string {
  const n = formatarAno(nascimento);
  const f = formatarAno(falecimento);
  if (n === "" && f === "") return "";
  return `${n} – ${f}`.trim();
}

/** Primeiro e último dia do período que a data cobre na precisão dada. */
function periodo(data: string, precisao: Precisao): [string, string] {
  const p = partes(data);
  if (precisao === "ano") return [`${p.ano}-01-01`, `${p.ano}-12-31`];
  if (precisao === "mes") {
    const ultimo = diasNoMes(Number(p.ano), Number(p.mes));
    return [`${p.ano}-${p.mes}-01`, `${p.ano}-${p.mes}-${doisDigitos(ultimo)}`];
  }
  return [data, data];
}

/**
 * Intervalo [mínimo, máximo] (AAAA-MM-DD) em que a data pode estar. null quando não dá para
 * comparar: sem data, ou "cerca de", que não tem limites.
 */
export function intervaloDaData(d: DataAproxBruta): [string, string] | null {
  const v = normalizarData(d);
  if (!v.data) return null;
  const [inicio, fim] = periodo(v.data, v.precisao);
  switch (v.qualificador) {
    case "cerca":
      return null;
    case "antes":
      return ["0000-01-01", inicio];
    case "depois":
      return [fim, "9999-12-31"];
    case "entre":
      return [inicio, v.ate ? periodo(v.ate, v.precisao)[1] : fim];
    default:
      return [inicio, fim];
  }
}

/**
 * A data `a` é anterior à data `b`?
 *   "antes"         certamente anterior: os dois intervalos existem e não se sobrepõem
 *   "talvez_antes"  parece anterior: alguma é "cerca de" ou os intervalos se sobrepõem, mas o
 *                   valor gravado de `a` é menor (serve para aviso, não para erro)
 *   null            não é anterior, ou falta alguma das datas
 */
export function compararDatas(a: DataAproxBruta, b: DataAproxBruta): "antes" | "talvez_antes" | null {
  const va = normalizarData(a);
  const vb = normalizarData(b);
  if (!va.data || !vb.data) return null;
  const ia = intervaloDaData(va);
  const ib = intervaloDaData(vb);
  // AAAA-MM-DD com ano de quatro dígitos ordena igual como texto e como data.
  if (ia && ib && ia[1] < ib[0]) return "antes";
  if (va.data < vb.data) return "talvez_antes";
  return null;
}

/** Resultado de `lerDataTexto`: vazio não é erro (`data` null e `erro` false). */
export interface DataTextoLida {
  data: string | null;
  precisao: Precisao | null;
  erro: boolean;
}

/**
 * Interpreta o texto digitado: "22/02/1882", "02/1882", "1882", "1882-02-22" ou "1882-02".
 * Dia e mês aceitam um dígito; o separador pode ser barra, ponto ou hífen. O ano tem sempre
 * quatro dígitos e não pode ser menor que 1000.
 */
export function lerDataTexto(texto: unknown): DataTextoLida {
  const t = typeof texto === "string" ? texto.trim() : "";
  if (t === "") return { data: null, precisao: null, erro: false };
  const erro: DataTextoLida = { data: null, precisao: null, erro: true };

  let ano: number;
  let mes = 1;
  let dia = 1;
  let precisao: Precisao;
  let m: RegExpExecArray | null;
  if ((m = /^(\d{4})-(\d{1,2})-(\d{1,2})$/.exec(t))) {
    [ano, mes, dia] = [Number(m[1]), Number(m[2]), Number(m[3])];
    precisao = "dia";
  } else if ((m = /^(\d{1,2})[/.-](\d{1,2})[/.-](\d{4})$/.exec(t))) {
    [dia, mes, ano] = [Number(m[1]), Number(m[2]), Number(m[3])];
    precisao = "dia";
  } else if ((m = /^(\d{4})-(\d{1,2})$/.exec(t))) {
    [ano, mes] = [Number(m[1]), Number(m[2])];
    precisao = "mes";
  } else if ((m = /^(\d{1,2})[/.-](\d{4})$/.exec(t))) {
    [mes, ano] = [Number(m[1]), Number(m[2])];
    precisao = "mes";
  } else if ((m = /^(\d{4})$/.exec(t))) {
    ano = Number(m[1]);
    precisao = "ano";
  } else {
    return erro;
  }

  if (ano < 1000 || !existeNoCalendario(ano, mes, dia)) return erro;
  return { data: `${ano}-${doisDigitos(mes)}-${doisDigitos(dia)}`, precisao, erro: false };
}

/** Trunca AAAA-MM-DD para a precisão (mes: dia 01; ano: 01/01). */
export function truncarData(data: string | null, precisao: Precisao): string | null {
  if (!data) return null;
  if (precisao === "ano") return `${data.slice(0, 4)}-01-01`;
  if (precisao === "mes") return `${data.slice(0, 7)}-01`;
  return data;
}

/**
 * Valor da data no formato que o campo de texto do formulário aceita de volta:
 * "22/02/1882", "02/1882" ou "1882". Sem data devolve "".
 */
export function dataParaCampo(data: string | null, precisao: Precisao): string {
  const valida = dataValida(data);
  if (!valida) return "";
  const p = partes(valida);
  if (precisao === "ano") return p.ano;
  if (precisao === "mes") return `${p.mes}/${p.ano}`;
  return `${p.dia}/${p.mes}/${p.ano}`;
}

/**
 * Normaliza uma data aproximada vinda de formulário (`data` e `ate` como texto digitado).
 *
 * - A precisão gravada é a mais grosseira entre a escolhida e a digitada: digitar só o ano
 *   grava precisão "ano"; escolher "ano" e digitar a data completa guarda só o ano.
 * - "entre" exige o fim da faixa, posterior ao início; as duas pontas ficam na mesma precisão.
 * - Sem data, qualquer qualificador diferente de "exata" é erro.
 * - `rotulo` completa as mensagens ("Data de <rotulo> inválida"): use "nascimento",
 *   "falecimento", "início da união" etc.
 *
 * O `valor` é devolvido mesmo quando há erros, para o formulário poder reexibir o que foi
 * entendido; só grave quando `erros` vier vazio.
 */
export function lerDataAprox(
  entrada: DataAproxBruta,
  rotulo: string,
): { valor: DataAprox; erros: string[] } {
  const erros: string[] = [];

  // Ausente (null/undefined) cai no padrão; qualquer outro valor fora do catálogo é erro.
  let qualificador: Qualificador = "exata";
  if (ehQualificador(entrada.qualificador)) qualificador = entrada.qualificador;
  else if (entrada.qualificador != null) erros.push(`Qualificador da data de ${rotulo} inválido.`);

  let escolhida: Precisao = "dia";
  if (ehPrecisao(entrada.precisao)) escolhida = entrada.precisao;
  else if (entrada.precisao != null) erros.push(`Precisão da data de ${rotulo} inválida.`);

  const lido = lerDataTexto(entrada.data);
  if (lido.erro) {
    erros.push(`Data de ${rotulo} inválida (use DD/MM/AAAA, MM/AAAA ou AAAA).`);
    return { valor: { ...DATA_VAZIA }, erros };
  }
  if (!lido.data || !lido.precisao) {
    if (qualificador !== "exata") {
      erros.push(`Informe a data de ${rotulo} (ou deixe o qualificador como "Exata").`);
    }
    return { valor: { ...DATA_VAZIA }, erros };
  }

  const maisGrosseira = (a: Precisao, b: Precisao): Precisao =>
    ORDEM_PRECISAO[a] > ORDEM_PRECISAO[b] ? a : b;

  let precisao = maisGrosseira(lido.precisao, escolhida);
  let ate: string | null = null;
  if (qualificador === "entre") {
    const fim = lerDataTexto(entrada.ate);
    if (fim.erro) {
      erros.push(`Fim da faixa da data de ${rotulo} inválido.`);
    } else if (!fim.data || !fim.precisao) {
      erros.push(`Com "Entre", informe também o fim da faixa da data de ${rotulo}.`);
    } else {
      precisao = maisGrosseira(fim.precisao, precisao);
      ate = truncarData(fim.data, precisao);
    }
  }

  const data = truncarData(lido.data, precisao);
  if (ate !== null && data !== null && ate <= data) {
    erros.push(
      `Na data de ${rotulo}, o fim da faixa (${formatarBase(ate, precisao)}) deve ser posterior ao início (${formatarBase(data, precisao)}).`,
    );
  }
  return { valor: { data, qualificador, precisao, ate }, erros };
}
