import { describe, expect, it } from "vitest";
import {
  DATA_VAZIA,
  PRECISOES,
  QUALIFICADORES,
  anosDeVida,
  compararDatas,
  dataDoCampo,
  dataParaCampo,
  dataParaExibicao,
  dataValida,
  formatarAno,
  formatarData,
  intervaloDaData,
  lerDataAprox,
  lerDataTexto,
  normalizarData,
  truncarData,
  type DataAprox,
} from "./datas";

function d(
  data: string | null,
  qualificador: DataAprox["qualificador"] = "exata",
  precisao: DataAprox["precisao"] = "dia",
  ate: string | null = null,
): DataAprox {
  return { data, qualificador, precisao, ate };
}

describe("catálogos", () => {
  it("lista qualificadores e precisões na ordem do legado", () => {
    expect(QUALIFICADORES).toEqual(["exata", "cerca", "antes", "depois", "entre"]);
    expect(PRECISOES).toEqual(["dia", "mes", "ano"]);
  });
});

describe("dataValida", () => {
  it("aceita AAAA-MM-DD de calendário", () => {
    expect(dataValida("1882-02-22")).toBe("1882-02-22");
    expect(dataValida("  1882-02-22  ")).toBe("1882-02-22");
    expect(dataValida("2000-02-29")).toBe("2000-02-29");
    expect(dataValida("0999-12-31")).toBe("0999-12-31");
  });

  it("recusa vazio, formato errado e dia que não existe", () => {
    expect(dataValida("")).toBeNull();
    expect(dataValida("   ")).toBeNull();
    expect(dataValida(null)).toBeNull();
    expect(dataValida(undefined)).toBeNull();
    expect(dataValida("22/02/1882")).toBeNull();
    expect(dataValida("1882-2-22")).toBeNull();
    expect(dataValida("1882-02-30")).toBeNull();
    expect(dataValida("1900-02-29")).toBeNull(); // 1900 não é bissexto
    expect(dataValida("1882-13-01")).toBeNull();
    expect(dataValida("1882-00-10")).toBeNull();
    expect(dataValida("1882-02-22 00:00:00")).toBeNull();
    expect(dataValida("0000-01-01")).toBeNull();
    expect(dataValida(18820222)).toBeNull();
  });
});

describe("normalizarData", () => {
  it("sem nada devolve a data vazia", () => {
    expect(normalizarData({})).toEqual(DATA_VAZIA);
    expect(DATA_VAZIA).toEqual(d(null));
  });

  it("troca qualificador e precisão desconhecidos pelos padrões", () => {
    expect(
      normalizarData({ data: "1882-02-22", qualificador: "talvez", precisao: "seculo" }),
    ).toEqual(d("1882-02-22"));
    expect(normalizarData({ data: "1882-02-22", qualificador: null, precisao: null })).toEqual(
      d("1882-02-22"),
    );
  });

  it("data inválida vira null, mantendo qualificador e precisão", () => {
    expect(normalizarData({ data: "1882-02-31", qualificador: "cerca", precisao: "ano" })).toEqual(
      d(null, "cerca", "ano"),
    );
  });

  it("só guarda o fim da faixa quando o qualificador é 'entre'", () => {
    expect(
      normalizarData({ data: "1880-01-01", qualificador: "entre", precisao: "ano", ate: "1885-01-01" }),
    ).toEqual(d("1880-01-01", "entre", "ano", "1885-01-01"));
    expect(
      normalizarData({ data: "1880-01-01", qualificador: "cerca", precisao: "ano", ate: "1885-01-01" }),
    ).toEqual(d("1880-01-01", "cerca", "ano"));
    expect(
      normalizarData({ data: "1880-01-01", qualificador: "entre", precisao: "ano", ate: "lixo" }),
    ).toEqual(d("1880-01-01", "entre", "ano"));
  });
});

