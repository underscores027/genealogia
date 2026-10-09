/*
 * Árvore genealógica navegável (estilo "Árvore Familiar" do FamilySearch), com layout próprio
 * sobre d3 7.x (d3.tree para posicionar, d3.zoom para arrastar/zoom).
 * Usada por arvore.php (página pública; aqui é a visão "Paisagem" de assets/js/visoes.js).
 * Também exporta o que as outras visões reaproveitam: Grafo (mescla + paisDe/familias),
 * criarPainel (painel de resumo), configurarBusca ("Ir para pessoa"), criarStatus e util
 * (esc, el, chamarApi, datas).
 *
 * Dados: só a API em api/ (leitura; a única gravação é o "+" do card, para quem está logado:
 * POST api/adicionar_parente.php). Nunca carrega a tabela inteira:
 *   foco               → api/pessoa.php?id=X + api/descendentes.php?id=X&geracoes=1
 *   seta ▲ (pais)      → api/ancestrais.php?id=X&geracoes=1
 *   seta ◀ ▶ (irmãos)  → api/pessoa.php?id=X (lista "irmaos")
 *   seta ▼ (filhos)    → api/descendentes.php?id=X&geracoes=1
 *   seta ♥ (cônjuges)  → api/pessoa.php?id=X
 *   painel de resumo   → api/pessoa.php?id=X (cache; não altera o gráfico)
 *   "Ir para pessoa"   → api/busca.php?q=...
 * As respostas (formato padrão: pessoas/filiacoes/unioes normalizadas) são mescladas por id;
 * o que aparece na tela depende só do que foi aberto (setas), não de tudo o que já veio.
 *
 * Segurança: o card é montado com innerHTML; todo texto vindo do banco passa por esc().
 * O painel é montado com createElement/textContent.
 */