describe("dataDoCampo", () => {
  const linha = {
    dataNascimento: "1824-01-01",
    dataNascimentoQualificador: "cerca",
    dataNascimentoPrecisao: "ano",
    dataNascimentoAte: null,
    dataFalecimento: null,
    dataFalecimentoQualificador: "exata",
    dataFalecimentoPrecisao: "dia",
    dataFalecimentoAte: null,
  };

  it("junta as quatro colunas de um campo", () => {
    expect(dataDoCampo(linha, "dataNascimento")).toEqual(d("1824-01-01", "cerca", "ano"));
    expect(dataDoCampo(linha, "dataFalecimento")).toEqual(DATA_VAZIA);
  });

  it("campo ausente na linha devolve a data vazia", () => {
    expect(dataDoCampo({}, "dataInicio")).toEqual(DATA_VAZIA);
  });
});

describe("formatarData", () => {
  it("formata conforme a precisão", () => {
    expect(formatarData(d("1882-02-22"))).toBe("22/02/1882");
    expect(formatarData(d("1882-02-01", "exata", "mes"))).toBe("fev. 1882");
    expect(formatarData(d("1882-01-01", "exata", "ano"))).toBe("1882");
  });

  it("abrevia os doze meses (maio sem ponto)", () => {
    const meses = ["jan.", "fev.", "mar.", "abr.", "maio", "jun.", "jul.", "ago.", "set.", "out.", "nov.", "dez."];
    meses.forEach((nome, i) => {
      const mm = String(i + 1).padStart(2, "0");
      expect(formatarData(d(`1900-${mm}-01`, "exata", "mes"))).toBe(`${nome} 1900`);
    });
  });

  it("aplica o qualificador", () => {
    expect(formatarData(d("1824-01-01", "cerca", "ano"))).toBe("c. 1824");
    expect(formatarData(d("1890-01-01", "antes", "ano"))).toBe("antes de 1890");
    expect(formatarData(d("1890-01-01", "depois", "ano"))).toBe("depois de 1890");
    expect(formatarData(d("1880-01-01", "entre", "ano", "1885-01-01"))).toBe("entre 1880 e 1885");
    expect(formatarData(d("1882-02-01", "cerca", "mes"))).toBe("c. fev. 1882");
    expect(formatarData(d("1882-02-22", "antes", "dia"))).toBe("antes de 22/02/1882");
    expect(formatarData(d("1882-02-01", "entre", "mes", "1882-05-01"))).toBe(
      "entre fev. 1882 e maio 1882",
    );
    expect(formatarData(d("1882-02-22", "entre", "dia", "1882-03-01"))).toBe(
      "entre 22/02/1882 e 01/03/1882",
    );
  });

  it("'entre' sem fim da faixa mostra só o início", () => {
    expect(formatarData(d("1880-01-01", "entre", "ano"))).toBe("1880");
  });

  it("sem data devolve texto vazio, qualquer que seja o qualificador", () => {
    expect(formatarData(DATA_VAZIA)).toBe("");
    expect(formatarData(d(null, "cerca", "ano"))).toBe("");
    expect(formatarData({})).toBe("");
    expect(formatarData({ data: "não é data" })).toBe("");
  });

  it("aceita entrada solta e usa os padrões para o que for desconhecido", () => {
    expect(formatarData({ data: "1882-02-22" })).toBe("22/02/1882");
    expect(formatarData({ data: "1882-02-22", qualificador: "xx", precisao: "yy" })).toBe("22/02/1882");
  });

  it("ano abaixo de 1000: sem zeros à esquerda em mês/ano, com zeros no dia (como no legado)", () => {
    expect(formatarData(d("0999-01-01", "exata", "ano"))).toBe("999");
    expect(formatarData(d("0999-03-01", "exata", "mes"))).toBe("mar. 999");
    expect(formatarData(d("0999-03-05"))).toBe("05/03/0999");
  });

  it("não desloca o dia por fuso horário", () => {
    // 1º de janeiro e 31 de dezembro são os dias que um Date em UTC-3 empurraria de ano.
    expect(formatarData(d("1900-01-01"))).toBe("01/01/1900");
    expect(formatarData(d("1899-12-31"))).toBe("31/12/1899");
    expect(formatarAno(d("1900-01-01"))).toBe("1900");
  });
});

describe("formatarAno", () => {
  it("devolve a forma curta", () => {
    expect(formatarAno(d("1824-05-17"))).toBe("1824");
    expect(formatarAno(d("1824-01-01", "cerca", "ano"))).toBe("c. 1824");
    expect(formatarAno(d("1890-01-01", "antes", "ano"))).toBe("ant. 1890");
    expect(formatarAno(d("1890-01-01", "depois", "ano"))).toBe("dep. 1890");
    expect(formatarAno(d("1880-01-01", "entre", "ano", "1885-01-01"))).toBe("1880/1885");
  });

  it("'entre' dentro do mesmo ano ou sem fim mostra um ano só", () => {
    expect(formatarAno(d("1880-02-01", "entre", "mes", "1880-09-01"))).toBe("1880");
    expect(formatarAno(d("1880-01-01", "entre", "ano"))).toBe("1880");
  });

  it("sem data devolve texto vazio", () => {
    expect(formatarAno(DATA_VAZIA)).toBe("");
    expect(formatarAno(d(null, "antes", "ano"))).toBe("");
  });
});

describe("dataParaExibicao", () => {
  it("entrega as quatro colunas, o local e os textos prontos", () => {
    expect(dataParaExibicao(d("1824-01-01", "cerca", "ano"), "Trento")).toEqual({
      data: "1824-01-01",
      local: "Trento",
      qualificador: "cerca",
      precisao: "ano",
      ate: null,
      texto: "c. 1824",
      ano: "c. 1824",
    });
  });

  it("sem data, texto e ano são null", () => {
    expect(dataParaExibicao(DATA_VAZIA)).toEqual({
      data: null,
      local: null,
      qualificador: "exata",
      precisao: "dia",
      ate: null,
      texto: null,
      ano: null,
    });
  });
});

describe("anosDeVida", () => {
  const nasc = d("1824-01-01", "cerca", "ano");
  const falec = d("1882-03-04");

  it("combina nascimento e falecimento", () => {
    expect(anosDeVida(nasc, falec)).toBe("c. 1824 – 1882");
  });

  it("com um lado só, mantém o travessão", () => {
    expect(anosDeVida(d("1871-06-01"), DATA_VAZIA)).toBe("1871 –");
    expect(anosDeVida(DATA_VAZIA, d("1890-01-01", "depois", "ano"))).toBe("– dep. 1890");
  });

  it("sem nenhuma data devolve texto vazio", () => {
    expect(anosDeVida(DATA_VAZIA, DATA_VAZIA)).toBe("");
  });
});

describe("intervaloDaData", () => {
  it("data exata cobre o período da precisão", () => {
    expect(intervaloDaData(d("1882-02-22"))).toEqual(["1882-02-22", "1882-02-22"]);
    expect(intervaloDaData(d("1882-02-01", "exata", "mes"))).toEqual(["1882-02-01", "1882-02-28"]);
    expect(intervaloDaData(d("1884-02-01", "exata", "mes"))).toEqual(["1884-02-01", "1884-02-29"]);
    expect(intervaloDaData(d("1882-04-01", "exata", "mes"))).toEqual(["1882-04-01", "1882-04-30"]);
    expect(intervaloDaData(d("1882-12-01", "exata", "mes"))).toEqual(["1882-12-01", "1882-12-31"]);
    expect(intervaloDaData(d("1882-01-01", "exata", "ano"))).toEqual(["1882-01-01", "1882-12-31"]);
  });

  it("'antes' e 'depois' ficam abertos de um lado", () => {
    expect(intervaloDaData(d("1890-01-01", "antes", "ano"))).toEqual(["0000-01-01", "1890-01-01"]);
    expect(intervaloDaData(d("1890-01-01", "depois", "ano"))).toEqual(["1890-12-31", "9999-12-31"]);
  });

  it("'entre' vai do início do primeiro período ao fim do último", () => {
    expect(intervaloDaData(d("1880-01-01", "entre", "ano", "1885-01-01"))).toEqual([
      "1880-01-01",
      "1885-12-31",
    ]);
    expect(intervaloDaData(d("1880-01-01", "entre", "ano"))).toEqual(["1880-01-01", "1880-12-31"]);
  });

  it("'cerca' e data vazia não têm intervalo", () => {
    expect(intervaloDaData(d("1824-01-01", "cerca", "ano"))).toBeNull();
    expect(intervaloDaData(DATA_VAZIA)).toBeNull();
  });
});