(function () {
    'use strict';

    var FLAGS = ['tem_mais_ancestrais', 'tem_mais_descendentes', 'tem_mais_conjuges'];
    // gapLinha: entre cards da mesma geração; gapGeracao: entre gerações (h*: orientação horizontal)
    var DIM = {
        // card em pé, na proporção de uma carta de baralho (5:7)
        normal:   { w: 150, h: 210, gapLinha: 26, gapGeracao: 76, hGapLinha: 34, hGapGeracao: 70, escalaMin: 0.6 },
        compacto: { w: 110, h: 154, gapLinha: 16, gapGeracao: 58, hGapLinha: 30, hGapGeracao: 48, escalaMin: 0.75 }
    };
    var ICONE_SEXO = { M: 'fa-person', F: 'fa-person-dress', D: 'fa-user' };
    var NAIPE_SEXO = { M: 'fa-mars', F: 'fa-venus', D: 'fa-genderless' }; // "naipe" na barra do nome da carta
    var ROTULO_SEXO = { M: 'Masculino', F: 'Feminino', D: 'Sexo não informado' };

    // ------------------------------------------------------------------ utilidades
    function esc(v) {
        return String(v === null || v === undefined ? '' : v).replace(/[&<>"'`]/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;', '`': '&#96;' }[c];
        });
    }
    function sexo(p) { return p && (p.sexo === 'M' || p.sexo === 'F') ? p.sexo : 'D'; }
    // Datas: o texto vem pronto da API (formatação única em includes/genealogia.php:
    // gen_formatar_data → "c. 1824", "antes de 1890", "entre 1880 e 1885", "mar. 1888",
    // "22/02/1882"; forma curta em .ano / .anos). Aqui só há um fallback para dados antigos.
    function ano(iso) { return iso ? String(iso).slice(0, 4) : ''; }
    function anosVida(p) {
        if (!p) return '';
        if (typeof p.anos === 'string') return p.anos;
        var n = ano(p.nascimento && p.nascimento.data), f = ano(p.falecimento && p.falecimento.data);
        if (!n && !f) return '';
        return (n + ' – ' + f).trim();
    }
    function textoData(d) {
        if (!d) return '';
        if (d.texto) return d.texto;
        var m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(d.data || '');
        return m ? m[3] + '/' + m[2] + '/' + m[1] : '';
    }
    function el(tag, attrs, filhos) {
        var e = document.createElement(tag);
        if (attrs) Object.keys(attrs).forEach(function (k) {
            if (k === 'texto') e.textContent = attrs[k];
            else if (k === 'classe') e.className = attrs[k];
            else if (k.indexOf('on') === 0) e.addEventListener(k.slice(2), attrs[k]);
            else e.setAttribute(k, attrs[k]);
        });
        (filhos || []).forEach(function (f) { if (f) e.appendChild(typeof f === 'string' ? document.createTextNode(f) : f); });
        return e;
    }
    function anexar(pai, filho) { if (filho) pai.appendChild(filho); }
    function icone(classes) { return el('i', { classe: classes, 'aria-hidden': 'true' }); }

    function chamarApi(base, endpoint, params, sinal) {
        var qs = Object.keys(params || {}).map(function (k) {
            return encodeURIComponent(k) + '=' + encodeURIComponent(params[k]);
        }).join('&');
        return fetch(base + endpoint + (qs ? '?' + qs : ''), {
            headers: { Accept: 'application/json' }, credentials: 'same-origin', signal: sinal
        }).then(lerResposta);
    }
    /** POST (form-urlencoded) para os endpoints de gravação. O erro traz .erros (lista) quando a API manda. */
    function enviarApi(base, endpoint, dados) {
        return fetch(base + endpoint, {
            method: 'POST', headers: { Accept: 'application/json' }, credentials: 'same-origin',
            body: new URLSearchParams(dados || {})
        }).then(lerResposta);
    }
    function lerResposta(r) {
        return r.json().catch(function () { return null; }).then(function (j) {
            if (!r.ok) {
                var err = new Error((j && j.erro) || ('Falha ao consultar a árvore (HTTP ' + r.status + ').'));
                err.status = r.status;
                err.erros = j && j.erros;
                throw err;
            }
            if (!j) throw new Error('Resposta inválida da API.');
            return j;
        });
    }

    // ------------------------------------------------------------------ grafo mesclado
    function Grafo() {
        this.pessoas = new Map();   // "id" -> card da API (flags mescladas)
        this.filiacoes = new Map(); // id -> filiação
        this.unioes = new Map();    // id -> união
    }

    /**
     * Junta uma resposta da API. Flags tem_mais_*: valem para a resposta isolada, então
     * a mescla é um E lógico — "false" numa resposta garante que todos aqueles parentes
     * vieram nela (e portanto já estão no grafo).
     */
    Grafo.prototype.mesclar = function (resp) {
        var self = this;
        (resp.pessoas || []).forEach(function (p) {
            var id = String(p.id), ant = self.pessoas.get(id);
            var novo = Object.assign({}, ant || {}, p);
            if (ant) FLAGS.forEach(function (k) { novo[k] = !!(ant[k] && p[k]); });
            self.pessoas.set(id, novo);
        });
        (resp.filiacoes || []).forEach(function (f) {
            var ant = self.filiacoes.get(f.id);
            if (!ant) { self.filiacoes.set(f.id, Object.assign({}, f)); return; }
            ['pai', 'mae'].forEach(function (papel) {
                if (f[papel + '_id']) { ant[papel + '_id'] = f[papel + '_id']; ant['tipo_' + papel] = f['tipo_' + papel]; }
            });
            ant.principal = !!(ant.principal || f.principal);
        });
        (resp.unioes || []).forEach(function (u) { self.unioes.set(u.id, Object.assign({}, u)); });
    };

    /** Filiações de um filho, a principal primeiro (depois a de menor id). */
    Grafo.prototype.filiacoesDoFilho = function (id) {
        id = String(id);
        return Array.from(this.filiacoes.values()).filter(function (f) { return String(f.filho_id) === id; })
            .sort(function (a, b) { return (b.principal ? 1 : 0) - (a.principal ? 1 : 0) || a.id - b.id; });
    };

    /**
     * Pai e mãe da filiação principal presente no grafo ({pai, mae}: "id" ou null), com o papel
     * de cada um (usado pelo leque: posição do pai à esquerda/acima, da mãe à direita/abaixo).
     */
    Grafo.prototype.paisDe = function (id) {
        var self = this, lista = this.filiacoesDoFilho(id);
        function presente(g) { var s = g ? String(g) : null; return s && s !== String(id) && self.pessoas.has(s) ? s : null; }
        for (var i = 0; i < lista.length; i++) {
            var pai = presente(lista[i].pai_id), mae = presente(lista[i].mae_id);
            if (pai || mae) return { pai: pai, mae: pai === mae ? null : mae, filiacao: lista[i] };
        }
        return { pai: null, mae: null, filiacao: null };
    };

    /**
     * Cônjuges e filhos de uma pessoa, agrupados como gen_familias() do PHP:
     * uma entrada por união (ordem de data de início), depois o outro genitor de filhos sem
     * união registrada, e por fim os filhos sem o outro genitor. Filhos por nascimento.
     * Cada grupo: {uniao|null, conjuge: "id"|null, filhos: [{id, tipo}]}; tipo = vínculo com a pessoa.
     */
    Grafo.prototype.familias = function (id) {
        var self = this, grupos = [], porConjuge = new Map(), semOutro = [], vistos = new Set();
        id = String(id);
        Array.from(this.unioes.values()).filter(function (u) {
            return String(u.pessoa1_id) === id || String(u.pessoa2_id) === id;
        }).sort(function (a, b) {
            return String(a.data_inicio || '9999').localeCompare(String(b.data_inicio || '9999')) || a.id - b.id;
        }).forEach(function (u) {
            var c = String(String(u.pessoa1_id) === id ? u.pessoa2_id : u.pessoa1_id);
            if (c === id || !self.pessoas.has(c) || porConjuge.has(c)) return;
            porConjuge.set(c, grupos.length);
            grupos.push({ uniao: u, conjuge: c, filhos: [] });
        });
        Array.from(this.filiacoes.values()).sort(function (a, b) { return a.id - b.id; }).forEach(function (f) {
            var filho = String(f.filho_id), papel = String(f.pai_id) === id ? 'pai' : String(f.mae_id) === id ? 'mae' : null;
            if (!papel || filho === id || vistos.has(filho) || !self.pessoas.has(filho)) return;
            vistos.add(filho);
            var outro = papel === 'pai' ? f.mae_id : f.pai_id;
            outro = outro && self.pessoas.has(String(outro)) ? String(outro) : null;
            var item = { id: filho, tipo: f['tipo_' + papel] || 'biologico', papel: papel };
            if (!outro) { semOutro.push(item); return; }
            if (!porConjuge.has(outro)) { porConjuge.set(outro, grupos.length); grupos.push({ uniao: null, conjuge: outro, filhos: [] }); }
            grupos[porConjuge.get(outro)].filhos.push(item);
        });
        if (semOutro.length) grupos.push({ uniao: null, conjuge: null, filhos: semOutro });
        grupos.forEach(function (g) {
            g.filhos.sort(function (a, b) {
                var na = (self.pessoas.get(a.id).nascimento || {}).data, nb = (self.pessoas.get(b.id).nascimento || {}).data;
                if (!na !== !nb) return na ? -1 : 1;
                return (na && nb && na !== nb ? (na < nb ? -1 : 1) : 0) || a.id - b.id;
            });
        });
        return grupos;
    };

    // ------------------------------------------------------------------ status (mensagem sobre o palco)
    function criarStatus(elemento) {
        var t = null;
        return function status(msg, erro, duracao) {
            if (!elemento) { if (erro) console.warn(msg); return; }
            clearTimeout(t);
            elemento.textContent = msg || '';
            elemento.classList.toggle('visivel', !!msg);
            elemento.classList.toggle('erro', !!erro);
            if (msg && duracao) t = setTimeout(function () { status(''); }, duracao);
        };
    }

    // ------------------------------------------------------------------ painel de resumo
    /**
     * Painel lateral (bottom sheet no celular) com o resumo de api/pessoa.php. Compartilhado
     * pelas visões (paisagem, descendência, leque).
     * op: painel (aside), api, logado, focar(id) → Promise, focoAtual() → "id",
     *     sempreFocar (mostra "Focar" até na pessoa em foco), cache (Map opcional).
     */
    function criarPainel(op) {
        var cache = op.cache || new Map();
        var painelId = null;
        var corpo = op.painel.querySelector('.arv-painel-corpo');

        function abrir(id) {
            id = String(id);
            painelId = id;
            op.painel.classList.add('aberto');
            op.painel.setAttribute('aria-hidden', 'false');
            if (cache.has(id)) { render(cache.get(id)); return; }
            corpo.textContent = '';
            corpo.appendChild(el('div', { classe: 'text-center text-muted py-4' }, [el('span', { classe: 'spinner-border spinner-border-sm me-2' }), 'Carregando…']));
            chamarApi(op.api, 'pessoa.php', { id: id }).then(function (r) {
                cache.set(id, r);
                if (painelId === id) render(r);
            }).catch(function (e) {
                if (painelId !== id) return;
                corpo.textContent = '';
                corpo.appendChild(el('div', { classe: 'alert alert-danger small mt-2', texto: e.message }));
            });
        }
        function fechar() {
            painelId = null;
            op.painel.classList.remove('aberto');
            op.painel.setAttribute('aria-hidden', 'true');
        }
        /** Guarda uma resposta de pessoa.php e redesenha se o painel mostra essa pessoa. */
        function atualizar(id, r) {
            id = String(id);
            cache.set(id, r);
            if (painelId === id) render(r);
        }
        /** Redesenha o painel aberto (ex.: o foco mudou e o botão "Focar" deve sumir/aparecer). */
        function renovar() { if (painelId && cache.has(painelId)) render(cache.get(painelId)); }

        function render(r) {
            var p = r.pessoa, id = String(p.id), s = sexo(p);
            var nomes = new Map();
            (r.pessoas || []).forEach(function (x) { nomes.set(x.id, x); });
            function linkPessoa(pid, extra) {
                var x = nomes.get(pid);
                if (!x) return null;
                var b = el('button', { type: 'button', classe: 'arv-pessoa-link', onclick: function () { abrir(pid); } }, [
                    el('span', { classe: 'arv-ponto arv-cor-' + sexo(x) }), x.nome
                ]);
                var anos = anosVida(x);
                return el('li', null, [b, anos ? el('span', { classe: 'arv-tag', texto: anos }) : null, extra ? el('span', { classe: 'arv-tag', texto: extra }) : null]);
            }
            function fato(ic, rot, ev) {
                if (!ev || (!ev.data && !ev.local)) return null;
                var t = rot + ': ' + (ev.data ? textoData(ev) : '?') + (ev.local ? ' — ' + ev.local : '');
                return el('div', { classe: 'arv-fato' }, [icone('fas ' + ic + ' me-1'), t]);
            }
            corpo.textContent = '';

            var foto = el('div', { classe: 'arv-painel-foto arv-cor-' + s });
            if (p.foto) {
                var img = el('img', { src: p.foto, alt: 'Foto de ' + p.nome });
                img.addEventListener('error', function () { img.replaceWith(icone('fas ' + ICONE_SEXO[s])); }, { once: true });
                foto.appendChild(img);
            } else foto.appendChild(icone('fas ' + ICONE_SEXO[s]));
            corpo.appendChild(el('div', { classe: 'arv-painel-cab' }, [foto, el('div', null, [
                el('h5', { id: 'arvPainelTitulo', texto: p.nome }),
                el('div', { classe: 'text-muted small', texto: [anosVida(p), ROTULO_SEXO[s]].filter(Boolean).join(' · ') })
            ])]));

            var fatos = [fato('fa-baby', 'Nascimento', p.nascimento), fato('fa-cross', 'Falecimento', p.falecimento)].filter(Boolean);
            fatos.forEach(function (f) { corpo.appendChild(f); });
            // outros eventos (imigração, batismo...), já ordenados por data pela API
            (r.eventos || []).forEach(function (ev) {
                var partes = [ev.data && ev.data.texto ? ev.data.texto : '', ev.local || ''].filter(Boolean).join(' — ');
                var linha = el('div', { classe: 'arv-fato' }, [
                    icone('fas ' + (/^fa-[a-z0-9-]+$/.test(ev.icone || '') ? ev.icone : 'fa-calendar-day') + ' me-1'),
                    (ev.rotulo_tipo || 'Evento') + (partes ? ': ' + partes : '')
                ]);
                if (ev.descricao) linha.appendChild(el('div', { classe: 'arv-tag ms-3', texto: ev.descricao }));
                if (ev.documento) linha.appendChild(el('div', { classe: 'arv-tag ms-3', texto: 'Fonte: ' + ev.documento.rotulo }));
                corpo.appendChild(linha);
            });

            // ações
            var acoes = el('div', { classe: 'arv-painel-acoes' });
            if (id !== op.focoAtual() || op.sempreFocar) {
                acoes.appendChild(el('button', { type: 'button', classe: 'btn btn-sm btn-primary', onclick: function () {
                    if (window.matchMedia('(max-width: 767.98px)').matches) fechar();
                    Promise.resolve(op.focar(id)).catch(function () {});
                } }, [icone('fas fa-crosshairs me-1'), 'Focar nesta pessoa']));
            }
            acoes.appendChild(el('a', { classe: 'btn btn-sm btn-outline-secondary', href: 'perfil.php?id=' + encodeURIComponent(id) }, [icone('fas fa-id-card me-1'), 'Ver perfil']));
            if (op.logado) acoes.appendChild(el('a', { classe: 'btn btn-sm btn-outline-success', href: 'editar_pessoa.php?id=' + encodeURIComponent(id) }, [icone('fas fa-pen me-1'), 'Editar']));
            corpo.appendChild(acoes);

            // pais (uma entrada por filiação: biológicos, adotivos…)
            corpo.appendChild(el('h6', { texto: 'Pais' }));
            if (!r.pais || !r.pais.length) corpo.appendChild(el('div', { classe: 'arv-tag ms-0', texto: 'Nenhum pai ou mãe registrado.' }));
            else {
                var ul = el('ul', { classe: 'arv-lista' });
                r.pais.forEach(function (f) {
                    if (f.pai_id) anexar(ul, linkPessoa(f.pai_id, f.tipo_pai !== 'biologico' ? '(' + f.rotulo_pai + ')' : ''));
                    if (f.mae_id) anexar(ul, linkPessoa(f.mae_id, f.tipo_mae !== 'biologico' ? '(' + f.rotulo_mae + ')' : ''));
                });
                corpo.appendChild(ul);
            }

            // cônjuges e filhos de cada união
            corpo.appendChild(el('h6', { texto: 'Cônjuges e filhos' }));
            if (!r.familias || !r.familias.length) corpo.appendChild(el('div', { classe: 'arv-tag ms-0', texto: 'Nenhum cônjuge ou filho registrado.' }));
            else {
                r.familias.forEach(function (fam) {
                    var cab;
                    if (fam.conjuge_id) {
                        var extra = [fam.rotulo_tipo || (fam.uniao_id ? '' : 'sem união registrada'),
                            fam.data_inicio ? 'desde ' + (fam.data_inicio_texto || textoData({ data: fam.data_inicio })) : '',
                            fam.data_fim ? 'até ' + (fam.data_fim_texto || textoData({ data: fam.data_fim })) : ''].filter(Boolean).join(', ');
                        cab = linkPessoa(fam.conjuge_id, extra ? '(' + extra + ')' : '');
                    } else {
                        cab = el('li', { classe: 'text-muted small', texto: 'Filhos sem o outro genitor registrado' });
                    }
                    var ulF = el('ul', { classe: 'arv-lista' }, [cab]); // el() ignora null
                    if (fam.filhos && fam.filhos.length) {
                        var sub = el('ul', { classe: 'arv-lista-sub' });
                        fam.filhos.forEach(function (c) { anexar(sub, linkPessoa(c.id, c.tipo !== 'biologico' ? '(' + c.rotulo_tipo + ')' : '')); });
                        ulF.appendChild(el('li', null, [sub]));
                    } else if (fam.conjuge_id) {
                        ulF.appendChild(el('li', { classe: 'arv-tag ms-3', texto: 'Sem filhos registrados' }));
                    }
                    corpo.appendChild(ulF);
                });
            }

            corpo.appendChild(el('h6', { texto: 'Irmãos' }));
            if (!r.irmaos || !r.irmaos.length) corpo.appendChild(el('div', { classe: 'arv-tag ms-0', texto: 'Nenhum irmão registrado.' }));
            else {
                var ulI = el('ul', { classe: 'arv-lista' });
                r.irmaos.forEach(function (i) { anexar(ulI, linkPessoa(i.id, i.tipo === 'meio' ? '(meio-irmão/irmã)' : '')); });
                corpo.appendChild(ulI);
            }

            if (p.biografia) {
                corpo.appendChild(el('h6', { texto: 'Biografia' }));
                corpo.appendChild(el('div', { classe: 'arv-bio', texto: p.biografia }));
            }
        }

        op.painel.addEventListener('click', function (e) { if (e.target.closest('[data-arv-fechar]')) fechar(); });
        document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && painelId) fechar(); });

        return { abrir: abrir, fechar: fechar, atualizar: atualizar, renovar: renovar, cache: cache, aberto: function () { return painelId; } };
    }

    // ------------------------------------------------------------------ busca "Ir para pessoa"
    /** Autocomplete com api/busca.php. op: api, status(msg, erro, duracao), escolher(card). */
    function configurarBusca(input, op) {
        var lista = el('div', { classe: 'arv-sugestoes', role: 'listbox', id: 'arvSugestoes' });
        input.parentNode.appendChild(lista);
        input.setAttribute('autocomplete', 'off');
        input.setAttribute('role', 'combobox');
        input.setAttribute('aria-controls', 'arvSugestoes');
        input.setAttribute('aria-expanded', 'false');
        var t = null, ctrl = null, itens = [], ativo = -1;
        function fechar() { lista.classList.remove('visivel'); input.setAttribute('aria-expanded', 'false'); ativo = -1; }
        function marcar(i) {
            itens.forEach(function (b, j) { b.classList.toggle('ativo', j === i); b.setAttribute('aria-selected', j === i ? 'true' : 'false'); });
            ativo = i;
            if (itens[i]) itens[i].scrollIntoView({ block: 'nearest' });
        }
        function escolher(p) {
            fechar();
            input.value = '';
            input.blur();
            op.escolher(p);
        }
        function mostrar(pessoas, q) {
            lista.textContent = '';
            itens = [];
            if (!pessoas.length) lista.appendChild(el('div', { classe: 'arv-sug-vazio', texto: 'Ninguém encontrado para "' + q + '".' }));
            pessoas.forEach(function (p) {
                var b = el('button', { type: 'button', role: 'option', onclick: function () { escolher(p); } }, [
                    el('span', { classe: 'arv-ponto arv-cor-' + sexo(p), style: 'display:inline-block;width:9px;height:9px;border-radius:50%' }),
                    el('span', { texto: p.nome }),
                    el('span', { classe: 'arv-sug-anos', texto: anosVida(p) })
                ]);
                b.addEventListener('mousedown', function (e) { e.preventDefault(); }); // não perde o foco antes do clique
                itens.push(b);
                lista.appendChild(b);
            });
            lista.classList.add('visivel');
            input.setAttribute('aria-expanded', 'true');
            ativo = -1;
        }
        input.addEventListener('input', function () {
            clearTimeout(t);
            var q = input.value.trim();
            if (q.length < 2) { fechar(); return; }
            t = setTimeout(function () {
                if (ctrl) ctrl.abort();
                ctrl = window.AbortController ? new AbortController() : null;
                chamarApi(op.api, 'busca.php', { q: q, limite: 12 }, ctrl ? ctrl.signal : undefined)
                    .then(function (r) { if (input.value.trim() === q) mostrar(r.pessoas || [], q); })
                    .catch(function (e) { if (e.name !== 'AbortError') op.status(e.message, true, 5000); });
            }, 250);
        });
        input.addEventListener('keydown', function (e) {
            if (!lista.classList.contains('visivel')) return;
            if (e.key === 'ArrowDown') { e.preventDefault(); marcar(Math.min(itens.length - 1, ativo + 1)); }
            else if (e.key === 'ArrowUp') { e.preventDefault(); marcar(Math.max(0, ativo - 1)); }
            else if (e.key === 'Enter') { e.preventDefault(); var b = itens[ativo >= 0 ? ativo : 0]; if (b) b.click(); }
            else if (e.key === 'Escape') { fechar(); }
        });
        input.addEventListener('blur', function () { setTimeout(fechar, 150); });
    }

    // ------------------------------------------------------------------ diálogo "Adicionar parente"
    var RELACOES = [
        { id: 'pai', rotulo: 'Pai', icone: 'fa-person' },
        { id: 'mae', rotulo: 'Mãe', icone: 'fa-person-dress' },
        { id: 'conjuge', rotulo: 'Cônjuge', icone: 'fa-heart' },
        { id: 'filho', rotulo: 'Filho(a)', icone: 'fa-child' },
        { id: 'irmao', rotulo: 'Irmão(ã)', icone: 'fa-user-group' }
    ];
    /**
     * Diálogo (<dialog>, criado sob demanda no <body>) para adicionar um parente de uma pessoa:
     * escolhe o parentesco e cadastra alguém novo (nome, sexo, datas) ou vincula quem já existe.
     * Grava com POST api/adicionar_parente.php. op: api.
     * abrir(r, extras, aoSalvar): r = resposta de api/pessoa.php da pessoa; extras.conjugePreferido
     * ("id"|null) vem marcado como outro genitor; aoSalvar(resposta, relacao) após gravar.
     */
    function criarDialogoAdicionar(op) {
        var est = { r: null, relacao: null, modo: 'nova', existente: null, aoSalvar: null, enviando: false, bloqueio: {} };

        function campo(rotulo, controle, classe) {
            return el('div', { classe: classe || 'col-12' }, [el('label', { classe: 'form-label small mb-1', for: controle.id, texto: rotulo }), controle]);
        }
        function opcao(valor, texto) { return el('option', { value: valor, texto: texto }); }

        var titulo = el('h5', { classe: 'mb-0 fw-bold', id: 'arvDlgTitulo' });
        var relBotoes = {};
        var boxRel = el('div', { classe: 'arv-dlg-rel', role: 'group', 'aria-label': 'Parentesco' }, RELACOES.map(function (R) {
            return (relBotoes[R.id] = el('button', { type: 'button', onclick: function () { est.relacao = R.id; atualizar(); } },
                [icone('fas ' + R.icone), el('span', { texto: R.rotulo })]));
        }));
        var dica = el('div', { classe: 'arv-dlg-dica' });

        var bNova = el('button', { type: 'button', classe: 'btn btn-sm', texto: 'Nova pessoa', onclick: function () { est.modo = 'nova'; atualizar(); inNome.focus(); } });
        var bExistente = el('button', { type: 'button', classe: 'btn btn-sm', texto: 'Já cadastrada', onclick: function () { est.modo = 'existente'; atualizar(); inBusca.focus(); } });

        var inNome = el('input', { type: 'text', classe: 'form-control', id: 'arvDlgNome', maxlength: '150', autocomplete: 'off' });
        var selSexo = el('select', { classe: 'form-select', id: 'arvDlgSexo' }, [opcao('M', 'Masculino'), opcao('F', 'Feminino'), opcao('D', 'Não informado')]);
        var dicaData = 'DD/MM/AAAA, MM/AAAA ou AAAA';
        var inNasc = el('input', { type: 'text', classe: 'form-control', id: 'arvDlgNasc', placeholder: dicaData, autocomplete: 'off' });
        var inFal = el('input', { type: 'text', classe: 'form-control', id: 'arvDlgFal', placeholder: dicaData, autocomplete: 'off' });
        var boxNova = el('div', { classe: 'row g-2' }, [
            campo('Nome completo', inNome, 'col-12 col-sm-8'), campo('Sexo', selSexo, 'col-12 col-sm-4'),
            campo('Nascimento', inNasc, 'col-6'), campo('Falecimento', inFal, 'col-6')
        ]);

        var inBusca = el('input', { type: 'search', classe: 'form-control', id: 'arvDlgBusca', placeholder: 'Digite o nome…', autocomplete: 'off' });
        var resultados = el('div', { classe: 'arv-dlg-res' });
        var escolhido = el('div', { classe: 'arv-dlg-escolhido' });
        var boxBusca = campo('Buscar pessoa cadastrada', inBusca);
        var boxExistente = el('div', null, [boxBusca, resultados, escolhido]);

        var selOutro = el('select', { classe: 'form-select', id: 'arvDlgOutro' });
        var boxOutro = campo('Outro pai/mãe', selOutro, 'mt-2');
        var selUniao = el('select', { classe: 'form-select', id: 'arvDlgUniao' },
            [opcao('casamento', 'Casamento'), opcao('uniao_estavel', 'União estável'), opcao('outro', 'Outro')]);
        var boxUniao = campo('Tipo de união', selUniao, 'mt-2');

        var erros = el('div', { classe: 'alert alert-danger small py-2 mt-3 mb-0', role: 'alert' });
        var bSalvar = el('button', { type: 'submit', classe: 'btn btn-primary' });
        var form = el('form', { novalidate: '' }, [
            el('div', { classe: 'arv-dlg-cab' }, [titulo, el('button', { type: 'button', classe: 'btn-close', 'aria-label': 'Fechar', onclick: function () { dlg.close(); } })]),
            el('div', { classe: 'arv-dlg-corpo' }, [
                boxRel, dica,
                el('div', { classe: 'arv-dlg-modo btn-group w-100', role: 'group', 'aria-label': 'Origem da pessoa' }, [bNova, bExistente]),
                boxNova, boxExistente, boxOutro, boxUniao, erros
            ]),
            el('div', { classe: 'arv-dlg-rodape' }, [
                el('button', { type: 'button', classe: 'btn btn-outline-secondary', texto: 'Cancelar', onclick: function () { dlg.close(); } }),
                bSalvar
            ])
        ]);
        var dlg = el('dialog', { classe: 'arv-dlg', 'aria-labelledby': 'arvDlgTitulo' }, [form]);
        document.body.appendChild(dlg);

        function mostrarErros(lista) {
            erros.textContent = '';
            erros.hidden = !lista || !lista.length;
            (lista || []).forEach(function (m) { erros.appendChild(el('div', { texto: m })); });
        }
        function linhaPessoa(p) {
            return [el('span', { classe: 'arv-ponto arv-cor-' + sexo(p) }), el('span', { classe: 'arv-dlg-nome', texto: p.nome }),
                el('span', { classe: 'arv-tag', texto: anosVida(p) })];
        }
        function escolher(p) {
            est.existente = p;
            inBusca.value = '';
            resultados.textContent = '';
            atualizar();
        }

        /** Mostra/esconde os campos conforme parentesco e modo (nova pessoa x já cadastrada). */
        function atualizar() {
            var p = est.r.pessoa, rel = est.relacao, nova = est.modo === 'nova';
            RELACOES.forEach(function (R) {
                var b = relBotoes[R.id];
                b.disabled = !!est.bloqueio[R.id];
                b.title = est.bloqueio[R.id] || '';
                b.classList.toggle('ativo', R.id === rel);
                b.setAttribute('aria-pressed', R.id === rel ? 'true' : 'false');
            });
            var motivos = Object.keys(est.bloqueio).map(function (k) { return est.bloqueio[k]; });
            dica.textContent = motivos.filter(function (m, i) { return motivos.indexOf(m) === i; }).join(' ');
            dica.hidden = !motivos.length;

            bNova.className = 'btn btn-sm ' + (nova ? 'btn-secondary' : 'btn-outline-secondary');
            bExistente.className = 'btn btn-sm ' + (nova ? 'btn-outline-secondary' : 'btn-secondary');
            boxNova.hidden = !nova;
            boxExistente.hidden = nova;
            boxBusca.hidden = !!est.existente;
            escolhido.hidden = !est.existente;
            escolhido.textContent = '';
            if (est.existente) {
                linhaPessoa(est.existente).forEach(function (x) { escolhido.appendChild(x); });
                escolhido.appendChild(el('button', { type: 'button', classe: 'btn btn-sm btn-link ms-auto p-0', texto: 'Trocar',
                    onclick: function () { est.existente = null; atualizar(); inBusca.focus(); } }));
            }

            // pai e mãe têm sexo definido; nos demais vale o que a pessoa escolher
            var fixo = rel === 'pai' ? 'M' : rel === 'mae' ? 'F' : null;
            if (fixo) selSexo.value = fixo;
            else if (selSexo.disabled) selSexo.value = rel === 'conjuge' && sexo(p) !== 'D' ? (sexo(p) === 'M' ? 'F' : 'M') : 'D';
            selSexo.disabled = !!fixo;

            boxOutro.hidden = rel !== 'filho' || selOutro.options.length < 2;
            boxUniao.hidden = rel !== 'conjuge';
            var R = RELACOES.filter(function (x) { return x.id === rel; })[0];
            bSalvar.textContent = est.enviando ? 'Salvando…' : 'Adicionar ' + (R ? R.rotulo.toLowerCase() : '');
            bSalvar.disabled = est.enviando || !R;
        }

        var tBusca = null, seqBusca = 0;
        inBusca.addEventListener('input', function () {
            clearTimeout(tBusca);
            var q = inBusca.value.trim(), meu = ++seqBusca;
            resultados.textContent = '';
            if (q.length < 2) return;
            tBusca = setTimeout(function () {
                chamarApi(op.api, 'busca.php', { q: q, limite: 8 }).then(function (r) {
                    if (meu !== seqBusca) return;
                    var ps = (r.pessoas || []).filter(function (x) { return String(x.id) !== String(est.r.pessoa.id); });
                    resultados.textContent = '';
                    if (!ps.length) resultados.appendChild(el('div', { classe: 'arv-tag ms-0 p-2', texto: 'Ninguém encontrado para "' + q + '".' }));
                    ps.forEach(function (x) {
                        resultados.appendChild(el('button', { type: 'button', onclick: function () { escolher(x); } }, linhaPessoa(x)));
                    });
                }).catch(function (e) { if (meu === seqBusca) mostrarErros([e.message]); });
            }, 250);
        });
        inBusca.addEventListener('keydown', function (e) {
            if (e.key !== 'Enter') return;
            e.preventDefault(); // Enter escolhe o primeiro resultado em vez de enviar o formulário
            var b = resultados.querySelector('button');
            if (b) b.click();
        });

        form.addEventListener('submit', function (e) {
            e.preventDefault();
            if (est.enviando || !est.relacao) return;
            var dados = { ancora_id: est.r.pessoa.id, relacao: est.relacao };
            if (est.modo === 'existente') {
                if (!est.existente) { mostrarErros(['Busque e escolha a pessoa já cadastrada.']); inBusca.focus(); return; }
                dados.pessoa_id = est.existente.id;
            } else {
                if (!inNome.value.trim()) { mostrarErros(['Informe o nome completo.']); inNome.focus(); return; }
                dados.nome_completo = inNome.value.trim();
                dados.sexo = selSexo.value;
                dados.data_nascimento = inNasc.value.trim();
                dados.data_falecimento = inFal.value.trim();
            }
            if (est.relacao === 'filho' && selOutro.value) dados.outro_genitor_id = selOutro.value;
            if (est.relacao === 'conjuge') dados.tipo_uniao = selUniao.value;
            var relacao = est.relacao, aoSalvar = est.aoSalvar;
            est.enviando = true;
            mostrarErros([]);
            atualizar();
            enviarApi(op.api, 'adicionar_parente.php', dados).then(function (res) {
                est.enviando = false;
                dlg.close();
                if (aoSalvar) aoSalvar(res, relacao);
            }).catch(function (err) {
                est.enviando = false;
                mostrarErros(err.erros && err.erros.length ? err.erros : [err.message]);
                atualizar();
            });
        });

        function abrir(r, extras, aoSalvar) {
            var p = r.pessoa, nomes = new Map();
            (r.pessoas || []).forEach(function (x) { nomes.set(String(x.id), x); });
            est.r = r;
            est.aoSalvar = aoSalvar;
            est.modo = 'nova';
            est.existente = null;
            est.enviando = false;

            // pai/mãe: só o lado vago da filiação principal; irmão: precisa de ao menos um dos pais
            var pais = (r.pais || []).filter(function (f) { return f.pai_id || f.mae_id; });
            var principal = pais.filter(function (f) { return f.principal; })[0] || pais[0];
            est.bloqueio = {};
            if (principal && principal.pai_id) est.bloqueio.pai = 'Já tem pai cadastrado.';
            if (principal && principal.mae_id) est.bloqueio.mae = 'Já tem mãe cadastrada.';
            if (!principal) est.bloqueio.irmao = 'Para adicionar irmãos, cadastre antes o pai ou a mãe.';
            est.relacao = RELACOES.map(function (R) { return R.id; }).filter(function (k) { return !est.bloqueio[k]; })[0];

            selOutro.textContent = '';
            selOutro.appendChild(opcao('', 'Não informado'));
            (r.familias || []).forEach(function (fam) {
                var c = fam.conjuge_id && nomes.get(String(fam.conjuge_id));
                if (c) selOutro.appendChild(opcao(String(c.id), c.nome));
            });
            var preferido = extras && extras.conjugePreferido ? String(extras.conjugePreferido) : '';
            selOutro.selectedIndex = selOutro.options.length > 1 ? 1 : 0;
            if (preferido && Array.from(selOutro.options).some(function (x) { return x.value === preferido; })) selOutro.value = preferido;

            titulo.textContent = 'Adicionar parente de ' + p.nome;
            inNome.value = inNasc.value = inFal.value = inBusca.value = '';
            resultados.textContent = '';
            selUniao.value = 'casamento';
            selSexo.disabled = true; // faz atualizar() escolher o sexo padrão do parentesco
            mostrarErros([]);
            atualizar();
            if (dlg.showModal) dlg.showModal(); else dlg.setAttribute('open', '');
            inNome.focus();
        }

        return { abrir: abrir, fechar: function () { dlg.close(); } };
    }

    // ------------------------------------------------------------------ árvore (visão "Paisagem")
    /**
     * Layout próprio (d3.tree + d3.zoom): a family-chart só desenhava a linha direta acima do foco,
     * sem irmãos de ancestrais nem pais do cônjuge. Cada pessoa ganha setas conforme as relações
     * que existem no banco (flags absolutas tem_pais / tem_filhos / tem_irmaos) e o que já está aberto:
     *   ▲ pais        → pessoa em foco, cônjuges dela e ancestrais
     *   ◀ / ▶ irmãos  → idem; os irmãos entram ao lado, do lado de fora do casal
     *   ▼ filhos      → pessoa em foco e descendentes
     *   ♥ cônjuges    → pessoa em foco e descendentes (quando há outros fora da tela)
     * Depois de aberto, a seta vira "recolher" (cinza, sentido contrário) no mesmo lugar.
     * Logado, todo card tem "+" (adicionar pai, mãe, cônjuge, filho ou irmão: criarDialogoAdicionar).
     * Quem entrou pela seta ▼ de alguém (descendentes e seus cônjuges) nunca tem ▲: os pais dele
     * já estão na tela, e subir de novo criaria um ciclo.
     *
     * Acima do foco: árvore de ancestrais (d3.tree com os pais como "filhos" do nó), em que os
     * irmãos de cada pessoa são folhas ao lado dela. A linha do foco é: irmãos do foco, foco,
     * cônjuges e irmãos dos cônjuges. Abaixo: árvore de descendentes, cada nó um bloco
     * pessoa + cônjuges. Coordenadas do layout: r (ao longo da geração) e g (entre gerações);
     * na orientação horizontal os eixos da tela são trocados.
     *
     * opcoes:
     *   grafico (elemento), api ('api/'), focoInicial (id|null), logado (bool), atualizarUrl (bool),
     *   cliqueCard ('painel' | 'focar'), painel (aside|null), busca (input|null),
     *   seletorConjuges (div|null), status (div|função status|null), controles (elemento com [data-arv-cmd]),
     *   painelCompartilhado (objeto de criarPainel(); usado no lugar de "painel"), onFoco(id, card),
     *   forcarCompacto (bool), focarExterno(id) → Promise (troca o foco por fora, ex.: pelo
     *   coordenador das visões, que também atualiza a URL)
     * Em arvore.php a paisagem é uma das visões de assets/js/visoes.js, que cuida da URL,
     * da busca e do painel (compartilhados); sem ele, esta função também funciona sozinha.
     */
    function criar(opcoes) {
        var o = Object.assign({ api: 'api/', cliqueCard: 'painel', atualizarUrl: false, logado: false }, opcoes || {});
        var grafo = new Grafo();
        var mainId = null, filtroConjuge = null, ocupado = false, horizontal = false;
        var compacto = calcCompacto();
        var fotosQuebradas = new Set(); // fotos que deram erro não são pedidas de novo

        // o que está aberto na tela (por id) — zera a cada novo foco
        var abertos = { pais: new Set(), irmaos: new Set(), filhos: new Set() };
        // o que já veio da API (vale para toda a sessão, como o grafo)
        var carregado = { pais: new Set(), filhos: new Set() };
        var irmaosDe = new Map();      // "id" -> [{id: "id", tipo: 'completo'|'meio'}] (api/pessoa.php)
        var layout = null;             // último cálculo: {nos: Map "id" -> nó, segs: [[[r,g],...]]}
        var origemExpansao = null;     // cards novos "nascem" da pessoa cuja seta foi clicada

        var svg = null, gLinhas = null, camada = null, zoom = null, transformAtual = null;
        var cards = new Map();         // "id" -> elemento do card (uma pessoa aparece uma vez só)

        function calcCompacto() { return o.forcarCompacto || o.grafico.clientWidth < 640; }
        function dim() { return compacto ? DIM.compacto : DIM.normal; }
        /** Medidas no espaço do layout: "linha" = ao longo da geração, "ger" = entre gerações. */
        function eixos() {
            var D = dim();
            return horizontal
                ? { linha: D.h, ger: D.w, gapL: D.hGapLinha, gapG: D.hGapGeracao }
                : { linha: D.w, ger: D.h, gapL: D.gapLinha, gapG: D.gapGeracao };
        }
        function tela(r, g) { return horizontal ? [g, r] : [r, g]; }

        var status = typeof o.status === 'function' ? o.status : criarStatus(o.status);
        var painel = o.painelCompartilhado || (o.painel ? criarPainel({
            painel: o.painel, api: o.api, logado: o.logado,
            focar: function (id) { return focar(id); },
            focoAtual: function () { return mainId; },
            sempreFocar: o.cliqueCard !== 'painel'
        }) : null);
        var cachePessoa = painel ? painel.cache : new Map();

        // ----- relações a partir do grafo mesclado
        function conjugesDe(id) {
            return grafo.familias(id).filter(function (g) { return g.conjuge; }).map(function (g) { return g.conjuge; });
        }

        // ----- layout
        function calcular() {
            var E = eixos(), passo = E.linha + E.gapL, nivel = E.ger + E.gapG;
            var hr = E.linha / 2, hg = E.ger / 2;
            var nos = new Map(), colocados = new Set([mainId]);
            function novo(id, papel, extra) { return Object.assign({ id: id, papel: papel, filhosArv: [] }, extra || {}); }

            // irmãos abertos de um nó entram ao lado dele, do lado de fora (no.lado)
            function comIrmaos(no) {
                if (!abertos.irmaos.has(no.id)) return [no];
                no.irmaos = (irmaosDe.get(no.id) || []).filter(function (i) {
                    return grafo.pessoas.has(i.id) && !colocados.has(i.id);
                }).map(function (i) {
                    colocados.add(i.id);
                    return novo(i.id, 'irmao', { ancora: no.id, meio: i.tipo === 'meio' });
                });
                return no.lado === 'antes' ? no.irmaos.concat([no]) : [no].concat(no.irmaos);
            }
            function comIrmaosLista(lista) { return [].concat.apply([], lista.map(comIrmaos)); }
            function subirPais(no) {
                if (!abertos.pais.has(no.id)) return;
                var pm = grafo.paisDe(no.id);
                no.pais = [];
                [['pai', 'antes'], ['mae', 'depois']].forEach(function (par) {
                    var g = pm[par[0]];
                    if (g && !colocados.has(g)) { colocados.add(g); no.pais.push(novo(g, 'ancestral', { lado: par[1] })); }
                });
                no.filhosArv = comIrmaosLista(no.pais);
                no.pais.forEach(subirPais);
            }

            // ---- linha do foco + ancestrais
            var mulher = sexo(grafo.pessoas.get(mainId)) === 'F';
            var conjs = conjugesDe(mainId).filter(function (c) { return (!filtroConjuge || c === filtroConjuge) && !colocados.has(c); });
            conjs.forEach(function (c) { colocados.add(c); });
            // homem (ou não informado) à esquerda do casal, mulher à direita; irmãos do lado de fora
            var noFoco = novo(mainId, 'foco', { lado: mulher ? 'depois' : 'antes' });
            var noConjs = conjs.map(function (c) { return novo(c, 'conjuge', { lado: mulher ? 'antes' : 'depois' }); });
            var linhaFoco = mulher ? noConjs.slice().reverse().concat([noFoco]) : [noFoco].concat(noConjs);
            var raizA = { virtual: true, filhosArv: comIrmaosLista(linhaFoco) };
            linhaFoco.forEach(subirPais);

            var hA = d3.hierarchy(raizA, function (d) { return d.filhosArv && d.filhosArv.length ? d.filhosArv : null; });
            d3.tree().nodeSize([passo, nivel]).separation(function (a, b) { return a.parent === b.parent ? 1 : 1.3; })(hA);
            var x0 = 0;
            hA.each(function (h) { if (h.data === noFoco) x0 = h.x; });
            hA.each(function (h) {
                if (h.data.virtual) return;
                h.data.r = h.x - x0;
                h.data.g = -(h.depth - 1) * nivel;
                nos.set(h.data.id, h.data);
            });

            // ---- descendentes (blocos pessoa + cônjuges)
            function bloco(id, ehRaiz) {
                var b = { id: id, filhosArv: [], grupos: [], pessoas: [], conjuges: [], w: 0 };
                var fams = grafo.familias(id);
                if (ehRaiz) {
                    fams = fams.filter(function (g) { return !filtroConjuge || g.conjuge === filtroConjuge; });
                } else {
                    b.conjuges = fams.filter(function (g) { return g.conjuge && !colocados.has(g.conjuge); })
                        .map(function (g) { return g.conjuge; });
                    b.conjuges.forEach(function (c) { colocados.add(c); });
                    b.pessoas = sexo(grafo.pessoas.get(id)) === 'F' ? b.conjuges.slice().reverse().concat([id]) : [id].concat(b.conjuges);
                    b.w = b.pessoas.length * E.linha + (b.pessoas.length - 1) * E.gapL;
                }
                if (!abertos.filhos.has(id)) return b;
                fams.forEach(function (g) {
                    var ids = g.filhos.map(function (f) { return f.id; })
                        .filter(function (f) { return grafo.pessoas.has(f) && !colocados.has(f); });
                    ids.forEach(function (f) { colocados.add(f); });
                    if (ids.length) b.grupos.push({ conjuge: g.conjuge, filhos: ids });
                });
                b.grupos.forEach(function (g) {
                    g.filhos.forEach(function (f) { b.filhosArv.push(bloco(f, false)); });
                });
                return b;
            }
            var raizP = bloco(mainId, true);
            var hP = d3.hierarchy(raizP, function (d) { return d.filhosArv.length ? d.filhosArv : null; });
            d3.tree().nodeSize([1, nivel]).separation(function (a, b) {
                return (a.data.w + b.data.w) / 2 + E.gapL * (a.parent === b.parent ? 1 : 2);
            })(hP);
            // filhos centrados sob o casal em foco
            var meio = [noFoco].concat(noConjs).reduce(function (s, n) { return s + n.r; }, 0) / (1 + noConjs.length);
            var dx = meio - hP.x;
            hP.each(function (h) {
                if (!h.depth) return;
                var b = h.data, esq = h.x + dx - b.w / 2 + hr;
                b.pessoas.forEach(function (pid, i) {
                    nos.set(pid, novo(pid, pid === b.id ? 'descendente' : 'conjuge-desc', { r: esq + i * passo, g: h.depth * nivel, bloco: b }));
                });
            });

            // ---- ligações (polilinhas no espaço r/g)
            var segs = [];
            function casal(a, b, desloc) {
                var e = a.r < b.r ? a : b, d = a.r < b.r ? b : a, g = a.g + (desloc || 0);
                segs.push([[e.r + hr, g], [d.r - hr, g]]);
                return { r: (e.r + d.r) / 2, g: g };
            }
            function base(n) { return { r: n.r, g: n.g + hg }; }
            function descer(origem, filhos, desloc) {
                if (!filhos.length) return;
                var topo = filhos[0].g - hg, barra = topo - E.gapG / 2 + (desloc || 0);
                var rs = filhos.map(function (f) { return f.r; });
                if (origem) { rs.push(origem.r); segs.push([[origem.r, origem.g], [origem.r, barra]]); }
                if (rs.length > 1) segs.push([[Math.min.apply(null, rs), barra], [Math.max.apply(null, rs), barra]]);
                filhos.forEach(function (f) { segs.push([[f.r, barra], [f.r, topo]]); });
            }
            // ancestrais: pais (ou só a chave dos irmãos, se os pais estão fechados) → pessoa + irmãos
            nos.forEach(function (n) {
                var grupo = [n].concat(n.irmaos || []);
                if (n.pais && n.pais.length) descer(n.pais.length === 2 ? casal(n.pais[0], n.pais[1]) : base(n.pais[0]), grupo);
                else if (grupo.length > 1) descer(null, grupo);
            });
            // casais da linha do foco e dos blocos de descendentes → filhos de cada união
            function ligarBloco(n, conjNos, grupos) {
                var origens = new Map();
                conjNos.forEach(function (c, i) { origens.set(c.id, casal(n, c, i * 7)); });
                grupos.forEach(function (g, i) {
                    var filhos = g.filhos.map(function (f) { return nos.get(f); }).filter(Boolean);
                    descer(origens.get(g.conjuge) || base(n), filhos, -i * 6);
                });
            }
            ligarBloco(noFoco, noConjs, raizP.grupos);
            hP.each(function (h) {
                if (!h.depth) return;
                ligarBloco(nos.get(h.data.id), h.data.conjuges.map(function (c) { return nos.get(c); }), h.data.grupos);
            });

            // ---- setas de cada card
            nos.forEach(function (n) {
                var p = grafo.pessoas.get(n.id) || {};
                var acima = n.papel === 'foco' || n.papel === 'conjuge' || n.papel === 'ancestral';
                var abaixo = n.papel === 'foco' || n.papel === 'descendente';
                var grupos = n.papel === 'foco' ? raizP.grupos : n.papel === 'descendente' ? n.bloco.grupos : [];
                n.setas = {
                    pais: acima && !!p.tem_pais && !abertos.pais.has(n.id),
                    irmaos: acima && !!p.tem_irmaos && !abertos.irmaos.has(n.id),
                    filhos: abaixo && !!p.tem_filhos && !abertos.filhos.has(n.id),
                    conjuges: abaixo && !!p.tem_mais_conjuges,
                    // recolher: só o que está de fato desenhado a partir deste card
                    recPais: !!(n.pais && n.pais.length),
                    recIrmaos: !!(n.irmaos && n.irmaos.length),
                    recFilhos: grupos.length > 0
                };
            });
            return { nos: nos, segs: segs };
        }

        // ----- card (HTML seguro: todo texto do banco passa por esc())
        function botao(acao, classe, iconeFa, titulo) {
            return '<button type="button" class="arv-exp ' + classe + '" data-acao="' + acao + '" title="' + esc(titulo) +
                '" aria-label="' + esc(titulo) + '"><i class="fas ' + iconeFa + '" aria-hidden="true"></i></button>';
        }
        function htmlCard(n) {
            var p = grafo.pessoas.get(n.id) || {}, D = dim(), s = sexo(p), nome = p.nome || '';
            var botoes = '';
            if (n.setas.pais) botoes += botao('pais', 'arv-exp-pais', 'fa-chevron-up', 'Mostrar pais de ' + nome);
            if (n.setas.irmaos) botoes += botao('irmaos', 'arv-exp-lado arv-exp-lado-' + n.lado,
                n.lado === 'antes' ? 'fa-chevron-left' : 'fa-chevron-right', 'Mostrar irmãos de ' + nome);
            if (n.setas.filhos) botoes += botao('filhos', 'arv-exp-filhos', 'fa-chevron-down', 'Mostrar filhos de ' + nome);
            if (n.setas.conjuges) botoes += botao('conjuges', 'arv-exp-conjuges', 'fa-heart', 'Mostrar outros cônjuges de ' + nome);
            // recolher: mesma posição da seta que abriu, apontando de volta para o card
            if (n.setas.recPais) botoes += botao('rec-pais', 'arv-rec arv-exp-pais', 'fa-chevron-down', 'Recolher pais de ' + nome);
            if (n.setas.recIrmaos) botoes += botao('rec-irmaos', 'arv-rec arv-exp-lado arv-exp-lado-' + n.lado,
                n.lado === 'antes' ? 'fa-chevron-right' : 'fa-chevron-left', 'Recolher irmãos de ' + nome);
            if (n.setas.recFilhos) botoes += botao('rec-filhos', 'arv-rec arv-exp-filhos', 'fa-chevron-up', 'Recolher filhos de ' + nome);
            if (o.logado) botoes += botao('adicionar', 'arv-exp-add', 'fa-plus', 'Adicionar parente de ' + nome);
            var rotulo = n.papel === 'foco' ? 'Em foco' : n.papel === 'irmao' ? (n.meio ? 'Meio-irmão(ã)' : 'Irmão(ã)') : '';
            var foto = p.foto && !fotosQuebradas.has(p.foto)
                ? '<img src="' + esc(p.foto) + '" alt="" loading="lazy" draggable="false">'
                : '<i class="fas ' + ICONE_SEXO[s] + '" aria-hidden="true"></i>';
            var dica = [textoData(p.nascimento) ? 'nasc. ' + textoData(p.nascimento) : '',
                textoData(p.falecimento) ? 'fal. ' + textoData(p.falecimento) : ''].filter(Boolean).join('; ');
            var anos = anosVida(p);
            // linha de fato (nascimento/falecimento): data completa e local; sem nada, diz "não informado"
            function fato(icone, rot, ev) {
                var data = textoData(ev), lugar = (ev && ev.local) || '';
                return '<div class="arv-info-l" title="' + esc(rot + ': ' + ([data, lugar].filter(Boolean).join(', ') || 'não informado')) + '">' +
                    '<i class="fas ' + icone + '" aria-hidden="true"></i><div>' +
                    (data ? '<span class="arv-info-data">' + esc(data) + '</span>' : '') +
                    (lugar ? '<span class="arv-info-local">' + esc(lugar) + '</span>' : '') +
                    (!data && !lugar ? '<span class="arv-info-vazio">não informado</span>' : '') + '</div></div>';
            }
            // carta colecionável: barra do nome com o "naipe", janela retangular da foto, faixa com os
            // anos de vida e caixa de informações (nascimento e falecimento, sempre preenchida)
            return '<div class="arv-card arv-sexo-' + s + (n.papel === 'foco' ? ' arv-card-foco' : '') +
                '" style="width:' + D.w + 'px;height:' + D.h + 'px" title="' + esc(nome + (dica ? ' (' + dica + ')' : '')) + '">' +
                (rotulo ? '<span class="arv-rotulo">' + esc(rotulo) + '</span>' : '') +
                '<div class="arv-carta">' +
                '<div class="arv-cab"><div class="arv-nome">' + esc(nome) + '</div>' +
                '<i class="arv-naipe fas ' + NAIPE_SEXO[s] + '" aria-hidden="true"></i></div>' +
                '<div class="arv-foto-wrap">' + foto + '</div>' +
                '<div class="arv-faixa">' + esc(anos || ROTULO_SEXO[s]) + '</div>' +
                '<div class="arv-info">' + fato('fa-star-of-life', 'Nascimento', p.nascimento) + fato('fa-cross', 'Falecimento', p.falecimento) + '</div>' +
                '</div>' + botoes + '</div>';
        }
        function ligarFoto(e, n) {
            // foto que não carrega (arquivo ausente) vira o ícone do sexo
            var img = e.querySelector('.arv-foto-wrap img');
            if (!img) return;
            img.addEventListener('error', function () {
                var p = grafo.pessoas.get(n.id) || {};
                fotosQuebradas.add(p.foto);
                img.replaceWith(icone('fas ' + ICONE_SEXO[sexo(p)]));
            }, { once: true });
        }

        // ----- desenho
        function montarDom() {
            o.grafico.textContent = '';
            o.grafico.classList.add('arv-paisagem');
            svg = d3.select(o.grafico).append('svg').attr('class', 'arv-linhas').attr('aria-hidden', 'true');
            gLinhas = svg.append('g');
            camada = el('div', { classe: 'arv-camada' });
            o.grafico.appendChild(camada);
            transformAtual = d3.zoomIdentity;
            zoom = d3.zoom().scaleExtent([0.15, 2.5]).on('zoom', function (ev) {
                transformAtual = ev.transform;
                gLinhas.attr('transform', ev.transform);
                camada.style.transform = 'translate(' + ev.transform.x + 'px,' + ev.transform.y + 'px) scale(' + ev.transform.k + ')';
            });
            d3.select(o.grafico).call(zoom).on('dblclick.zoom', null);
            camada.addEventListener('click', aoClicar);
            camada.addEventListener('keydown', function (e) {
                if ((e.key === 'Enter' || e.key === ' ') && e.target.classList.contains('arv-no')) { e.preventDefault(); aoClicar(e); }
            });
        }
        /** Desenha o layout atual; devolve os ids dos cards que entraram agora. */
        function desenhar() {
            var D = dim();
            var origemT = origemExpansao && cards.has(origemExpansao) ? cards.get(origemExpansao)._final : null;
            var vistos = new Set(), novos = [], novosIds = [];
            layout.nos.forEach(function (n, id) {
                vistos.add(id);
                var p = tela(n.r, n.g);
                var t = 'translate(' + Math.round(p[0] - D.w / 2) + 'px,' + Math.round(p[1] - D.h / 2) + 'px)';
                var e = cards.get(id);
                if (!e) {
                    var pessoa = grafo.pessoas.get(id) || {};
                    e = el('div', { classe: 'arv-no', 'data-id': id, tabindex: '0', role: 'group', 'aria-label': pessoa.nome || '' });
                    e.style.transition = 'none';
                    e.style.opacity = '0';
                    e.style.transform = origemT || t;
                    camada.appendChild(e);
                    cards.set(id, e);
                    novos.push(e);
                    novosIds.push(id);
                }
                var html = htmlCard(n);
                if (e._html !== html) { e.innerHTML = html; e._html = html; ligarFoto(e, n); }
                e.classList.toggle('arv-no-foco', n.papel === 'foco');
                e._final = t;
                if (novos.indexOf(e) < 0) e.style.transform = t;
            });
            cards.forEach(function (e, id) { if (!vistos.has(id)) { e.remove(); cards.delete(id); } });
            if (novos.length) {
                void camada.offsetWidth; // aplica a posição inicial antes de animar
                novos.forEach(function (e) { e.style.transition = ''; e.style.opacity = ''; e.style.transform = e._final; });
            }
            origemExpansao = null;

            gLinhas.selectAll('path').data(layout.segs).join('path')
                .attr('d', function (s) { return 'M' + s.map(function (pt) { return tela(pt[0], pt[1]).join(','); }).join('L'); });
            gLinhas.interrupt().style('opacity', 0).transition().delay(200).duration(300).style('opacity', 1);
            return novosIds;
        }
        /**
         * props.posicionar: ajusta/centraliza no foco; props.revelar: id cuja seta foi clicada —
         * move a vista (sem mudar o zoom) para que ele e os cards que acabaram de entrar fiquem visíveis.
         */
        function renderizar(props) {
            props = props || {};
            if (!camada) montarDom();
            o.grafico.classList.toggle('arv-compacto', compacto);
            o.grafico.classList.toggle('arv-horizontal', horizontal);
            layout = calcular();
            // quem saiu da tela (recolhido junto com um parente) deixa de estar "aberto"
            ['pais', 'irmaos', 'filhos'].forEach(function (k) {
                abertos[k].forEach(function (id) { if (!layout.nos.has(id)) abertos[k].delete(id); });
            });
            var novos = desenhar();
            atualizarSeletorConjuges();
            if (props.posicionar) posicionarFoco(props.primeira ? 0 : 500);
            else if (props.revelar) revelar(novos, props.revelar, 500);
        }

        // ----- zoom / posição
        function area() {
            var r = o.grafico.getBoundingClientRect();
            return { w: Math.max(100, r.width - (o.controles ? 64 : 0)), h: Math.max(100, r.height) };
        }
        function caixa() {
            var D = dim(), x0 = Infinity, x1 = -Infinity, y0 = Infinity, y1 = -Infinity;
            layout.nos.forEach(function (n) {
                var p = tela(n.r, n.g);
                x0 = Math.min(x0, p[0] - D.w / 2); x1 = Math.max(x1, p[0] + D.w / 2);
                y0 = Math.min(y0, p[1] - D.h / 2); y1 = Math.max(y1, p[1] + D.h / 2);
            });
            return { w: x1 - x0, h: y1 - y0, cx: (x0 + x1) / 2, cy: (y0 + y1) / 2 };
        }
        function irPara(t, tempo) {
            var s = d3.select(o.grafico);
            if (tempo) s.transition().duration(tempo).call(zoom.transform, t); else s.call(zoom.transform, t);
        }
        function transformAjustar(kMax) {
            var c = caixa(), a = area(), folga = 48;
            var k = Math.max(0.15, Math.min(a.w / (c.w + folga), a.h / (c.h + folga), kMax || 1));
            return d3.zoomIdentity.translate(a.w / 2 - k * c.cx, a.h / 2 - k * c.cy).scale(k);
        }
        function transformFoco(k) {
            var a = area(), f = layout.nos.get(mainId), p = tela(f.r, f.g);
            return d3.zoomIdentity.translate(a.w / 2 - k * p[0], a.h / 2 - k * p[1]).scale(k);
        }
        /**
         * Desloca a vista o mínimo para mostrar os cards ids. Se não cabem, centraliza o grupo,
         * mas sem tirar da tela o card origem (a pessoa cuja seta foi clicada).
         */
        function revelar(ids, origem, tempo) {
            var D = dim(), a = area(), t = transformAtual, m = 24;
            function caixaTela(lista) {
                var c = { x0: Infinity, x1: -Infinity, y0: Infinity, y1: -Infinity };
                lista.forEach(function (id) {
                    var n = layout.nos.get(id);
                    if (!n) return;
                    var p = tela(n.r, n.g), cx = t.applyX(p[0]), cy = t.applyY(p[1]);
                    c.x0 = Math.min(c.x0, cx - t.k * D.w / 2); c.x1 = Math.max(c.x1, cx + t.k * D.w / 2);
                    c.y0 = Math.min(c.y0, cy - t.k * D.h / 2); c.y1 = Math.max(c.y1, cy + t.k * D.h / 2);
                });
                return c;
            }
            var g = caixaTela(ids.concat([origem])), o0 = caixaTela([origem]);
            if (g.x0 === Infinity) return;
            function delta(v0, v1, o0v, o1v, tam) {
                if (v1 - v0 <= tam - 2 * m) return v0 < m ? m - v0 : v1 > tam - m ? tam - m - v1 : 0;
                var d = tam / 2 - (v0 + v1) / 2;
                if (o0v === Infinity) return d;
                if (o0v + d < m) d = m - o0v;                 // origem saiu pela esquerda/topo
                else if (o1v + d > tam - m) d = tam - m - o1v; // origem saiu pela direita/base
                return d;
            }
            var dx = delta(g.x0, g.x1, o0.x0, o0.x1, a.w), dy = delta(g.y0, g.y1, o0.y0, o0.y1, a.h);
            if (Math.abs(dx) > 1 || Math.abs(dy) > 1) irPara(t.translate(dx / t.k, dy / t.k), tempo);
        }
        /** Árvore inteira cabe com escala razoável → ajusta; senão centraliza a pessoa em foco. */
        function posicionarFoco(tempo) {
            var t = transformAjustar(1);
            irPara(t.k >= dim().escalaMin ? t : transformFoco(dim().escalaMin), tempo);
        }
        function zoomAtual() { return transformAtual ? transformAtual.k : 1; }
        function comando(cmd) {
            if (!layout) return;
            var s = d3.select(o.grafico);
            if (cmd === 'mais') s.transition().duration(250).call(zoom.scaleBy, 1.25);
            else if (cmd === 'menos') s.transition().duration(250).call(zoom.scaleBy, 0.8);
            else if (cmd === 'centralizar') irPara(transformFoco(Math.max(zoomAtual(), dim().escalaMin)), 500);
            else if (cmd === 'ajustar') irPara(transformAjustar(1.2), 500);
            else if (cmd === 'girar') {
                horizontal = !horizontal;
                renderizar({ posicionar: 'foco' });
            }
        }
        if (o.controles) {
            o.controles.addEventListener('click', function (e) {
                var b = e.target.closest('[data-arv-cmd]');
                if (b) comando(b.getAttribute('data-arv-cmd'));
            });
        }

        // ----- cliques
        function aoClicar(e) {
            var no = e.target.closest('.arv-no');
            if (!no) return;
            var id = no.getAttribute('data-id');
            var b = e.target.closest('[data-acao]');
            if (b) {
                e.stopPropagation();
                var acao = b.getAttribute('data-acao');
                if (acao === 'adicionar') adicionar(id);
                else if (acao.indexOf('rec-') === 0) recolher(acao.slice(4), id);
                else expandir(acao, id, b);
                return;
            }
            if (o.cliqueCard === 'focar') focar(id).catch(function () {});
            else abrirPainel(id);
        }

        // ----- foco e expansões
        /** Guarda o que api/pessoa.php traz além do grafo: irmãos (com o tipo) e o resumo do painel. */
        function guardarPessoa(id, r) {
            if (painel) painel.atualizar(id, r); else cachePessoa.set(id, r);
            irmaosDe.set(id, (r.irmaos || []).map(function (i) { return { id: String(i.id), tipo: i.tipo }; }));
            carregado.pais.add(id); // a resposta inclui os pais (e as filiações deles)
        }

        function focar(id, opts) {
            opts = opts || {};
            id = String(id);
            if (ocupado) return Promise.resolve(false);
            ocupado = true;
            status('Carregando…');
            return Promise.all([
                chamarApi(o.api, 'pessoa.php', { id: id }),
                chamarApi(o.api, 'descendentes.php', { id: id, geracoes: 1 })
            ]).then(function (r) {
                grafo.mesclar(r[0]);
                grafo.mesclar(r[1]);
                guardarPessoa(id, r[0]);
                carregado.filhos.add(id);
                var primeira = !layout;
                mainId = id;
                filtroConjuge = null;
                // por padrão: pais e filhos do foco abertos; irmãos só pela seta lateral
                abertos = { pais: new Set([id]), irmaos: new Set(), filhos: new Set([id]) };
                origemExpansao = null;
                renderizar({ posicionar: 'foco', primeira: primeira });
                status('');
                if (o.atualizarUrl && opts.historico !== 'nenhum') {
                    var url = new URL(window.location.href);
                    url.searchParams.set('id', id);
                    var metodo = opts.historico === 'substituir' ? 'replaceState' : 'pushState';
                    if (metodo === 'replaceState' || url.href !== window.location.href) history[metodo]({ arvId: id }, '', url.href);
                }
                var card = r[0].pessoa;
                if (card && card.nome && o.atualizarUrl) document.title = card.nome + ' | ' + (o.titulo || 'Árvore Genealógica');
                if (o.onFoco) o.onFoco(id, card);
                if (painel) painel.renovar();
                return true;
            }).catch(function (e) {
                status(e.message, true, 6000);
                throw e;
            }).finally(function () { ocupado = false; });
        }

        var MSG = { pais: 'Carregando pais…', irmaos: 'Carregando irmãos…', filhos: 'Carregando filhos…', conjuges: 'Carregando cônjuges…' };
        /** acao: 'pais' | 'irmaos' | 'filhos' | 'conjuges'. Busca na API só o que ainda não veio. */
        function expandir(acao, id, botaoEl) {
            if (ocupado || !MSG[acao]) return;
            id = String(id);
            var req = acao === 'pais' && !carregado.pais.has(id) ? ['ancestrais.php', { id: id, geracoes: 1 }]
                : acao === 'filhos' && !carregado.filhos.has(id) ? ['descendentes.php', { id: id, geracoes: 1 }]
                : (acao === 'irmaos' && !irmaosDe.has(id)) || acao === 'conjuges' ? ['pessoa.php', { id: id }]
                : null;
            ocupado = true;
            if (req) {
                if (botaoEl) botaoEl.classList.add('arv-girando');
                status(MSG[acao]);
            }
            (req ? chamarApi(o.api, req[0], req[1]) : Promise.resolve(null)).then(function (r) {
                if (r) {
                    grafo.mesclar(r);
                    if (req[0] === 'pessoa.php') guardarPessoa(id, r);
                    if (req[0] === 'ancestrais.php') carregado.pais.add(id);
                    if (req[0] === 'descendentes.php') carregado.filhos.add(id);
                }
                if (acao === 'conjuges') { var p = grafo.pessoas.get(id); if (p) p.tem_mais_conjuges = false; }
                else abertos[acao].add(id);
                origemExpansao = id;
                renderizar({ revelar: id });
                status('');
            }).catch(function (e) {
                status(e.message, true, 6000);
                if (botaoEl) botaoEl.classList.remove('arv-girando');
            }).finally(function () { ocupado = false; });
        }

        /** tipo: 'pais' | 'irmaos' | 'filhos'. Fecha o que a seta abriu (e tudo o que estava aberto a partir dali). */
        function recolher(tipo, id) {
            if (ocupado || !abertos[tipo]) return;
            abertos[tipo].delete(String(id));
            renderizar();
        }

        // ----- adicionar parente pela árvore (botão "+" do card; só logado)
        var dialogo = null;
        function adicionar(id) {
            if (!o.logado || ocupado) return;
            id = String(id);
            // o diálogo precisa dos pais e cônjuges atuais da pessoa (não mexe no grafo: nada novo aparece na tela)
            var resumo = cachePessoa.has(id) ? Promise.resolve(cachePessoa.get(id))
                : chamarApi(o.api, 'pessoa.php', { id: id }).then(function (r) { cachePessoa.set(id, r); return r; });
            resumo.then(function (r) {
                if (!dialogo) dialogo = criarDialogoAdicionar({ api: o.api });
                dialogo.abrir(r, { conjugePreferido: id === mainId ? filtroConjuge : null }, function (res, relacao) {
                    aposAdicionar(id, relacao, res);
                });
            }).catch(function (e) { status(e.message, true, 6000); });
        }
        /** Recarrega o entorno da pessoa e abre o lado em que o parente novo entrou. */
        function aposAdicionar(id, relacao, res) {
            var n = layout && layout.nos.get(id), papel = n ? n.papel : '';
            var acima = papel === 'foco' || papel === 'conjuge' || papel === 'ancestral';
            var abaixo = papel === 'foco' || papel === 'descendente';
            var paraCima = relacao === 'pai' || relacao === 'mae' || relacao === 'irmao';
            var tipo = relacao === 'irmao' ? 'irmaos' : paraCima ? 'pais' : relacao === 'filho' ? 'filhos' : null;
            var msg = res.nome + (res.criado ? ' foi adicionado(a) à árvore.' : ' foi vinculado(a).') +
                (res.avisos && res.avisos.length ? ' ' + res.avisos.join(' ') : '');
            var painelEm = painel ? painel.aberto() : null;
            cachePessoa.clear();
            irmaosDe.clear();

            if (paraCima ? !acima : !abaixo) {
                // daqui o parente novo não aparece (ex.: filho de um ancestral): a pessoa vira o foco
                Promise.resolve((o.focarExterno || focar)(id)).then(function () {
                    if (mainId !== id) return;
                    if (tipo === 'irmaos') { abertos.irmaos.add(id); renderizar({ posicionar: 'foco' }); }
                    status(msg, false, 8000);
                }).catch(function () {});
                return;
            }
            var ids = [id].concat(Array.from(abertos.irmaos).filter(function (i) { return i !== id; }));
            var pedidos = ids.map(function (i) { return chamarApi(o.api, 'pessoa.php', { id: i }); });
            if (abaixo) pedidos.push(chamarApi(o.api, 'descendentes.php', { id: id, geracoes: 1 }));
            ocupado = true;
            status('Atualizando…');
            Promise.all(pedidos).then(function (rs) {
                rs.forEach(function (r, i) {
                    grafo.mesclar(r);
                    if (i < ids.length) guardarPessoa(ids[i], r);
                });
                if (abaixo) carregado.filhos.add(id);
                if (tipo) abertos[tipo].add(id);
                if (id === mainId) filtroConjuge = null;
                origemExpansao = id;
                renderizar({ revelar: id });
                status(msg, false, 8000);
                if (painelEm) painel.abrir(painelEm);
            }).catch(function (e) {
                status(msg + ' Recarregue a página para ver (' + e.message + ').', true, 8000);
            }).finally(function () { ocupado = false; });
        }

        // ----- alternar entre cônjuges da pessoa em foco
        function atualizarSeletorConjuges() {
            var box = o.seletorConjuges;
            if (!box) return;
            var conjuges = mainId ? conjugesDe(mainId) : [];
            box.textContent = '';
            if (conjuges.length < 2) { box.classList.remove('visivel'); filtroConjuge = null; return; }
            box.appendChild(el('span', { classe: 'text-muted me-1', texto: 'Cônjuges:' }));
            [null].concat(conjuges).forEach(function (sid) {
                var p = sid ? grafo.pessoas.get(sid) : null;
                var ativo = filtroConjuge === sid;
                box.appendChild(el('button', {
                    type: 'button',
                    classe: 'btn btn-sm ' + (ativo ? 'btn-primary' : 'btn-outline-primary'),
                    'aria-pressed': ativo ? 'true' : 'false',
                    texto: sid ? (p ? p.nome : sid) : 'Todos',
                    onclick: function () {
                        filtroConjuge = sid;
                        renderizar();
                        irPara(transformFoco(zoomAtual()), 500);
                    }
                }));
            });
            box.classList.add('visivel');
        }

        // ----- painel de resumo (criarPainel) e busca (configurarBusca)
        function abrirPainel(id) {
            if (!painel) { focar(id).catch(function () {}); return; }
            painel.abrir(id);
        }
        function fecharPainel() { if (painel) painel.fechar(); }
        if (o.busca) configurarBusca(o.busca, { api: o.api, status: status, escolher: function (p) { focar(p.id).catch(function () {}); } });

        // ----- histórico do navegador (botão voltar)
        if (o.atualizarUrl) {
            window.addEventListener('popstate', function (e) {
                var id = (e.state && e.state.arvId) || new URL(window.location.href).searchParams.get('id');
                if (id && /^\d+$/.test(String(id)) && String(id) !== mainId) focar(id, { historico: 'nenhum' }).catch(function () {});
            });
        }

        // ----- celular x desktop: recalcula ao redimensionar / girar o aparelho
        // (com o gráfico oculto — outra visão ativa — não faz nada; redimensionar() é chamado ao reexibir)
        function redimensionar() {
            if (!layout || !o.grafico.clientWidth) return;
            var c = calcCompacto();
            if (c === compacto) return;
            compacto = c;
            renderizar({ posicionar: 'foco' });
        }
        var tRes = null;
        window.addEventListener('resize', function () {
            clearTimeout(tRes);
            tRes = setTimeout(redimensionar, 200);
        });

        // ----- início
        function iniciar() {
            if (!window.d3) {
                status('Não foi possível carregar a biblioteca da árvore. Verifique a conexão.', true);
                return Promise.reject(new Error('d3 indisponível'));
            }
            var inicial = o.focoInicial ? String(o.focoInicial) : null;
            var p = inicial ? Promise.resolve(inicial)
                : chamarApi(o.api, 'raiz.php', {}).then(function (r) { return String(r.id); });
            return p.then(function (id) {
                return focar(id, { historico: 'substituir' }).catch(function (e) {
                    if (e.status === 404 && inicial) {
                        // id da URL inexistente: abre a raiz padrão
                        return chamarApi(o.api, 'raiz.php', {}).then(function (r) {
                            return focar(String(r.id), { historico: 'substituir' }).then(function () {
                                status('Pessoa ' + inicial + ' não encontrada. Mostrando a raiz da árvore.', true, 6000);
                            });
                        });
                    }
                    throw e;
                });
            }).catch(function (e) { status(e.message, true); });
        }

        return {
            iniciar: iniciar,
            focar: focar,
            expandir: expandir,
            abrirPainel: abrirPainel,
            fecharPainel: fecharPainel,
            comando: comando,
            redimensionar: redimensionar,
            getMainId: function () { return mainId; },
            getGrafo: function () { return grafo; },
            getLayout: function () { return layout; }
        };
    }

    window.ArvoreGenealogica = {
        criar: criar,
        Grafo: Grafo,
        criarPainel: criarPainel,
        criarStatus: criarStatus,
        configurarBusca: configurarBusca,
        util: {
            esc: esc, sexo: sexo, anosVida: anosVida, textoData: textoData, el: el, icone: icone,
            chamarApi: chamarApi, enviarApi: enviarApi, ICONE_SEXO: ICONE_SEXO, ROTULO_SEXO: ROTULO_SEXO
        }
    };
})();