describe("compararDatas", () => {
  it("'antes' quando os intervalos não se sobrepõem", () => {
    expect(compararDatas(d("1880-05-01"), d("1880-05-02"))).toBe("antes");
    expect(compararDatas(d("1880-01-01", "exata", "ano"), d("1881-01-01", "exata", "ano"))).toBe("antes");
    expect(compararDatas(d("1880-01-01", "antes", "ano"), d("1890-06-01"))).toBe("antes");
    expect(compararDatas(d("1850-01-01"), d("1890-01-01", "depois", "ano"))).toBe("antes");
    expect(
      compararDatas(d("1870-01-01", "entre", "ano", "1875-01-01"), d("1876-01-01", "exata", "ano")),
    ).toBe("antes");
  });

  it("'talvez_antes' quando há sobreposição mas o valor gravado é menor", () => {
    // ano 1880 inteiro x um dia de 1880
    expect(compararDatas(d("1880-01-01", "exata", "ano"), d("1880-06-15"))).toBe("talvez_antes");
    // faixa que contém a outra data
    expect(compararDatas(d("1870-01-01", "entre", "ano", "1880-01-01"), d("1875-03-01"))).toBe(
      "talvez_antes",
    );
    // "depois de 1850" pode ser depois de 1900, mas o valor gravado é menor
    expect(compararDatas(d("1850-01-01", "depois", "ano"), d("1900-01-01"))).toBe("talvez_antes");
  });

  it("'talvez_antes' quando alguma das datas é 'cerca de'", () => {
    expect(compararDatas(d("1800-01-01", "cerca", "ano"), d("1900-01-01"))).toBe("talvez_antes");
    expect(compararDatas(d("1800-01-01"), d("1900-01-01", "cerca", "ano"))).toBe("talvez_antes");
  });

  it("null quando não é anterior", () => {
    expect(compararDatas(d("1880-05-02"), d("1880-05-01"))).toBeNull();
    expect(compararDatas(d("1880-05-01"), d("1880-05-01"))).toBeNull();
    expect(compararDatas(d("1900-01-01", "cerca", "ano"), d("1800-01-01"))).toBeNull();
    expect(compararDatas(d("1900-01-01", "antes", "ano"), d("1850-01-01"))).toBeNull();
  });

  it("limite de 'antes de' encostado na outra data não conta como anterior (como no legado)", () => {
    expect(compararDatas(d("1890-01-01", "antes", "ano"), d("1890-01-01"))).toBeNull();
  });

  it("null quando falta alguma data", () => {
    expect(compararDatas(DATA_VAZIA, d("1880-01-01"))).toBeNull();
    expect(compararDatas(d("1880-01-01"), DATA_VAZIA)).toBeNull();
    expect(compararDatas(DATA_VAZIA, DATA_VAZIA)).toBeNull();
  });
});

describe("lerDataTexto", () => {
  it("lê dia, mês e ano nos formatos brasileiro e ISO", () => {
    expect(lerDataTexto("22/02/1882")).toEqual({ data: "1882-02-22", precisao: "dia", erro: false });
    expect(lerDataTexto("1882-02-22")).toEqual({ data: "1882-02-22", precisao: "dia", erro: false });
    expect(lerDataTexto("02/1882")).toEqual({ data: "1882-02-01", precisao: "mes", erro: false });
    expect(lerDataTexto("1882-02")).toEqual({ data: "1882-02-01", precisao: "mes", erro: false });
    expect(lerDataTexto("1882")).toEqual({ data: "1882-01-01", precisao: "ano", erro: false });
  });

  it("aceita um dígito, ponto ou hífen como separador e espaços em volta", () => {
    expect(lerDataTexto("2/2/1882").data).toBe("1882-02-02");
    expect(lerDataTexto("22.02.1882").data).toBe("1882-02-22");
    expect(lerDataTexto("22-02-1882").data).toBe("1882-02-22");
    expect(lerDataTexto("22/02-1882").data).toBe("1882-02-22");
    expect(lerDataTexto("2-1882")).toEqual({ data: "1882-02-01", precisao: "mes", erro: false });
    expect(lerDataTexto("1882-2-3").data).toBe("1882-02-03");
    expect(lerDataTexto("  1882  ").data).toBe("1882-01-01");
  });

  it("vazio não é erro", () => {
    const vazio = { data: null, precisao: null, erro: false };
    expect(lerDataTexto("")).toEqual(vazio);
    expect(lerDataTexto("   ")).toEqual(vazio);
    expect(lerDataTexto(null)).toEqual(vazio);
    expect(lerDataTexto(undefined)).toEqual(vazio);
  });

  it("marca erro em texto que não é data", () => {
    const erro = { data: null, precisao: null, erro: true };
    for (const t of [
      "abc",
      "22/02/82", // ano com dois dígitos
      "188",
      "18820",
      "30/02/1882", // dia que não existe
      "29/02/1900", // 1900 não é bissexto
      "31/04/1882",
      "00/02/1882",
      "13/1882",
      "00/1882",
      "0999", // ano abaixo de 1000
      "01/01/0999",
      "1882/02/22",
      "22 02 1882",
      "c. 1882",
    ]) {
      expect(lerDataTexto(t), t).toEqual(erro);
    }
  });

  it("aceita 29 de fevereiro em ano bissexto", () => {
    expect(lerDataTexto("29/02/1884").data).toBe("1884-02-29");
    expect(lerDataTexto("29/02/2000").data).toBe("2000-02-29");
  });
});

describe("truncarData e dataParaCampo", () => {
  it("trunca para a precisão", () => {
    expect(truncarData("1882-02-22", "dia")).toBe("1882-02-22");
    expect(truncarData("1882-02-22", "mes")).toBe("1882-02-01");
    expect(truncarData("1882-02-22", "ano")).toBe("1882-01-01");
    expect(truncarData(null, "ano")).toBeNull();
  });

  it("devolve o texto no formato que o campo de formulário aceita", () => {
    expect(dataParaCampo("1882-02-22", "dia")).toBe("22/02/1882");
    expect(dataParaCampo("1882-02-01", "mes")).toBe("02/1882");
    expect(dataParaCampo("1882-01-01", "ano")).toBe("1882");
    expect(dataParaCampo(null, "dia")).toBe("");
  });

  it("o texto do campo volta a ser a mesma data", () => {
    for (const [data, precisao] of [
      ["1882-02-22", "dia"],
      ["1882-02-01", "mes"],
      ["1882-01-01", "ano"],
    ] as const) {
      expect(lerDataTexto(dataParaCampo(data, precisao))).toEqual({ data, precisao, erro: false });
    }
  });
});

describe("lerDataAprox", () => {
  it("data completa e exata", () => {
    expect(lerDataAprox({ data: "22/02/1882" }, "nascimento")).toEqual({
      valor: d("1882-02-22"),
      erros: [],
    });
  });

  it("tudo vazio é uma data vazia, sem erro", () => {
    expect(lerDataAprox({}, "nascimento")).toEqual({ valor: DATA_VAZIA, erros: [] });
    expect(lerDataAprox({ data: "  ", qualificador: "exata", precisao: "ano" }, "nascimento")).toEqual({
      valor: DATA_VAZIA,
      erros: [],
    });
  });

  it("a precisão gravada é a mais grosseira entre a escolhida e a digitada", () => {
    // digitou só o ano com precisão 'dia' escolhida
    expect(lerDataAprox({ data: "1882", precisao: "dia" }, "nascimento").valor).toEqual(
      d("1882-01-01", "exata", "ano"),
    );
    // digitou mês/ano
    expect(lerDataAprox({ data: "02/1882" }, "nascimento").valor).toEqual(
      d("1882-02-01", "exata", "mes"),
    );
    // escolheu 'ano' e digitou a data completa: guarda só o ano
    expect(lerDataAprox({ data: "22/02/1882", precisao: "ano" }, "nascimento").valor).toEqual(
      d("1882-01-01", "exata", "ano"),
    );
    // escolheu 'mes' e digitou a data completa
    expect(lerDataAprox({ data: "22/02/1882", precisao: "mes" }, "nascimento").valor).toEqual(
      d("1882-02-01", "exata", "mes"),
    );
    // escolheu 'mes' e digitou só o ano
    expect(lerDataAprox({ data: "1882", precisao: "mes" }, "nascimento").valor).toEqual(
      d("1882-01-01", "exata", "ano"),
    );
  });

  it("guarda o qualificador", () => {
    for (const q of ["cerca", "antes", "depois"] as const) {
      expect(lerDataAprox({ data: "1824", qualificador: q }, "nascimento")).toEqual({
        valor: d("1824-01-01", q, "ano"),
        erros: [],
      });
    }
  });

  it("ignora o fim da faixa quando o qualificador não é 'entre', mesmo que seja lixo", () => {
    expect(lerDataAprox({ data: "1824", qualificador: "cerca", ate: "lixo" }, "nascimento")).toEqual({
      valor: d("1824-01-01", "cerca", "ano"),
      erros: [],
    });
  });

  it("data ilegível devolve a data vazia com erro", () => {
    expect(lerDataAprox({ data: "31/02/1882", qualificador: "cerca" }, "nascimento")).toEqual({
      valor: DATA_VAZIA,
      erros: ["Data de nascimento inválida (use DD/MM/AAAA, MM/AAAA ou AAAA)."],
    });
  });

  it("qualificador diferente de 'exata' sem data é erro", () => {
    for (const q of ["cerca", "antes", "depois", "entre"]) {
      expect(lerDataAprox({ data: "", qualificador: q }, "falecimento"), q).toEqual({
        valor: DATA_VAZIA,
        erros: ['Informe a data de falecimento (ou deixe o qualificador como "Exata").'],
      });
    }
  });

  it("sem data e 'exata', um fim de faixa preenchido é descartado em silêncio", () => {
    expect(lerDataAprox({ data: "", ate: "1885" }, "nascimento")).toEqual({
      valor: DATA_VAZIA,
      erros: [],
    });
  });

  it("qualificador ou precisão desconhecidos são erro e caem nos padrões", () => {
    expect(lerDataAprox({ data: "1882", qualificador: "talvez", precisao: "seculo" }, "nascimento")).toEqual({
      valor: d("1882-01-01", "exata", "ano"),
      erros: [
        "Qualificador da data de nascimento inválido.",
        "Precisão da data de nascimento inválida.",
      ],
    });
    // texto vazio não é o mesmo que ausente
    expect(lerDataAprox({ data: "1882", qualificador: "" }, "nascimento").erros).toEqual([
      "Qualificador da data de nascimento inválido.",
    ]);
    expect(lerDataAprox({ data: "1882", qualificador: null, precisao: null }, "nascimento").erros).toEqual(
      [],
    );
  });

  it("acumula o erro de catálogo com o erro de data", () => {
    expect(lerDataAprox({ data: "xx", qualificador: "talvez" }, "nascimento").erros).toEqual([
      "Qualificador da data de nascimento inválido.",
      "Data de nascimento inválida (use DD/MM/AAAA, MM/AAAA ou AAAA).",
    ]);
  });

  describe("'entre'", () => {
    it("lê a faixa", () => {
      expect(lerDataAprox({ data: "1880", qualificador: "entre", ate: "1885" }, "nascimento")).toEqual({
        valor: d("1880-01-01", "entre", "ano", "1885-01-01"),
        erros: [],
      });
      expect(
        lerDataAprox({ data: "10/02/1880", qualificador: "entre", ate: "20/02/1880" }, "nascimento"),
      ).toEqual({ valor: d("1880-02-10", "entre", "dia", "1880-02-20"), erros: [] });
    });

    it("as duas pontas ficam na precisão mais grosseira das três", () => {
      // fim mais grosseiro que o início
      expect(
        lerDataAprox({ data: "10/02/1880", qualificador: "entre", ate: "1885" }, "nascimento").valor,
      ).toEqual(d("1880-01-01", "entre", "ano", "1885-01-01"));
      // início mais grosseiro que o fim
      expect(
        lerDataAprox({ data: "1880", qualificador: "entre", ate: "15/06/1885" }, "nascimento").valor,
      ).toEqual(d("1880-01-01", "entre", "ano", "1885-01-01"));
      // precisão escolhida mais grosseira que as duas
      expect(
        lerDataAprox(
          { data: "10/02/1880", qualificador: "entre", precisao: "mes", ate: "15/06/1885" },
          "nascimento",
        ).valor,
      ).toEqual(d("1880-02-01", "entre", "mes", "1885-06-01"));
    });

    it("exige o fim da faixa", () => {
      expect(lerDataAprox({ data: "1880", qualificador: "entre" }, "nascimento")).toEqual({
        valor: d("1880-01-01", "entre", "ano"),
        erros: ['Com "Entre", informe também o fim da faixa da data de nascimento.'],
      });
    });

    it("fim da faixa ilegível é erro", () => {
      expect(lerDataAprox({ data: "1880", qualificador: "entre", ate: "xx" }, "nascimento")).toEqual({
        valor: d("1880-01-01", "entre", "ano"),
        erros: ["Fim da faixa da data de nascimento inválido."],
      });
    });

    it("o fim precisa ser posterior ao início", () => {
      expect(lerDataAprox({ data: "1885", qualificador: "entre", ate: "1880" }, "nascimento")).toEqual({
        valor: d("1885-01-01", "entre", "ano", "1880-01-01"),
        erros: ["Na data de nascimento, o fim da faixa (1880) deve ser posterior ao início (1885)."],
      });
      expect(
        lerDataAprox({ data: "1880", qualificador: "entre", ate: "1880" }, "nascimento").erros,
      ).toEqual(["Na data de nascimento, o fim da faixa (1880) deve ser posterior ao início (1880)."]);
    });

    it("pontas diferentes que ficam iguais depois de truncar também são erro", () => {
      expect(
        lerDataAprox({ data: "03/1882", qualificador: "entre", ate: "1882" }, "nascimento").erros,
      ).toEqual(["Na data de nascimento, o fim da faixa (1882) deve ser posterior ao início (1882)."]);
    });

    it("a mensagem usa a precisão final", () => {
      expect(
        lerDataAprox({ data: "05/1882", qualificador: "entre", ate: "02/1882" }, "falecimento").erros,
      ).toEqual([
        "Na data de falecimento, o fim da faixa (fev. 1882) deve ser posterior ao início (maio 1882).",
      ]);
    });
  });

  it("o que foi lido formata de volta sem perda", () => {
    const { valor } = lerDataAprox({ data: "1880", qualificador: "entre", ate: "1885" }, "nascimento");
    expect(formatarData(valor)).toBe("entre 1880 e 1885");
    expect(formatarAno(valor)).toBe("1880/1885");
  });
});
